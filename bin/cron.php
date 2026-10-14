<?php
declare(strict_types=1);
// Housekeeping and email retries (Hostinger hPanel > Advanced > Cron Jobs). Recommended:
//   */10 * * * *  /usr/bin/php /home/USER/domains/DOMAIN/ngo-saas/bin/cron.php
// Every run delivers queued emails whose retry time has come. Emails are always attempted
// immediately when they are created, so without cron only the automatic retries are lost
// (they can still be retried by hand from the email log). Subscription statuses are also
// refreshed whenever a subscription is read; the purges below run at most once a day.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;
use App\Services\EmailService;
use App\Services\SubscriptionService;

$mail = EmailService::processDue(100);
echo date('c') . " emails_sent={$mail['sent']} emails_waiting={$mail['queued']} emails_failed={$mail['failed']}\n";

// Daily part: only once per day, whatever the cron frequency.
$stamp = storage_path('cache/cron_daily');
if (is_file($stamp) && filemtime($stamp) > time() - 82800) {
    exit(0);
}
@mkdir(dirname($stamp), 0775, true);
@touch($stamp);

$scrubbed = EmailService::scrubOld(30);
$changed = SubscriptionService::refreshAll();
$db = Database::instance();
$attempts = $db->execute('DELETE FROM login_attempts WHERE attempted_at < ?', [date('Y-m-d H:i:s', time() - 30 * 86400)]);
$tokens = $db->execute('DELETE FROM password_reset_tokens WHERE (used_at IS NOT NULL OR expires_at < NOW()) AND created_at < ?', [date('Y-m-d H:i:s', time() - 30 * 86400)]);
$sessions = 0;
foreach (glob(storage_path('sessions/sess_*')) ?: [] as $f) {
    if (filemtime($f) < time() - 86400) { @unlink($f); $sessions++; }
}
echo date('c') . " subscriptions_changed=$changed login_attempts_purged=$attempts tokens_purged=$tokens sessions_purged=$sessions email_bodies_scrubbed=$scrubbed\n";
