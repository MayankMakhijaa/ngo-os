<?php
declare(strict_types=1);

namespace App\Controllers\Platform;

use App\Core\Response;
use App\Services\AuditLogger;
use App\Services\Migrator;
use App\Services\SettingsService;

final class SettingsController extends PlatformController
{
    public function index(): string
    {
        return $this->view('platform/settings', [
            'title' => 'Platform settings',
            'settings' => SettingsService::all(),
            'pending' => Migrator::pending(),
            'applied' => Migrator::applied(),
            'env' => [
                'Environment' => (string) config('app.env'),
                'Debug mode' => config('app.debug') ? 'ON (turn off in production)' : 'Off',
                'PHP version' => PHP_VERSION,
                'Mail driver' => \App\Services\MailSettings::platform()['driver'] . ' (change in Email settings)',
                'Encryption key (APP_KEY)' => \App\Support\Crypto::available() ? 'Set' : 'NOT SET: run php bin/generate-key.php and add it to .env',
                'Session idle timeout' => config('security.session.idle_minutes') . ' minutes',
                'Login lockout' => config('security.login.max_attempts_per_email') . ' attempts / ' . config('security.login.decay_minutes') . ' minutes',
                'Install token still set' => strlen((string) config('app.install_token')) > 0 ? 'Yes (remove it from .env)' : 'No',
            ],
        ]);
    }

    public function update(): Response
    {
        $data = $this->validate([
            'platform_name' => 'required|max:100',
            'support_email' => 'email',
            'default_trial_days' => 'required|integer|min_value:0|max_value:365',
            'subscription_grace_days' => 'required|integer|min_value:0|max_value:90',
            'usage_warning_percent' => 'required|integer|min_value:50|max_value:99',
            'invite_ttl_hours' => 'required|integer|min_value:1|max_value:336',
            'reset_ttl_minutes' => 'required|integer|min_value:10|max_value:1440',
        ], 'admin/settings', [
            'default_trial_days' => 'Default trial days', 'subscription_grace_days' => 'Grace period',
            'usage_warning_percent' => 'Usage warning threshold', 'invite_ttl_hours' => 'Invitation validity',
            'reset_ttl_minutes' => 'Reset link validity',
        ]);
        $data['allow_password_reset'] = $this->request->str('allow_password_reset') === '1' ? '1' : '0';
        $before = SettingsService::all();
        $changes = [];
        $security = [];
        foreach ($data as $k => $v) {
            if (!array_key_exists($k, SettingsService::DEFAULTS) || (string) $before[$k] === (string) $v) {
                continue;
            }
            SettingsService::set($k, (string) $v, (int) $this->user()['id']);
            if (in_array($k, SettingsService::SECURITY_KEYS, true)) {
                $security[$k] = ['from' => $before[$k], 'to' => $v];
            } else {
                $changes[$k] = ['from' => $before[$k], 'to' => $v];
            }
        }
        if ($changes) {
            AuditLogger::log('settings.updated', 'system_settings', null, ['changes' => $changes], null);
        }
        if ($security) {
            AuditLogger::log('settings.security_updated', 'system_settings', null, ['changes' => $security], null);
        }
        return $this->redirect('admin/settings', 'success', ($changes || $security) ? 'Settings saved.' : 'No changes to save.');
    }

    public function migrate(): Response
    {
        $done = Migrator::migrate();
        if ($done) {
            AuditLogger::log('system.migrated', 'schema', null, ['versions' => $done], null);
        }
        return $this->redirect('admin/settings', 'success', $done ? 'Applied: ' . implode(', ', $done) : 'The database schema is already up to date.');
    }
}
