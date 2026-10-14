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

    $r->get('/certificate-templates', [Platform\CertificateTemplateController::class, 'index'], ['perm:certificate_templates.view']);
    $r->get('/certificate-templates/create', [Platform\CertificateTemplateController::class, 'create'], ['perm:certificate_templates.manage']);
    $r->post('/certificate-templates', [Platform\CertificateTemplateController::class, 'store'], ['perm:certificate_templates.manage']);
    $r->get('/certificate-templates/{id}/edit', [Platform\CertificateTemplateController::class, 'edit'], ['perm:certificate_templates.view']);
    $r->post('/certificate-templates/{id}', [Platform\CertificateTemplateController::class, 'update'], ['perm:certificate_templates.manage']);
    $r->post('/certificate-templates/{id}/toggle', [Platform\CertificateTemplateController::class, 'toggle'], ['perm:certificate_templates.manage']);
    $r->get('/certificate-templates/{id}/preview', [Platform\CertificateTemplateController::class, 'preview'], ['perm:certificate_templates.view']);

    $r->get('/emails', [Platform\EmailController::class, 'index'], ['perm:platform.email.view']);
    $r->post('/emails/process', [Platform\EmailController::class, 'process'], ['perm:platform.email.manage']);
    $r->get('/emails/settings', [Platform\EmailController::class, 'settings'], ['perm:platform.email.manage']);
    $r->post('/emails/settings', [Platform\EmailController::class, 'saveSettings'], ['perm:platform.email.manage']);
    $r->post('/emails/test', [Platform\EmailController::class, 'test'], ['perm:platform.email.manage']);
    $r->get('/emails/{id}', [Platform\EmailController::class, 'show'], ['perm:platform.email.view']);
    $r->post('/emails/{id}/retry', [Platform\EmailController::class, 'retry'], ['perm:platform.email.manage']);

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

    // Members, volunteers and interns ({type} = members|volunteers|interns; the controller also
    // checks that the type's own module is on the plan).
    $r->get('/people/{type}', [Ngo\PeopleController::class, 'index'], ['perm:people.view']);
    $r->get('/people/{type}/export', [Ngo\PeopleController::class, 'export'], ['perm:people.export', 'module:data_export']);
    $r->get('/people/{type}/create', [Ngo\PeopleController::class, 'create'], ['perm:people.create', 'writable']);
    $r->post('/people/{type}', [Ngo\PeopleController::class, 'store'], ['perm:people.create', 'writable']);
    $r->get('/people/{type}/{id}', [Ngo\PeopleController::class, 'show'], ['perm:people.view']);
    $r->get('/people/{type}/{id}/edit', [Ngo\PeopleController::class, 'edit'], ['perm:people.edit', 'writable']);
    $r->post('/people/{type}/{id}', [Ngo\PeopleController::class, 'update'], ['perm:people.edit', 'writable']);
    $r->post('/people/{type}/{id}/status', [Ngo\PeopleController::class, 'status'], ['perm:people.status', 'writable']);
    $r->post('/people/{type}/{id}/photo', [Ngo\PeopleController::class, 'photo'], ['perm:people.edit', 'writable']);
    $r->get('/people/{type}/{id}/photo', [Ngo\PeopleController::class, 'showPhoto'], ['perm:people.view']);
    $r->get('/people/{type}/{id}/id-card', [Ngo\PeopleController::class, 'idCard'], ['perm:people.id_cards']);
    $r->post('/people/{type}/{id}/documents', [Ngo\PeopleController::class, 'uploadDocument'], ['perm:people.documents', 'module:documents', 'writable']);
    $r->get('/people/{type}/{id}/documents/{doc}', [Ngo\PeopleController::class, 'document'], ['perm:people.documents', 'module:documents']);
    $r->post('/people/{type}/{id}/documents/{doc}/delete', [Ngo\PeopleController::class, 'deleteDocument'], ['perm:people.documents', 'module:documents', 'writable']);
    $r->post('/people/{type}/{id}/verify', [Ngo\PeopleController::class, 'verify'], ['perm:people.verify', 'module:documents', 'writable']);
    $r->get('/people/{type}/{id}/salary-slips/create', [Ngo\PeopleController::class, 'slipForm'], ['perm:salary.manage', 'writable']);
    $r->post('/people/{type}/{id}/salary-slips', [Ngo\PeopleController::class, 'storeSlip'], ['perm:salary.manage', 'writable']);
    $r->get('/salary-slips', [Ngo\PeopleController::class, 'slips'], ['perm:salary.view']);
    $r->get('/salary-slips/{id}/pdf', [Ngo\PeopleController::class, 'slipPdf'], ['perm:salary.view']);

    $r->get('/people-categories', [Ngo\CategoryController::class, 'index'], ['perm:people.categories']);
    $r->post('/people-categories', [Ngo\CategoryController::class, 'store'], ['perm:people.categories', 'writable']);
    $r->post('/people-categories/{id}/delete', [Ngo\CategoryController::class, 'delete'], ['perm:people.categories', 'writable']);

    $r->get('/certificates', [Ngo\CertificateController::class, 'index'], ['perm:certificates.view', 'module:certificates']);
    $r->get('/certificates/create', [Ngo\CertificateController::class, 'create'], ['perm:certificates.issue', 'module:certificates', 'writable']);
    $r->post('/certificates', [Ngo\CertificateController::class, 'store'], ['perm:certificates.issue', 'module:certificates', 'writable']);
    $r->get('/certificates/templates/{id}/preview', [Ngo\CertificateController::class, 'preview'], ['perm:certificates.view', 'module:certificates']);
    $r->get('/certificates/{id}/pdf', [Ngo\CertificateController::class, 'pdf'], ['perm:certificates.view', 'module:certificates']);
    $r->post('/certificates/{id}/revoke', [Ngo\CertificateController::class, 'revoke'], ['perm:certificates.revoke', 'module:certificates', 'writable']);
    $r->post('/certificates/{id}/email', [Ngo\CertificateController::class, 'email'], ['perm:certificates.issue', 'module:certificates', 'writable']);

    $r->get('/receipts', [Ngo\ReceiptController::class, 'index'], ['perm:receipts.view', 'module:certificates_80g']);
    $r->get('/receipts/export', [Ngo\ReceiptController::class, 'export'], ['perm:receipts.export', 'module:certificates_80g']);
    $r->get('/receipts/create', [Ngo\ReceiptController::class, 'create'], ['perm:receipts.create', 'module:certificates_80g', 'writable']);
    $r->post('/receipts', [Ngo\ReceiptController::class, 'store'], ['perm:receipts.create', 'module:certificates_80g', 'writable']);
    $r->get('/receipts/settings', [Ngo\ReceiptController::class, 'settings'], ['perm:receipts.settings', 'module:certificates_80g']);
    $r->post('/receipts/settings', [Ngo\ReceiptController::class, 'saveSettings'], ['perm:receipts.settings', 'module:certificates_80g', 'writable']);
    $r->get('/receipts/settings/preview', [Ngo\ReceiptController::class, 'preview'], ['perm:receipts.settings', 'module:certificates_80g']);
    $r->get('/receipts/settings/signature', [Ngo\ReceiptController::class, 'signature'], ['perm:receipts.settings', 'module:certificates_80g']);
    $r->get('/receipts/{id}', [Ngo\ReceiptController::class, 'show'], ['perm:receipts.view', 'module:certificates_80g']);
    $r->get('/receipts/{id}/pdf', [Ngo\ReceiptController::class, 'pdf'], ['perm:receipts.view', 'module:certificates_80g']);
    $r->post('/receipts/{id}/cancel', [Ngo\ReceiptController::class, 'cancel'], ['perm:receipts.cancel', 'module:certificates_80g', 'writable']);
    $r->post('/receipts/{id}/email', [Ngo\ReceiptController::class, 'email'], ['perm:receipts.create', 'module:certificates_80g', 'writable']);

    $r->get('/emails', [Ngo\EmailController::class, 'index'], ['perm:emails.view', 'module:email_automation']);
    $r->get('/emails/settings', [Ngo\EmailController::class, 'settings'], ['perm:emails.manage', 'module:email_automation']);
    $r->post('/emails/settings', [Ngo\EmailController::class, 'saveSettings'], ['perm:emails.manage', 'module:email_automation', 'writable']);
    $r->post('/emails/test', [Ngo\EmailController::class, 'test'], ['perm:emails.manage', 'module:email_automation', 'writable']);
    $r->get('/emails/{id}', [Ngo\EmailController::class, 'show'], ['perm:emails.view', 'module:email_automation']);
    $r->post('/emails/{id}/retry', [Ngo\EmailController::class, 'retry'], ['perm:emails.manage', 'module:email_automation', 'writable']);

    $r->get('/organization', [Ngo\OrganizationController::class, 'show'], ['perm:organization.view']);
    $r->post('/organization', [Ngo\OrganizationController::class, 'update'], ['perm:organization.edit', 'writable']);
    $r->get('/organization/logo', [Ngo\OrganizationController::class, 'logo'], ['perm:organization.view']);

    $r->get('/subscription', [Ngo\SubscriptionController::class, 'show'], ['perm:subscription.view']);
    $r->get('/usage', [Ngo\UsageController::class, 'index'], ['perm:usage.view']);

    $r->get('/audit', [Ngo\AuditController::class, 'index'], ['perm:audit.view', 'module:audit_logs']);
    $r->get('/audit/export', [Ngo\AuditController::class, 'export'], ['perm:audit.export', 'module:audit_logs']);
});
