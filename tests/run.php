<?php
declare(strict_types=1);

/*
 * Automated test suite (no PHPUnit / Composer needed).
 *
 *   cp .env.testing.example .env.testing   # point it at an EMPTY database whose name ends in _test
 *   php tests/run.php
 *
 * What it does:
 *   1. Wipes the test database and a private temporary storage folder, then installs the app.
 *   2. Starts PHP's built-in web server on a free local port against that database.
 *   3. Exercises the app over real HTTP (sessions, cookies, CSRF, redirects) plus a few
 *      service-level checks (transactions, idempotency, audit chain).
 *
 * The suite refuses to run against a database whose name does not end in "_test".
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$envFile = getenv('APP_ENV_FILE') ?: $root . '/.env.testing';
if (!is_file($envFile)) {
    fwrite(STDERR, "Missing $envFile. Copy .env.testing.example to .env.testing and set the test database credentials.\n");
    exit(2);
}
$storage = $root . '/tests/.tmp/storage';
putenv('APP_ENV_FILE=' . $envFile);
putenv('STORAGE_PATH=' . $storage);
putenv('MAIL_DRIVER=log');
// A throwaway encryption key so documents, SMTP passwords and email bodies are encrypted in tests.
$appKey = 'base64:' . base64_encode(random_bytes(32));
putenv('APP_KEY=' . $appKey);

// ---------------------------------------------------------------- fresh storage + database
function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}
rrmdir($storage);
foreach (['', '/logs', '/sessions', '/private', '/cache'] as $d) {
    mkdir($storage . $d, 0770, true);
}

require $root . '/bootstrap/app.php';

use App\Core\Database;
use App\Exceptions\DomainException;
use App\Services\AuditLogger;
use App\Services\Installer;
use App\Services\PasswordService;
use App\Services\PermissionService;
use App\Services\SubscriptionService;
use App\Services\TenantService;
use App\Services\UsageService;

$dbName = (string) config('database.database', env('DB_DATABASE'));
if (!str_ends_with($dbName, '_test')) {
    fwrite(STDERR, "Refusing to run: DB_DATABASE \"$dbName\" does not end in _test. The suite wipes the database it uses.\n");
    exit(2);
}
$db = Database::instance();
$db->execute('SET FOREIGN_KEY_CHECKS = 0');
foreach ($db->fetchAll('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"') as $t) {
    $db->execute('DROP TABLE `' . str_replace('`', '', array_values($t)[0]) . '`');
}
$db->execute('SET FOREIGN_KEY_CHECKS = 1');

// ---------------------------------------------------------------- tiny test harness
$results = ['pass' => 0, 'fail' => 0, 'failures' => []];
function check(string $name, bool $ok, string $detail = ''): void
{
    global $results;
    if ($ok) {
        $results['pass']++;
        echo "  \033[32mPASS\033[0m $name\n";
    } else {
        $results['fail']++;
        $results['failures'][] = $name . ($detail !== '' ? " ($detail)" : '');
        echo "  \033[31mFAIL\033[0m $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}
function section(string $title): void { echo "\n$title\n"; }

/** A cookie-keeping HTTP client, one per simulated browser. */
final class Client
{
    private string $jar;
    public int $status = 0;
    public string $body = '';
    public string $location = '';

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'ngo-jar');
    }

    public function get(string $path): self { return $this->send('GET', $path); }

    public function post(string $path, array $fields = [], bool $withCsrf = true, ?string $csrfFrom = null): self
    {
        if ($withCsrf && !array_key_exists('_csrf', $fields)) {
            $fields['_csrf'] = $this->csrf($csrfFrom);
        }
        return $this->send('POST', $path, $fields);
    }

    /** GET a page, then follow redirects within the app. */
    public function visit(string $path): self
    {
        $this->get($path);
        for ($i = 0; $i < 5 && in_array($this->status, [301, 302, 303], true); $i++) {
            $this->get(parse_url($this->location, PHP_URL_PATH) . (($q = parse_url($this->location, PHP_URL_QUERY)) ? "?$q" : ''));
        }
        return $this;
    }

    /** Follow the redirect of the last response. */
    public function follow(): self
    {
        $loc = $this->location;
        return $loc === '' ? $this : $this->visit(parse_url($loc, PHP_URL_PATH) . (($q = parse_url($loc, PHP_URL_QUERY)) ? "?$q" : ''));
    }

    public function csrf(?string $page = null): string
    {
        $this->get($page ?? '/login');
        if (in_array($this->status, [301, 302, 303], true)) {
            $this->visit($page ?? '/login');
        }
        if (!preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $this->body, $m)) {
            throw new RuntimeException("No CSRF token on $page (HTTP {$this->status})");
        }
        return $m[1];
    }

    public function sessionId(): ?string
    {
        foreach (file($this->jar) ?: [] as $line) {
            $parts = explode("\t", trim($line));
            if (count($parts) === 7 && $parts[5] === config('security.session.name')) {
                return urldecode($parts[6]);
            }
        }
        return null;
    }

    public function login(string $email, string $password): self
    {
        return $this->post('/login', ['email' => $email, 'password' => $password]);
    }

    public function logout(): self
    {
        return $this->post('/logout', [], true, '/account');
    }

    private function send(string $method, string $path, array $fields = []): self
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Accept: text/html'],
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
        $raw = (string) curl_exec($ch);
        $this->status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $headers = substr($raw, 0, $headerSize);
        $this->body = substr($raw, $headerSize);
        $this->location = preg_match('/^Location:\s*(\S+)/mi', $headers, $m) ? $m[1] : '';
        return $this;
    }

    public function redirectsTo(string $path): bool
    {
        return in_array($this->status, [302, 303], true) && parse_url($this->location, PHP_URL_PATH) === $path;
    }

    public function has(string $text): bool
    {
        return str_contains(html_entity_decode($this->body, ENT_QUOTES), $text);
    }
}

// ---------------------------------------------------------------- install + web server
$adminPass = 'Adm1n-' . bin2hex(random_bytes(6));
$adminId = Installer::install(['name' => 'Test Fameweb Admin', 'email' => 'admin@fameweb.test', 'password' => $adminPass]);
$admin = $db->fetch('SELECT * FROM users WHERE id = ?', [$adminId]);

$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr((string) stream_socket_get_name($sock, false), strrpos((string) stream_socket_get_name($sock, false), ':') + 1);
fclose($sock);
$base = "http://127.0.0.1:$port";
$cmd = sprintf('APP_ENV_FILE=%s STORAGE_PATH=%s MAIL_DRIVER=log APP_KEY=%s exec %s -S 127.0.0.1:%d -t %s %s',
    escapeshellarg($envFile), escapeshellarg($storage), escapeshellarg($appKey), escapeshellarg(PHP_BINARY), $port,
    escapeshellarg($root . '/public'), escapeshellarg($root . '/public/index.php'));
$server = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $storage . '/logs/server.log', 'a'], 2 => ['file', $storage . '/logs/server.log', 'a']], $pipes);
register_shutdown_function(static function () use ($server) { proc_terminate($server); });
for ($i = 0; $i < 50; $i++) {
    if (@fsockopen('127.0.0.1', $port)) break;
    usleep(100000);
}

// ---------------------------------------------------------------- fixtures
$planId = $db->insert('subscription_plans', ['name' => 'Test Basic', 'slug' => 'test-basic', 'monthly_price' => 500, 'annual_price' => 5000,
    'max_users' => 3, 'max_members' => 100, 'max_storage_mb' => 5, 'status' => 'active', 'created_by' => $adminId]);
$db->insert('plan_modules', ['plan_id' => $planId, 'module_key' => 'audit_logs']);
$inactivePlan = $db->insert('subscription_plans', ['name' => 'Retired', 'slug' => 'retired', 'max_users' => 10, 'status' => 'inactive']);

$profile = static fn (string $name, string $email) => ['name' => $name, 'address' => '1 Test Road, Delhi', 'contact_name' => 'Contact',
    'email' => $email, 'phone' => '+91 98100 00000', 'website' => '', 'registration_number' => '', 'notes' => ''];
$a = TenantService::create($profile('NGO Alpha', 'office@alpha.test'), ['plan_id' => $planId, 'status' => 'active', 'billing_cycle' => 'monthly'],
    ['name' => 'Alpha Admin', 'email' => 'admin@alpha.test'], null, $admin);
$b = TenantService::create($profile('NGO Beta', 'office@beta.test'), ['plan_id' => $planId, 'status' => 'active', 'billing_cycle' => 'monthly'],
    ['name' => 'Beta Admin', 'email' => 'admin@beta.test'], null, $admin);
