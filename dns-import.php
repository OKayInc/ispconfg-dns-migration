#!/usr/bin/env php
<?php
/**
 * Import dns-export.php JSON into an independent ISPConfig 3.3 installation.
 * PHP 7.4+, 64-bit, PDO MySQL, MySQLi and mbstring. No Composer dependencies.
 * Run on the DESTINATION panel host. Uses its installed ISPConfig code.
 * Preview is the default; --apply performs writes. See README.md.
 */
declare(strict_types=1);

function migFail(string $message): void { throw new RuntimeException($message); }
function migNote(string $message): void { fwrite(STDERR, $message . PHP_EOL); }

function migHelp(): void {
    echo <<<'HELP'
Usage:
  php dns-import.php --input=zones.json --server-id=2 --client-id=7 [DB OPTIONS]
  php dns-import.php --input=zones.json --server-id=2 --client-id=7 --apply
  php dns-import.php --list-targets [DB OPTIONS]

Run on the destination ISPConfig panel host, against its CENTRAL database.
Without --apply, the script validates and prints the plan without DNS writes.

Selection and ownership:
  --input=PATH             JSON from dns-export.php; '-' reads standard input.
  --zones=a.example,b.example  Import these origins only; omitted means all.
  --zone=example.com       Repeatable alternative to --zones.
  --server-id=N            Destination DNS server (parent, not a mirror).
  --client-id=N            Destination client; 0 means administrator ownership.
  --map=PATH               JSON per-origin overrides: {"example.com":{"client_id":7,
                           "server_id":2}}. Unspecified values use CLI defaults.
  --skip-existing         Leave existing zones untouched instead of merging them.
  --list-targets          List destination DNS servers and clients, then exit.
  --apply                 Perform import; otherwise preview only.
  --dry-run               Explicit preview (cannot be combined with --apply).

Database / installation:
  --ispconfig-root=PATH    Default /usr/local/ispconfig.
  --db-config=PATH         JSON: host, port, database, user, password, socket.
  --db-host=HOST           Override installed connection setting.
  --db-port=PORT           Override installed connection setting.
  --db-name=DATABASE       Override installed connection setting.
  --db-user=USER           Override installed connection setting.
  --db-password=PASSWORD   Supported, but visible in shell history/process lists.
  --db-password-file=PATH  Read one password, trimming one final line ending.
  --db-password-env=NAME   Read password from named environment variable.
  --db-socket=PATH         MySQL Unix socket; use localhost as host.
  Password priority: explicit option, --db-config password, default environment
  variable ISPCONFIG_DB_PASSWORD, then the installed interface config password.
  Other credentials default to the destination interface config.inc.php.

Existing zones are updated in place. Matching records are reused; different
values are added. Destination-only records are kept. No record deletions.
DNSSEC-signed primary zones are refused: JSON has no private signing keys.
HELP;
    echo PHP_EOL;
}

function migOptions(array $argv): array {
    $flags = array_fill_keys(['help','apply','dry-run','skip-existing','list-targets'], true);
    $values = array_fill_keys(['input','zones','zone','server-id','client-id','map','ispconfig-root',
        'db-config','db-host','db-port','db-name','db-user','db-password','db-password-file',
        'db-password-env','db-socket'], true);
    $out = [];
    for ($i = 1; $i < count($argv); $i++) {
        if (substr($argv[$i], 0, 2) !== '--') migFail('Unexpected argument: ' . $argv[$i]);
        $pair = explode('=', substr($argv[$i], 2), 2); $key = $pair[0];
        if (isset($flags[$key])) {
            if (count($pair) > 1) migFail('--' . $key . ' does not take a value.');
            $value = true;
        } elseif (isset($values[$key])) {
            if (count($pair) > 1) $value = $pair[1];
            else {
                if (!isset($argv[$i+1]) || substr($argv[$i+1], 0, 2) === '--') migFail('Missing value for --' . $key);
                $value = $argv[++$i];
            }
            if ($value === '' && $key !== 'db-password') migFail('Empty value for --' . $key);
        } else migFail('Unknown option --' . $key);
        if ($key === 'zone') $out[$key][] = $value;
        else {
            if (array_key_exists($key, $out)) migFail('Repeated option --' . $key);
            $out[$key] = $value;
        }
    }
    if (isset($out['apply'], $out['dry-run'])) migFail('Use either --apply or --dry-run.');
    return $out;
}

function migUInt($value, string $label, int $min = 0, int $max = 4294967295): int {
    if ((!is_string($value) && !is_int($value)) || !preg_match('/^(0|[1-9][0-9]*)$/D', (string)$value)
        || strlen((string)$value) > 10 || (int)$value < $min || (int)$value > $max) {
        migFail($label . ' must be an integer between ' . $min . ' and ' . $max . '.');
    }
    return (int)$value;
}

function migOrigin($value): string {
    if (!is_string($value)) migFail('Zone origins must be strings.');
    $value = strtolower(trim($value));
    if (substr($value, -1) === '.') $value = substr($value, 0, -1);
    if ($value === '' || strlen($value) > 253) migFail('Invalid zone origin.');
    foreach (explode('.', $value) as $label) {
        if (!preg_match('/^[a-z0-9_\/\-]{1,63}$/D', $label)) {
            migFail('Invalid zone origin ' . $value . '; use ASCII/punycode names.');
        }
    }
    return $value . '.';
}

