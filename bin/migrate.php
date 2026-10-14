<?php
declare(strict_types=1);
// Apply pending database migrations and re-sync the permission catalogue.
// Usage: php bin/migrate.php [--status]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../bootstrap/app.php';

use App\Services\AuditLogger;
use App\Services\Migrator;

Migrator::ensureTable();
if (in_array('--status', $argv, true)) {
    echo "Applied:\n  " . implode("\n  ", Migrator::applied() ?: ['(none)']) . "\n";
    echo "Pending:\n  " . implode("\n  ", Migrator::pending() ?: ['(none)']) . "\n";
    exit(0);
}
$done = Migrator::migrate();
\App\Services\RbacSeeder::sync();
if ($done) {
    AuditLogger::log('system.migrated', 'schema', null, ['versions' => $done], null, []);
}
echo $done ? "Applied: " . implode(', ', $done) . "\n" : "Nothing to migrate. Permission catalogue synced.\n";