$tA = (int) $a['tenant_id'];
$tB = (int) $b['tenant_id'];
$pw = 'Str0ng-pass-' . bin2hex(random_bytes(4));
$userIdByEmail = static fn (string $e) => (int) $db->fetchColumn('SELECT id FROM users WHERE email = ?', [$e]);
$roleId = static fn (int $t, string $slug) => (int) $db->fetchColumn('SELECT id FROM roles WHERE tenant_id = ? AND slug = ?', [$t, $slug]);
$userStatus = static fn (int $id) => (string) $db->fetchColumn('SELECT status FROM users WHERE id = ?', [$id]);
PasswordService::setPassword($userIdByEmail('admin@beta.test'), $pw);

// ================================================================= 1. Installation
section('Installation');
$anon = new Client($base);
check('Installer returns 404 once installed', $anon->get('/install')->status === 404);
check('Second install is refused', (function () { try { Installer::install(['name' => 'x', 'email' => 'x@x.test', 'password' => 'whatever123']); return false; } catch (DomainException) { return true; } })());
check('Install token is never written to the audit log', !$db->fetchColumn("SELECT COUNT(*) FROM audit_logs WHERE metadata LIKE '%INSTALL_TOKEN%'"));

// ================================================================= 2. Authentication
section('Authentication');
$c = new Client($base);
check('Anonymous /admin redirects to login', $c->get('/admin')->redirectsTo('/login'));
check('Anonymous /ngo redirects to login', $c->get('/ngo')->redirectsTo('/login'));
$c->login('admin@fameweb.test', 'wrong-password-1');
check('Wrong password is rejected', $c->redirectsTo('/login') && $c->visit('/login')->has('The email or password is incorrect.'));
$before = $c->sessionId();
$c->login('admin@fameweb.test', $adminPass);
check('Fameweb admin signs in and lands on /admin', $c->redirectsTo('/admin'));
check('Session id is regenerated at sign-in', $before !== null && $c->sessionId() !== null && $before !== $c->sessionId());
check('Admin dashboard loads', $c->get('/admin')->status === 200);
check('No password hash or secret in admin HTML', !str_contains($c->body, '$2y$') && !str_contains($c->body, (string) env('DB_PASSWORD', '__none__')));

// Invitation link: single use, and sets the user's own password.
$alphaInvite = parse_url($a['invite_link'], PHP_URL_PATH);
$inv = new Client($base);
check('Invitation page opens', $inv->get($alphaInvite)->status === 200);
$inv->post($alphaInvite, ['password' => 'short', 'password_confirmation' => 'short'], true, $alphaInvite);
check('Weak password rejected on invitation', $userStatus($userIdByEmail('admin@alpha.test')) === 'invited');
$inv->post($alphaInvite, ['password' => $pw, 'password_confirmation' => $pw], true, $alphaInvite);
check('Invitation accepted and account activated', $userStatus($userIdByEmail('admin@alpha.test')) === 'active');
$inv2 = new Client($base);
$inv2->get($alphaInvite);
check('Invitation link cannot be used twice', $inv2->status !== 200 || !str_contains($inv2->body, 'name="password"'));
check('Invite token stored only as a hash', !$db->fetchColumn('SELECT COUNT(*) FROM password_reset_tokens WHERE token_hash = ?', [basename($alphaInvite)]));
check('No plaintext password in the mail log', !str_contains((string) @file_get_contents($storage . '/logs/mail.log'), $pw));

$alpha = new Client($base);
$alpha->login('admin@alpha.test', $pw);
check('NGO Super Admin lands on /ngo', $alpha->redirectsTo('/ngo'));
check('NGO dashboard loads', $alpha->get('/ngo')->status === 200);

// Lockout: 5 failures lock the email, even the correct password is then refused.
$lock = new Client($base);
for ($i = 0; $i < 5; $i++) {
    $lock->login('admin@beta.test', 'nope-nope-' . $i);
}
$lock->login('admin@beta.test', $pw);
check('Account locked after repeated failures', $lock->redirectsTo('/login') && $lock->visit('/login')->has('Too many failed sign-in attempts'));
check('Lockout is audited', (bool) $db->fetchColumn("SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.login_locked'"));
check('Failed-login audit rows never contain the attempted password', !$db->fetchColumn("SELECT COUNT(*) FROM audit_logs WHERE metadata LIKE '%nope-nope%'"));
$db->execute('DELETE FROM login_attempts'); // clear for the rest of the suite

// Password reset: same answer for known and unknown emails (no account enumeration).
$r1 = new Client($base);
$r1->post('/forgot-password', ['email' => 'nobody@nowhere.test'], true, '/forgot-password');
$msgUnknown = $r1->follow()->body;
$r2 = new Client($base);
$r2->post('/forgot-password', ['email' => 'admin@alpha.test'], true, '/forgot-password');
$msgKnown = $r2->follow()->body;
$flash = static fn (string $html) => preg_match('/<div class="flex-grow-1">([^<]*)<\/div>/', $html, $m) ? $m[1] : '';
check('Password reset does not reveal whether an email exists', $flash($msgUnknown) !== '' && $flash($msgUnknown) === $flash($msgKnown));

// ================================================================= 3. Authorization boundaries
section('Authorization boundaries');
check('NGO user gets 403 on /admin', $alpha->get('/admin')->status === 403);
check('NGO user gets 403 on /admin/ngos/{id}', $alpha->get('/admin/ngos/' . $tA)->status === 403);
check('NGO user cannot POST to admin endpoints', $alpha->post('/admin/ngos/' . $tB . '/status', ['status' => 'suspended', 'reason' => 'x'], true, '/ngo')->status === 403
    && $db->fetchColumn('SELECT status FROM tenants WHERE id = ?', [$tB]) === 'active');
check('Fameweb admin gets 403 inside the NGO portal', $c->get('/ngo')->status === 403);
$alpha->post('/account/profile', ['name' => 'Alpha Admin', 'email' => 'admin@alpha.test', 'phone' => '', 'user_type' => 'platform', 'tenant_id' => '', 'role' => 'fameweb_super_admin'], true, '/account');
$row = $db->fetch('SELECT user_type, tenant_id FROM users WHERE email = ?', ['admin@alpha.test']);
check('Profile form cannot change user_type or tenant', $row['user_type'] === 'tenant' && (int) $row['tenant_id'] === $tA);
check('Still blocked from /admin after tampering attempt', $alpha->get('/admin')->status === 403);

// ================================================================= 4. CSRF + validation
section('CSRF and validation');
check('POST without CSRF token returns 419', $alpha->post('/ngo/users', ['name' => 'X', 'email' => 'x@alpha.test', 'role_id' => $roleId($tA, 'ngo_operations')], false)->status === 419);
check('POST with a wrong CSRF token returns 419', $alpha->post('/ngo/users', ['_csrf' => str_repeat('a', 64), 'name' => 'X', 'email' => 'x@alpha.test', 'role_id' => $roleId($tA, 'ngo_operations')])->status === 419);
check('Login without CSRF token returns 419', (new Client($base))->post('/login', ['email' => 'a@b.c', 'password' => 'x'], false)->status === 419);
check('No user created by rejected requests', !$userIdByEmail('x@alpha.test'));
$alpha->post('/ngo/users', ['name' => '', 'email' => 'not-an-email', 'role_id' => $roleId($tA, 'ngo_operations')], true, '/ngo/users/create');
check('Invalid user form is rejected with field errors', $alpha->redirectsTo('/ngo/users/create') && $alpha->visit('/ngo/users/create')->has('is-invalid'));
check('Invalid user form creates nothing', (int) $db->fetchColumn('SELECT COUNT(*) FROM users WHERE tenant_id = ?', [$tA]) === 1);

// ================================================================= 5. Tenant isolation
section('Tenant isolation (NGO Alpha vs NGO Beta)');
$betaAdminId = $userIdByEmail('admin@beta.test');
$betaRole = $roleId($tB, 'ngo_manager');
// A Beta file to try to read from Alpha.
$png = $storage . '/fixture.png';
$img = imagecreatetruecolor(4, 4);
imagepng($img, $png);
TenantService::replaceLogo($tB, ['name' => 'logo.png', 'tmp_name' => $png, 'error' => UPLOAD_ERR_OK, 'size' => filesize($png)], $admin);
$betaFile = (string) $db->fetchColumn('SELECT uuid FROM storage_objects WHERE tenant_id = ? AND deleted_at IS NULL', [$tB]);

