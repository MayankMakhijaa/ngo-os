<?php
// NGO Operations layout (also used by custom roles): minimal day-to-day navigation.
$ngoTheme = 'theme-ngo theme-ngo-ops';
$ngoLabel = auth_user()['role_name'] ?? 'NGO Operations';
include __DIR__ . '/ngo_base.php';
