# ISPConfig 3.3 DNS migration — PHP

Two standalone PHP scripts move DNS configuration between **independent ISPConfig 3.3 installations**:

- `dns-export.php` reads selected source zones and writes a portable JSON backup.
- `dns-import.php` previews that backup, assigns destination ownership and server IDs, then imports it when `--apply` is supplied. **Missing destination zones are created automatically; existing zones are merged in place.**

The importer uses the destination installation's **native `datalogInsert()` and `datalogUpdate()` helpers**. This queues the normal ISPConfig configuration work, including replication to service nodes. Plain SQL inserts alone would leave that work unqueued. The remote API is another reasonable approach, particularly for off-server automation, but requires remote-user credentials and permissions. These scripts use MySQL credentials and installed ISPConfig code; no remote API account is required. See [source references](#source-references).

## 1. Requirements and placement

Use **64-bit PHP 7.4 or newer**, preferably the system PHP executable already running ISPConfig. The exporter needs `pdo_mysql`; importing also needs `mysqli`, `mbstring`, and the installed framework's normal XML/SimpleXML dependencies. No Composer installation is necessary. Check your CLI executable:

```bash
php -v
php -r 'foreach (["pdo_mysql", "mysqli", "mbstring", "SimpleXML"] as $e) echo $e, ": ", extension_loaded($e) ? "yes" : "MISSING", PHP_EOL;'
```

Place the scripts in a private working directory, for example `/root/dns-migration`. Normally, run the exporter on the **source panel host** and the importer on the **destination panel host**. Both must use their respective **central management databases**. Do not import into an individual service node's replicated database. Explicit credentials also let the exporter run elsewhere with database access.

With no database options, both scripts read `/usr/local/ispconfig/interface/lib/config.inc.php`. The exporter loads configuration only, including local overrides; the importer also uses the installed framework when applying changes. Run as root or with `sudo` when files are protected. Both accept `--ispconfig-root=/your/ispconfig/path` for a nonstandard installation.

Keep DNS edits paused while taking the final export and completing the migration. Start with a small selection to verify the target's normal DNS processing.

## 2. Automatic credentials and optional overrides

**No credentials JSON is needed for the normal workflow below.** Each script uses its own installation's database configuration. The importer still requires the **backup payload** through `--input=dns-zones.json`; that file contains zones, not connection credentials.

Optional manual access can use a protected `source-db.json` or `target-db.json` containing the appropriate installation's credentials:

```json
{
  "host": "localhost",
  "port": 3306,
  "database": "dbispconfig",
  "user": "YOUR_DB_USER",
  "password": "YOUR_DB_PASSWORD"
}
```

```bash
chmod 600 source-db.json
```

Add `--db-config=source-db.json` to export commands or `--db-config=target-db.json` to import commands. Protect either file with `chmod 600`. For a Unix socket, add `"socket": "/var/lib/mysql/mysql.sock"`, substituting the actual path, and use `"host": "localhost"`. The exporter needs SELECT access; non-InnoDB tables additionally require LOCK TABLES permission.

For **export**, any explicit `--db-*` option selects manual mode: supply a user through `--db-user` or `--db-config`; installed credentials are not mixed into that mode. Manual defaults are `localhost`, port `3306`, database `dbispconfig`. For **import**, database options override the installed settings. Credentials never enter the exported payload.

### Database options accepted by both scripts

| Option | Meaning |
| --- | --- |
| `--db-config=FILE` | JSON connection settings: `host`, `port`, `database`, `user`, `password`, optional `socket`. |
| `--db-host=HOST` | Host override. |
| `--db-port=PORT` | Port override. |
| `--db-name=NAME` | Database-name override. |
| `--db-user=USER` | MySQL user override. |
| `--db-password=VALUE` | Direct password; may be visible in shell history/process listings. |
| `--db-password-file=FILE` | Read a password, removing one final LF or CRLF only. |
| `--db-password-env=NAME` | Read the named environment variable; an unset variable is an error. |
| `--db-socket=PATH` | Unix socket; use `localhost` as the host. |

Explicit connection options override the JSON file. Password priority is: an explicit password option, the JSON file's password, `ISPCONFIG_DB_PASSWORD`, then the installed password. Manual export does not load installed settings and falls back to an empty password. The three explicit password options are mutually exclusive.

Both scripts accept `--option=value` and `--option value`, reject unknown options and duplicate scalar options, and provide `--help`.

## 3. Export the source zones

Export a selected list:

```bash
sudo php dns-export.php \
  --zones=example.com,example.net \
  --output=dns-zones.json
```

