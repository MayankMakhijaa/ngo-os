<?php
declare(strict_types=1);

/**
 * Route table. Middleware run in order:
 *   auth      signed-in, active user (re-checked against the DB)
 *   platform  Fameweb administrator (user_type = platform)
 *   tenant    NGO user; resolves the tenant from the user's DB record
 *   perm:x    user holds permission x (deny by default; "a|b" = either)
 *   module:x  module x is enabled on the NGO's plan
 *   writable  NGO subscription allows changes (trial / active / past due)
 * Every POST is additionally CSRF-checked in Application::handle().
 *
 * @var App\Core\Router $router
 */

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\FileController;
use App\Controllers\HomeController;
use App\Controllers\InstallController;
use App\Controllers\Ngo;
use App\Controllers\Platform;

// ---------------------------------------------------------------- Installer
$router->get('/install', [InstallController::class, 'show']);
$router->post('/install', [InstallController::class, 'install']);

// ---------------------------------------------------------------- Public / auth
$router->get('/', [HomeController::class, 'index']);
$router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
$router->post('/login', [AuthController::class, 'login'], ['guest']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/forgot-password', [AuthController::class, 'showForgot'], ['guest']);
$router->post('/forgot-password', [AuthController::class, 'forgot'], ['guest']);
$router->get('/reset-password/{token}', [AuthController::class, 'showReset']);
$router->post('/reset-password/{token}', [AuthController::class, 'reset']);
$router->get('/invite/{token}', [AuthController::class, 'showInvite']);
$router->post('/invite/{token}', [AuthController::class, 'acceptInvite']);

// ---------------------------------------------------------------- Any signed-in user
$router->get('/account', [AccountController::class, 'show'], ['auth']);
$router->post('/account/profile', [AccountController::class, 'updateProfile'], ['auth']);
$router->post('/account/password', [AccountController::class, 'changePassword'], ['auth']);
$router->get('/files/{uuid}', [FileController::class, 'show'], ['auth']);

// ---------------------------------------------------------------- Fameweb admin panel
$router->group('/admin', ['auth', 'platform'], function ($r) {
    $r->get('', [Platform\DashboardController::class, 'index'], ['perm:platform.dashboard']);

    $r->get('/ngos', [Platform\TenantController::class, 'index'], ['perm:tenants.view']);
    $r->get('/ngos/create', [Platform\TenantController::class, 'create'], ['perm:tenants.manage']);
    $r->post('/ngos', [Platform\TenantController::class, 'store'], ['perm:tenants.manage']);
    $r->get('/ngos/{id}', [Platform\TenantController::class, 'show'], ['perm:tenants.view']);
    $r->get('/ngos/{id}/edit', [Platform\TenantController::class, 'edit'], ['perm:tenants.manage']);
    $r->post('/ngos/{id}', [Platform\TenantController::class, 'update'], ['perm:tenants.manage']);
    $r->post('/ngos/{id}/status', [Platform\TenantController::class, 'status'], ['perm:tenants.manage']);
    $r->post('/ngos/{id}/invite', [Platform\TenantController::class, 'invite'], ['perm:tenants.manage']);
    $r->get('/ngos/{id}/subscription', [Platform\SubscriptionController::class, 'assignForm'], ['perm:subscriptions.manage']);
    $r->post('/ngos/{id}/subscription', [Platform\SubscriptionController::class, 'assign'], ['perm:subscriptions.manage']);

    $r->get('/subscriptions', [Platform\SubscriptionController::class, 'index'], ['perm:subscriptions.view']);
    $r->post('/subscriptions/{id}', [Platform\SubscriptionController::class, 'update'], ['perm:subscriptions.manage']);
    $r->post('/subscriptions/{id}/payments', [Platform\SubscriptionController::class, 'recordPayment'], ['perm:payments.record']);
    $r->get('/payments', [Platform\SubscriptionController::class, 'payments'], ['perm:subscriptions.view']);

    $r->get('/plans', [Platform\PlanController::class, 'index'], ['perm:plans.view']);
    $r->get('/plans/create', [Platform\PlanController::class, 'create'], ['perm:plans.manage']);
    $r->post('/plans', [Platform\PlanController::class, 'store'], ['perm:plans.manage']);
    $r->get('/plans/{id}', [Platform\PlanController::class, 'show'], ['perm:plans.view']);
    $r->get('/plans/{id}/edit', [Platform\PlanController::class, 'edit'], ['perm:plans.manage']);
    $r->post('/plans/{id}', [Platform\PlanController::class, 'update'], ['perm:plans.manage']);
    $r->post('/plans/{id}/toggle', [Platform\PlanController::class, 'toggle'], ['perm:plans.manage']);

    $r->get('/usage', [Platform\UsageController::class, 'index'], ['perm:platform.usage.view']);

    $r->get('/audit', [Platform\AuditController::class, 'index'], ['perm:platform.audit.view']);
    $r->get('/audit/export', [Platform\AuditController::class, 'export'], ['perm:platform.audit.export']);
    $r->post('/audit/verify', [Platform\AuditController::class, 'verify'], ['perm:platform.audit.view']);

    $r->get('/settings', [Platform\SettingsController::class, 'index'], ['perm:platform.settings']);
    $r->post('/settings', [Platform\SettingsController::class, 'update'], ['perm:platform.settings']);
    $r->post('/settings/migrate', [Platform\SettingsController::class, 'migrate'], ['perm:platform.settings']);

    $r->get('/admins', [Platform\AdminController::class, 'index'], ['perm:platform.admins.manage']);
    $r->get('/admins/create', [Platform\AdminController::class, 'create'], ['perm:platform.admins.manage']);
    $r->post('/admins', [Platform\AdminController::class, 'store'], ['perm:platform.admins.manage']);
    $r->post('/admins/{id}/status', [Platform\AdminController::class, 'status'], ['perm:platform.admins.manage']);
    $r->post('/admins/{id}/link', [Platform\AdminController::class, 'link'], ['perm:platform.admins.manage']);
});

// ---------------------------------------------------------------- NGO portal
$router->group('/ngo', ['auth', 'tenant'], function ($r) {
    $r->get('', [Ngo\DashboardController::class, 'index'], ['perm:dashboard.view']);

    $r->get('/users', [Ngo\UserController::class, 'index'], ['perm:users.view']);
    $r->get('/users/export', [Ngo\UserController::class, 'export'], ['perm:users.export', 'module:data_export']);
    $r->get('/users/create', [Ngo\UserController::class, 'create'], ['perm:users.create', 'writable']);
    $r->post('/users', [Ngo\UserController::class, 'store'], ['perm:users.create', 'writable']);
    $r->get('/users/{id}', [Ngo\UserController::class, 'show'], ['perm:users.view']);
    $r->get('/users/{id}/edit', [Ngo\UserController::class, 'edit'], ['perm:users.edit', 'writable']);
    $r->post('/users/{id}', [Ngo\UserController::class, 'update'], ['perm:users.edit', 'writable']);
    $r->post('/users/{id}/role', [Ngo\UserController::class, 'role'], ['perm:users.assign_roles', 'writable']);
    $r->post('/users/{id}/status', [Ngo\UserController::class, 'status'], ['perm:users.deactivate', 'writable']);
    $r->post('/users/{id}/link', [Ngo\UserController::class, 'link'], ['perm:users.invite', 'writable']);
    $r->get('/users/{id}/permissions', [Ngo\UserController::class, 'permissions'], ['perm:users.assign_permissions']);
    $r->post('/users/{id}/permissions', [Ngo\UserController::class, 'savePermissions'], ['perm:users.assign_permissions', 'writable']);

    $r->get('/roles', [Ngo\RoleController::class, 'index'], ['perm:roles.view']);
    $r->get('/roles/create', [Ngo\RoleController::class, 'create'], ['perm:roles.manage', 'writable']);
    $r->post('/roles', [Ngo\RoleController::class, 'store'], ['perm:roles.manage', 'writable']);
    $r->get('/roles/{id}', [Ngo\RoleController::class, 'show'], ['perm:roles.view']);
    $r->post('/roles/{id}', [Ngo\RoleController::class, 'update'], ['perm:roles.manage', 'writable']);
    $r->post('/roles/{id}/delete', [Ngo\RoleController::class, 'delete'], ['perm:roles.manage', 'writable']);

    $r->get('/organization', [Ngo\OrganizationController::class, 'show'], ['perm:organization.view']);
    $r->post('/organization', [Ngo\OrganizationController::class, 'update'], ['perm:organization.edit', 'writable']);
    $r->get('/organization/logo', [Ngo\OrganizationController::class, 'logo'], ['perm:organization.view']);

    $r->get('/subscription', [Ngo\SubscriptionController::class, 'show'], ['perm:subscription.view']);
    $r->get('/usage', [Ngo\UsageController::class, 'index'], ['perm:usage.view']);

    $r->get('/audit', [Ngo\AuditController::class, 'index'], ['perm:audit.view', 'module:audit_logs']);
    $r->get('/audit/export', [Ngo\AuditController::class, 'export'], ['perm:audit.export', 'module:audit_logs']);
});
