<?php
declare(strict_types=1);

namespace App\Controllers\Ngo;

use App\Core\Database;
use App\Core\Paginator;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Exceptions\DomainException;
use App\Exceptions\LimitExceededException;
use App\Services\AuditLogger;
use App\Services\PermissionService;
use App\Services\RoleService;
use App\Services\SubscriptionService;
use App\Services\UsageService;
use App\Services\UserService;

final class UserController extends NgoController
{
    /** Load a user of the current tenant or respond 404 (never reveals other tenants' users). */
    private function target(): array
    {
        $id = (int) $this->request->param('id');
        $user = UserService::findInTenant($this->tenantId(), $id);
        if ($user === null) {
            AuditLogger::log('authz.denied', 'user', $id, ['reason' => 'not_in_tenant', 'path' => $this->request->path]);
            $this->notFound('user');
        }
        return $user;
    }

    public function index(): string
    {
        $tid = $this->tenantId();
        $q = $this->request->str('q');
        $status = $this->request->str('status');
        $roleId = $this->request->int('role_id');
        $where = ['u.tenant_id = :tid'];
        $params = ['tid' => $tid];
        if ($q !== '') {
            $where[] = '(u.name LIKE :q OR u.email LIKE :q2 OR u.phone LIKE :q3)';
            $params += ['q' => "%$q%", 'q2' => "%$q%", 'q3' => "%$q%"];
        }
        if (in_array($status, ['active', 'invited', 'inactive'], true)) {
            $where[] = 'u.status = :status';
            $params['status'] = $status;
        }
        if ($roleId > 0) {
            $where[] = 'r.id = :rid';
            $params['rid'] = $roleId;
        }
        $from = ' FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id AND ur.is_primary = 1
                  LEFT JOIN roles r ON r.id = ur.role_id AND r.tenant_id = u.tenant_id
                 WHERE ' . implode(' AND ', $where);
        $page = new Paginator(
            'SELECT u.id, u.name, u.email, u.phone, u.status, u.last_login_at, u.created_at, r.name AS role_name, r.slug AS role_slug'
            . $from . ' ORDER BY u.name',
            'SELECT COUNT(*)' . $from,
            $params,
            $this->request->int('page', 1)
        );
        return $this->view('ngo/users/index', [
            'title' => 'Users',
            'page' => $page,
            'roles' => RoleService::listForTenant($tid),
            'f' => compact('q', 'status', 'roleId'),
            'usage' => UsageService::summary($tid)['users'],
        ]);
    }

    public function create(): string
    {
        $tid = $this->tenantId();
        $me = $this->user();
        $perm = PermissionService::instance();
        $roles = array_values(array_filter(RoleService::listForTenant($tid), static fn ($r) => $perm->canAssignRole($me, $r)));
        return $this->view('ngo/users/create', [
            'title' => 'Add user',
            'roles' => $roles,
            'usage' => UsageService::summary($tid)['users'],
        ]);
    }

    public function store(): Response
    {
        $data = $this->validate(['name' => 'required|max:120', 'email' => 'required|email', 'phone' => 'phone|max:30', 'role_id' => 'required|integer'],
            'ngo/users/create', ['role_id' => 'Role']);
        try {
            $res = UserService::create($this->user(), $data, (int) $data['role_id']);
        } catch (LimitExceededException $e) {
            $this->failValidation([], 'ngo/users/create', $e->getMessage());
        } catch (DomainException $e) {
            $this->failValidation([], 'ngo/users/create', $e->getMessage());
        }
        Session::flash('access_link', $res['invite_link']);
        return $this->redirect('ngo/users/' . $res['user_id'], 'success', 'User created and invited. They must set their own password using the single-use link.');
    }

    public function show(): string
    {
        $target = $this->target();
        $me = $this->user();
        $perm = PermissionService::instance();
        $tid = $this->tenantId();
        $roles = array_values(array_filter(RoleService::listForTenant($tid), static fn ($r) => $perm->canAssignRole($me, $r)));
        return $this->view('ngo/users/show', [
            'title' => $target['name'],
            'u' => $target,
            'effective' => $perm->effective($target + ['user_type' => 'tenant']),
            'catalog' => $perm->catalogFor('tenant'),
            'canManage' => $perm->canManageUser($me, $target),
            'roles' => $roles,
            'link' => Session::getFlash('access_link'),
            'activity' => Database::instance()->fetchAll(
                "SELECT a.*, u.name AS actor_name FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
                  WHERE a.tenant_id = ? AND ((a.entity_type = 'user' AND a.entity_id = ?) OR a.actor_user_id = ?)
                    AND a.actor_type <> 'platform'
                  ORDER BY a.id DESC LIMIT 10",
                [$tid, (string) $target['id'], (int) $target['id']]
            ),
        ]);
    }

