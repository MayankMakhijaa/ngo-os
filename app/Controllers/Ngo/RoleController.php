<?php
declare(strict_types=1);

namespace App\Controllers\Ngo;

use App\Core\Response;
use App\Exceptions\DomainException;
use App\Services\AuditLogger;
use App\Services\PermissionService;
use App\Services\RoleService;
use App\Services\SubscriptionService;
use App\Services\UserService;

final class RoleController extends NgoController
{
    private function target(): array
    {
        $id = (int) $this->request->param('id');
        $role = UserService::roleInTenant($this->tenantId(), $id);
        if ($role === null) {
            AuditLogger::log('authz.denied', 'role', $id, ['reason' => 'not_in_tenant']);
            $this->notFound('role');
        }
        return $role;
    }

    public function index(): string
    {
        return $this->view('ngo/roles/index', ['title' => 'Roles & permissions', 'roles' => RoleService::listForTenant($this->tenantId())]);
    }

    public function create(): string
    {
        return $this->view('ngo/roles/form', $this->formData(null) + ['title' => 'Create custom role']);
    }

    public function store(): Response
    {
        $data = $this->validate(['name' => 'required|max:100', 'description' => 'max:255'], 'ngo/roles/create');
        try {
            $id = RoleService::create($this->user(), $data['name'], $data['description'], array_map('strval', $this->request->arr('permissions')));
        } catch (DomainException $e) {
            $this->failValidation([], 'ngo/roles/create', $e->getMessage());
        }
        return $this->redirect('ngo/roles/' . $id, 'success', 'Role created.');
    }

    public function show(): string
    {
        $role = $this->target();
        return $this->view('ngo/roles/form', $this->formData($role) + ['title' => $role['name']]);
    }

    public function update(): Response
    {
        $role = $this->target();
        $back = 'ngo/roles/' . $role['id'];
        $data = $this->validate(['name' => 'required|max:100', 'description' => 'max:255'], $back);
        try {
            RoleService::update($this->user(), $role, $data['name'], $data['description'], array_map('strval', $this->request->arr('permissions')));
        } catch (DomainException $e) {
            $this->failValidation([], $back, $e->getMessage());
        }
        return $this->redirect($back, 'success', 'Role saved. Changes apply to its users immediately.');
    }

    public function delete(): Response
    {
        $role = $this->target();
        try {
            RoleService::delete($this->user(), $role);
        } catch (DomainException $e) {
            return $this->redirect('ngo/roles/' . $role['id'], 'error', $e->getMessage());
        }
        return $this->redirect('ngo/roles', 'success', 'Role deleted.');
    }

    private function formData(?array $role): array
    {
        $perm = PermissionService::instance();
        $me = $this->user();
        $catalog = $perm->catalogFor('tenant');
        $editable = can('roles.manage') && SubscriptionService::isWritable(\App\Core\TenantContext::subscription())
            && ($role === null || (!(int) $role['is_locked'] && (int) $role['id'] !== (int) ($me['role_id'] ?? 0)));
        return [
            'role' => $role,
            'catalog' => $catalog,
            'roleKeys' => $role ? $perm->roleKeys((int) $role['id']) : [],
            'delegable' => array_keys(array_filter($catalog, static fn ($p) => $perm->canDelegate($me, $p['perm_key']))),
            'editable' => $editable,
            'modules' => SubscriptionService::enabledModules($this->tenantId()),
        ];
    }
}
