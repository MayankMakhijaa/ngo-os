<?php
// Permission catalogue and default role templates.
//
// - "platform" permissions can only ever be held by Fameweb (platform) users.
// - "tenant" permissions can only ever be held by NGO users.
// The PermissionService enforces this split, so an NGO role can never carry a
// platform permission even if a row were inserted by mistake.
//
// Adding a future module = add its permissions here, run `php bin/migrate.php`
// (which re-syncs this catalogue), and grant them to roles.
return [
    'permissions' => [
        // ---------------- Platform (Fameweb) ----------------
        ['platform.dashboard',       'platform', 'dashboard',     'View SaaS dashboard'],
        ['tenants.view',             'platform', 'tenants',       'View NGOs, their users and usage'],
        ['tenants.manage',           'platform', 'tenants',       'Register, edit, activate, suspend and deactivate NGOs'],
        ['plans.view',               'platform', 'plans',         'View subscription plans'],
        ['plans.manage',             'platform', 'plans',         'Create, edit, activate and deactivate plans'],
        ['subscriptions.view',       'platform', 'subscriptions', 'View subscriptions and payments'],
        ['subscriptions.manage',     'platform', 'subscriptions', 'Assign and change NGO subscriptions'],
        ['payments.record',          'platform', 'subscriptions', 'Record manual subscription payments'],
        ['platform.usage.view',      'platform', 'usage',         'View platform-wide usage'],
        ['platform.settings',        'platform', 'settings',      'Change platform settings and run schema upgrades'],
        ['platform.audit.view',      'platform', 'audit',         'View system and audit logs'],
        ['platform.audit.export',    'platform', 'audit',         'Export audit logs'],
        ['platform.admins.manage',   'platform', 'admins',        'Manage Fameweb administrator accounts'],

        // ---------------- NGO (tenant) ----------------
        ['dashboard.view',           'tenant', 'dashboard',    'View NGO dashboard'],
        ['users.view',               'tenant', 'users',        'View NGO users'],
        ['users.create',             'tenant', 'users',        'Create and invite NGO users'],
        ['users.edit',               'tenant', 'users',        'Edit NGO user details'],
        ['users.deactivate',         'tenant', 'users',        'Activate and deactivate NGO users'],
        ['users.invite',             'tenant', 'users',        'Send invitations and password-reset links'],
        ['users.assign_roles',       'tenant', 'users',        'Change a user\'s role'],
        ['users.assign_permissions', 'tenant', 'users',        'Grant or deny individual permissions'],
        ['users.export',             'tenant', 'users',        'Export the user list (CSV)'],
        ['roles.view',               'tenant', 'roles',        'View roles and their permissions'],
        ['roles.manage',             'tenant', 'roles',        'Create custom roles and edit role permissions'],
        ['organization.view',        'tenant', 'organization', 'View organization profile and settings'],
        ['organization.edit',        'tenant', 'organization', 'Edit organization profile and settings'],
        ['subscription.view',        'tenant', 'subscription', 'View subscription, payments and plan'],
        ['usage.view',               'tenant', 'subscription', 'View usage against plan limits'],
        ['audit.view',               'tenant', 'audit',        'View the NGO audit log'],
        ['audit.export',             'tenant', 'audit',        'Export the NGO audit log (CSV)'],
    ],

    // Module each tenant permission belongs to in config/modules.php. A permission
    // only takes effect when its module is core or enabled on the NGO's plan.
    'permission_modules' => [
        'users.export' => 'data_export',
        'audit.view'   => 'audit_logs',
        'audit.export' => 'audit_logs',
    ],

    'platform_roles' => [
        'fameweb_super_admin' => [
            'name' => 'Fameweb Super Admin',
            'description' => 'Full platform-level access',
            'locked' => true,
            'permissions' => '*',
        ],
        'fameweb_support' => [
            'name' => 'Fameweb Support',
            'description' => 'Read-only access to NGOs, plans, subscriptions, usage and logs',
            'locked' => false,
            'permissions' => [
                'platform.dashboard', 'tenants.view', 'plans.view', 'subscriptions.view',
                'platform.usage.view', 'platform.audit.view',
            ],
        ],
    ],

    // Copied into every NGO when it is registered, so each NGO can tune
    // Manager / Operations permissions without affecting other NGOs.
    'tenant_role_templates' => [
        'ngo_super_admin' => [
            'name' => 'NGO Super Admin',
            'description' => 'Manages the NGO workspace, users, roles and settings',
            'locked' => true,
            'permissions' => '*',
        ],
        'ngo_manager' => [
            'name' => 'NGO Manager',
            'description' => 'Manages assigned operational work and views reports',
            'locked' => false,
            'permissions' => [
                'dashboard.view', 'users.view', 'users.create', 'users.edit', 'users.invite',
                'users.assign_roles', 'roles.view', 'organization.view', 'subscription.view',
                'usage.view', 'audit.view',
            ],
        ],
        'ngo_operations' => [
            'name' => 'NGO Operations',
            'description' => 'Performs explicitly permitted day-to-day tasks',
            'locked' => false,
            'permissions' => ['dashboard.view', 'organization.view'],
        ],
    ],
];
