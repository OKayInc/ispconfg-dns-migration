#!/usr/bin/env php
<?php
/** ISPConfig 3.3 DNS JSON export. PHP 7.4+, 64-bit, pdo_mysql. No Composer. */
declare(strict_types=1);
namespace IspconfigDnsExport;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

ini_set('display_errors', 'stderr');

function usage(): string {
    return <<<'HELP'
Usage: php dns-export.php [database options] (--zones=LIST | --zone=ZONE... | --all)
                          [--server-id=ID] [--client-id=ID] [--output=FILE|-]
       php dns-export.php --list-sources [database options]

Selection:
  --list-sources                   List source DNS servers and clients, then exit.
                                  Lists all sources; export selection/output options
                                  are not applied in this mode. No --all is needed.
  --zones=example.com,example.net   Comma-separated zone names (one option).
  --zone=example.org               One zone; may be repeated and combined with --zones.
  --all                           Explicitly export all zones; excludes zone selectors.
  --server-id=ID                   Filter by SOURCE ISPConfig server ID.
  --client-id=ID                   Filter by SOURCE client ID; 0 means administrator.
                                  Client/server filters intersect, including with --all.
  --output=FILE                   New JSON file, mode 0600; refuses to overwrite.
                                  Default: - (JSON on stdout; diagnostics on stderr).

Database:
  With no --db-* options, read the source panel's installed interface config.
  Any --db-* option selects manual mode; supply --db-user or --db-config with user.
  --ispconfig-root=PATH           Installed-mode root; default /usr/local/ispconfig.
  --db-config=FILE                JSON object: host, port, database, user, password,
                                  socket (optional). CLI values override this file.
  --db-host=HOST                  Manual-mode default: localhost.
  --db-port=PORT                  Manual-mode default: 3306.
  --db-name=DATABASE              Manual-mode default: dbispconfig.
  --db-user=USER                  Required in manual mode unless set in --db-config.
  --db-password=PASSWORD          Direct value; visible in shell history/process lists.
  --db-password-file=FILE         Password file; removes one final LF or CRLF only.
  --db-password-env=NAME          Read password from this environment variable.
  --db-socket=PATH                Unix socket; replaces host/port in the DSN.
  --help                         Show this help.

Password priority: explicit password option, then JSON config password, then the
ISPCONFIG_DB_PASSWORD environment variable, then the installed password (automatic
mode) or an empty password (manual mode/socket auth).
The three explicit password options are mutually exclusive. Other options take
either --name=value or --name value. International names must be in Punycode.

Exports primary zones, every RR including inactive records, and secondary zone
definitions. Secondary transferred zone contents and DNSSEC private keys are
not stored in the ISPConfig tables and are not included.
Use the source MANAGEMENT database. Automatic mode runs on the source panel host;
use sudo if its interface configuration is protected. Avoid DNS edits during migration.
HELP;
}

function options(array $argv): array {
    $flags = ['all', 'help', 'list-sources'];
    $values = ['zones', 'zone', 'server-id', 'client-id', 'output', 'ispconfig-root', 'db-config', 'db-host',
        'db-port', 'db-name', 'db-user', 'db-password', 'db-password-file',
        'db-password-env', 'db-socket'];
    $out = [];
    for ($i = 1; $i < count($argv); $i++) {
        if (substr($argv[$i], 0, 2) !== '--') {
            throw new RuntimeException('Unexpected positional argument; see --help.');
        }
        $pair = explode('=', substr($argv[$i], 2), 2);
        $key = $pair[0];
        if (!in_array($key, array_merge($flags, $values), true)) {
            throw new RuntimeException('Unknown option name; see --help.');
        }
        if ($key !== 'zone' && array_key_exists($key, $out)) {
            throw new RuntimeException("Option --$key must not be repeated.");
        }
        if (in_array($key, $flags, true)) {
            if (count($pair) !== 1) throw new RuntimeException("--$key takes no value.");
            $out[$key] = true;
            continue;
        }
        if (count($pair) === 2) {
            $value = $pair[1];
        } else {
            if (!isset($argv[$i + 1]) || substr($argv[$i + 1], 0, 2) === '--') {
                throw new RuntimeException("Missing value for --$key.");
            }
            $value = $argv[++$i];
        }
        if ($value === '' && $key !== 'db-password') {
            throw new RuntimeException("Empty value for --$key.");
        }
        if ($key === 'zone') $out[$key][] = $value;
        else $out[$key] = $value;
    }
    return $out;
}

