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
        ['tenants.users.reset',      'platform', 'tenants',       'Reset NGO users\' passwords (reset link or temporary password)'],
        ['tenants.impersonate',      'platform', 'tenants',       'Sign in to an NGO as its Super Admin (audited support session)'],
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
        ['certificate_templates.view',   'platform', 'certificates', 'View certificate templates'],
        ['certificate_templates.manage', 'platform', 'certificates', 'Design certificate templates and assign them to NGOs'],
        ['platform.email.view',      'platform', 'email',         'View the platform email log'],
        ['platform.email.manage',    'platform', 'email',         'Change email (SMTP) settings and retry emails'],

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
        ['people.view',              'tenant', 'people',       'View members, volunteers and interns'],
        ['people.create',            'tenant', 'people',       'Register members, volunteers and interns'],
        ['people.edit',              'tenant', 'people',       'Edit member details and photos'],
        ['people.status',            'tenant', 'people',       'Approve, deactivate or complete members'],
        ['people.export',            'tenant', 'people',       'Export member lists (CSV)'],
        ['people.categories',        'tenant', 'people',       'Manage member categories'],
        ['people.id_cards',          'tenant', 'people',       'Download member ID cards'],
        ['people.documents',         'tenant', 'documents',    'View and upload identity documents'],
        ['people.verify',            'tenant', 'documents',    'Verify or reject Aadhaar / identity'],
        ['salary.view',              'tenant', 'salary',       'View and download salary / stipend slips'],
        ['salary.manage',            'tenant', 'salary',       'Create salary / stipend slips'],
        ['certificates.view',        'tenant', 'certificates', 'View and download issued certificates'],
        ['certificates.issue',       'tenant', 'certificates', 'Issue certificates'],
        ['certificates.revoke',      'tenant', 'certificates', 'Revoke certificates'],
        ['receipts.view',            'tenant', 'receipts',     'View and download 80G receipts'],
        ['receipts.create',          'tenant', 'receipts',     'Issue 80G receipts'],
        ['receipts.cancel',          'tenant', 'receipts',     'Cancel 80G receipts'],
        ['receipts.settings',        'tenant', 'receipts',     'Edit 80G details and the receipt template'],
        ['receipts.export',          'tenant', 'receipts',     'Export receipts and Form 10BD data (CSV)'],
        ['emails.view',              'tenant', 'email',        'View the NGO email log'],
        ['emails.manage',            'tenant', 'email',        'Email settings, automatic emails and retries'],
    ],

    // Module each tenant permission belongs to in config/modules.php. A permission
    // only takes effect when its module is core or enabled on the NGO's plan.
    // A list means "any of these modules".
    'permission_modules' => [
        'users.export' => 'data_export',
        'audit.view'   => 'audit_logs',
        'audit.export' => 'audit_logs',
        'people.view'       => ['members', 'volunteers', 'interns'],
        'people.create'     => ['members', 'volunteers', 'interns'],
        'people.edit'       => ['members', 'volunteers', 'interns'],
        'people.status'     => ['members', 'volunteers', 'interns'],
        'people.export'     => ['members', 'volunteers', 'interns'],
        'people.categories' => ['members', 'volunteers', 'interns'],
        'people.id_cards'   => ['members', 'volunteers', 'interns'],
        'people.documents'  => 'documents',
        'people.verify'     => 'documents',
        'salary.view'       => ['members', 'volunteers', 'interns'],
        'salary.manage'     => ['members', 'volunteers', 'interns'],
        'certificates.view'   => 'certificates',
        'certificates.issue'  => 'certificates',
        'certificates.revoke' => 'certificates',
        'receipts.view'     => 'certificates_80g',
        'receipts.create'   => 'certificates_80g',
        'receipts.cancel'   => 'certificates_80g',
        'receipts.settings' => 'certificates_80g',
        'receipts.export'   => 'certificates_80g',
        'emails.view'       => 'email_automation',
        'emails.manage'     => 'email_automation',
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
                'platform.usage.view', 'platform.audit.view', 'certificate_templates.view', 'platform.email.view',
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
                'people.view', 'people.create', 'people.edit', 'people.status', 'people.export', 'people.categories',
                'people.id_cards', 'people.documents', 'salary.view', 'certificates.view', 'certificates.issue',
                'receipts.view', 'receipts.create', 'emails.view',
            ],
        ],
        'ngo_operations' => [
            'name' => 'NGO Operations',
            'description' => 'Performs explicitly permitted day-to-day tasks',
            'locked' => false,
            'permissions' => ['dashboard.view', 'organization.view', 'people.view'],
        ],
    ],
];