Use repeated `--zone=example.com` options if preferred. Names are matched without case sensitivity or dependence on a trailing dot. International names must be supplied in Punycode; underscore labels and RFC 2317 reverse-zone names are accepted.

Export everything explicitly:

```bash
sudo php dns-export.php \
  --all \
  --output=dns-all.json
```

Export all zones owned by source client 7, across all source servers:

```bash
sudo php dns-export.php \
  --all --client-id=7 \
  --output=client-7-zones.json
```

### Find the source server ID

On the **source panel host**, list the available source IDs:

```bash
sudo php dns-export.php --list-sources
```

This lists all source DNS servers with `server_id`, `server_name`, `active` and `mirror_server_id`, plus clients with `client_id` and `username`, including client `0` for administrator ownership. It uses the same automatic ISPConfig database configuration as an export, or the explicit database options described above.

`--list-sources` requires no `--all` or zone selector. It prints readable text and exits without creating a JSON backup. Listing takes precedence over export selection and output options, matching the importer's `--list-targets` behavior; invoke it on its own, with database options if needed.

`mirror_server_id` describes an **ISPConfig server mirroring relationship**. It does not identify a zone as primary or secondary. Use the primary-zone query below when you need to establish which server holds a particular primary definition.

The following read-only SQL queries are an optional alternative, run against the **source management database**. Replace `dbispconfig` if your database has a different name. The `mariadb` client uses its own database authentication; it does not automatically read ISPConfig's PHP configuration. If socket authentication through `sudo` is unavailable, supply your normal database login options, such as `-u USER -p`.

List the source servers configured to provide DNS:

```bash
sudo mariadb dbispconfig <<'SQL'
SELECT server_id, server_name
FROM server
WHERE dns_server = 1
ORDER BY server_id;
SQL
```

To identify the server containing a particular **primary zone**, replace client ID `7` and both spellings of `example.com` below:

```bash
sudo mariadb dbispconfig <<'SQL'
SELECT
    z.id AS zone_id,
    z.origin,
    z.active,
    z.server_id,
    s.server_name,
    g.client_id
FROM dns_soa AS z
LEFT JOIN server AS s ON s.server_id = z.server_id
JOIN sys_group AS g ON g.groupid = z.sys_groupid
WHERE g.client_id = 7
  AND LOWER(TRIM(z.origin)) IN ('example.com', 'example.com.')
ORDER BY z.server_id, z.id;
SQL
```

Use the intended row's **`server_id`**, not its `zone_id`, in the export command. If a primary and a secondary definition of the same name are on different servers, selecting the primary's server excludes that secondary definition. Inspect all returned rows if the primary-zone query itself has multiple matches.

The server filter applies to the **entire export**: `--all --client-id=7 --server-id=2` includes only client 7's zones on source server 2 and excludes that client's zones on other servers. Source and destination IDs are independent; find destination IDs separately with `--list-targets` as described below.

For example, if the intended source server ID is `2`, restrict the export to it:

```bash
sudo php dns-export.php \
  --all --client-id=7 --server-id=2 \
  --output=client-7-server-2-zones.json
```

Client filtering follows `sys_group.client_id`, not the row creator's user ID or the group number itself. It includes primary and secondary definitions and inactive zones. `--client-id=0` selects known administrator-owned groups; records with an unknown group are not classified as administrator-owned. A nonexistent nonzero client ID is an error; an existing client with no matching zones produces a valid empty backup with `--all`.

Export requires selectors or `--all`. You may combine `--zones`/`--zone` with client/server filters: all conditions must match, and a requested zone outside the filters causes an error before output is written. Remaining duplicate origins are rejected; narrow the client/server selection to one definition. On **export**, IDs identify source objects; on **import**, they identify destination objects.

If export reports an **ambiguous source zone** even though you see one zone in the panel, check both **Zones** and **Secondary DNS Zones**. The exporter compares normalized domain names across `dns_soa` (primary) and `dns_slave` (secondary), including inactive definitions, after applying every requested filter. A primary definition and a secondary definition on another server can therefore collide even though each screen shows only one entry. The error lists the first two matching definitions with their table, row ID, original name, server ID/name, client ID and active state. Inspect those entries and clear any panel search filters; if the definitions are on different servers, add the intended source `--server-id`. The check does not examine duplicate resource records, and a failed ambiguity check writes no backup payload or database changes.

Output files are created exclusively with mode `0600`: existing files are refused. Without `--output`, or with `--output=-`, JSON goes to stdout and diagnostics to stderr. Shell redirection controls its own file permissions; using `--output` is preferable.

The exporter reads all related tables from one consistent InnoDB snapshot. If any read table uses another engine, it holds READ locks while collecting the backup; this can briefly delay panel writes. It never changes source DNS rows.