function zoneName(string $name): string {
    $name = strtolower(trim($name));
    if (substr($name, -1) === '.') $name = substr($name, 0, -1);
    if ($name === '' || strlen($name) > 253 ||
        !preg_match('~\A[a-z0-9_/-]+(?:\.[a-z0-9_/-]+)*\z~D', $name)) {
        throw new RuntimeException('Invalid zone name; use ASCII/Punycode, optionally with a final dot.');
    }
    foreach (explode('.', $name) as $label) {
        if (strlen($label) > 63) throw new RuntimeException('A zone label exceeds 63 bytes.');
    }
    return $name . '.';
}

function positiveInt($value, string $label, int $max = PHP_INT_MAX): int {
    if ((!is_int($value) && !is_string($value)) ||
        !preg_match('/\A[0-9]+\z/D', (string)$value) ||
        strlen((string)$value) > 18 || (int)$value < 1 || (int)$value > $max) {
        throw new RuntimeException("$label must be a positive integer within its supported range.");
    }
    return (int)$value;
}

function localText(string $path, int $limit, string $label): string {
    if (!is_file($path) || !is_readable($path)) throw new RuntimeException("Cannot read $label.");
    $data = @file_get_contents($path, false, null, 0, $limit + 1);
    if ($data === false || strlen($data) > $limit) throw new RuntimeException("Invalid or oversized $label.");
    return $data;
}

function installedConfig(string $root): array {
    $root = realpath($root);
    if ($root === false || !is_file($root . '/interface/lib/config.inc.php') ||
        !is_readable($root . '/interface/lib/config.inc.php')) {
        throw new RuntimeException('Cannot read installed ISPConfig interface configuration. Run on the source panel host (sudo if needed), or supply manual --db-* options.');
    }
    $oldDirectory = getcwd();
    if ($oldDirectory === false) throw new RuntimeException('Cannot determine the current working directory.');
    $bufferLevel = ob_get_level();
    // Discard even an explicit flush by the trusted configuration/local override.
    ob_start(static function (string $buffer): string { return ''; });
    set_error_handler(static function (): void {
        throw new RuntimeException('Installed configuration could not be loaded.');
    });
    try {
        if (!chdir($root . '/interface/lib')) throw new RuntimeException('Cannot enter configuration directory.');
        // Scope isolation prevents config.inc.php from replacing exporter variables.
        // Only configuration is included: no application bootstrap or database writes.
        $installed = (static function (string $file): array {
            $conf = [];
            require $file;
            if (!isset($conf) || !is_array($conf)) throw new RuntimeException('Missing configuration array.');
            return $conf;
        })($root . '/interface/lib/config.inc.php');
        foreach (['db_host', 'db_database', 'db_user', 'db_password'] as $key) {
            if (!array_key_exists($key, $installed) || !is_string($installed[$key])) {
                throw new RuntimeException('Incomplete installed database configuration.');
            }
        }
        $cfg = ['host' => $installed['db_host'], 'port' => $installed['db_port'] ?? 3306,
            'database' => $installed['db_database'], 'user' => $installed['db_user'],
            'password' => $installed['db_password']];
        if (!empty($installed['db_socket']) && is_string($installed['db_socket'])) $cfg['socket'] = $installed['db_socket'];
        return $cfg;
    } catch (Throwable $error) {
        // Configuration errors may contain credentials; never print their details.
        throw new RuntimeException('Cannot load installed ISPConfig database configuration. Check the interface configuration and local overrides, or use manual --db-* options.');
    } finally {
        while (ob_get_level() > $bufferLevel) ob_end_clean();
        restore_error_handler();
        if (!@chdir($oldDirectory)) throw new RuntimeException('Cannot restore the working directory after reading ISPConfig configuration.');
    }
}