function migReadJson(string $path): array {
    $text = $path === '-' ? stream_get_contents(STDIN) : @file_get_contents($path);
    if ($text === false) migFail('Cannot read JSON file: ' . $path);
    try { $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
    catch (JsonException $e) { migFail('Invalid JSON in ' . $path . ': ' . $e->getMessage()); }
    if (!is_array($data)) migFail('Expected a JSON object in ' . $path);
    return $data;
}

function migCredentials(array $o, array $installed): array {
    $fileHasPassword = false;
    $c = ['host'=>$installed['db_host'] ?? 'localhost','port'=>$installed['db_port'] ?? 3306,
        'database'=>$installed['db_database'] ?? 'dbispconfig','user'=>$installed['db_user'] ?? '',
        'password'=>$installed['db_password'] ?? '', 'socket'=>''];
    if (isset($o['db-config'])) {
        $file = migReadJson($o['db-config']);
        $fileHasPassword = array_key_exists('password', $file);
        foreach ($file as $k=>$v) {
            if (!array_key_exists($k, $c)) migFail('Unknown DB configuration key: ' . $k);
            if (!is_string($v) && !is_int($v)) migFail('Invalid DB configuration value: ' . $k);
            $c[$k] = $v;
        }
    }
    foreach (['host','port','user','socket'] as $k) if (isset($o['db-'.$k])) $c[$k] = $o['db-'.$k];
    if (isset($o['db-name'])) $c['database'] = $o['db-name'];
    $methods = array_intersect(['db-password','db-password-file','db-password-env'], array_keys($o));
    if (count($methods) > 1) migFail('Use only one explicit password option.');
    if (array_key_exists('db-password', $o)) $c['password'] = $o['db-password'];
    elseif (isset($o['db-password-file'])) {
        $password = @file_get_contents($o['db-password-file']);
        if ($password === false) migFail('Cannot read password file.');
        $c['password'] = preg_replace('/\r?\n\z/', '', $password);
    } elseif (isset($o['db-password-env']) || !$fileHasPassword) {
        $env = $o['db-password-env'] ?? 'ISPCONFIG_DB_PASSWORD';
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $env)) migFail('Invalid password environment variable name.');
        $password = getenv($env);
        if ($password !== false) $c['password'] = $password;
        elseif (isset($o['db-password-env'])) migFail('Password environment variable is not set: ' . $env);
    }
    $c['port'] = migUInt($c['port'], 'Database port', 1, 65535);
    foreach (['host','database','user','password','socket'] as $k) $c[$k] = (string)$c[$k];
    if ($c['user'] === '') migFail('A MySQL user is required.');
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/D', $c['database'])) migFail('Invalid database name.');
    foreach (['host','socket'] as $k) if (preg_match('/[;\x00-\x1f]/', $c[$k])) migFail('Invalid database ' . $k);
    if ($c['socket'] !== '' && $c['host'] !== 'localhost') migFail('--db-socket requires --db-host=localhost.');
    return $c;
}

function migConnect(array $c): PDO {
    $dsn = $c['socket'] !== '' ? 'mysql:unix_socket='.$c['socket'] : 'mysql:host='.$c['host'].';port='.$c['port'];
    try {
        return new PDO($dsn.';dbname='.$c['database'].';charset=utf8mb4', $c['user'], $c['password'],
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_STRINGIFY_FETCHES=>true]);
    } catch (PDOException $e) { migFail('Cannot connect to the destination database. Check host, database and credentials.'); }
}

function migQuery(PDO $pdo, string $sql, array $params = []): array {
    $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchAll();
}

function migColumns(PDO $pdo, string $table): array {
    $rows = migQuery($pdo, 'SHOW FULL COLUMNS FROM `'.$table.'`');
    $out = []; foreach ($rows as $r) $out[$r['Field']] = $r; return $out;
}

function migCheckFields(array $row, array $columns, string $context): void {
    foreach ($row as $field=>$value) {
        if (!isset($columns[$field])) migFail($context . ': destination has no field ' . $field);
        $column = $columns[$field]; $type = strtolower($column['Type']);
        if ($value === null) {
            if ($column['Null'] !== 'YES') migFail($context . ': ' . $field . ' cannot be null.');
            continue;
        }
        if (!is_string($value) && !is_int($value)) migFail($context . ': invalid value for ' . $field);
        if ((string)$value === '#NULL#') migFail($context . ': literal #NULL# cannot be passed through ISPConfig\'s native SQL helper without changing it.');
        if (strpos((string)$value, "\0") !== false) migFail($context . ': NUL byte in ' . $field);
        if (preg_match('/^(?:tinyint|smallint|mediumint|int|bigint)\b/', $type)) {
            migUInt($value, $context . '.' . $field);
        } elseif (preg_match('/^(?:var)?char\((\d+)\)/', $type, $m)) {
            if (mb_strlen((string)$value, 'UTF-8') > (int)$m[1]) migFail($context . ': ' . $field . ' is too long for the destination.');
        } elseif (preg_match('/^(tinytext|text|mediumtext|longtext)\b/', $type, $m)) {
            $max = ['tinytext'=>255,'text'=>65535,'mediumtext'=>16777215,'longtext'=>4294967295][$m[1]];
            if (strlen((string)$value) > $max) migFail($context . ': ' . $field . ' is too long for the destination.');
        }
        if (substr($type, 0, 5) === 'enum(' || substr($type, 0, 4) === 'set(') {
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $column['Type'], $m);
            if (!in_array((string)$value, $m[1], true)) migFail($context . ': unsupported value for ' . $field . ': ' . (string)$value);
        }
        $collation = $column['Collation'] ?? '';
        if (preg_match('/^utf8(?:mb3)?_/', $collation) && preg_match('/[\x{10000}-\x{10FFFF}]/u', (string)$value)) {
            migFail($context . ': ' . $field . ' contains characters unsupported by the destination UTF-8 column.');
        }
    }
}

