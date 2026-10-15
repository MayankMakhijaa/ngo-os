<?php
declare(strict_types=1);

namespace App\Controllers\Platform;

use App\Core\Paginator;
use App\Core\Response;
use App\Exceptions\DomainException;
use App\Services\AuditLogger;
use App\Services\EmailService;
use App\Services\MailSettings;
use App\Support\Crypto;

/** Platform email log (all NGOs and platform emails), retries, queue processing and SMTP settings. */
final class EmailController extends PlatformController
{
    public function index(): string
    {
        $f = ['q' => $this->request->str('q'), 'status' => $this->request->str('status'), 'category' => $this->request->str('category')];
        $where = ['1 = 1'];
        $params = [];
        if ($f['q'] !== '') {
            $where[] = '(o.to_email LIKE :q OR o.subject LIKE :q2 OR t.name LIKE :q3)';
            $params += ['q' => "%{$f['q']}%", 'q2' => "%{$f['q']}%", 'q3' => "%{$f['q']}%"];
        }
        if (in_array($f['status'], ['queued', 'sending', 'sent', 'failed'], true)) {
            $where[] = 'o.status = :status';
            $params['status'] = $f['status'];
        }
        if (preg_match('/^[a-z_]{1,40}$/', $f['category'])) {
            $where[] = 'o.category = :cat';
            $params['cat'] = $f['category'];
        }
        $from = ' FROM email_outbox o LEFT JOIN tenants t ON t.id = o.tenant_id WHERE ' . implode(' AND ', $where);
        return $this->view('platform/emails/index', [
            'title' => 'Email log',
            'page' => new Paginator(
                'SELECT o.id, o.category, o.to_email, o.to_name, o.subject, o.status, o.attempts, o.max_attempts, o.next_attempt_at, o.last_error, o.sent_at, o.created_at, t.name AS tenant_name'
                . $from . ' ORDER BY o.id DESC',
                'SELECT COUNT(*)' . $from, $params, $this->request->int('page', 1)
            ),
            'f' => $f,
            'stats' => EmailService::stats(null),
            'base' => 'admin/emails',
            'platform' => MailSettings::platform(),
        ]);
    }

    public function show(): string
    {
        $row = EmailService::find((int) $this->request->param('id'), null) ?? $this->notFound('email');
        return $this->view('ngo/emails/show', [
            'title' => 'Email to ' . $row['to_email'],
            'row' => $row,
            'body' => EmailService::displayBody($row),
            'attempts' => EmailService::attempts((int) $row['id']),
            'base' => 'admin/emails',
            'canRetry' => can('platform.email.manage'),
        ]);
    }

    public function retry(): Response
    {
        $id = (int) $this->request->param('id');
        try {
            $status = EmailService::retry($id, null, $this->user());
        } catch (DomainException $e) {
            return $this->redirect('admin/emails/' . $id, 'error', $e->getMessage());
        }
        return $this->redirect('admin/emails/' . $id, $status === 'sent' ? 'success' : 'warning',
            $status === 'sent' ? 'Email sent.' : 'The retry did not succeed. See the latest attempt below.');
    }

    /** Deliver due emails now (the same work bin/cron.php does). */
    public function process(): Response
    {
        $r = EmailService::processDue(50);
        AuditLogger::log('email.queue_processed', 'email', null, $r);
        return $this->redirect('admin/emails', 'success', sprintf('Queue processed: %d sent, %d waiting for retry, %d failed.', $r['sent'], $r['queued'], $r['failed']));
    }

    public function settings(): string
    {
        return $this->view('platform/emails/settings', [
            'title' => 'Email settings',
            'effective' => MailSettings::platform(),
            's' => \App\Services\SettingsService::all(),
            'cryptoReady' => Crypto::available(),
        ]);
    }

    public function saveSettings(): Response
    {
        $data = [
            'mail_driver' => $this->request->str('mail_driver'),
            'smtp_host' => $this->request->str('smtp_host'),
            'smtp_port' => $this->request->str('smtp_port') ?: '587',
            'smtp_encryption' => $this->request->str('smtp_encryption'),
            'smtp_username' => $this->request->str('smtp_username'),
            'mail_from_address' => mb_strtolower($this->request->str('mail_from_address')),
            'mail_from_name' => $this->request->str('mail_from_name'),
        ];
        $errors = [];
        if ($data['mail_driver'] !== '' && !isset(MailSettings::DRIVERS[$data['mail_driver']])) {
            $errors['mail_driver'] = 'Choose how emails are sent.';
        }
        if ($data['smtp_host'] !== '' && !preg_match('/^[A-Za-z0-9.\-]{1,190}$/', $data['smtp_host'])) {
            $errors['smtp_host'] = 'Enter a host name such as smtp.hostinger.com.';
        }
        if (!preg_match('/^\d{1,5}$/', $data['smtp_port']) || (int) $data['smtp_port'] < 1 || (int) $data['smtp_port'] > 65535) {
            $errors['smtp_port'] = 'Enter a port between 1 and 65535.';
        }
        if (!isset(MailSettings::ENCRYPTIONS[$data['smtp_encryption']])) {
            $errors['smtp_encryption'] = 'Choose the encryption.';
        }
        if ($data['mail_from_address'] !== '' && !filter_var($data['mail_from_address'], FILTER_VALIDATE_EMAIL)) {
            $errors['mail_from_address'] = 'Enter a valid email address.';
        }
        if ($data['mail_driver'] === 'smtp' && $data['smtp_host'] === '') {
            $errors['smtp_host'] = 'Enter the SMTP host.';
        }
        if (mb_strlen($data['mail_from_name']) > 120 || mb_strlen($data['smtp_username']) > 190) {
            $errors['mail_from_name'] = 'Too long.';
        }
        $raw = $this->request->body['smtp_pass'] ?? null;
        $password = $this->request->str('clear_password') === '1' ? '' : (is_string($raw) && $raw !== '' ? $raw : null);
        if ($password !== null && $password !== '' && !Crypto::available()) {
            $errors['smtp_pass'] = 'Set APP_KEY in .env first (run php bin/generate-key.php), so the password can be stored encrypted.';
        }
        if ($errors) {
            $this->failValidation($errors, 'admin/emails/settings');
        }
        MailSettings::savePlatform($data, $password, $this->user());
        return $this->redirect('admin/emails/settings', 'success', 'Email settings saved.');
    }

    public function test(): Response
    {
        $user = $this->user();
        $to = mb_strtolower($this->request->str('to')) ?: $user['email'];
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->redirect('admin/emails/settings', 'error', 'Enter a valid email address for the test.');
        }
        $id = EmailService::send([
            'to' => $to,
            'subject' => 'Test email from ' . \App\Services\SettingsService::get('platform_name'),
            'body' => "This is a test email sent from Admin > Email settings.\n\nIf you received it, platform emails (invitations, password resets) are being delivered.\n",
            'tenant_id' => null,
            'category' => 'test',
            'max_attempts' => 1,
            'created_by' => (int) $user['id'],
        ]);
        $row = EmailService::find($id, null);
        return $row && $row['status'] === 'sent'
            ? $this->redirect('admin/emails/' . $id, 'success', 'Test email sent to ' . $to . ' via ' . $row['transport'] . '.')
            : $this->redirect('admin/emails/' . $id, 'error', 'The test email failed: ' . ($row['last_error'] ?? 'unknown error'));
    }
}