Copy the completed JSON and importer to the destination working directory using your usual secure transfer method. Preserve the backup unchanged.

## 4. Find destination IDs and preview

On the destination panel host:

```bash
sudo php dns-import.php \
  --list-targets
```

Choose an active DNS server whose `mirror_server_id` is `0`, and an existing destination client. **Source client/server IDs are informational only.** Independently installed systems may assign identical numbers to unrelated objects.

Preview all zones in the payload:

```bash
sudo php dns-import.php \
  --input=dns-zones.json \
  --server-id=2 \
  --client-id=7
```

The plan labels zones `CREATE`, `MERGE`, `UNCHANGED`, or `SKIP`, and shows record changes and destination assignments. **Preview is the default**: no DNS rows or datalog events are written. It checks selection, schema, ownership and certain merge conflicts; it does not run the DNS daemon's zone validator. `--dry-run` makes preview explicit. `--input=-` reads the backup from standard input.

## 5. Apply the reviewed import

Repeat the same command with `--apply`:

```bash
sudo php dns-import.php \
  --input=dns-zones.json \
  --server-id=2 \
  --client-id=7 \
  --apply
```

To restore only a subset, add `--zones=example.com` or repeated `--zone` options to both preview and apply commands. **Omitting the import filter selects all zones in the payload**; the importer has no `--all` switch. `--apply` and `--dry-run` cannot be combined.

Use `--client-id=0` for administrator ownership. Existing clients and DNS servers are reused; the scripts do not create or copy client accounts, users, servers, or mirror relationships.

For an ISPConfig mirror cluster, import **once**, targeting the parent server. Let the destination installation replicate its normal datalog events. Do not independently import the same zone on each mirror.

### Different owners or servers per zone

Create `destination-map.json`:

```json
{
  "example.com": {"client_id": 7},
  "example.net": {"client_id": 9, "server_id": 3}
}
```

Add `--map=destination-map.json` to the import command. Mapping values override the command-line defaults **for that origin**. With `--server-id=2 --client-id=0`, the example gives `example.com` to client 7 on server 2, and `example.net` to client 9 on server 3. Other selected origins use administrator ownership on server 2. Complete per-zone mappings can replace both defaults. Mapping origins must exist in the backup; entries for backup zones outside the current selection are allowed.

### Existing zones: merge without creating duplicates

An absent origin is created. An existing origin keeps its zone ID and is merged if it has the same primary/secondary kind and is already on the requested destination server. Multiple existing definitions, kind conflicts, or a different server cause an error. The importer does not automatically move or convert zones. `--skip-existing` opts out of merging and leaves the whole origin untouched if any definition exists anywhere in the destination database.

Record identity consists of the canonical owner name, record type, value, and relevant priority. Equivalent IP/name spellings are recognized; TXT values remain case- and whitespace-sensitive.

| Backup versus destination | Result |
| --- | --- |
| Same identity | Reuse a matching row ID; update TTL, active state and ownership metadata when needed. |
| Different value or relevant priority | Add the backup record and retain the existing value. |
| Destination-only record | Keep its DNS data, TTL and active state. |
| Identical backup repeated after success | Report `UNCHANGED`; no new records, serial bump or datalog writes. |

For example, if `www A 192.0.2.10` exists and the backup contains `www A 192.0.2.20`, **both addresses remain**. This is an additive merge: existing DNS records are never deleted. Changing the assigned client also updates ownership of retained destination-only records.

Duplicate identities in the backup collapse when TTL and active state agree; conflicting versions are rejected. Preexisting duplicate destination rows are retained and reported. The importer chooses one best match for TTL/active-state updates, leaving other copies' TTL and active state untouched; ownership reassignment still reaches every copy. It does not activate an inactive duplicate when a suitable active match exists.

Conservative checks reject conflicting active CNAME/ALIAS combinations and multiple DNAME targets. Because the importer cannot reliably identify the authoritative backend, it treats ALIAS as CNAME for these checks, matching BIND's behavior. **Apex ALIAS imports/merges are refused.** Resolve conflicts before applying.

A merge advances the SOA serial once when DNS content/settings change, without restoring an older backup serial over a newer destination serial. Ownership-only changes keep the serial. New zones retain the source serial.

### Interrupted imports and recovery

Writes use native autocommit and are **not one transaction**. New zones are created inactive, populated, then given their intended active state. Existing zones are not deliberately disabled during record merging; their final active state follows the backup. A failure stops further work and keeps completed changes.

