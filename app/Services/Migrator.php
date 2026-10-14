<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Versioned schema upgrades. Files in database/migrations named NNN_description.sql or .php are
 * applied once, in order, and recorded in schema_migrations. A .php migration returns
 * a callable receiving the Database. Run with `php bin/migrate.php` or from Admin > Settings.
 */
final class Migrator
{
    public static function dir(): string
    {
        return BASE_PATH . '/database/migrations';
    }

    public static function ensureTable(): void
    {
        Database::instance()->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(150) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return string[] all migration versions on disk, sorted */
    public static function available(): array
    {
        $files = array_merge(glob(self::dir() . '/*.sql') ?: [], glob(self::dir() . '/*.php') ?: []);
        $versions = array_map(static fn ($f) => basename($f), $files);
        sort($versions, SORT_STRING);
        return $versions;
    }

    public static function applied(): array
    {
        self::ensureTable();
        return array_column(Database::instance()->fetchAll('SELECT version FROM schema_migrations ORDER BY version'), 'version');
    }

    public static function pending(): array
    {
        return array_values(array_diff(self::available(), self::applied()));
    }

    /** @return string[] versions applied in this run */
    public static function migrate(): array
    {
        $db = Database::instance();
        $done = [];
        foreach (self::pending() as $version) {
            $path = self::dir() . '/' . $version;
            if (str_ends_with($version, '.sql')) {
                foreach (self::splitSql((string) file_get_contents($path)) as $statement) {
                    $db->pdo()->exec($statement);
                }
            } else {
                $fn = require $path;
                $fn($db);
            }
            $db->insert('schema_migrations', ['version' => $version, 'applied_at' => date('Y-m-d H:i:s')]);
            $done[] = $version;
        }
        if ($done) {
            RbacSeeder::sync();
        }
        return $done;
    }

    /** Split a SQL file into statements: drops "--" comment lines, splits on ";" at end of line. */
    public static function splitSql(string $sql): array
    {
        $lines = array_filter(
            preg_split('/\r?\n/', $sql),
            static fn ($l) => !preg_match('/^\s*--/', $l)
        );
        $parts = preg_split('/;\s*(?:\r?\n|$)/', implode("\n", $lines));
        return array_values(array_filter(array_map('trim', $parts), static fn ($s) => $s !== ''));
    }
}
