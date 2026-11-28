# Database access

Scope: local setup and authorized production inspection. Reviewed against [database configuration](../../config/database.php), [example environment](../../.env.example), [test configuration](../../phpunit.xml), and the existing local tunnel helper on November 17, 2026. Live connection details and database grants were not verified.

Later scoped native and read-only production observations are recorded in the connection register (private verification record retained separately) and database release assessment (private verification record retained separately). They do not replace the target, transport and privilege checks below.

## Choose the right database

| Purpose | Connection |
| --- | --- |
| Local application | Developer-owned SQLite or MySQL database from local `.env` |
| Automated PHP tests | SQLite `:memory:` as configured in `phpunit.xml` |
| Production facts | Authenticated API first; approved read-only production DB access when needed |
| NBA snapshot work | Optional `nba_snapshot` connection; a local snapshot is not current production |

Laravel defaults to SQLite, and `.env.example` selects SQLite. Production-oriented architecture targets MySQL 8.4. Historical names such as `psa`, `psa2`, and `picksports` occur in migration notes; obtain the actual database name from the active environment rather than selecting one by memory.

## Local configuration

For SQLite, create `database/database.sqlite` if absent and use `DB_CONNECTION=sqlite`. Leave `DB_DATABASE` unset to use that file, or set an absolute local file path.

For a developer-owned MySQL database, configure these keys in local `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<your-local-database>
DB_USERNAME=<your-local-user>
DB_PASSWORD=<your-local-password>
```

`DB_URL` can override individual connection fields. `DB_SOCKET` selects a Unix socket. Check both when a connection goes somewhere unexpected. `MYSQL_ATTR_SSL_CA` configures the MySQL CA certificate when required by the server. Do not disable required TLS to get a connection working.

After changing local connection settings, clear **local** cached configuration with `php artisan config:clear`. Once the target is confirmed local, use `php artisan migrate`. Restoring a production dump into a local database requires an approved, private handling process for customer data; dump/import helpers are not connection checks.

For an authorized export with `scripts/export-laravel-cloud-dump.php`, choose a private output directory. The helper creates new directories with mode `0700` and the dump with mode `0600`, refuses existing directories accessible to other users, and never overwrites an existing output file. It does not change permissions on an existing shared directory.

Native import/export share `MySqlCliConnection`: it resolves `DB_URL` using Laravel's URL parser, preserves an explicit Unix socket, and translates configured MySQL TLS CA, CA directory, client certificate/key and cipher options. TCP connections require `VERIFY_IDENTITY`; there is no fallback to weaker TLS. These helpers require Oracle MySQL clients supporting `--no-defaults`, `--no-login-paths` and `--ssl-mode`. Ambient client option/login files cannot redirect the connection. Passwords travel only in the restricted child process environment, never command arguments or helper diagnostics. Unsupported PDO/transport settings, disabled certificate verification and ambiguous `localhost` without an explicit socket are refused before import queries or DDL. Configure an explicit socket or TCP address instead. Socket transport with configured PDO TLS options is refused rather than silently discarding them.

This source contract and synthetic process tests do not establish a production certificate handshake or read-only grants. Verify actual server identity, client compatibility, TLS and privileges before an authorized export/import. Import executes trusted SQL with the configured user's grants; it is not a sandbox for an untrusted dump and must target a disposable or specifically authorized schema.

## Automated test target guard

The shared PHP test case refuses cached configuration before evaluating its PHP file, then validates the resolved default database after environment/configuration loading and before service providers and `RefreshDatabase`. Normal tests require `APP_ENV=testing` and SQLite `:memory:`. An inherited `DB_URL` is resolved before validation and cannot silently redirect that safe-looking default to a remote database. Clear local cached configuration before running tests; the guard does not delete it.

Disposable MySQL tests require the exact schema opt-in `PICKSPORTS_TEST_MYSQL_DATABASE`, a schema matching `picksports_test_*`, and user `picksports_test`. TCP hosts must be `127.0.0.1` or `::1`. For a socket-only disposable server, set both `DB_SOCKET` and `PICKSPORTS_TEST_MYSQL_SOCKET` to the same absolute socket path beneath a private, current-user-owned directory in the system temporary directory or `/tmp`. The guard rejects missing/mismatched opt-ins, remote hosts, privileged users and read/write target configurations. It checks the initial configured default; deliberate synthetic configuration changes within a test remain available. Never set these opt-ins to repurpose a production database.

## Production through an SSH tunnel

Obtain the verified SSH host, database endpoint as reachable from that host, database name, TLS requirements, and approved read-only user from the active infrastructure account. Do not assume that the application database user is read-only.

Open a foreground tunnel in a dedicated terminal:

