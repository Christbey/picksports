<?php

namespace App\Services\Database;

use Illuminate\Database\Connection;
use Throwable;

class DatabaseServerVersionResolver
{
    /**
     * Resolve the database engine's own version rather than trusting a proxy's
     * connection-handshake version.
     */
    public function resolve(Connection $connection): string
    {
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $this->fallback($connection);
        }

        foreach (['select version() as server_version', 'select @@version as server_version'] as $query) {
            try {
                $row = $connection->selectOne($query);
                $version = $this->versionFromRow($row);

                if ($version !== null) {
                    return $version;
                }
            } catch (Throwable) {
                // A restricted database user may not be allowed to read one
                // of the server variables. The PDO handshake remains a safe
                // final fallback for diagnostics.
            }
        }

        return $this->fallback($connection);
    }

    private function fallback(Connection $connection): string
    {
        try {
            $version = trim((string) $connection->getServerVersion());

            return $version !== '' ? $version : 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }

    private function versionFromRow(mixed $row): ?string
    {
        if (! is_array($row) && ! is_object($row)) {
            return null;
        }

        $values = array_change_key_case((array) $row, CASE_LOWER);
        $version = trim((string) ($values['server_version'] ?? ''));

        return $version !== '' ? $version : null;
    }
}
