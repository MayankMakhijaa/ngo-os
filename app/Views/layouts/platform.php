<?php
// Fameweb Admin layout: platform branding, platform navigation.
$shell = [
    'brand' => \App\Services\SettingsService::get('platform_name'),
    'brand_sub' => 'Fameweb Admin',
    'brand_icon' => 'globe2',
    'theme' => 'theme-platform',
    'home' => '/admin',
    'role_label' => auth_user()['role_name'] ?? 'Fameweb',
    'search' => can('tenants.view') ? ['action' => 'admin/ngos', 'placeholder' => 'Search NGOs by name, code or email'] : null,
    'nav' => [
        ['url' => '/admin', 'label' => 'Dashboard', 'icon' => 'speedometer2', 'perm' => 'platform.dashboard'],
        ['section' => 'Customers'],
        ['url' => '/admin/ngos', 'label' => 'NGOs', 'icon' => 'buildings', 'perm' => 'tenants.view'],
        ['url' => '/admin/subscriptions', 'label' => 'Subscriptions', 'icon' => 'arrow-repeat', 'perm' => 'subscriptions.view'],
        ['url' => '/admin/payments', 'label' => 'Payments', 'icon' => 'cash-stack', 'perm' => 'subscriptions.view'],
        ['url' => '/admin/usage', 'label' => 'Usage', 'icon' => 'hdd-stack', 'perm' => 'platform.usage.view'],
        ['section' => 'Configuration'],
        ['url' => '/admin/plans', 'label' => 'Plans', 'icon' => 'layers', 'perm' => 'plans.view'],
        ['url' => '/admin/admins', 'label' => 'Administrators', 'icon' => 'person-badge', 'perm' => 'platform.admins.manage'],
        ['url' => '/admin/settings', 'label' => 'Settings', 'icon' => 'gear', 'perm' => 'platform.settings'],
        ['url' => '/admin/audit', 'label' => 'Audit log', 'icon' => 'journal-text', 'perm' => 'platform.audit.view'],
    ],
];
include __DIR__ . '/../partials/app_shell.php';
