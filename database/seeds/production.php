<?php
declare(strict_types=1);
// Production seed: permission catalogue, platform roles and default platform settings.
// Run automatically by the installer and by bin/migrate.php. Contains no users or demo data.
return static function (): void {
    App\Services\RbacSeeder::sync();
    App\Services\SettingsService::seedDefaults();
};