function migOwner(PDO $pdo, int $clientId): array {
    if ($clientId === 0) {
        $users = migQuery($pdo, "SELECT userid,default_group,username FROM sys_user WHERE typ='admin' ORDER BY userid");
        if (!$users) migFail('No destination administrator found.');
        $u = $users[0];
        $groups = migQuery($pdo, 'SELECT groupid,client_id FROM sys_group WHERE groupid=?', [$u['default_group']]);
        if (!$groups || (int)$groups[0]['client_id'] !== 0) migFail('Administrator default group is not an administrator group.');
        $label = 'administrator (' . $u['username'] . ')';
    } else {
        $clients = migQuery($pdo, 'SELECT client_id,username FROM client WHERE client_id=?', [$clientId]);
        if (!$clients) migFail('Destination client ID ' . $clientId . ' does not exist.');
        $users = migQuery($pdo, 'SELECT userid,default_group,username FROM sys_user WHERE client_id=? ORDER BY userid', [$clientId]);
        $users = array_values(array_filter($users, static function (array $u) use ($clients): bool {
            return $u['username'] === $clients[0]['username'];
        }));
        if (count($users) !== 1) migFail('Cannot identify the primary panel user for destination client ' . $clientId);
        $u = $users[0];
        $groups = migQuery($pdo, 'SELECT groupid,client_id FROM sys_group WHERE groupid=?', [$u['default_group']]);
        if (!$groups || (int)$groups[0]['client_id'] !== $clientId) migFail('Destination client user/group association is inconsistent for client ' . $clientId);
        $label = $clients[0]['username'];
    }
    return ['label'=>$label,'fields'=>['sys_userid'=>(int)$u['userid'],'sys_groupid'=>(int)$u['default_group'],
        'sys_perm_user'=>'riud','sys_perm_group'=>'riud','sys_perm_other'=>'']];
}

function migExisting(PDO $pdo, string $origin, bool $hasSlave): array {
    $found = [];
    foreach ($hasSlave ? ['dns_soa','dns_slave'] : ['dns_soa'] as $table) {
        foreach (migQuery($pdo, "SELECT * FROM `".$table."` WHERE LOWER(TRIM(TRAILING '.' FROM origin))=?", [rtrim($origin, '.')]) as $r) {
            $found[] = ['table'=>$table, 'row'=>$r];
        }
    }
    return $found;
}

function migExistingLabel(array $found): string {
    return implode(', ', array_map(static function (array $item): string {
        return $item['table'].'#'.$item['row']['id'].' on server '.$item['row']['server_id'];
    }, $found));
}

function migDiff(array $wanted, array $actual): array {
    $diff = [];
    foreach ($wanted as $key=>$value) {
        if (!array_key_exists($key, $actual) || ($value === null ? $actual[$key] !== null : $actual[$key] === null || (string)$value !== (string)$actual[$key])) $diff[$key] = $value;
    }
    return $diff;
}

function migDnsName(string $name, string $origin): string {
    if ($name === '' || $name === '@') return strtolower($origin);
    return strtolower(substr($name, -1) === '.' ? $name : $name.'.'.$origin);
}

function migRecordKey(array $rr, string $origin): string {
    $type = strtoupper((string)$rr['type']); $value = (string)$rr['data'];
    if (in_array($type, ['A','AAAA'], true)) {
        $packed = @inet_pton($value);
        if ($packed !== false) $value = bin2hex($packed);
    } elseif (in_array($type, ['NS','MX','CNAME','ALIAS','DNAME','PTR'], true)) {
        $value = migDnsName($value, $origin);
    } elseif ($type === 'SRV' && preg_match('/^([0-9]+)\s+([0-9]+)\s+(\S+)$/D', $value, $m)) {
        $value = (int)$m[1].' '.(int)$m[2].' '.migDnsName($m[3], $origin);
    }
    // Aux is part of RDATA for these types, not for A/AAAA/TXT.
    $aux = in_array($type, ['MX','SRV','NAPTR'], true) ? (int)$rr['aux'] : 0;
    return hash('sha256', json_encode([migDnsName((string)$rr['name'], $origin), $type, $value, $aux], JSON_THROW_ON_ERROR));
}

function migCheckMergedRecords(array $records, string $origin): void {
    $owners = [];
    foreach ($records as $rr) {
        if ($rr['active'] !== 'Y') continue;
        $name = migDnsName((string)$rr['name'], $origin); $type = strtoupper((string)$rr['type']);
        // ISPConfig's BIND template renders ALIAS as CNAME.
        if ($type === 'ALIAS') $type = 'CNAME';
        $key = migRecordKey(array_replace($rr, ['type'=>$type]), $origin);
        $owners[$name][$type][$key] = true;
    }
    foreach ($owners as $name=>$types) {
        if (isset($types['CNAME']) && (count($types['CNAME']) > 1 || count($types) > 1 || $name === strtolower($origin))) {
            migFail($origin.': merging would create conflicting active CNAME records at '.$name.'. Resolve this DNS conflict before importing.');
        }
        if (isset($types['DNAME']) && count($types['DNAME']) > 1) migFail($origin.': merging would create multiple active DNAME targets at '.$name);
    }
}

function migNextSerial($current, $source): int {
    $current = migUInt($current, 'Destination SOA serial'); $source = migUInt($source, 'Source SOA serial');
    $mod = 4294967296; $half = 2147483648;
    $delta = ($source - $current + $mod) % $mod;
    if ($delta === $half) migFail('Source and destination SOA serials are ambiguous under DNS serial arithmetic; reconcile them before merging.');
    $base = $delta > 0 && $delta < $half ? $source : $current;
    $next = ($base + 1) % $mod;
    foreach ([$current, $source] as $old) {
        $distance = ($next - $old + $mod) % $mod;
        if ($distance === 0 || $distance >= $half) migFail('Cannot select a safe newer SOA serial; reconcile source and destination serials before merging.');
    }
    return $next;
}

function migSerialNewer(int $candidate, int $old): bool {
    $distance = ($candidate - $old + 4294967296) % 4294967296;
    return $distance > 0 && $distance < 2147483648;
}