function databaseConfig(array $opts): array {
    $manual = false;
    foreach (array_keys($opts) as $key) if (strpos($key, 'db-') === 0) $manual = true;
    $cfg = $manual ? ['host' => 'localhost', 'port' => 3306, 'database' => 'dbispconfig']
        : installedConfig($opts['ispconfig-root'] ?? '/usr/local/ispconfig');
    $jsonPassword = false;
    if (isset($opts['db-config'])) {
        $loaded = json_decode(localText($opts['db-config'], 1048576, 'database configuration'), false, 32, JSON_THROW_ON_ERROR);
        if (!is_object($loaded)) {
            throw new RuntimeException('Database configuration must be a JSON object.');
        }
        $loaded = (array)$loaded;
        foreach ($loaded as $key => $value) {
            if (!in_array($key, ['host', 'port', 'database', 'user', 'password', 'socket'], true)) {
                throw new RuntimeException('Unknown database configuration key.');
            }
            if ($key !== 'port' && !is_string($value)) {
                throw new RuntimeException('Database configuration values other than port must be strings.');
            }
        }
        $jsonPassword = array_key_exists('password', $loaded);
        $cfg = array_merge($cfg, $loaded);
    }
    foreach (['db-host' => 'host', 'db-port' => 'port', 'db-name' => 'database',
              'db-user' => 'user', 'db-socket' => 'socket'] as $option => $key) {
        if (isset($opts[$option])) $cfg[$key] = $opts[$option];
    }
    $passwordOptions = array_intersect(['db-password', 'db-password-file', 'db-password-env'], array_keys($opts));
    if (count($passwordOptions) > 1) throw new RuntimeException('Choose only one explicit password option.');
    if (isset($opts['db-password'])) {
        $cfg['password'] = $opts['db-password'];
    } elseif (isset($opts['db-password-file'])) {
        $cfg['password'] = preg_replace('/\r?\n\z/', '', localText($opts['db-password-file'], 65536, 'password file'));
    } elseif (isset($opts['db-password-env'])) {
        if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $opts['db-password-env'])) {
            throw new RuntimeException('Invalid password environment variable name.');
        }
        $password = getenv($opts['db-password-env']);
        if ($password === false) throw new RuntimeException('Requested password environment variable is unset.');
        $cfg['password'] = $password;
    } elseif (!$jsonPassword) {
        $password = getenv('ISPCONFIG_DB_PASSWORD');
        if ($password !== false) $cfg['password'] = $password;
        elseif (!array_key_exists('password', $cfg)) $cfg['password'] = '';
    }
    if (!isset($cfg['user']) || $cfg['user'] === '') throw new RuntimeException('Supply --db-user or a user in --db-config.');
    $cfg['port'] = positiveInt($cfg['port'], 'Database port', 65535);
    foreach (['host', 'database', 'socket'] as $key) {
        if (isset($cfg[$key]) && ($cfg[$key] === '' || preg_match('/[;\x00-\x1f\x7f]/', $cfg[$key]))) {
            throw new RuntimeException("Invalid database $key setting.");
        }
    }
    return $cfg;
}

function listSources(PDO $db): void {
    $servers = $db->query('SELECT server_id,server_name,active,mirror_server_id FROM server WHERE dns_server=1 ORDER BY server_id')->fetchAll();
    $clients = $db->query('SELECT client_id,username FROM client ORDER BY client_id')->fetchAll();
    fwrite(STDOUT, "DNS servers (source IDs; choose the server holding the intended zones):\n");
    foreach ($servers as $server) {
        fwrite(STDOUT, $server['server_id'] . "\t" . $server['server_name']
            . "\tactive=" . $server['active'] . "\tmirror_server_id=" . $server['mirror_server_id'] . "\n");
    }
    fwrite(STDOUT, "\nClients:\n0\tadministrator\n");
    foreach ($clients as $client) {
        fwrite(STDOUT, $client['client_id'] . "\t" . $client['username'] . "\n");
    }
}

