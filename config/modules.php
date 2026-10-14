<?php
// Module registry. "core" modules are always available to every NGO.
// Other modules must be enabled on the NGO's subscription plan (plan_modules).
// Modules marked available=false are reserved for future phases: they can be
// switched on in a plan now, but have no screens until they are built.
return [
    'dashboard'        => ['label' => 'Dashboard',               'core' => true,  'available' => true],
    'users'            => ['label' => 'Users & Roles',           'core' => true,  'available' => true],
    'organization'     => ['label' => 'Organization Settings',   'core' => true,  'available' => true],
    'subscription'     => ['label' => 'Subscription & Usage',    'core' => true,  'available' => true],
    'audit_logs'       => ['label' => 'Audit Logs',              'core' => false, 'available' => true],
    'data_export'      => ['label' => 'Data Export (CSV)',       'core' => false, 'available' => true],
    'members'          => ['label' => 'Members',                 'core' => false, 'available' => false],
    'volunteers'       => ['label' => 'Volunteers',              'core' => false, 'available' => false],
    'interns'          => ['label' => 'Interns',                 'core' => false, 'available' => false],
    'documents'        => ['label' => 'Identity Documents',      'core' => false, 'available' => false],
    'certificates'     => ['label' => 'Certificates',            'core' => false, 'available' => false],
    'certificates_80g' => ['label' => '80G Certificates',        'core' => false, 'available' => false],
    'wordpress_sync'   => ['label' => 'WordPress Sync',          'core' => false, 'available' => false],
    'email_automation' => ['label' => 'Email Automation',        'core' => false, 'available' => false],
];