function migMergePlan(PDO $pdo, array $p, ?array $existing, array $ownerFields): array {
    $origin = $p['origin']; $unique = []; $backupDuplicates = 0;
    foreach ($p['records'] as $rr) {
        $key = migRecordKey($rr, $origin);
        if (isset($unique[$key])) {
            if (migDiff(['ttl'=>$rr['ttl'],'active'=>$rr['active']], $unique[$key])) migFail($origin.': duplicate backup record has conflicting TTL/active settings.');
            $backupDuplicates++; continue;
        }
        $unique[$key] = $rr;
    }
    $p['records'] = array_values($unique);
    $p['existing'] = $existing;
    $p['backup_duplicates'] = $backupDuplicates;
    $p['record_inserts'] = []; $p['record_updates'] = [];
    $p['extra_records'] = 0; $p['existing_duplicates'] = 0; $p['matched_records'] = 0;
    $p['soa_updates'] = [];
    if ($existing === null) {
        migCheckMergedRecords($p['records'], $origin);
        $p['record_inserts'] = $p['records']; $p['changed'] = true; $p['operation'] = 'CREATE';
        return $p;
    }
    $wantedSoa = $p['soa']; $wantedSoa['active'] = $p['active'];
    // Keep existing name spelling and never restore an old bookkeeping serial.
    $wantedSoa['origin'] = $existing['origin']; unset($wantedSoa['serial']);
    $p['soa_updates'] = migDiff($wantedSoa, $existing);
    $oldRrs = $p['kind'] === 'primary' ? migQuery($pdo, 'SELECT * FROM dns_rr WHERE zone=? ORDER BY id', [$existing['id']]) : [];
    $p['existing_records'] = $oldRrs;
    $byKey = []; $merged = [];
    $pendingSerial = $p['kind'] === 'primary' ? (int)$existing['serial'] : 0;
    foreach ($oldRrs as $rr) {
        if ((int)$rr['server_id'] !== (int)$existing['server_id']) migFail($origin.': existing record '.$rr['id'].' belongs to a different DNS server; repair that association first.');
        $key = migRecordKey($rr, $origin); $byKey[$key][] = $rr;
        $merged[(int)$rr['id']] = $rr;
        if (count($byKey[$key]) > 1) $p['existing_duplicates']++;
        if ($rr['serial'] !== null) {
            $rrSerial = migUInt($rr['serial'], $origin.' destination record serial');
            if (migSerialNewer($rrSerial, $pendingSerial)) $pendingSerial = $rrSerial;
        }
    }
    $touched = [];
    foreach ($unique as $key=>$wanted) {
        $wanted['zone'] = (int)$existing['id'];
        if (!isset($byKey[$key])) { $p['record_inserts'][] = $wanted; continue; }
        $p['matched_records']++;
        // Reuse one best match; never activate an inactive duplicate when a
        // suitable active row already exists. Existing duplicate rows are kept.
        $selectedId = null; $bestScore = PHP_INT_MAX;
        foreach ($byKey[$key] as $candidate) {
            $score = ((string)$candidate['active'] === (string)$wanted['active'] ? 0 : 100)
                + ((string)$candidate['ttl'] === (string)$wanted['ttl'] ? 0 : 10)
                + count(migDiff($ownerFields, $candidate));
            if ($score < $bestScore) { $bestScore = $score; $selectedId = (int)$candidate['id']; }
        }
        foreach ($byKey[$key] as $old) {
            $id = (int)$old['id']; $touched[$id] = true;
            // Equivalent name/IP spelling and irrelevant aux values stay intact.
            $wantedFields = $ownerFields + ['server_id'=>$p['server_id']];
            if ($id === $selectedId) { $wantedFields['ttl'] = $wanted['ttl']; $wantedFields['active'] = $wanted['active']; }
            $changes = migDiff($wantedFields, $old);
            if ($changes) {
                $p['record_updates'][$id] = ['id'=>$id,'fields'=>$changes,'before'=>$old];
                $merged[$id] = array_replace($old, $changes);
            }
        }
    }
    foreach ($oldRrs as $old) {
        $id = (int)$old['id']; if (isset($touched[$id])) continue;
        $p['extra_records']++;
        // A zone reassignment applies to retained records too; DNS values stay.
        $changes = migDiff($ownerFields + ['server_id'=>$p['server_id']], $old);
        if ($changes) { $p['record_updates'][$id] = ['id'=>$id,'fields'=>$changes,'before'=>$old]; $merged[$id] = array_replace($old, $changes); }
    }
    $p['record_updates'] = array_values($p['record_updates']);
    // Recover an interrupted merge that wrote RRs but failed before its final
    // SOA serial update. Ordinary repeat imports still remain true no-ops.
    $needsFinalization = $p['kind'] === 'primary' && migSerialNewer($pendingSerial, (int)$existing['serial']);
    $p['changed'] = (bool)($p['soa_updates'] || $p['record_inserts'] || $p['record_updates'] || $needsFinalization);
    $p['operation'] = $p['changed'] ? 'MERGE' : 'UNCHANGED';
    if ($p['changed']) migCheckMergedRecords(array_merge(array_values($merged), $p['record_inserts']), $origin);
    if ($p['kind'] === 'primary' && $p['changed']) {
        $dnsChange = (bool)$p['record_inserts'] || $needsFinalization;
        foreach ($p['record_updates'] as $update) if (isset($update['fields']['ttl']) || isset($update['fields']['active'])) $dnsChange = true;
        foreach (array_keys($p['soa_updates']) as $field) if (!in_array($field, ['sys_userid','sys_groupid','sys_perm_user','sys_perm_group','sys_perm_other','server_id'], true)) $dnsChange = true;
        $serialSeed = (int)$p['soa']['serial'];
        if (migSerialNewer($pendingSerial, $serialSeed)) $serialSeed = $pendingSerial;
        $serial = $dnsChange ? migNextSerial($existing['serial'], $serialSeed) : (int)$existing['serial'];
        if ($dnsChange) $p['soa_updates']['serial'] = $serial;
        foreach ($p['record_inserts'] as &$rr) $rr['serial'] = $serial;
        unset($rr);
        foreach ($p['record_updates'] as &$update) if (isset($update['fields']['ttl']) || isset($update['fields']['active'])) $update['fields']['serial'] = $serial;
        unset($update);
    }
    return $p;
}