Inspect the reported zone ID and error. A partial new zone is left inactive when possible. Previewing and rerunning can merge the remaining data into it. A rerun also detects record serials ahead of the SOA after an interrupted merge and finalizes the SOA advancement. **Do not delete a preexisting zone just to retry a merge**, and do not use `--skip-existing` to complete a partial import.

After a database or datalog-write failure, a row may have committed without its event. A rerun can recognize that row as already matching and therefore may not requeue it. After resolving the failure, inspect the database, job queue and authoritative answers; **Tools → Resync → DNS zones** may be needed to regenerate and replicate the committed configuration. Keep delegation unchanged until verification succeeds.

## 6. What the backup includes

Primary zones include SOA settings and every stored resource record, including inactive records, TTL zero, priorities, long TXT data, and DS records delegating signed child zones. TXT case, whitespace, quotes, and escapes are preserved. Secondary zones include their ISPConfig definitions; their transferred zone contents are not stored in these tables and are not exported.

New rows receive destination IDs and insertion timestamps; reused rows keep their IDs. ISPConfig regenerates rendered zone data. The scripts do not automatically translate IP addresses, SOA nameservers, NS targets, transfer ACLs, update ACLs, or notification addresses: source settings are imported as provided. TSIG secrets and global DNS-server configuration are outside this backup. Review references to the old infrastructure before cutover.

**DNSSEC private signing keys are not in this JSON.** The exporter warns when DNSSEC data is present. The importer refuses DNSSEC-enabled primary zones in either the source or destination, and rejects generated DNSSEC records in the backup. Handle signing-key migration and parent DS changes separately. Ordinary child-delegation DS records remain supported. Secondary definitions do not carry local private signing keys.

## 7. Verify processing, then change delegation

An import success means configuration changes were **queued**. Allow ISPConfig's normal worker cycle to finish, inspect its job queue in Monitor, and check the DNS server's ISPConfig logs for rendering or validation failures. The usual log is:

```bash
tail -n 80 /var/log/ispconfig/ispconfig.log
```

Query the destination directly, substituting its actual authoritative server:

```bash
dig @NEW_DNS_SERVER example.com SOA +norecurse
dig @NEW_DNS_SERVER example.com NS +norecurse
dig @NEW_DNS_SERVER example.com MX +norecurse
dig @NEW_DNS_SERVER example.com TXT +norecurse
dig @NEW_DNS_SERVER www.example.com A +norecurse
dig @NEW_DNS_SERVER www.example.com A +norecurse +tcp
```

Confirm authoritative answers and representative records, including DKIM/SRV/CAA where used. Verify each mirror or secondary. Secondary transfer permissions and the configured primary must work from the new location.

Then make any required registrar delegation, nameserver glue, and parent DS changes. The scripts do none of these. Keep the old authoritative service available during the DNS transition; cached delegations can continue sending queries there.

## Validation and limits

The scripts passed syntax/CLI checks and **86 integration assertions**: 30 core, 21 automatic-configuration/client-filter, 23 merge, and 12 recovery/duplicate checks, plus earlier focused MyISAM, Unicode and mapping regressions. Testing used PHP 8.4.24, MariaDB 11.8.6 and ISPConfig's actual native application/database helper from `3.3dev` snapshot `b0f49b23fa160b414e4e5d045ce7167c11643dc9`. Coverage includes record preservation, ownership, inactive/secondary zones, selection, repeated imports, duplicate handling, serial recovery, preview behavior, queued events and partial failures. Four-byte Unicode survived database and serialized datalog writes. This is **not validation against your servers or a complete live ISPConfig 3.3.3 deployment**. Schema checks reject incompatible values; generated DNS files and live answers still require verification.

## Source references

- [Official ISPConfig datalog description](https://www.ispconfig.org/blog/ispconfig-3-1-14-released/): the internal configuration-change system.
- [Official 3.3.3 release notes](https://www.ispconfig.org/blog/ispconfig-3-3-3-released/): DNS validation and remote API changes.
- [ISPConfig database helper source, public mirror](https://github.com/deltafactory/ispconfig/blob/develop/interface/lib/classes/db_mysql.inc.php): native datalog insertion/update structure.
- [ISPConfig BIND plugin source, public mirror](https://github.com/deltafactory/ispconfig/blob/develop/server/plugins-available/bind_plugin.inc.php): zone generation and DNSSEC key-file handling.
- [ISPConfig remote API entry source, public mirror](https://github.com/deltafactory/ispconfig/blob/develop/interface/web/remote/index.php): application bootstrap sequence.
- [ISC BIND DNSSEC guide](https://bind9.readthedocs.io/en/stable/dnssec-guide.html): private signing keys and the parent DS relationship.

The scripts are purpose-written. They load the installed ISPConfig implementation at runtime instead of embedding copied framework code.
