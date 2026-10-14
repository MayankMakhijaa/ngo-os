<?php
declare(strict_types=1);
// OPTIONAL daily housekeeping (Hostinger hPanel > Advanced > Cron Jobs):
//   0 2 * * *  /usr/bin/php /home/USER/domains/DOMAIN/ngo-saas/bin/cron.php
// The app does not depend on it: subscription statuses are also refreshed whenever
// a subscription is read. This just keeps dashboards and reports accurate for idle NGOs.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;
use App\Services\SubscriptionService;

$changed = SubscriptionService::refreshAll();
$db = Database::instance();
$attempts = $db->execute('DELETE FROM login_attempts WHERE attempted_at < ?', [date('Y-m-d H:i:s', time() - 30 * 86400)]);
$tokens = $db->execute('DELETE FROM password_reset_tokens WHERE (used_at IS NOT NULL OR expires_at < NOW()) AND created_at < ?', [date('Y-m-d H:i:s', time() - 30 * 86400)]);
$sessions = 0;
foreach (glob(storage_path('sessions/sess_*')) ?: [] as $f) {
    if (filemtime($f) < time() - 86400) { @unlink($f); $sessions++; }
}
echo date('c') . " subscriptions_changed=$changed login_attempts_purged=$attempts tokens_purged=$tokens sessions_purged=$sessions\n";