function migPlan(PDO $pdo, array $payload, array $o, bool $hasSlave): array {
    if (($payload['format'] ?? null) !== 'ispconfig-dns-backup' || ($payload['version'] ?? null) !== 1
        || !isset($payload['zones']) || !is_array($payload['zones'])) migFail('Unsupported backup format/version.');
    $wanted = [];
    $selectors = $o['zone'] ?? [];
    if (isset($o['zones'])) $selectors = array_merge($selectors, explode(',', $o['zones']));
    foreach ($selectors as $selector) $wanted[migOrigin($selector)] = true;
    $all = [];
    foreach ($payload['zones'] as $zone) {
        if (!is_array($zone) || !isset($zone['origin'])) migFail('Invalid zone entry in the backup.');
        $origin = migOrigin($zone['origin']);
        if (isset($all[$origin])) migFail('Backup contains duplicate origin ' . $origin . '; export one source server at a time.');
        $all[$origin] = $zone;
    }
    foreach ($wanted as $origin=>$_) if (!isset($all[$origin])) migFail('Requested zone is absent from the backup: ' . $origin);
    $backupOrigins = array_fill_keys(array_keys($all), true);
    if ($wanted) $all = array_intersect_key($all, $wanted);
    if (!$all) migFail('No zones selected.');
    $mapping = [];
    if (isset($o['map'])) {
        foreach (migReadJson($o['map']) as $name=>$entry) {
            $origin = migOrigin($name);
            if (!isset($backupOrigins[$origin])) migFail('Mapping refers to a zone absent from the backup: ' . $origin);
            if (isset($mapping[$origin]) || !is_array($entry) || !$entry) migFail('Invalid/duplicate mapping for ' . $origin);
            foreach ($entry as $key=>$value) {
                if (!in_array($key, ['client_id','server_id'], true)) migFail('Unknown mapping key ' . $key . ' for ' . $origin);
                $entry[$key] = migUInt($value, 'Mapping ' . $key, $key === 'server_id' ? 1 : 0);
            }
            $mapping[$origin] = $entry;
        }
    }
    $schema = ['dns_soa'=>migColumns($pdo, 'dns_soa'),'dns_rr'=>migColumns($pdo, 'dns_rr')];
    if ($hasSlave) $schema['dns_slave'] = migColumns($pdo, 'dns_slave');
    $owners = []; $servers = []; $plan = [];
    foreach ($all as $origin=>$zone) {
        $exists = migExisting($pdo, $origin, $hasSlave);
        if ($exists && isset($o['skip-existing'])) {
            $plan[] = ['origin'=>$origin, 'skip'=>true, 'reason'=>migExistingLabel($exists)];
            continue;
        }
        $m = $mapping[$origin] ?? [];
        $sid = $m['server_id'] ?? ($o['server-id'] ?? null);
        $cid = $m['client_id'] ?? ($o['client-id'] ?? null);
        if ($sid === null || $cid === null) migFail('Supply destination --server-id and --client-id, or a complete --map entry for ' . $origin);
        $sid = migUInt($sid, 'Destination server ID', 1); $cid = migUInt($cid, 'Destination client ID');
        if (!isset($servers[$sid])) {
            $s = migQuery($pdo, 'SELECT server_id,server_name,dns_server,active,mirror_server_id FROM server WHERE server_id=?', [$sid]);
            if (!$s || (int)$s[0]['dns_server'] !== 1 || (int)$s[0]['active'] !== 1 || (int)$s[0]['mirror_server_id'] !== 0) {
                migFail('Server ' . $sid . ' is not an active, non-mirror destination DNS server.');
            }
            $servers[$sid] = $s[0]['server_name'];
        }
        if (!isset($owners[$cid])) $owners[$cid] = migOwner($pdo, $cid);
        $owner = $owners[$cid];
        $kind = $zone['kind'] ?? '';
        if (!in_array($kind, ['primary','secondary'], true)) migFail($origin . ': unknown zone kind.');
        $table = $kind === 'primary' ? 'dns_soa' : 'dns_slave';
        if (!isset($schema[$table])) migFail('Destination does not support ' . $table);
        $existing = null;
        if ($exists) {
            if (count($exists) !== 1) migFail($origin.': multiple existing definitions; resolve the ambiguity before merging ('.migExistingLabel($exists).').');
            if ($exists[0]['table'] !== $table) migFail($origin.': destination primary/secondary kind differs from the backup; automatic conversion is not supported.');
            $existing = $exists[0]['row'];
            if ((int)$existing['server_id'] !== $sid) migFail($origin.': existing zone is on destination server '.$existing['server_id'].', not '.$sid.'. Use its existing server ID; this importer does not move zones between servers.');
            if ($kind === 'primary' && (($existing['dnssec_wanted'] ?? 'N') === 'Y' || ($existing['dnssec_initialized'] ?? 'N') === 'Y')) {
                migFail($origin.': the existing destination zone uses DNSSEC; coordinate its signing keys separately before merging.');
            }
        }
        if (!isset($zone['soa'], $zone['records']) || !is_array($zone['soa']) || !is_array($zone['records'])) migFail($origin . ': missing SOA/records payload.');
        $soa = $zone['soa'];
        if (!isset($soa['origin']) || migOrigin($soa['origin']) !== $origin) migFail($origin . ': inconsistent origin in SOA.');
        if ($kind === 'secondary' && count($zone['records']) !== 0) migFail($origin . ': a secondary definition must not contain records.');
        if ($kind === 'primary' && (strtoupper((string)($soa['dnssec_wanted'] ?? 'N')) === 'Y'
            || strtoupper((string)($soa['dnssec_initialized'] ?? 'N')) === 'Y')) {
            migFail($origin . ': signed/DNSSEC-enabled zone. Private signing keys are not in this JSON; migrate DNSSEC separately before importing this zone.');
        }
        $allowed = $kind === 'primary'
            ? ['origin','ns','mbox','serial','refresh','retry','expire','minimum','ttl','active','xfer','also_notify','update_acl']
            : ['origin','ns','active','xfer'];
        $required = $kind === 'primary' ? ['origin','ns','mbox','serial','refresh','retry','expire','minimum','ttl','active'] : ['origin','ns','active'];
        foreach ($required as $field) if (!array_key_exists($field, $soa)) migFail($origin . ': missing SOA field ' . $field);
        $ignored = ['id','sys_userid','sys_groupid','sys_perm_user','sys_perm_group','sys_perm_other','server_id',
            'dnssec_wanted','dnssec_initialized','dnssec_algo','dnssec_last_signed','dnssec_info','rendered_zone'];
        foreach ($soa as $key=>$value) if (!in_array($key, $allowed, true) && !in_array($key, $ignored, true)) {
            migFail($origin . ': unsupported source SOA field ' . $key . '; review schema differences before importing.');
        }
        $newSoa = $owner['fields'] + ['server_id'=>$sid];
        foreach ($allowed as $field) if (array_key_exists($field, $soa)) $newSoa[$field] = $soa[$field];
        $newSoa['origin'] = $origin;
        $active = $newSoa['active'];
        if (!in_array($active, ['Y','N'], true)) migFail($origin . ': active flag must be Y or N.');
        // Validate the intended active value as well as every field before writes.
        migCheckFields($newSoa, $schema[$table], $origin . ' SOA');
        $newSoa['active'] = 'N';
        if ($kind === 'primary' && $existing === null) {
            foreach (['dnssec_wanted'=>'N','dnssec_initialized'=>'N','dnssec_last_signed'=>0,'dnssec_info'=>'','rendered_zone'=>''] as $field=>$value) {
                if (isset($schema[$table][$field])) $newSoa[$field] = $value;
            }
        }
        $rrs = [];
        foreach ($zone['records'] as $n=>$rr) {
            if (!is_array($rr)) migFail($origin . ': invalid resource record.');
            $fields = ['name','type','data','aux','ttl','active','serial'];
            foreach (['name','type','data','aux','ttl','active'] as $field) if (!array_key_exists($field, $rr)) migFail($origin . ': record lacks ' . $field);
            foreach ($rr as $field=>$value) if (!in_array($field, array_merge($fields, ['id','zone','server_id','stamp','sys_userid','sys_groupid','sys_perm_user','sys_perm_group','sys_perm_other']), true)) {
                migFail($origin . ': unsupported source record field ' . $field);
            }
            if (isset($rr['zone'], $soa['id']) && (string)$rr['zone'] !== (string)$soa['id']) migFail($origin . ': resource record belongs to a different source zone.');
            if (!is_string($rr['type']) || in_array(strtoupper($rr['type']), ['DNSKEY','RRSIG','NSEC','NSEC3','NSEC3PARAM'], true)) migFail($origin . ': generated DNSSEC record requires separate key migration.');
            if (!is_string($rr['name']) || preg_match('/[\x00-\x20\x7f]/', $rr['name'])) migFail($origin . ': invalid record owner name.');
            $newRr = $owner['fields'] + ['server_id'=>$sid, 'zone'=>1]; // New zone ID is assigned during apply.
            foreach ($fields as $field) if (array_key_exists($field, $rr)) $newRr[$field] = $rr[$field];
            migCheckFields($newRr, $schema['dns_rr'], $origin . ' record ' . ($n+1));
            $rrs[] = $newRr;
        }
        $p = ['origin'=>$origin,'skip'=>false,'kind'=>$kind,'table'=>$table,'soa'=>$newSoa,
            'records'=>$rrs,'active'=>$active,'client_id'=>$cid,'client_name'=>$owner['label'],
            'server_id'=>$sid,'server_name'=>$servers[$sid], 'source_client_id'=>$zone['source']['client_id'] ?? null];
        $plan[] = migMergePlan($pdo, $p, $existing, $owner['fields']);
    }
    return $plan;
}

