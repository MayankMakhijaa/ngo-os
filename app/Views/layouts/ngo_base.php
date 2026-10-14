<?php
/**
 * Shared NGO portal shell. The three role layouts set $ngoTheme / $ngoLabel and include this.
 * Navigation items are filtered by the user's effective permissions (the routes enforce the
 * same permissions on the server, so hiding a link is never the only protection).
 */
$t = \App\Core\TenantContext::tenant();
$sub = \App\Core\TenantContext::subscription();
$settings = \App\Services\TenantService::settings((int) $t['id']);
$banner = null;
if ($sub === null) {
    $banner = ['tone' => 'danger', 'icon' => 'x-octagon', 'text' => 'Your organization has no subscription. The workspace is read-only until Fameweb assigns a plan.'];
} elseif (in_array($sub['status'], ['expired', 'suspended', 'cancelled'], true)) {
    $banner = ['tone' => 'danger', 'icon' => 'lock', 'text' => 'Your subscription is ' . str_replace('_', ' ', $sub['status']) . '. The workspace is read-only; your data is safe. Please contact Fameweb to renew.'];
} elseif ($sub['status'] === 'past_due') {
    $banner = ['tone' => 'warning', 'icon' => 'exclamation-triangle', 'text' => 'Your subscription renewal is past due (renewal date ' . format_date($sub['end_date']) . '). Please renew to avoid interruption.'];
} elseif ($sub['status'] === 'trial' && $sub['trial_ends_at'] && $sub['trial_ends_at'] <= date('Y-m-d', strtotime('+7 days'))) {
    $banner = ['tone' => 'info', 'icon' => 'hourglass-split', 'text' => 'Your trial ends on ' . format_date($sub['trial_ends_at']) . '.'];
}
$shell = [
    'brand' => $settings['display_name'] ?: $t['name'],
    'brand_sub' => $t['reference_code'],
    'brand_icon' => 'heart-pulse',
    'logo_url' => $t['logo_object_id'] ? url('ngo/organization/logo') : null,
    'theme' => $ngoTheme,
    'home' => '/ngo',
    'role_label' => $ngoLabel,
    'banner' => $banner,
    'search' => can('users.view') ? ['action' => 'ngo/users', 'placeholder' => 'Search users'] : null,
    'nav' => [
        ['url' => '/ngo', 'label' => 'Dashboard', 'icon' => 'speedometer2', 'perm' => 'dashboard.view'],
        ['section' => 'Team'],
        ['url' => '/ngo/users', 'label' => 'Users', 'icon' => 'people', 'perm' => 'users.view'],
        ['url' => '/ngo/roles', 'label' => 'Roles & permissions', 'icon' => 'shield-lock', 'perm' => 'roles.view'],
        ['section' => 'Organization'],
        ['url' => '/ngo/organization', 'label' => 'Organization', 'icon' => 'building', 'perm' => 'organization.view'],
        ['url' => '/ngo/subscription', 'label' => 'Subscription', 'icon' => 'credit-card', 'perm' => 'subscription.view'],
        ['url' => '/ngo/usage', 'label' => 'Usage', 'icon' => 'bar-chart', 'perm' => 'usage.view'],
        ['url' => '/ngo/audit', 'label' => 'Audit log', 'icon' => 'journal-text', 'perm' => 'audit.view'],
    ],
];
include __DIR__ . '/../partials/app_shell.php';
