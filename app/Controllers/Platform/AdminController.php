<?php
declare(strict_types=1);

namespace App\Controllers\Platform;

use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditLogger;
use App\Services\Mailer;
use App\Services\SettingsService;
use App\Services\TokenService;

/** Fameweb administrator accounts (platform users). */
final class AdminController extends PlatformController
{
    public function index(): string
    {
        return $this->view('platform/admins/index', [
            'title' => 'Fameweb administrators',
            'admins' => Database::instance()->fetchAll(
                "SELECT u.*, r.name AS role_name FROM users u
                   LEFT JOIN user_roles ur ON ur.user_id = u.id AND ur.is_primary = 1
                   LEFT JOIN roles r ON r.id = ur.role_id
                  WHERE u.user_type = 'platform' ORDER BY u.id"
            ),
            'link' => Session::getFlash('admin_link'),
        ]);
    }

    public function create(): string
    {
        return $this->view('platform/admins/create', [
            'title' => 'Add Fameweb administrator',
            'roles' => Database::instance()->fetchAll("SELECT * FROM roles WHERE scope = 'platform' ORDER BY id"),
        ]);
    }

    public function store(): Response
    {
        $data = $this->validate(['name' => 'required|max:120', 'email' => 'required|email', 'role_id' => 'required|integer'], 'admin/admins/create');
        $db = Database::instance();
        $email = mb_strtolower($data['email']);
        $role = $db->fetch("SELECT * FROM roles WHERE id = ? AND scope = 'platform' AND tenant_id IS NULL", [(int) $data['role_id']]);
        if (!$role) {
            $this->failValidation(['role_id' => 'Choose a valid role.'], 'admin/admins/create');
        }
        if ($db->fetchColumn('SELECT id FROM users WHERE email = ?', [$email])) {
            $this->failValidation(['email' => 'A user with this email already exists.'], 'admin/admins/create');
        }
        $ttl = SettingsService::int('invite_ttl_hours') ?: 72;
        $me = $this->user();
        $token = $db->transaction(function (Database $db) use ($data, $email, $role, $me, $ttl) {
            $id = $db->insert('users', ['tenant_id' => null, 'user_type' => 'platform', 'name' => $data['name'], 'email' => $email,
                'status' => 'invited', 'created_by' => (int) $me['id']]);
            $db->insert('user_roles', ['user_id' => $id, 'role_id' => (int) $role['id'], 'is_primary' => 1, 'assigned_by' => (int) $me['id']]);
            AuditLogger::log('admin.created', 'user', $id, ['email' => $email, 'role' => $role['slug']], null);
            return TokenService::issue($id, 'invite', $ttl * 3600, (int) $me['id']);
        });
        $link = absolute_url('invite/' . $token);
        Mailer::send($email, 'Your Fameweb administrator invitation', "Hello {$data['name']},\n\nActivate your administrator account (link valid $ttl hours):\n\n$link\n");
        Session::flash('admin_link', $link);
        return $this->redirect('admin/admins', 'success', 'Administrator invited. Share the single-use activation link securely.');
    }

    public function status(): Response
    {
        $id = (int) $this->request->param('id');
        $db = Database::instance();
        $target = $db->fetch("SELECT * FROM users WHERE id = ? AND user_type = 'platform'", [$id]) ?? $this->notFound('administrator');
        if ($id === (int) $this->user()['id']) {
            return $this->redirect('admin/admins', 'error', 'You cannot change the status of your own account.');
        }
        if ($target['status'] === 'inactive') {
            $db->execute("UPDATE users SET status = IF(password_hash IS NULL, 'invited', 'active') WHERE id = ?", [$id]);
            AuditLogger::log('admin.updated', 'user', $id, ['status' => 'reactivated'], null);
            return $this->redirect('admin/admins', 'success', 'Administrator reactivated.');
        }
        $activeSupers = (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
              WHERE u.user_type = 'platform' AND u.status = 'active' AND r.slug = 'fameweb_super_admin' AND u.id <> ?",
            [$id]
        );
        if ($activeSupers === 0) {
            return $this->redirect('admin/admins', 'error', 'At least one active Fameweb Super Admin must remain.');
        }
        $db->execute("UPDATE users SET status = 'inactive', session_version = session_version + 1 WHERE id = ?", [$id]);
        TokenService::revokeAll($id);
        AuditLogger::log('admin.updated', 'user', $id, ['status' => 'deactivated'], null);
        return $this->redirect('admin/admins', 'success', 'Administrator deactivated and signed out.');
    }

    public function link(): Response
    {
        $id = (int) $this->request->param('id');
        $target = Database::instance()->fetch("SELECT * FROM users WHERE id = ? AND user_type = 'platform' AND status <> 'inactive'", [$id]) ?? $this->notFound('administrator');
        $purpose = $target['status'] === 'invited' ? 'invite' : 'reset';
        $ttl = $purpose === 'invite' ? (SettingsService::int('invite_ttl_hours') ?: 72) * 3600 : (SettingsService::int('reset_ttl_minutes') ?: 60) * 60;
        $token = TokenService::issue($id, $purpose, $ttl, (int) $this->user()['id']);
        $link = absolute_url(($purpose === 'invite' ? 'invite/' : 'reset-password/') . $token);
        Mailer::send($target['email'], $purpose === 'invite' ? 'Your invitation' : 'Reset your password', "Hello {$target['name']},\n\nUse this single-use link:\n\n$link\n");
        AuditLogger::log($purpose === 'invite' ? 'user.invite_reissued' : 'user.reset_link_issued', 'user', $id, [], null);
        Session::flash('admin_link', $link);
        return $this->redirect('admin/admins', 'success', 'New single-use link created. Earlier links no longer work.');
    }
}
