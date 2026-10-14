<?php
declare(strict_types=1);
/*
 * DEVELOPMENT / DEMONSTRATION data only. Every record created here is flagged is_demo = 1
 * (plans and NGOs) so it can be told apart from production data and removed with
 * `php bin/seed-demo.php --purge`. bin/seed-demo.php refuses to run when APP_ENV=production.
 * Passwords are random and printed once; nothing is hardcoded.
 */
use App\Core\Database;
use App\Services\PasswordService;
use App\Services\SubscriptionService;
use App\Services\TenantService;
use App\Services\UserService;

return static function (array $platformAdmin): array {
    $db = Database::instance();
    $credentials = [];
    $pw = static fn () => 'Demo-' . bin2hex(random_bytes(5)) . '9';

    $plans = [
        ['Demo Starter', 'Small NGOs getting started', 999, 9990, 14, 3, 200, 100, 0, []],
        ['Demo Growth', 'Growing NGOs with audit and exports', 2499, 24990, 14, 15, 2000, 2048, 500, ['audit_logs', 'data_export']],
        ['Demo Enterprise', 'Large organisations, no limits', 7999, 79990, null, null, null, null, null, ['audit_logs', 'data_export', 'members', 'volunteers', 'interns', 'documents', 'certificates']],
    ];
    $planIds = [];
    foreach ($plans as [$name, $desc, $m, $a, $trial, $users, $members, $storage, $certs, $mods]) {
        $existing = $db->fetchColumn('SELECT id FROM subscription_plans WHERE name = ?', [$name]);
        if ($existing) {
            $planIds[$name] = (int) $existing;
            continue;
        }
        $id = $db->insert('subscription_plans', [
            'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'description' => $desc,
            'monthly_price' => $m, 'annual_price' => $a, 'currency' => 'INR', 'trial_days' => $trial,
            'max_users' => $users, 'max_members' => $members, 'max_storage_mb' => $storage, 'max_certificates' => $certs,
            'status' => 'active', 'is_demo' => 1, 'created_by' => $platformAdmin['id'],
        ]);
        foreach ($mods as $mod) {
            $db->insert('plan_modules', ['plan_id' => $id, 'module_key' => $mod]);
        }
        $planIds[$name] = $id;
    }

    $ngos = [
        ['Asha Seva Foundation', 'asha', 'Demo Growth', 'active'],
        ['Green Earth Trust', 'greenearth', 'Demo Starter', 'trial'],
    ];
    foreach ($ngos as [$name, $slug, $plan, $subStatus]) {
        if ($db->fetchColumn('SELECT id FROM tenants WHERE name = ? AND is_demo = 1', [$name])) {
            continue;
        }
        $res = TenantService::create(
            ['name' => $name, 'address' => '12 MG Road, Pune, Maharashtra 411001', 'contact_name' => 'Demo Contact',
             'email' => "office@$slug.demo.test", 'phone' => '+91 98765 43210', 'website' => "https://$slug.demo.test",
             'registration_number' => 'DEMO/' . strtoupper($slug), 'notes' => 'Demonstration record', 'status' => 'active', 'is_demo' => 1],
            ['plan_id' => $planIds[$plan], 'status' => $subStatus, 'billing_cycle' => 'monthly', 'start_date' => date('Y-m-d')],
            ['name' => "$name Admin", 'email' => "admin@$slug.demo.test"],
            null,
            $platformAdmin
        );
        $tid = $res['tenant_id'];
        // Activate the demo Super Admin directly with a random password.
        $adminId = (int) $db->fetchColumn('SELECT id FROM users WHERE email = ?', ["admin@$slug.demo.test"]);
        $p = $pw();
        PasswordService::setPassword($adminId, $p);
        $credentials[] = ["admin@$slug.demo.test", $p, "$name — NGO Super Admin"];

        $admin = $db->fetch(
            'SELECT u.*, r.id AS role_id, r.slug AS role_slug, r.name AS role_name FROM users u
               JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE u.id = ?',
            [$adminId]
        );
        foreach (['ngo_manager' => 'manager', 'ngo_operations' => 'ops'] as $roleSlug => $prefix) {
            $roleId = (int) $db->fetchColumn('SELECT id FROM roles WHERE tenant_id = ? AND slug = ?', [$tid, $roleSlug]);
            $u = UserService::create($admin, ['name' => ucfirst($prefix) . ' User', 'email' => "$prefix@$slug.demo.test", 'phone' => ''], $roleId);
            $p = $pw();
            PasswordService::setPassword($u['user_id'], $p);
            $credentials[] = ["$prefix@$slug.demo.test", $p, "$name — " . ($roleSlug === 'ngo_manager' ? 'NGO Manager' : 'NGO Operations')];
        }
        if ($subStatus === 'active') {
            $sub = SubscriptionService::current($tid);
            SubscriptionService::recordPayment((int) $sub['id'], [
                'amount' => '2499', 'status' => 'paid', 'method' => 'upi', 'reference' => 'DEMO-UPI-' . $tid,
                'paid_at' => date('Y-m-d'), 'period_start' => date('Y-m-d'), 'period_end' => date('Y-m-d', strtotime('+1 month')),
                'notes' => 'Demo payment', 'extend' => false,
            ], 'demo-payment-' . $tid, $platformAdmin);
        }
    }
    return $credentials;
};