function migBootstrap(string $root, array $c): object {
    global $app, $conf;
    foreach (['mysqli','mbstring'] as $ext) if (!extension_loaded($ext)) migFail('PHP extension required: ' . $ext);
    foreach (['host'=>'db_host','port'=>'db_port','database'=>'db_database','user'=>'db_user','password'=>'db_password'] as $key=>$cfg) $conf[$cfg] = $c[$key];
    // Match the PDO preflight/export connection; do not narrow TXT encoding.
    $conf['db_charset'] = 'utf8mb4';
    $conf['start_session'] = false; $conf['start_db'] = true;
    if ($c['socket'] !== '') ini_set('mysqli.default_socket', $c['socket']);
    $oldDirectory = getcwd(); chdir($root . '/interface/web');
    // Use the installation's real bootstrap and security configuration.
    try { require $root . '/interface/lib/app.inc.php'; }
    finally { if ($oldDirectory !== false) chdir($oldDirectory); }
    if (!isset($app->db) || !is_object($app->db)) migFail('ISPConfig could not open its database connection.');
    if (!class_exists('ISPCDnsMigrationDb', false)) {
        class ISPCDnsMigrationDb extends db {
            public function query($sQuery = '') {
                $result = parent::query(...func_get_args());
                if ($result === false) throw new RuntimeException('ISPConfig database query failed: ' . $this->errorMessage);
                return $result;
            }
        }
    }
    $old = $app->db; $app->db = new ISPCDnsMigrationDb(); $old->close();
    // The native constructor resets MySQLi reporting; restore it afterwards.
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    foreach (['datalogInsert','datalogUpdate'] as $method) if (!method_exists($app->db, $method)) migFail('Installed ISPConfig lacks ' . $method);
    $app->db->query("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
    $app->db->query('SET autocommit=1');
    return $app->db;
}

function migVerify(object $db, string $table, int $id, array $expected): void {
    $actual = $db->queryOneRecord('SELECT * FROM ?? WHERE id=?', $table, $id);
    if (!is_array($actual)) migFail('Inserted record could not be read back: ' . $table . '#' . $id);
    foreach ($expected as $field=>$value) {
        if (!array_key_exists($field, $actual) || ($value === null ? $actual[$field] !== null : (string)$value !== (string)$actual[$field])) {
            migFail('Read-back mismatch: ' . $table . '#' . $id . ' field ' . $field);
        }
    }
}

function migInsert(object $db, string $table, array $row): int {
    $id = (int)$db->datalogInsert($table, $row, 'id');
    if ($id < 1) migFail('ISPConfig returned no new ID for ' . $table);
    migVerify($db, $table, $id, $row);
    $event = $db->queryOneRecord("SELECT datalog_id FROM sys_datalog WHERE dbtable=? AND dbidx=? AND action='i' ORDER BY datalog_id DESC LIMIT 1", $table, 'id:'.$id);
    if (!$event) migFail('No insert event was queued for ' . $table . '#' . $id);
    return $id;
}

function migUpdate(object $db, string $table, int $id, array $fields, bool $force = false): int {
    $previous = $db->queryOneRecord('SELECT MAX(datalog_id) AS id FROM sys_datalog WHERE dbtable=? AND dbidx=?', $table, 'id:'.$id);
    $db->datalogUpdate($table, $fields, 'id', $id, $force);
    migVerify($db, $table, $id, $fields);
    $event = $db->queryOneRecord("SELECT datalog_id FROM sys_datalog WHERE dbtable=? AND dbidx=? AND action='u' ORDER BY datalog_id DESC LIMIT 1", $table, 'id:'.$id);
    if (!$event || (int)$event['datalog_id'] <= (int)($previous['id'] ?? 0)) migFail('No new update event was queued for '.$table.'#'.$id);
    return (int)$event['datalog_id'];
}

function migCheckUnchangedSincePlan(PDO $pdo, array $p, bool $hasSlave): void {
    $found = migExisting($pdo, $p['origin'], $hasSlave);
    if ($p['existing'] === null) {
        if ($found) migFail('Destination zone appeared after preflight: '.$p['origin'].'. Rerun to review a merge.');
        return;
    }
    if (count($found) !== 1 || $found[0]['table'] !== $p['table']) migFail('Destination zone changed since preflight: '.$p['origin']);
    $before = $p['existing']; $after = $found[0]['row'];
    // The DNS worker may refresh generated fields without an administrator edit.
    foreach (['rendered_zone','dnssec_info','dnssec_last_signed'] as $field) { unset($before[$field], $after[$field]); }
    if (migDiff($before, $after)) migFail('Destination zone changed since preflight: '.$p['origin'].'. Pause DNS edits and retry.');
    if ($p['kind'] === 'primary') {
        $current = migQuery($pdo, 'SELECT * FROM dns_rr WHERE zone=? ORDER BY id', [$p['existing']['id']]);
        if ($current !== $p['existing_records']) migFail('Destination records changed since preflight: '.$p['origin'].'. Pause DNS edits and retry.');
    }
}

function migApply(object $db, PDO $pdo, array $plan, bool $hasSlave): void {
    $complete = 0; $unchanged = 0;
    foreach ($plan as $p) {
        if ($p['skip']) continue;
        if (!$p['changed']) { $unchanged++; migNote('UNCHANGED '.$p['origin'].'; existing zone and records reused.'); continue; }
        $isNew = $p['existing'] === null;
        $zoneId = $isNew ? null : (int)$p['existing']['id'];
        try {
            migCheckUnchangedSincePlan($pdo, $p, $hasSlave);
            if ($isNew) $zoneId = migInsert($db, $p['table'], $p['soa']);
            $lastEvent = 0;
            foreach ($p['record_updates'] as $change) $lastEvent = migUpdate($db, 'dns_rr', $change['id'], $change['fields']);
            foreach ($p['record_inserts'] as $rr) { $rr['zone'] = $zoneId; migInsert($db, 'dns_rr', $rr); }
            if ($p['kind'] === 'primary') {
                $count = $db->queryOneRecord('SELECT COUNT(*) AS total FROM dns_rr WHERE zone=?', $zoneId);
                $expected = ($isNew ? 0 : count($p['existing_records'])) + count($p['record_inserts']);
                if ((int)$count['total'] !== $expected) migFail('Record count mismatch for ' . $p['origin']);
            }
            if ($isNew) {
                // Force the final event even for a newly imported inactive zone.
                $lastEvent = migUpdate($db, $p['table'], $zoneId, ['active'=>$p['active']], true);
            } elseif ($p['soa_updates']) {
                // Existing zones stay active throughout the record merge; final
                // SOA changes include a newer serial when DNS data changed.
                $lastEvent = migUpdate($db, $p['table'], $zoneId, $p['soa_updates']);
            }
            $complete++;
            migNote('QUEUED '.$p['origin'].' -> '.$p['table'].'#'.$zoneId.', added='.count($p['record_inserts']).', updated='.count($p['record_updates']).', active='.$p['active'].($lastEvent ? ', final datalog='.$lastEvent : ''));
        } catch (Throwable $e) {
            $state = $isNew ? 'If an incomplete zone row was created, inspect its inactive state before retrying.' : 'Existing zone '.$p['table'].'#'.$zoneId.' was not deliberately disabled. Some changes may already be queued; review the partial merge before retrying.';
            if ($isNew && $zoneId !== null) {
                try {
                    $db->datalogUpdate($p['table'], ['active'=>'N'], 'id', $zoneId, true);
                    migVerify($db, $p['table'], $zoneId, ['active'=>'N']);
                    $state = 'Partial new zone '.$p['table'].'#'.$zoneId.' is inactive. Review it before retrying the merge.';
                } catch (Throwable $cleanup) { $state = 'Could not verify or deactivate '.$p['table'].'#'.$zoneId.'. Inspect it before retrying or changing DNS delegation.'; }
            }
            migFail('Stopped at '.$p['origin'].': '.$e->getMessage().' '.$state.' Previously completed zones: '.$complete.'. This is not a transactional rollback. If a database row committed but its datalog event failed, use ISPConfig Tools > Resync > DNS after repairing the database error; rerunning alone does not requeue unchanged rows.');
        }
    }
    migNote('Finished: '.$complete.' zone(s) queued, '.$unchanged.' unchanged. Verify ISPConfig processing and authoritative DNS answers before cutover.');
}

function migMain(array $argv): int {
    global $conf;
    if (PHP_SAPI !== 'cli') migFail('CLI only.');
    if (PHP_VERSION_ID < 70400 || PHP_INT_SIZE < 8) migFail('64-bit PHP 7.4 or newer is required.');
    $o = migOptions($argv);
    if (isset($o['help'])) { migHelp(); return 0; }
    foreach (['pdo_mysql','mbstring'] as $ext) if (!extension_loaded($ext)) migFail('PHP extension required: '.$ext);
    if (!isset($o['input']) && !isset($o['list-targets'])) migFail('--input is required. See --help.');
    $root = realpath($o['ispconfig-root'] ?? '/usr/local/ispconfig');
    if ($root === false || !is_readable($root.'/interface/lib/config.inc.php') || !is_readable($root.'/interface/lib/app.inc.php')) {
        migFail('Run on the destination panel host, with readable ISPConfig interface files; use --ispconfig-root for a custom installation path.');
    }
    $dir = getcwd(); chdir($root.'/interface/lib');
    try { require $root.'/interface/lib/config.inc.php'; }
    finally { if ($dir !== false) chdir($dir); }
    if (defined('ISPC_APP_VERSION') && !preg_match('/^3\.3(?:\.|p|dev|$)/', ISPC_APP_VERSION)) migFail('This importer targets ISPConfig 3.3.x; installed version is '.ISPC_APP_VERSION);
    $c = migCredentials($o, $conf); $pdo = migConnect($c);
    // SHOW TABLES has a dynamic column name, so take each row's first value.
    $tables = array_map(static function (array $r): string { return (string)reset($r); }, migQuery($pdo, 'SHOW TABLES'));
    foreach (['dns_soa','dns_rr','sys_datalog','server','client','sys_user','sys_group'] as $t) if (!in_array($t, $tables, true)) migFail('Missing destination ISPConfig table: '.$t);
    $hasSlave = in_array('dns_slave', $tables, true);
    if (isset($o['list-targets'])) {
        echo "DNS servers (use an active parent with mirror_server_id=0):\n";
        foreach (migQuery($pdo, 'SELECT server_id,server_name,active,mirror_server_id FROM server WHERE dns_server=1 ORDER BY server_id') as $s) {
            echo $s['server_id']."\t".$s['server_name']."\tactive=".$s['active']."\tmirror_server_id=".$s['mirror_server_id']."\n";
        }
        echo "\nClients:\n0\tadministrator\n";
        foreach (migQuery($pdo, 'SELECT client_id,username FROM client ORDER BY client_id') as $r) echo $r['client_id']."\t".$r['username']."\n";
        return 0;
    }
    $payload = migReadJson($o['input']);
    $plan = migPlan($pdo, $payload, $o, $hasSlave);
    echo (isset($o['apply']) ? 'IMPORT PLAN' : 'PREVIEW ONLY')." — destination ".$c['host'].':'.$c['port'].'/'.$c['database']."\n";
    foreach ($plan as $p) {
        if ($p['skip']) echo 'SKIP '.$p['origin'].' — '.$p['reason']."\n";
        else echo $p['operation'].' '.$p['origin'].' ['.$p['kind'].', active='.$p['active'].', add='.count($p['record_inserts']).', update='.count($p['record_updates']).', matched='.$p['matched_records'].', retain-extra='.$p['extra_records'].'] source-client='.
            ($p['source_client_id'] === null ? 'unknown' : $p['source_client_id']).' -> client '.$p['client_id'].' ('.$p['client_name'].'), server '.$p['server_id'].' ('.$p['server_name'].")\n";
        if (!$p['skip'] && $p['backup_duplicates']) echo '  Collapsed '.$p['backup_duplicates']." identical duplicate backup record(s).\n";
        if (!$p['skip'] && $p['existing_duplicates']) echo '  Warning: '.$p['existing_duplicates']." duplicate destination row(s) already exist; retained without adding further copies.\n";
    }
    if (!isset($o['apply'])) { echo "No DNS rows or datalog events written. Add --apply to import this selection.\n"; return 0; }
    if (!array_filter($plan, static function (array $p): bool { return !$p['skip'] && $p['changed']; })) { migNote('Nothing to change; existing matching zones and records were reused.'); return 0; }
    $lockName = 'ispc_dns_import_'.substr(hash('sha256', $c['database']), 0, 32);
    $lock = migQuery($pdo, 'SELECT GET_LOCK(?,0) AS acquired', [$lockName]);
    if ((int)$lock[0]['acquired'] !== 1) migFail('Another DNS importer holds the destination database lock.');
    try {
        $db = migBootstrap($root, $c);
        $native = $db->queryOneRecord('SELECT DATABASE() AS db, @@hostname AS host, @@port AS port');
        $check = migQuery($pdo, 'SELECT DATABASE() AS db, @@hostname AS host, @@port AS port')[0];
        if ($native != $check) migFail('Native ISPConfig and preflight connections point to different databases.');
        // Recheck ALL selected zones and IDs immediately before the first write.
        $plan = migPlan($pdo, $payload, $o, $hasSlave);
        migApply($db, $pdo, $plan, $hasSlave);
    } finally { migQuery($pdo, 'SELECT RELEASE_LOCK(?)', [$lockName]); }
    return 0;
}

try { exit(migMain($argv)); }
catch (Throwable $e) { migNote('ERROR: '.$e->getMessage()); exit(1); }