```bash
PROD_SSH_HOST='forge@<verified-production-host>'
PROD_DB_HOST='<database-host-from-ssh-server>'
PROD_DB_PORT='3306'
PROD_LOCAL_PORT='3310'

ssh -N -o ExitOnForwardFailure=yes \
  -L "127.0.0.1:${PROD_LOCAL_PORT}:${PROD_DB_HOST}:${PROD_DB_PORT}" \
  "$PROD_SSH_HOST"
```

Replace the placeholders inside the quoted assignments first. For MySQL on the same server, the database endpoint is typically `127.0.0.1`; verify it. Use the configured remote database port if it differs. Keep the terminal open and press Ctrl-C when finished.

An existing `scripts/prod-db-tunnel.sh` may be present locally. It is intentionally ignored by Git and is not available in a fresh clone. Inspect its host before use: it starts a background tunnel and treats an occupied port as an existing listener without proving that listener reaches the intended server. Prefer the explicit foreground command above when establishing a new session.

In a second terminal, use a MySQL client with password prompting:

```bash
PROD_DB_NAME='<verified-database-name>'
PROD_DB_USER='<approved-read-only-user>'

mysql --protocol=TCP --host=127.0.0.1 --port=3310 \
  --user="$PROD_DB_USER" --password --database="$PROD_DB_NAME"
```

Use the same local port selected in the tunnel terminal. Add the server's required TLS options and trusted CA. If certificate hostname verification fails because the client connects to `127.0.0.1`, use the infrastructure-approved tunnel/client configuration that preserves the certificate hostname; do not turn verification off. For a GUI client, use the same host, port, database, user, and TLS settings; store the password in the client's secure credential store. Do not save credentials in a tracked connection file.

Verify identity and grants before querying application records:

```sql
SELECT DATABASE() AS database_name, @@hostname AS server_name,
       @@port AS server_port, VERSION() AS server_version,
       CURRENT_USER() AS authenticated_account;
SHOW GRANTS FOR CURRENT_USER;
START TRANSACTION READ ONLY;
SELECT 1 AS connection_ok;
ROLLBACK;
```

This smoke check closes its read-only transaction immediately. For further inspection, start a new read-only transaction, run the bounded SELECT queries, and roll it back promptly when finished. The transaction is an extra session safeguard, not a replacement for read-only grants. Keep analysis queries bounded, select only necessary columns, and avoid exporting personal data. Do not point the normal local application or test runner at production: model hooks, workers, and tests can write even when the intended task is inspection.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| SSH permission denied | Authorized SSH account, loaded identity, server access, and host verification |
| Local port 3310 occupied | Inspect `lsof -nP -iTCP:3310 -sTCP:LISTEN`; verify the listener or choose another local port |
| Tunnel connects but MySQL refuses | Database service/endpoint from the SSH host and remote port |
| MySQL access denied | Database username, password, host grants, and TLS requirements |
| Unknown database | Active environment's exact schema name; do not create a replacement schema |
| Laravel uses an old connection | Local config cache, exported environment variables, `DB_URL`, and `DB_SOCKET` |
| Data differs from the API | Confirm server/schema, snapshot age, season filters, and API presentation semantics |

Close the MySQL client with `exit` and stop the foreground SSH tunnel with Ctrl-C. A fresh connection failure is an access/configuration issue, not evidence that production has no records.

## Legacy SQLite import command

`db:import-from-sqlite` and `db:import-sql` are retired. Their names/signatures remain discoverable, but both return failure before reading an input file, resolving a database, changing session settings or starting a process, in every environment. Repository caller searches found no active application, scheduler or deployment use; this does not establish the absence of external operator scripts. The former SQLite importer allowed partial writes with foreign-key checks disabled; the former SQL importer exposed a shell-password argument and bypassed the reviewed native transport contract. Neither is a production recovery procedure. For an authorized trusted MySQL import into a new schema, use `scripts/import-mysql-dump.php` under the transport/grants checks above. SQLite source migration needs a separately reviewed source/schema workflow rather than restoring the retired writer.

### Retired manual NFL import entry points

`nfl:import-csv-data`, `nfl:import-games-from-json`, `nfl:import-line-scores` and `nfl:import-player-data-json` are retired in every environment. Their original signatures/options remain discoverable and return failure before file or database access. Repository-wide caller searches found no application, scheduler, documentation or test caller before retirement; external operator scripts remain unknown. The CSV writer could truncate tables before discovering empty input and bypass related evidence; JSON writers could erase missing score/line-score facts and bypass canonical event/finalization workflows.

For an approved trusted database recovery, use the reviewed native new-schema restore procedure above. For explicitly authorized acquisition, the supported entry points are `espn:sync-nfl-games` and `espn:sync-nfl-game-details`; invoking either can dispatch provider work and is not a read-only substitute or an automatic recovery action. No provider refresh is performed by the retired commands. A future manual dataset migration requires a separately reviewed identity/provenance, staged validation, atomic application and grading-reconciliation workflow.
