<?php
declare(strict_types=1);
// CLI installer (alternative to the web installer, for hosts with SSH access).
// Usage: php bin/install.php "Full Name" admin@example.com
// The password is read interactively and never appears in shell history.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../bootstrap/app.php';

use App\Services\Installer;
use App\Services\PasswordService;

if (Installer::isInstalled()) {
    fwrite(STDERR, "Already installed (storage/installed.lock exists).\n");
    exit(1);
}
[$_, $name, $email] = array_pad($argv, 3, null);
if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php bin/install.php \"Full Name\" admin@example.com\n");
    exit(1);
}
if ($err = Installer::databaseReachable()) {
    fwrite(STDERR, $err . "\n");
    exit(1);
}
$password = getenv('INSTALL_ADMIN_PASSWORD') ?: null; // for automated test setups only
if ($password === null) {
    echo 'Password for ' . $email . ': ';
    system('stty -echo 2>/dev/null');
    $password = trim((string) fgets(STDIN));
    system('stty echo 2>/dev/null');
    echo "\nConfirm password: ";
    system('stty -echo 2>/dev/null');
    $confirm = trim((string) fgets(STDIN));
    system('stty echo 2>/dev/null');
    echo "\n";
} else {
    $confirm = $password;
}
if ($errors = PasswordService::validate($password, $email, $confirm)) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
$id = Installer::install(['name' => $name, 'email' => $email, 'password' => $password]);
echo "Installed. Fameweb Super Admin #$id created for $email.\n";
echo "Remove INSTALL_TOKEN from .env now.\n";
