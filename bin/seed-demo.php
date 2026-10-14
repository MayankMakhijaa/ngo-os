<?php
declare(strict_types=1);
// Load (or with --purge remove) demonstration data. Refuses to run in production.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;

if (config('app.env') === 'production') {
    fwrite(STDERR, "Refusing to touch demo data while APP_ENV=production.\n");
    exit(1);
}
$db = Database::instance();
$admin = $db->fetch(
    "SELECT u.*, r.slug AS role_slug, r.name AS role_name, r.id AS role_id FROM users u
       JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
      WHERE u.user_type = 'platform' AND r.slug = 'fameweb_super_admin' ORDER BY u.id LIMIT 1"
);
if (!$admin) {
    fwrite(STDERR, "Install the application first (php bin/install.php ...).\n");
    exit(1);
}

if (in_array('--purge', $argv, true)) {
    $ids = array_column($db->fetchAll('SELECT id FROM tenants WHERE is_demo = 1'), 'id');
    $db->transaction(function (Database $db) use ($ids): void {
        foreach ($ids as $tid) {
            $db->execute('UPDATE tenants SET logo_object_id = NULL WHERE id = ?', [$tid]);
            foreach (['subscription_payments', 'subscription_events', 'usage_records', 'storage_objects'] as $t) {
                $db->execute("DELETE FROM $t WHERE tenant_id = ?", [$tid]);
            }
            $db->execute('DELETE FROM subscriptions WHERE tenant_id = ?', [$tid]);
            $db->execute('DELETE ur FROM user_roles ur JOIN users u ON u.id = ur.user_id WHERE u.tenant_id = ?', [$tid]);
            $db->execute('DELETE FROM users WHERE tenant_id = ?', [$tid]);
            $db->execute('DELETE FROM tenants WHERE id = ?', [$tid]);
        }
        $db->execute('DELETE FROM subscription_plans WHERE is_demo = 1 AND id NOT IN (SELECT plan_id FROM subscriptions)');
    });
    \App\Services\AuditLogger::log('system.demo_purged', 'tenant', null, ['tenants' => count($ids)], null, []);
    echo 'Removed ' . count($ids) . " demo NGOs and unused demo plans. Audit history is kept.\n";
    exit(0);
}

$seed = require BASE_PATH . '/database/seeds/demo.php';
$creds = $seed($admin);
\App\Services\AuditLogger::log('system.demo_seeded', 'tenant', null, ['accounts' => count($creds)], null, []);
echo "Demo data loaded. Accounts (passwords shown once):\n";
foreach ($creds as [$email, $pw, $label]) {
    printf("  %-32s %-18s %s\n", $email, $pw, $label);
}