check("Alpha cannot view Beta's user (404)", $alpha->get('/ngo/users/' . $betaAdminId)->status === 404);
check("Alpha cannot open Beta's user edit form (404)", $alpha->get('/ngo/users/' . $betaAdminId . '/edit')->status === 404);
check("Alpha cannot view Beta's role (404)", $alpha->get('/ngo/roles/' . $betaRole)->status === 404);
check("Alpha cannot download Beta's file (404)", $betaFile !== '' && $alpha->get('/files/' . $betaFile)->status === 404);
$alpha->post('/ngo/users/' . $betaAdminId . '/status', ['status' => 'inactive'], true, '/ngo/users');
check("Alpha cannot deactivate Beta's user", $alpha->status === 404 && $userStatus($betaAdminId) === 'active');
$alpha->post('/ngo/users/' . $betaAdminId, ['name' => 'Hacked', 'email' => 'admin@beta.test', 'phone' => ''], true, '/ngo/users');
check("Alpha cannot edit Beta's user", $alpha->status === 404 && $db->fetchColumn('SELECT name FROM users WHERE id = ?', [$betaAdminId]) === 'Beta Admin');
$alpha->post('/ngo/roles/' . $betaRole, ['name' => 'Pwned', 'description' => '', 'permissions' => ['users.view']], true, '/ngo/roles');
check("Alpha cannot edit Beta's role", $alpha->status === 404 && $db->fetchColumn('SELECT name FROM roles WHERE id = ?', [$betaRole]) === 'NGO Manager');
$alpha->post('/ngo/users', ['tenant_id' => $tB, 'name' => 'Ops One', 'email' => 'ops1@alpha.test', 'role_id' => $roleId($tA, 'ngo_operations')], true, '/ngo/users/create');
check('Submitted tenant_id is ignored: user lands in Alpha', (int) $db->fetchColumn('SELECT tenant_id FROM users WHERE email = ?', ['ops1@alpha.test']) === $tA);
$alpha->post('/ngo/users', ['name' => 'Cross Role', 'email' => 'cross@alpha.test', 'role_id' => $betaRole], true, '/ngo/users/create');
check("Alpha cannot assign one of Beta's roles", !$userIdByEmail('cross@alpha.test'));
$alpha->post('/ngo/organization', ['tenant_id' => $tB, 'display_name' => 'Alpha Renamed', 'notification_email' => 'office@alpha.test', 'timezone' => 'Asia/Kolkata', 'date_format' => 'd M Y',
    'name' => 'NGO Alpha', 'address' => '1 Test Road, Delhi', 'contact_name' => 'Contact', 'email' => 'office@alpha.test', 'phone' => '+91 98100 00000'], true, '/ngo/organization');
check("Organization form with Beta's tenant_id leaves Beta untouched", TenantService::settings($tB)['display_name'] === 'NGO Beta');
$alpha->get('/ngo/users?q=Beta');
check("Alpha's user search never lists Beta's users", $alpha->status === 200 && !$alpha->has('admin@beta.test'));
$alpha->get('/ngo/audit');
check("Alpha's audit log never shows Beta's entries", $alpha->status === 200 && !$alpha->has('NGO Beta') && !$alpha->has('admin@beta.test'));

// ================================================================= 6. Roles, permissions, escalation
section('Roles, permissions and escalation');
$alpha->post('/ngo/users', ['name' => 'Maya Manager', 'email' => 'mgr@alpha.test', 'role_id' => $roleId($tA, 'ngo_manager')], true, '/ngo/users/create');
$mgrId = $userIdByEmail('mgr@alpha.test');
PasswordService::setPassword($mgrId, $pw);
$opsId = $userIdByEmail('ops1@alpha.test');
PasswordService::setPassword($opsId, $pw);
$alphaAdminId = $userIdByEmail('admin@alpha.test');

$ops = new Client($base);
$ops->login('ops1@alpha.test', $pw);
check('Operations user signs in', $ops->redirectsTo('/ngo'));
check('Operations cannot list users (403)', $ops->get('/ngo/users')->status === 403);
check('Operations cannot open roles (403)', $ops->get('/ngo/roles')->status === 403);
check('Operations cannot create users via direct POST (403)', $ops->post('/ngo/users', ['name' => 'Z', 'email' => 'z@alpha.test', 'role_id' => $roleId($tA, 'ngo_operations')], true, '/ngo')->status === 403);
check('Operations cannot edit organization via direct POST (403)', $ops->post('/ngo/organization', ['display_name' => 'Ops was here'], true, '/ngo')->status === 403);
check('Denied attempts are audited', (bool) $db->fetchColumn("SELECT COUNT(*) FROM audit_logs WHERE action = 'authz.denied' AND actor_user_id = ?", [$opsId]));