function zoneDefinitionLabel(string $kind, array $row, array $servers, ?int $clientId): string {
    $quote = static function ($value): string {
        return (string)json_encode((string)$value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    };
    $table = $kind === 'primary' ? 'dns_soa' : 'dns_slave';
    return $kind . ' (' . $table . '.id=' . (int)$row['id']
        . ', origin=' . $quote($row['origin'])
        . ', server_id=' . (int)$row['server_id']
        . ', server_name=' . $quote($servers[$row['server_id']] ?? '')
        . ', client_id=' . ($clientId === null ? 'unknown' : (string)$clientId)
        . ', active=' . $quote($row['active'] ?? '') . ')';
}

function collect(PDO $db, array $requested, ?int $serverId, ?int $clientFilter): array {
    $tables = ['dns_soa', 'dns_rr', 'dns_slave', 'server', 'sys_group', 'client'];
    $statement = $db->prepare('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?,?,?,?,?,?)');
    $statement->execute($tables);
    $engines = $statement->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($tables as $table) {
        if (!isset($engines[$table])) throw new RuntimeException("Missing ISPConfig table: $table.");
    }
    $locked = false;
    try {
        if (count(array_filter($engines, static function ($engine) { return strcasecmp($engine, 'InnoDB') !== 0; })) === 0) {
            $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->beginTransaction(); // All subsequent consistent reads share one snapshot.
        } else {
            fwrite(STDERR, "Non-InnoDB tables detected: holding READ locks while exporting.\n");
            $db->exec('LOCK TABLES ' . implode(', ', array_map(static function ($table) { return "`$table` READ"; }, $tables)));
            $locked = true;
        }
        $servers = $db->query('SELECT server_id, server_name FROM server')->fetchAll(PDO::FETCH_KEY_PAIR);
        $groups = $db->query('SELECT groupid, client_id FROM sys_group')->fetchAll(PDO::FETCH_KEY_PAIR);
        $clients = $db->query('SELECT client_id, username FROM client')->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($serverId !== null && !array_key_exists($serverId, $servers)) {
            throw new RuntimeException('Requested source server ID does not exist.');
        }
        if ($clientFilter !== null && $clientFilter !== 0 && !array_key_exists($clientFilter, $clients)) {
            throw new RuntimeException('Requested source client ID does not exist.');
        }
        $zones = [];
        foreach (['dns_soa' => 'primary', 'dns_slave' => 'secondary'] as $table => $kind) {
            $query = $db->prepare("SELECT * FROM `$table`" . ($serverId === null ? '' : ' WHERE server_id = ?') . ' ORDER BY id');
            $query->execute($serverId === null ? [] : [$serverId]);
            foreach ($query->fetchAll() as $row) {
                $clientId = isset($groups[$row['sys_groupid']]) ? (int)$groups[$row['sys_groupid']] : null;
                if ($clientFilter !== null && $clientId !== $clientFilter) continue;
                $origin = zoneName((string)$row['origin']);
                if ($requested !== [] && !isset($requested[$origin])) continue;
                if (isset($zones[$origin])) {
                    $existing = $zones[$origin];
                    $guidance = 'Inspect the listed zone definitions and the source panel\'s filters before exporting; the exporter will not choose one automatically.';
                    if ((int)$existing['source']['server_id'] !== (int)$row['server_id']) {
                        $guidance = 'Use --server-id to select the intended source server shown above.';
                    } elseif ($clientFilter === null && $existing['source']['client_id'] !== $clientId) {
                        $guidance = 'Use --client-id to select the intended source client shown above, when its ownership is known.';
                    }
                    throw new RuntimeException("Ambiguous source zone $origin: at least two definitions match after all requested filters.\n"
                        . '  - ' . zoneDefinitionLabel($existing['kind'], $existing['soa'], $servers, $existing['source']['client_id']) . "\n"
                        . '  - ' . zoneDefinitionLabel($kind, $row, $servers, $clientId) . "\n"
                        . "Primary and secondary zones appear in separate ISPConfig lists.\n" . $guidance);
                }
                unset($row['rendered_zone']);
                $zones[$origin] = ['kind' => $kind, 'origin' => $origin, 'source' => [
                    'zone_id' => (int)$row['id'], 'server_id' => (int)$row['server_id'],
                    'server_name' => (string)($servers[$row['server_id']] ?? ''),
                    'client_id' => $clientId, 'client_username' => $clientId === null ? null : ($clients[$clientId] ?? null),
                    'sys_userid' => (int)$row['sys_userid'], 'sys_groupid' => (int)$row['sys_groupid'],
                ], 'soa' => $row, 'records' => []];
            }
        }
        $missing = array_diff_key($requested, $zones);
        if ($missing !== []) throw new RuntimeException('Requested zone(s) not found: ' . implode(', ', array_keys($missing)) . ' (after source server/client filters).');
        ksort($zones, SORT_STRING);
        $records = $db->prepare('SELECT * FROM dns_rr WHERE zone = ? ORDER BY id');
        foreach ($zones as &$zone) {
            if ($zone['kind'] === 'primary') {
                $records->execute([$zone['source']['zone_id']]);
                $zone['records'] = $records->fetchAll();
            }
        }
        unset($zone);
        return array_values($zones);
    } finally {
        if ($db->inTransaction()) $db->rollBack(); // Read-only snapshot, no changes to commit.
        if ($locked) $db->exec('UNLOCK TABLES');
    }
}

function writePayload(string $path, string $json): void {
    $stream = STDOUT;
    $created = false;
    try {
        if ($path !== '-') {
            if (strpos($path, '://') !== false) throw new RuntimeException('Output must be a local filename or -.');
            $oldMask = umask(0077);
            try { $stream = @fopen($path, 'xb'); }
            finally { umask($oldMask); }
            if ($stream === false) throw new RuntimeException('Cannot create output file; it may already exist or its directory may be unwritable.');
            $created = true;
        }
        $length = strlen($json);
        for ($offset = 0; $offset < $length;) {
            $written = @fwrite($stream, substr($json, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('Could not write complete JSON output.');
            $offset += $written;
        }
        if (!fflush($stream)) throw new RuntimeException('Could not flush JSON output.');
        if ($created && function_exists('fsync') && !fsync($stream)) throw new RuntimeException('Could not synchronize JSON output.');
    } catch (Throwable $error) {
        if ($created) { fclose($stream); @unlink($path); $created = false; }
        throw $error;
    } finally {
        if ($created) fclose($stream);
    }
}

try {
    if (PHP_SAPI !== 'cli') throw new RuntimeException('Run this script from PHP CLI only.');
    if (PHP_VERSION_ID < 70400 || PHP_INT_SIZE < 8) throw new RuntimeException('PHP 7.4+ with 64-bit integers is required.');
    $opts = options($argv);
    if (isset($opts['help'])) { fwrite(STDOUT, usage() . "\n"); exit(0); }
    $requested = [];
    $serverId = null;
    $clientFilter = null;
    if (!isset($opts['list-sources'])) {
        $selectors = $opts['zone'] ?? [];
        if (isset($opts['zones'])) $selectors = array_merge($selectors, explode(',', $opts['zones']));
        if (isset($opts['all']) && $selectors !== []) throw new RuntimeException('--all cannot be combined with --zone or --zones.');
        if (!isset($opts['all']) && $selectors === []) throw new RuntimeException('Select --zone/--zones, or use --all explicitly; use --list-sources to find source IDs.');
        foreach ($selectors as $selector) $requested[zoneName($selector)] = true;
        $serverId = isset($opts['server-id']) ? positiveInt($opts['server-id'], 'Source server ID', 4294967295) : null;
        $clientFilter = isset($opts['client-id']) ? ($opts['client-id'] === '0' ? 0 : positiveInt($opts['client-id'], 'Source client ID', 4294967295)) : null;
    }
    $cfg = databaseConfig($opts);
    if (!extension_loaded('pdo_mysql')) throw new RuntimeException('The pdo_mysql extension is required.');
    $dsn = 'mysql:' . (isset($cfg['socket']) ? 'unix_socket=' . $cfg['socket'] : 'host=' . $cfg['host'] . ';port=' . $cfg['port'])
        . ';dbname=' . $cfg['database'] . ';charset=utf8mb4';
    $db = new PDO($dsn, $cfg['user'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false]);
    if (isset($opts['list-sources'])) { listSources($db); exit(0); }
    $zones = collect($db, $requested, $serverId, $clientFilter);
    $payload = ['format' => 'ispconfig-dns-backup', 'version' => 1, 'created_at' => gmdate('c'),
        'source' => ['database' => $cfg['database']], 'zones' => $zones];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    writePayload($opts['output'] ?? '-', $json);
    $rrCount = 0;
    foreach ($zones as $zone) {
        $count = count($zone['records']); $rrCount += $count;
        fwrite(STDERR, sprintf("Exported %s (%s; %d records).\n", $zone['origin'], $zone['kind'], $count));
        if (strtoupper((string)($zone['soa']['dnssec_wanted'] ?? 'N')) === 'Y' ||
            strtoupper((string)($zone['soa']['dnssec_initialized'] ?? 'N')) === 'Y' ||
            in_array('DNSKEY', array_map(static function ($type) { return strtoupper((string)$type); }, array_column($zone['records'], 'type')), true)) {
            fwrite(STDERR, "WARNING: {$zone['origin']} has DNSSEC data; private signing keys are NOT backed up.\n");
        }
    }
    fwrite(STDERR, sprintf("Complete: %d zones, %d records.\n", count($zones), $rrCount));
} catch (PDOException $error) {
    fwrite(STDERR, "ERROR: Database connection/query failed. Check credentials, schema, SELECT permissions, and LOCK TABLES permission for non-InnoDB tables.\n");
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