    public function edit(): string
    {
        $target = $this->target();
        return $this->view('ngo/users/edit', ['title' => 'Edit ' . $target['name'], 'u' => $target]);
    }

    public function update(): Response
    {
        $target = $this->target();
        $back = 'ngo/users/' . $target['id'] . '/edit';
        $data = $this->validate(['name' => 'required|max:120', 'email' => 'required|email', 'phone' => 'phone|max:30'], $back);
        try {
            UserService::updateDetails($this->user(), $target, $data);
        } catch (DomainException $e) {
            $this->failValidation([], $back, $e->getMessage());
        }
        return $this->redirect('ngo/users/' . $target['id'], 'success', 'User details updated.');
    }

    public function role(): Response
    {
        $target = $this->target();
        try {
            UserService::changeRole($this->user(), $target, $this->request->int('role_id'));
        } catch (DomainException $e) {
            return $this->redirect('ngo/users/' . $target['id'], 'error', $e->getMessage());
        }
        return $this->redirect('ngo/users/' . $target['id'], 'success', 'Role updated.');
    }

    public function status(): Response
    {
        $target = $this->target();
        $status = $this->request->str('status');
        try {
            UserService::setStatus($this->user(), $target, $status);
        } catch (DomainException|LimitExceededException $e) {
            return $this->redirect('ngo/users/' . $target['id'], 'error', $e->getMessage());
        }
        return $this->redirect('ngo/users/' . $target['id'], 'success',
            $status === 'inactive' ? 'User deactivated and signed out. Their records are kept.' : 'User reactivated.');
    }

    public function link(): Response
    {
        $target = $this->target();
        try {
            $link = UserService::sendAccessLink($this->user(), $target);
        } catch (DomainException $e) {
            return $this->redirect('ngo/users/' . $target['id'], 'error', $e->getMessage());
        }
        Session::flash('access_link', $link);
        return $this->redirect('ngo/users/' . $target['id'], 'success', 'A new single-use link was created and emailed. Earlier links no longer work.');
    }

    public function permissions(): string
    {
        $target = $this->target();
        $me = $this->user();
        $perm = PermissionService::instance();
        $catalog = $perm->catalogFor('tenant');
        return $this->view('ngo/users/permissions', [
            'title' => 'Permissions: ' . $target['name'],
            'u' => $target,
            'catalog' => $catalog,
            'roleKeys' => $target['role_id'] ? $perm->roleKeys((int) $target['role_id']) : [],
            'overrides' => UserService::overrides((int) $target['id']),
            'effective' => $perm->effective($target + ['user_type' => 'tenant']),
            'delegable' => array_keys(array_filter($catalog, static fn ($p) => $perm->canDelegate($me, $p['perm_key']))),
            'canManage' => $perm->canManageUser($me, $target),
            'modules' => SubscriptionService::enabledModules($this->tenantId()),
        ]);
    }

    public function savePermissions(): Response
    {
        $target = $this->target();
        $overrides = [];
        foreach ($this->request->arr('perm') as $key => $effect) {
            if (is_string($key) && is_string($effect)) {
                $overrides[$key] = $effect;
            }
        }
        try {
            UserService::setOverrides($this->user(), $target, $overrides);
        } catch (DomainException $e) {
            return $this->redirect('ngo/users/' . $target['id'] . '/permissions', 'error', $e->getMessage());
        }
        return $this->redirect('ngo/users/' . $target['id'] . '/permissions', 'success', 'Permissions saved.');
    }

    public function export(): Response
    {
        $tid = $this->tenantId();
        $rows = Database::instance()->fetchAll(
            'SELECT u.id, u.name, u.email, u.phone, r.name AS role_name, u.status, u.last_login_at, u.created_at
               FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id AND ur.is_primary = 1
               LEFT JOIN roles r ON r.id = ur.role_id
              WHERE u.tenant_id = ? ORDER BY u.name',
            [$tid]
        );
        AuditLogger::log('users.exported', 'user', null, ['rows' => count($rows)]);
        return Response::csv('users-' . date('Ymd') . '.csv', ['ID', 'Name', 'Email', 'Phone', 'Role', 'Status', 'Last login', 'Created'],
            array_map('array_values', $rows));
    }
}
