# Database access

Scope: local setup and authorized production inspection. Reviewed against [database configuration](../../config/database.php), [example environment](../../.env.example), [test configuration](../../phpunit.xml), and the existing local tunnel helper on November 17, 2026. Live connection details and database grants were not verified.

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