$mgr = new Client($base);
$mgr->login('mgr@alpha.test', $pw);
check('Manager can list users', $mgr->get('/ngo/users')->status === 200);
$mgr->post('/ngo/users', ['name' => 'Sneaky Admin', 'email' => 'sneaky@alpha.test', 'role_id' => $roleId($tA, 'ngo_super_admin')], true, '/ngo/users/create');
check('Manager cannot create an NGO Super Admin', !$userIdByEmail('sneaky@alpha.test'));
$mgr->post('/ngo/users/' . $mgrId . '/role', ['role_id' => $roleId($tA, 'ngo_super_admin')], true, '/ngo/users');
check('Manager cannot promote themselves', $db->fetchColumn('SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$mgrId]) === 'ngo_manager');
$mgr->post('/ngo/users/' . $alphaAdminId . '/role', ['role_id' => $roleId($tA, 'ngo_operations')], true, '/ngo/users');
check('Manager cannot demote the Super Admin', $db->fetchColumn('SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$alphaAdminId]) === 'ngo_super_admin');
check('Manager cannot grant permissions (no users.assign_permissions)', $mgr->post('/ngo/users/' . $mgrId . '/permissions', ['perm' => ['roles.manage' => 'grant']], true, '/ngo/users')->status === 403
    && !$db->fetchColumn('SELECT COUNT(*) FROM user_permissions WHERE user_id = ?', [$mgrId]));
check('Manager cannot edit role permissions (403)', $mgr->post('/ngo/roles/' . $roleId($tA, 'ngo_manager'), ['name' => 'NGO Manager', 'description' => '', 'permissions' => ['roles.manage', 'users.assign_permissions']], true, '/ngo/roles')->status === 403);

// Super Admin: overrides, platform permissions can never be granted to NGO users.
$alpha->post('/ngo/users/' . $opsId . '/permissions', ['perm' => ['users.view' => 'grant', 'tenants.manage' => 'grant', 'platform.dashboard' => 'grant']], true, '/ngo/users/' . $opsId . '/permissions');
PermissionService::instance()->flush();
$opsRow = $db->fetch('SELECT * FROM users WHERE id = ?', [$opsId]);
$eff = PermissionService::instance()->effective($opsRow);
check('Grant override takes effect', in_array('users.view', $eff, true) && $ops->get('/ngo/users')->status === 200);
check('Platform permissions can never be granted to an NGO user', !in_array('tenants.manage', $eff, true) && !in_array('platform.dashboard', $eff, true)
    && !$db->fetchColumn("SELECT COUNT(*) FROM user_permissions up JOIN permissions p ON p.id = up.permission_id WHERE up.user_id = ? AND p.scope = 'platform'", [$opsId]));
check('Granted NGO user still cannot reach /admin', $ops->get('/admin')->status === 403);
$alpha->post('/ngo/users/' . $mgrId . '/permissions', ['perm' => ['users.create' => 'deny']], true, '/ngo/users/' . $mgrId . '/permissions');
check('Deny override removes a role permission', $mgr->get('/ngo/users/create')->status === 403);
$alpha->post('/ngo/roles', ['name' => 'Platform Thief', 'description' => '', 'permissions' => ['users.view', 'tenants.manage', 'platform.settings']], true, '/ngo/roles/create');
$roleError = preg_match('/<div class="flex-grow-1">([^<]*)/', $alpha->follow()->body, $mm) ? html_entity_decode($mm[1]) : '';
$thief = (int) $db->fetchColumn("SELECT id FROM roles WHERE tenant_id = ? AND name = 'Platform Thief'", [$tA]);
check('Role with platform permissions is refused with a reason', $thief === 0 && str_contains($roleError, 'because you do not have that permission'));
$alpha->post('/ngo/roles', ['name' => 'Volunteer Desk', 'description' => 'Front desk', 'permissions' => ['dashboard.view', 'users.view']], true, '/ngo/roles/create');
$desk = (int) $db->fetchColumn("SELECT id FROM roles WHERE tenant_id = ? AND name = 'Volunteer Desk'", [$tA]);
check('Custom role is created with the chosen permissions', $desk > 0 && (int) $db->fetchColumn('SELECT COUNT(*) FROM role_permissions WHERE role_id = ?', [$desk]) === 2);
check('No NGO role anywhere holds a platform permission', !$db->fetchColumn("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.tenant_id IS NOT NULL AND p.scope = 'platform'"));
$alpha->post('/ngo/users/' . $alphaAdminId . '/status', ['status' => 'inactive'], true, '/ngo/users');
check('Last NGO Super Admin cannot deactivate themselves', $userStatus($alphaAdminId) === 'active');
$alpha->post('/ngo/users/' . $alphaAdminId . '/role', ['role_id' => $roleId($tA, 'ngo_manager')], true, '/ngo/users');
check('Last NGO Super Admin cannot be demoted', $db->fetchColumn('SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$alphaAdminId]) === 'ngo_super_admin');
$alpha->post('/ngo/roles/' . $roleId($tA, 'ngo_super_admin'), ['name' => 'Renamed', 'description' => '', 'permissions' => ['users.view']], true, '/ngo/roles');
check('Locked NGO Super Admin role cannot be edited', $db->fetchColumn('SELECT name FROM roles WHERE id = ?', [$roleId($tA, 'ngo_super_admin')]) === 'NGO Super Admin');

// Deactivation ends the user's live session.
$alpha->post('/ngo/users/' . $opsId . '/status', ['status' => 'inactive'], true, '/ngo/users');
check('Deactivated user is signed out on next request', $userStatus($opsId) === 'inactive' && $ops->get('/ngo')->redirectsTo('/login'));
$alpha->post('/ngo/users/' . $opsId . '/status', ['status' => 'active'], true, '/ngo/users');

// ================================================================= 7. Plan limits + read-only mode
section('Subscription limits and read-only mode');
// Alpha: admin + ops + manager = 3 active users, plan allows 3.
check('Usage counts real users', UsageService::used($tA, 'users') === 3);
$alpha->post('/ngo/users', ['name' => 'Fourth', 'email' => 'fourth@alpha.test', 'role_id' => $roleId($tA, 'ngo_operations')], true, '/ngo/users/create');
check('User limit is enforced on the server', !$userIdByEmail('fourth@alpha.test'));
check('Limit message explains what to do', $alpha->visit('/ngo/users/create')->has('ask Fameweb to upgrade your plan'));
check('Usage shows 100% at the limit', (int) UsageService::summary($tA)['users']['percent'] === 100);
// Plan change keeps history.
[$newSub] = SubscriptionService::assign($tA, $planId, ['status' => 'active', 'billing_cycle' => 'annual'], $admin);
check('Plan change creates a new subscription row', (int) $db->fetchColumn('SELECT COUNT(*) FROM subscriptions WHERE tenant_id = ?', [$tA]) === 2);
check('Old subscription kept as history', (int) $db->fetchColumn('SELECT COUNT(*) FROM subscriptions WHERE tenant_id = ? AND is_current = 0 AND ended_at IS NOT NULL', [$tA]) === 1);
check('Only one current subscription', (int) $db->fetchColumn('SELECT COUNT(*) FROM subscriptions WHERE tenant_id = ? AND is_current = 1', [$tA]) === 1);
// Expire Beta and check read-only mode.
$betaSub = (int) $db->fetchColumn('SELECT id FROM subscriptions WHERE tenant_id = ? AND is_current = 1', [$tB]);
SubscriptionService::update($betaSub, 'expired', null, 'Test expiry', $admin);
$beta = new Client($base);
$beta->login('admin@beta.test', $pw);
check('Expired NGO can still sign in and read', $beta->redirectsTo('/ngo') && $beta->get('/ngo/users')->status === 200);
$beta->post('/ngo/users', ['name' => 'Blocked', 'email' => 'blocked@beta.test', 'role_id' => $roleId($tB, 'ngo_operations')], true, '/ngo');
check('Expired NGO cannot make changes (read-only)', $beta->status === 403 && $beta->has('read-only') && !$userIdByEmail('blocked@beta.test'));
check('Expired NGO cannot open the add-user form', $beta->get('/ngo/users/create')->status === 403);
// Lazy expiry: a trial whose end date passed becomes expired on read.
$db->execute("UPDATE subscriptions SET status = 'trial', end_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY), trial_ends_at = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = ?", [$betaSub]);
SubscriptionService::flushCache();
check('Trial past its end date is reported as expired', SubscriptionService::current($tB)['status'] === 'expired');

// ================================================================= 8. Payments are idempotent
section('Payments');
$c->get('/admin/ngos/' . $tA);
$nonce = bin2hex(random_bytes(16));
$pay = ['amount' => '5000', 'status' => 'paid', 'method' => 'upi', 'reference' => 'UPI-1', 'paid_at' => date('Y-m-d'),
    'period_start' => date('Y-m-d'), 'period_end' => date('Y-m-d', strtotime('+1 year')), 'notes' => '', 'nonce' => $nonce, 'extend' => '1'];
$c->post('/admin/subscriptions/' . $newSub . '/payments', $pay, true, '/admin/ngos/' . $tA);
$c->post('/admin/subscriptions/' . $newSub . '/payments', $pay, true, '/admin/ngos/' . $tA);
check('Double-submitted payment is recorded once', (int) $db->fetchColumn('SELECT COUNT(*) FROM subscription_payments WHERE subscription_id = ?', [$newSub]) === 1);
[$id1, $created1] = SubscriptionService::recordPayment($newSub, $pay + ['nonce' => 'x'], 'manual:service-key', $admin);
[$id2, $created2] = SubscriptionService::recordPayment($newSub, $pay, 'manual:service-key', $admin);
check('Service-level retry returns the same payment', $created1 && !$created2 && $id1 === $id2);
$c->post('/admin/subscriptions/' . $newSub . '/payments', ['amount' => '-5'] + $pay + ['nonce' => bin2hex(random_bytes(16))], true, '/admin/ngos/' . $tA);
check('Negative payment amount is rejected', (int) $db->fetchColumn('SELECT COUNT(*) FROM subscription_payments WHERE subscription_id = ?', [$newSub]) === 2);
check('Usage records are idempotent', UsageService::record($tA, 'emails', 1, 'test:once') && !UsageService::record($tA, 'emails', 1, 'test:once'));

// ================================================================= 9. Transactions
section('Transactions');
$countTenants = (int) $db->fetchColumn('SELECT COUNT(*) FROM tenants');
try {
    TenantService::create($profile('Rollback NGO', 'office@rollback.test'), ['plan_id' => $inactivePlan, 'status' => 'active'], ['name' => 'R', 'email' => 'admin@rollback.test'], null, $admin);
    $threw = false;
} catch (DomainException) {
    $threw = true;
}
check('Registration with an inactive plan fails', $threw);
check('Failed registration leaves no tenant, user or roles behind', (int) $db->fetchColumn('SELECT COUNT(*) FROM tenants') === $countTenants
    && !$userIdByEmail('admin@rollback.test') && !$db->fetchColumn("SELECT COUNT(*) FROM tenants WHERE name = 'Rollback NGO'"));
try {
    $db->transaction(function (Database $db) use ($tA) {
        $db->execute("UPDATE tenants SET name = 'Half done' WHERE id = ?", [$tA]);
        $db->transaction(fn () => throw new RuntimeException('inner failure'));
    });
} catch (RuntimeException) {
}
check('Nested transaction failure rolls back the outer work', $db->fetchColumn('SELECT name FROM tenants WHERE id = ?', [$tA]) === 'NGO Alpha');

// ================================================================= 10. Sessions
section('Sessions');
$sid = $mgr->sessionId();
$file = $storage . '/sessions/sess_' . $sid;
$data = (string) @file_get_contents($file);
file_put_contents($file, preg_replace('/_last_activity\|i:\d+;/', '_last_activity|i:' . (time() - 7200) . ';', $data));
check('Idle session expires', $data !== '' && $mgr->get('/ngo')->redirectsTo('/login'));
$alphaCookieSid = $alpha->sessionId();
$alpha->logout();
check('Logout ends the session', $alpha->get('/ngo')->redirectsTo('/login'));
check('Old session id is destroyed on logout', !is_file($storage . '/sessions/sess_' . $alphaCookieSid) || !str_contains((string) file_get_contents($storage . '/sessions/sess_' . $alphaCookieSid), '_auth'));
$alpha->login('admin@alpha.test', $pw);
TenantService::changeStatus($tA, 'suspended', 'Test suspension', $admin);
check('Suspension signs out active NGO sessions', $alpha->get('/ngo')->redirectsTo('/login'));
$late = new Client($base);
$late->login('admin@alpha.test', $pw);
check('Suspended NGO users cannot sign in', $late->redirectsTo('/login') && $late->visit('/login')->has('suspended'));
check('Suspension keeps the NGO data', (int) $db->fetchColumn('SELECT COUNT(*) FROM users WHERE tenant_id = ?', [$tA]) === 3);
TenantService::changeStatus($tA, 'active', 'Test reactivation', $admin);
check('Reactivated NGO can sign in again', $late->login('admin@alpha.test', $pw)->redirectsTo('/ngo'));

// ================================================================= 11. Audit log
section('Audit log');
check('Audit hash chain verifies', AuditLogger::verify() === null);
AuditLogger::log('test.sensitive', 'test', null, ['password' => 'hunter2hunter2', 'token' => 'abc', 'note' => 'Aadhaar 1234 5678 9012'], $tA, []);
$meta = (string) $db->fetchColumn("SELECT metadata FROM audit_logs WHERE action = 'test.sensitive'");
check('Sensitive keys are redacted in audit metadata', !str_contains($meta, 'hunter2') && !str_contains($meta, '"abc"'));
check('Aadhaar-like numbers are masked', !str_contains($meta, '1234 5678') && str_contains($meta, '9012'));
$victim = (int) $db->fetchColumn('SELECT MIN(id) FROM audit_logs WHERE tenant_id = ?', [$tA]);
$orig = (string) $db->fetchColumn('SELECT action FROM audit_logs WHERE id = ?', [$victim]);
$db->execute("UPDATE audit_logs SET action = 'tampered' WHERE id = ?", [$victim]);
check('Tampering with an audit row is detected', AuditLogger::verify() === $victim);
$db->execute('UPDATE audit_logs SET action = ? WHERE id = ?', [$orig, $victim]);
check('Chain verifies again after restore', AuditLogger::verify() === null);
check('Admin can export the audit log as CSV', ($c->get('/admin/audit/export')->status === 200) && str_contains($c->body, 'Action'));

// ================================================================= 12. Phase 2: members, volunteers, interns
section('Members, volunteers and interns');
foreach (['members', 'volunteers', 'interns', 'documents', 'certificates', 'certificates_80g', 'email_automation', 'data_export'] as $m) {
    $db->insert('plan_modules', ['plan_id' => $planId, 'module_key' => $m]);
}
// Beta was left expired by the subscription tests: renew it so it can make changes again.
$db->execute("UPDATE subscriptions SET status = 'active', end_date = DATE_ADD(CURDATE(), INTERVAL 1 YEAR), trial_ends_at = NULL WHERE id = ?", [$betaSub]);
$litePlan = $db->insert('subscription_plans', ['name' => 'Test Lite', 'slug' => 'test-lite', 'max_users' => 3, 'status' => 'active']);
SubscriptionService::flushCache();
$p2 = new Client($base);
$p2->login('admin@alpha.test', $pw);
$p2b = new Client($base);
$p2b->login('admin@beta.test', $pw);
check('Members list opens and appears in the menu', $p2->get('/ngo/people/members')->status === 200 && $p2->has('Volunteers'));

/** A valid Aadhaar number (Verhoeff check digit) for tests. */
$aadhaarFor = static function (string $first11): string {
    for ($d = 0; $d <= 9; $d++) {
        if (\App\Support\Aadhaar::isValid($first11 . $d)) {
            return $first11 . $d;
        }
    }
    throw new RuntimeException('no check digit');
};
$aadhaar = $aadhaarFor('23456789012');
$aadhaarSpaced = substr($aadhaar, 0, 4) . ' ' . substr($aadhaar, 4, 4) . ' ' . substr($aadhaar, 8, 4);
$badAadhaar = substr($aadhaar, 0, 11) . (((int) substr($aadhaar, -1) + 1) % 10);
$memberForm = ['name' => 'Ravi Kumar', 'status' => 'active', 'gender' => 'male', 'phone' => '+91 98100 11111', 'email' => 'ravi@member.test',
    'joined_on' => date('Y-m-d'), 'aadhaar_number' => $aadhaarSpaced, 'bank_account' => '123456789012', 'bank_ifsc' => 'SBIN0001234', 'monthly_amount' => '12000'];
$p2->post('/ngo/people/members', ['name' => 'Bad Aadhaar', 'status' => 'active', 'aadhaar_number' => $badAadhaar], true, '/ngo/people/members/create');
check('An Aadhaar number with a wrong check digit is rejected', $p2->redirectsTo('/ngo/people/members/create'));
$sessionFiles = implode('', array_map('file_get_contents', glob($storage . '/sessions/sess_*') ?: []));
check('Aadhaar number is not kept in the session after a validation error', !str_contains($sessionFiles, $badAadhaar));
$p2->visit('/ngo/people/members/create');
$p2->post('/ngo/people/members', $memberForm, true, '/ngo/people/members/create');
$m1 = (int) $db->fetchColumn("SELECT id FROM people WHERE tenant_id = ? AND name = 'Ravi Kumar'", [$tA]);
check('A member is registered and gets member ID MEM-00001', $m1 > 0 && $p2->redirectsTo('/ngo/people/members/' . $m1)
    && $db->fetchColumn('SELECT member_code FROM people WHERE id = ?', [$m1]) === 'MEM-00001');
$row = $db->fetch('SELECT * FROM people WHERE id = ?', [$m1]);
check('Only the last 4 Aadhaar and bank digits are stored', $row['aadhaar_last4'] === substr($aadhaar, -4) && $row['bank_account_last4'] === '9012'
    && !str_contains(json_encode($row), substr($aadhaar, 0, 8)));
check('Full Aadhaar number is not in the audit log', !(int) $db->fetchColumn('SELECT COUNT(*) FROM audit_logs WHERE metadata LIKE ? OR metadata LIKE ?', ['%' . $aadhaar . '%', '%' . $aadhaarSpaced . '%']));
check('Profile shows the masked Aadhaar only', $p2->get('/ngo/people/members/' . $m1)->status === 200 && $p2->has('XXXX XXXX ' . substr($aadhaar, -4)) && !$p2->has($aadhaarSpaced));
$p2->post('/ngo/people/members', ['name' => 'Second Member', 'status' => 'pending'], true, '/ngo/people/members/create');
$m2 = (int) $db->fetchColumn("SELECT id FROM people WHERE tenant_id = ? AND name = 'Second Member'", [$tA]);
check('Member IDs are sequential per NGO', $db->fetchColumn('SELECT member_code FROM people WHERE id = ?', [$m2]) === 'MEM-00002');
$p2->post('/ngo/people/volunteers', ['name' => 'Asha Volunteer', 'status' => 'active', 'email' => 'asha@vol.test'], true, '/ngo/people/volunteers/create');
$v1 = (int) $db->fetchColumn("SELECT id FROM people WHERE tenant_id = ? AND name = 'Asha Volunteer'", [$tA]);
check('Volunteers have their own ID series (VOL-00001)', $db->fetchColumn('SELECT member_code FROM people WHERE id = ?', [$v1]) === 'VOL-00001');
$p2b->post('/ngo/people/interns', ['name' => 'Beta Intern', 'status' => 'active'], true, '/ngo/people/interns/create');
check('Each NGO numbers its own people (Beta intern INT-00001)', $db->fetchColumn("SELECT member_code FROM people WHERE tenant_id = ? AND name = 'Beta Intern'", [$tB]) === 'INT-00001');
check('Another NGO gets 404 for this member', $p2b->get('/ngo/people/members/' . $m1)->status === 404);
check('A member cannot be opened under another type URL', $p2->get('/ngo/people/volunteers/' . $m1)->status === 404);
check('Beta cannot change an Alpha member', $p2b->post('/ngo/people/members/' . $m1 . '/status', ['status' => 'inactive'], true, '/ngo/people/interns')->status === 404
    && $db->fetchColumn('SELECT status FROM people WHERE id = ?', [$m1]) === 'active');
$db->update('subscriptions', ['plan_id' => $litePlan], ['tenant_id' => $tB, 'is_current' => 1]);
check('Without the module on the plan the pages are blocked', $p2b->get('/ngo/people/interns')->status === 403);
$db->update('subscriptions', ['plan_id' => $planId], ['tenant_id' => $tB, 'is_current' => 1]);

$used = UsageService::used($tA, 'members');
$db->update('subscription_plans', ['max_members' => $used], ['id' => $planId]);
$p2->post('/ngo/people/members', ['name' => 'Over Limit', 'status' => 'active'], true, '/ngo/people/members/create');
check('The plan member limit is enforced on the server', !(int) $db->fetchColumn("SELECT COUNT(*) FROM people WHERE name = 'Over Limit'")
    && $p2->visit('/ngo/people/members/create')->has('allows up to'));
$p2->post('/ngo/people/members/' . $m2 . '/status', ['status' => 'completed'], true, '/ngo/people/members/' . $m2);
check('Completed members stop counting towards the limit', UsageService::used($tA, 'members') === $used - 1);
$db->update('subscription_plans', ['max_members' => $used - 1], ['id' => $planId]);
$p2->post('/ngo/people/members/' . $m2 . '/status', ['status' => 'pending'], true, '/ngo/people/members/' . $m2);
check('Reactivating past the limit is refused', $db->fetchColumn('SELECT status FROM people WHERE id = ?', [$m2]) === 'completed');
$db->update('subscription_plans', ['max_members' => 100], ['id' => $planId]);
$p2->post('/ngo/people/members/' . $m2 . '/status', ['status' => 'pending'], true, '/ngo/people/members/' . $m2);
check('Status changes are recorded in the history', (int) $db->fetchColumn("SELECT COUNT(*) FROM person_history WHERE person_id = ? AND event = 'status_changed'", [$m2]) >= 1);

/** Checks that a PDF is structurally sound: header, EOF, and every xref offset points at its object. */
$pdfOk = static function (string $pdf): bool {
    if (!str_starts_with($pdf, '%PDF-1.') || !str_contains(substr($pdf, -32), '%%EOF')) {
        return false;
    }
    if (!preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/', $pdf, $m) || substr($pdf, (int) $m[1], 4) !== 'xref') {
        return false;
    }
    if (!preg_match('/xref\s+0 (\d+)\s+((?:\d{10} \d{5} [fn]\s+)+)/', $pdf, $x)) {
        return false;
    }
    preg_match_all('/(\d{10}) \d{5} n/', $x[2], $offsets);
    foreach ($offsets[1] as $i => $off) {
        if (!preg_match('/^' . ($i + 1) . ' 0 obj/', substr($pdf, (int) $off, 20))) {
            return false;
        }
    }
    return count($offsets[1]) === (int) $x[1] - 1;
};

/** Text of a PDF's (compressed) content streams, for content checks. */
$pdfText = static function (string $pdf): string {
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m);
    return implode("\n", array_map(static fn ($st) => (string) (@gzuncompress($st) ?: $st), $m[1]));
};

// Identity documents (multipart upload through curl).
$upload = static function (Client $cl, string $path, array $fields, string $file, string $mime, string $name, string $csrfFrom, string $field = 'document') use ($base): array {
    $fields['_csrf'] = $cl->csrf($csrfFrom);
    $jarProp = (new ReflectionClass($cl))->getProperty('jar');
    $jar = $jarProp->getValue($cl);
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields + [$field => new CURLFile($file, $mime, $name)]]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $raw];
};
$docPdf = new \App\Pdf\PdfDocument();
$docPdf->addPage();
$docPdf->setFont('helvetica', '', 12);
$docPdf->text(20, 30, 'AADHAAR TEST DOCUMENT');
$tmpDoc = tempnam(sys_get_temp_dir(), 'doc') . '.pdf';
file_put_contents($tmpDoc, $docPdf->output());
[$st] = $upload($p2, '/ngo/people/members/' . $m1 . '/documents', ['doc_type' => 'aadhaar', 'title' => 'Front'], $tmpDoc, 'application/pdf', 'aadhaar.pdf', '/ngo/people/members/' . $m1);
$doc = $db->fetch('SELECT d.*, s.stored_path, s.uuid FROM person_documents d JOIN storage_objects s ON s.id = d.storage_object_id WHERE d.person_id = ?', [$m1]);
check('Aadhaar document uploads', $st === 302 && $doc !== null);
$stored = $doc ? (string) @file_get_contents($storage . '/private/' . $doc['stored_path']) : '';
check('The document is encrypted on disk', $doc && str_ends_with($doc['stored_path'], '.enc') && $stored !== '' && !str_contains($stored, '%PDF') && !str_contains($stored, 'AADHAAR TEST'));
check('Uploading the Aadhaar card sets verification to pending', $db->fetchColumn('SELECT verification_status FROM people WHERE id = ?', [$m1]) === 'pending');
$p2->get('/ngo/people/members/' . $m1 . '/documents/' . ($doc['id'] ?? 0));
check('The NGO downloads the decrypted document', $p2->status === 200 && str_starts_with($p2->body, '%PDF') && str_contains($pdfText($p2->body), 'AADHAAR TEST'));
check('Another NGO cannot download it', $p2b->get('/ngo/people/members/' . $m1 . '/documents/' . ($doc['id'] ?? 0))->status === 404);
check('Documents are not reachable through the generic file URL', in_array($p2->get('/files/' . ($doc['uuid'] ?? ''))->status, [403, 404], true));
$p2->post('/ngo/people/members/' . $m1 . '/verify', ['decision' => 'rejected', 'note' => ''], true, '/ngo/people/members/' . $m1);
check('Rejecting needs a reason', $db->fetchColumn('SELECT verification_status FROM people WHERE id = ?', [$m1]) === 'pending');
$p2->post('/ngo/people/members/' . $m1 . '/verify', ['decision' => 'verified', 'note' => 'Matches card'], true, '/ngo/people/members/' . $m1);
check('Aadhaar verification is recorded', $db->fetchColumn('SELECT verification_status FROM people WHERE id = ?', [$m1]) === 'verified');

$photo = imagecreatetruecolor(60, 80);
imagefill($photo, 0, 0, imagecolorallocate($photo, 200, 120, 60));
$photoFile = $storage . '/photo.png';
imagepng($photo, $photoFile);
[$st] = $upload($p2, '/ngo/people/members/' . $m1 . '/photo', [], $photoFile, 'image/png', 'photo.png', '/ngo/people/members/' . $m1, 'photo');
$photoPath = (string) $db->fetchColumn('SELECT s.stored_path FROM people p JOIN storage_objects s ON s.id = p.photo_object_id WHERE p.id = ?', [$m1]);
check('Member photo is stored encrypted', $st === 302 && str_ends_with($photoPath, '.enc'));
check('The photo is shown to the NGO and not to others', str_starts_with($p2->get('/ngo/people/members/' . $m1 . '/photo')->body, "\x89PNG")
    && $p2b->get('/ngo/people/members/' . $m1 . '/photo')->status === 404);
$p2->get('/ngo/people/members/' . $m1 . '/id-card');
check('ID card downloads as a valid PDF', $p2->status === 200 && $pdfOk($p2->body));
check('ID cards are refused for pending people', $p2->get('/ngo/people/members/' . $m2 . '/id-card')->status === 302);

$slipFields = ['period_month' => date('Y-m', strtotime('first day of last month')), 'pay_type' => 'salary', 'payment_mode' => 'bank_transfer',
    'earning_label' => ['Basic pay', 'HRA', ''], 'earning_amount' => ['12000', '3000', ''], 'deduction_label' => ['Professional tax'], 'deduction_amount' => ['200']];
$p2->post('/ngo/people/members/' . $m1 . '/salary-slips', $slipFields, true, '/ngo/people/members/' . $m1 . '/salary-slips/create');
$slip = $db->fetch('SELECT * FROM salary_slips WHERE person_id = ?', [$m1]);
check('Salary slip is created with correct totals', $slip && (float) $slip['gross_amount'] === 15000.0 && (float) $slip['net_amount'] === 14800.0);
$p2->get('/ngo/salary-slips/' . ($slip['id'] ?? 0) . '/pdf');
check('Salary slip downloads as a valid PDF', $p2->status === 200 && $pdfOk($p2->body));
$p2->post('/ngo/people/members/' . $m1 . '/salary-slips', $slipFields, true, '/ngo/people/members/' . $m1 . '/salary-slips/create');
check('A second slip for the same month is refused', (int) $db->fetchColumn('SELECT COUNT(*) FROM salary_slips WHERE person_id = ?', [$m1]) === 1);
check('Another NGO cannot download the slip', $p2b->get('/ngo/salary-slips/' . ($slip['id'] ?? 0) . '/pdf')->status === 404);
check('People export is a CSV with masked Aadhaar', $p2->get('/ngo/people/members/export')->status === 200 && str_contains($p2->body, 'XXXX XXXX ' . substr($aadhaar, -4)) && !str_contains($p2->body, $aadhaar));

// ================================================================= 13. Certificates
section('Certificates');
$preset = \App\Services\CertificateTemplateService::presets()['appreciation'];
$tplFields = ['name' => 'Test Appreciation', 'orientation' => 'landscape', 'status' => 'active', 'page_background' => '#ffffff', 'page_border' => 'double',
    'page_border_color' => '#8a6d1f', 'tenant_ids' => [$tA]];
foreach ($preset['layout']['elements'] as $el) {
    foreach (['type', 'text', 'x', 'y', 'w', 'h', 'size', 'font', 'style', 'align', 'color'] as $k) {
        $tplFields['el_' . $k][] = (string) ($el[$k] ?? '');
    }
}
check('Template builder opens with a preset', $c->get('/admin/certificate-templates/create?preset=appreciation')->status === 200 && $c->has('Live preview'));
$c->post('/admin/certificate-templates', $tplFields, true, '/admin/certificate-templates/create');
$tplId = (int) $db->fetchColumn("SELECT id FROM certificate_templates WHERE name = 'Test Appreciation'");
check('Fameweb creates a template and assigns it to one NGO', $tplId > 0 && \App\Services\CertificateTemplateService::assignedTenantIds($tplId) === [$tA]);
$c->get('/admin/certificate-templates/' . $tplId . '/preview');
check('Template PDF preview is valid', $c->status === 200 && $pdfOk($c->body));
check('NGOs cannot open the template builder', $p2->get('/admin/certificate-templates')->status === 403);
check('The assigned NGO sees the template', $p2->get('/ngo/certificates/create')->has('Test Appreciation'));
check('Other NGOs do not see it', !$p2b->get('/ngo/certificates/create')->has('Test Appreciation'));
$p2b->post('/ngo/certificates', ['person_id' => (string) $db->fetchColumn("SELECT id FROM people WHERE tenant_id = ? AND name = 'Beta Intern'", [$tB]),
    'template_id' => $tplId, 'title' => 'Hack'], true, '/ngo/certificates/create');
check('Other NGOs cannot issue from it', !(int) $db->fetchColumn('SELECT COUNT(*) FROM certificates WHERE tenant_id = ?', [$tB]));
$p2->post('/ngo/certificates', ['person_id' => $m2, 'template_id' => $tplId, 'title' => 'Certificate of Appreciation'], true, '/ngo/certificates/create');
check('Certificates are refused for people pending approval', !(int) $db->fetchColumn('SELECT COUNT(*) FROM certificates WHERE person_id = ?', [$m2]));
$p2->post('/ngo/certificates', ['person_id' => $m1, 'template_id' => $tplId, 'title' => 'Certificate of Appreciation', 'description' => 'for the health camp',
    'issued_on' => date('Y-m-d')], true, '/ngo/certificates/create');
$cert = $db->fetch('SELECT * FROM certificates WHERE person_id = ?', [$m1]);
check('Certificate is issued with a number', $cert && $cert['certificate_no'] === 'CERT-' . date('Y') . '-00001');
$p2->get('/ngo/certificates/' . ($cert['id'] ?? 0) . '/pdf');
check('Certificate PDF is valid and filled in', $p2->status === 200 && $pdfOk($p2->body) && str_contains($pdfText($p2->body), 'Ravi Kumar'));
check('Another NGO cannot download it', $p2b->get('/ngo/certificates/' . ($cert['id'] ?? 0) . '/pdf')->status === 404);
check('Issued certificates count as usage', UsageService::used($tA, 'certificates') === 1);
$db->update('subscription_plans', ['max_certificates' => 1], ['id' => $planId]);
$p2->post('/ngo/certificates', ['person_id' => $v1, 'template_id' => $tplId, 'title' => 'Second'], true, '/ngo/certificates/create');
check('The plan certificate limit is enforced', (int) $db->fetchColumn('SELECT COUNT(*) FROM certificates WHERE tenant_id = ?', [$tA]) === 1);
$db->update('subscription_plans', ['max_certificates' => null], ['id' => $planId]);
$p2->post('/ngo/certificates/' . ($cert['id'] ?? 0) . '/revoke', ['reason' => 'Issued in error'], true, '/ngo/certificates');
check('Certificates can be revoked with a reason', $db->fetchColumn('SELECT status FROM certificates WHERE id = ?', [$cert['id'] ?? 0]) === 'revoked');
$tplLayout = (string) $db->fetchColumn('SELECT layout FROM certificate_templates WHERE id = ?', [$tplId]);
$c->post('/admin/certificate-templates/' . $tplId . '/toggle', [], true, '/admin/certificate-templates');
check('A deactivated template disappears for the NGO', !$p2->get('/ngo/certificates/create')->has('Test Appreciation'));
$c->post('/admin/certificate-templates/' . $tplId . '/toggle', [], true, '/admin/certificate-templates');

// ================================================================= 14. 80G receipts
section('80G receipts');
$p2->post('/ngo/receipts', ['donor_name' => 'Early', 'donor_address' => 'x', 'amount' => '100', 'donation_date' => date('Y-m-d'), 'payment_mode' => 'cash',
    'donor_id_type' => 'none', 'form_key' => bin2hex(random_bytes(16))], true, '/ngo/receipts');
check('Receipts need the 80G settings first', !(int) $db->fetchColumn('SELECT COUNT(*) FROM donation_receipts'));
$p2->post('/ngo/receipts/settings', ['r80g_pan' => 'AAATA1234A', 'r80g_reg_no' => 'AAATA1234AF20214', 'r80g_valid_from' => '2021-04-01', 'r80g_valid_to' => '2026-03-31',
    'r80g_signatory_name' => 'R. Mehta', 'r80g_signatory_designation' => 'Treasurer', 'r80g_prefix' => 'RCPT', 'r80g_cash_limit' => '2000', 'r80g_show_logo' => '1',
    'r80g_title' => 'Donation Receipt', 'r80g_subtitle' => '', 'r80g_declaration' => '', 'r80g_footer' => ''], true, '/ngo/receipts/settings');
check('80G settings are saved', \App\Services\ReceiptService::missingSettings(\App\Services\ReceiptService::settings($tA)) === []);
$p2->get('/ngo/receipts/settings/preview');
check('Sample receipt preview is a valid PDF', $p2->status === 200 && $pdfOk($p2->body) && str_contains($pdfText($p2->body), 'SAMPLE'));
$key = bin2hex(random_bytes(16));
$donation = ['donor_name' => 'Meera Shah', 'donor_address' => '9 Lake Road, Pune 411001', 'donor_email' => 'meera@donor.test', 'donor_id_type' => 'pan',
    'donor_id_number' => 'ABCPS1234K', 'amount' => '5000', 'donation_date' => date('Y-m-d'), 'payment_mode' => 'upi', 'payment_reference' => 'UPI12345',
    'donation_type' => 'others', 'purpose' => 'Education', 'form_key' => $key];
$fy = \App\Services\ReceiptService::financialYear(date('Y-m-d'));
$p2->post('/ngo/receipts', $donation, true, '/ngo/receipts/create');
$r1 = $db->fetch('SELECT * FROM donation_receipts WHERE tenant_id = ? ORDER BY id LIMIT 1', [$tA]);
check('Receipt is issued with a financial-year number', $r1 && $r1['receipt_no'] === 'RCPT/' . $fy . '/00001' && (int) $r1['eligible_80g'] === 1);
$p2->post('/ngo/receipts', $donation, true, '/ngo/receipts/create');
check('Submitting the same form twice does not create a second receipt', (int) $db->fetchColumn('SELECT COUNT(*) FROM donation_receipts WHERE tenant_id = ?', [$tA]) === 1);
$p2->get('/ngo/receipts/' . ($r1['id'] ?? 0) . '/pdf');
check('Receipt PDF is valid and shows the 80G details', $p2->status === 200 && $pdfOk($p2->body) && str_contains($pdfText($p2->body), 'AAATA1234A') && str_contains($pdfText($p2->body), 'Five Thousand'));
check('Another NGO cannot open the receipt', $p2b->get('/ngo/receipts/' . ($r1['id'] ?? 0))->status === 404 && $p2b->get('/ngo/receipts/' . ($r1['id'] ?? 0) . '/pdf')->status === 404);
$p2->post('/ngo/receipts', ['form_key' => bin2hex(random_bytes(16)), 'payment_mode' => 'cash', 'amount' => '5000', 'payment_reference' => ''] + $donation, true, '/ngo/receipts/create');
$cashId = (int) $db->fetchColumn('SELECT MAX(id) FROM donation_receipts WHERE tenant_id = ?', [$tA]);
check('Cash above Rs. 2,000 is marked not eligible for 80G', (int) $db->fetchColumn('SELECT eligible_80g FROM donation_receipts WHERE id = ?', [$cashId]) === 0);
$p2->post('/ngo/receipts', ['form_key' => bin2hex(random_bytes(16)), 'payment_mode' => 'kind', 'amount' => '800', 'payment_reference' => ''] + $donation, true, '/ngo/receipts/create');
$kindId = (int) $db->fetchColumn('SELECT MAX(id) FROM donation_receipts WHERE tenant_id = ?', [$tA]);
check('Donations in kind are marked not eligible', $kindId !== $cashId && (int) $db->fetchColumn('SELECT eligible_80g FROM donation_receipts WHERE id = ?', [$kindId]) === 0);
$lastFyDate = (date('n') >= 4 ? date('Y') - 1 : date('Y') - 2) . '-06-15';
$p2->post('/ngo/receipts', ['form_key' => bin2hex(random_bytes(16)), 'donation_date' => $lastFyDate] + $donation, true, '/ngo/receipts/create');
check('Each financial year has its own number series', $db->fetchColumn('SELECT receipt_no FROM donation_receipts WHERE tenant_id = ? AND donation_date = ?', [$tA, $lastFyDate])
    === 'RCPT/' . \App\Services\ReceiptService::financialYear($lastFyDate) . '/00001');
$p2->post('/ngo/receipts', ['form_key' => bin2hex(random_bytes(16)), 'donor_id_number' => 'NOTAPAN'] + $donation, true, '/ngo/receipts/create');
check('An invalid donor PAN is rejected', !(int) $db->fetchColumn("SELECT COUNT(*) FROM donation_receipts WHERE donor_id_number = 'NOTAPAN'"));
$p2->post('/ngo/receipts/' . $cashId . '/cancel', ['reason' => 'Wrong amount'], true, '/ngo/receipts/' . $cashId);
check('A receipt can be cancelled and keeps its number', $db->fetchColumn('SELECT status FROM donation_receipts WHERE id = ?', [$cashId]) === 'cancelled');
$p2->get('/ngo/receipts/export?fy=' . $fy);
check('Form 10BD export lists eligible receipts only', $p2->status === 200 && str_contains($p2->body, 'RCPT/' . $fy . '/00001') && str_contains($p2->body, 'ABCPS1234K')
    && !str_contains($p2->body, (string) $db->fetchColumn('SELECT receipt_no FROM donation_receipts WHERE id = ?', [$kindId]))
    && !str_contains($p2->body, (string) $db->fetchColumn('SELECT receipt_no FROM donation_receipts WHERE id = ?', [$cashId])));
check('Receipts count as usage', UsageService::used($tA, 'invoices') === 4);

// ================================================================= 15. Email automation
section('Email automation');
$smtpPort = (int) substr((string) stream_socket_get_name($s1 = stream_socket_server('tcp://127.0.0.1:0'), false), strrpos((string) stream_socket_get_name($s1, false), ':') + 1);
fclose($s1);
$smtpOut = $storage . '/logs/fake-smtp.txt';
$startSmtp = static function (int $port, int $failFirst) use ($root, $smtpOut) {
    $proc = proc_open([PHP_BINARY, $root . '/tests/fixtures/fake_smtp.php', (string) $port, $smtpOut, (string) $failFirst], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
    return $proc;
};
$smtp = $startSmtp($smtpPort, 1);
$c->post('/admin/emails/settings', ['mail_driver' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => (string) $smtpPort, 'smtp_encryption' => 'none',
    'smtp_username' => 'mailer@fameweb.test', 'smtp_pass' => 'Smtp-Secret-123', 'mail_from_address' => 'no-reply@fameweb.test', 'mail_from_name' => 'Fameweb'], true, '/admin/emails/settings');
check('SMTP password is stored encrypted', ($enc = (string) $db->fetchColumn("SELECT setting_value FROM system_settings WHERE setting_key = 'smtp_password_enc'")) !== '' && !str_contains($enc, 'Smtp-Secret-123')
    && str_starts_with($enc, 'v1'));
check('Email settings page never shows the saved password', !$c->get('/admin/emails/settings')->has('Smtp-Secret-123'));
$r2 = new Client($base);
$r2->post('/forgot-password', ['email' => 'admin@beta.test'], true, '/forgot-password');
$reset = $db->fetch("SELECT * FROM email_outbox WHERE to_email = 'admin@beta.test' ORDER BY id DESC LIMIT 1");
check('A temporary SMTP failure (451) leaves the email queued for retry', $reset && $reset['status'] === 'queued' && (int) $reset['attempts'] === 1
    && $reset['next_attempt_at'] > date('Y-m-d H:i:s') && str_contains((string) $reset['last_error'], '451'));
$db->update('email_outbox', ['next_attempt_at' => date('Y-m-d H:i:s', time() - 5)], ['id' => (int) ($reset['id'] ?? 0)]);
\App\Services\SettingsService::flush(); // the admin changed the settings through the web server
$due = \App\Services\EmailService::processDue();
$reset = $db->fetch('SELECT * FROM email_outbox WHERE id = ?', [(int) ($reset['id'] ?? 0)]);
check('The cron run retries and delivers it over SMTP', $due['sent'] >= 1 && $reset['status'] === 'sent' && $reset['transport'] === 'smtp' && (int) $db->fetchColumn('SELECT COUNT(*) FROM email_attempts WHERE outbox_id = ?', [$reset['id']]) === 2);
$received = quoted_printable_decode((string) @file_get_contents($smtpOut));
check('The password reset email really went out with its link', preg_match('#/reset-password/[a-f0-9]{64}#', $received) === 1);
preg_match('#/reset-password/([a-f0-9]{64})#', $received, $tok);
check('The single-use token is removed from the stored copy after delivery', isset($tok[1]) && !str_contains(\App\Services\EmailService::displayBody($reset), $tok[1])
    && !str_contains((string) $db->fetchColumn('SELECT body_encrypted FROM email_outbox WHERE id = ?', [$reset['id']]), $tok[1]));
check('The email log shows the attempts but not the token', $c->get('/admin/emails/' . $reset['id'])->status === 200 && $c->has('451') && !$c->has($tok[1] ?? 'x'));
check('SMTP credentials never appear in attempts or logs', !(int) $db->fetchColumn("SELECT COUNT(*) FROM email_attempts WHERE response LIKE '%Smtp-Secret%'")
    && !str_contains((string) @file_get_contents($storage . '/logs/server.log'), 'Smtp-Secret'));
$p2->post('/ngo/certificates', ['person_id' => $v1, 'template_id' => $tplId, 'title' => 'Volunteer of the Month'], true, '/ngo/certificates/create');
$cert2 = (int) $db->fetchColumn('SELECT id FROM certificates WHERE person_id = ?', [$v1]);
$p2->post('/ngo/certificates/' . $cert2 . '/email', [], true, '/ngo/certificates');
$certMail = $db->fetch("SELECT * FROM email_outbox WHERE category = 'certificate' AND tenant_id = ? ORDER BY id DESC LIMIT 1", [$tA]);
check('A certificate is emailed with its PDF attached', $certMail && $certMail['status'] === 'sent' && str_contains((string) @file_get_contents($smtpOut), 'application/pdf'));
check('NGO email log shows its own emails', $p2->get('/ngo/emails')->status === 200 && $p2->has('asha@vol.test'));
check('Other NGOs cannot open it', $p2b->get('/ngo/emails/' . ($certMail['id'] ?? 0))->status === 404);
check('Platform log lists emails from all NGOs', $c->get('/admin/emails')->status === 200 && $c->has('asha@vol.test') && $c->has('admin@beta.test'));
$p2->post('/ngo/emails/settings', ['auto_receipt_donor' => '1', 'auto_member_welcome' => '1', 'mail_smtp_port' => '587', 'mail_smtp_encryption' => 'tls'], true, '/ngo/emails/settings');
$p2->post('/ngo/people/members', ['name' => 'Welcome Test', 'status' => 'active', 'email' => 'welcome@member.test'], true, '/ngo/people/members/create');
check('Automatic welcome email is sent with the member ID', (int) $db->fetchColumn("SELECT COUNT(*) FROM email_outbox WHERE category = 'member_welcome' AND to_email = 'welcome@member.test' AND status = 'sent'") === 1
    && str_contains((string) @file_get_contents($smtpOut), 'MEM-0000'));
proc_terminate($smtp);
$p2->post('/ngo/receipts', ['form_key' => bin2hex(random_bytes(16))] + $donation, true, '/ngo/receipts/create');
$downMail = $db->fetch("SELECT * FROM email_outbox WHERE category = 'receipt' ORDER BY id DESC LIMIT 1");
check('When the mail server is down the receipt is still issued and the email waits for retry', $downMail && $downMail['status'] === 'queued'
    && (int) $db->fetchColumn('SELECT COUNT(*) FROM donation_receipts WHERE tenant_id = ?', [$tA]) === 5);
$smtp = $startSmtp($smtpPort, 0);
$p2->post('/ngo/emails/' . $downMail['id'] . '/retry', [], true, '/ngo/emails/' . $downMail['id']);
check('A manual retry from the email log delivers it', $db->fetchColumn('SELECT status FROM email_outbox WHERE id = ?', [$downMail['id']]) === 'sent');
proc_terminate($smtp);
\App\Services\MailSettings::savePlatform(['mail_driver' => 'log', 'smtp_host' => '', 'smtp_port' => '', 'smtp_encryption' => '', 'smtp_username' => '',
    'mail_from_address' => '', 'mail_from_name' => ''], '', $admin);
$logs = (string) @file_get_contents($storage . '/logs/server.log') . implode('', array_map('file_get_contents', glob($storage . '/logs/*.log') ?: []));
check('Full Aadhaar numbers never reach any log', !str_contains($logs, $aadhaar) && !str_contains($logs, $aadhaarSpaced));

// ================================================================= 16. Production error handling
section('Errors');
$miss = (new Client($base))->get('/does-not-exist');
check('Unknown route returns a friendly 404', $miss->status === 404 && !str_contains($miss->body, BASE_PATH));
$serverLog = (string) @file_get_contents($storage . '/logs/server.log');
$appLogs = implode('', array_map('file_get_contents', glob($storage . '/logs/app-*.log') ?: []));
check('No PHP errors logged during the run', !preg_match('/(Fatal|Warning|Notice|Deprecated|Uncaught)/', $serverLog . $appLogs), trim(substr($appLogs ?: $serverLog, 0, 300)));
check('Plaintext test passwords never reach any log', !str_contains($serverLog . $appLogs . (string) @file_get_contents($storage . '/logs/mail.log'), $pw));

// ---------------------------------------------------------------- summary
echo "\n{$results['pass']} passed, {$results['fail']} failed\n";
foreach ($results['failures'] as $f) {
    echo "  - $f\n";
}
exit($results['fail'] > 0 ? 1 : 0);
