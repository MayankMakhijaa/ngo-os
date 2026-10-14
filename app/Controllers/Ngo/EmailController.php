<?php
declare(strict_types=1);

namespace App\Controllers\Ngo;

use App\Core\Paginator;
use App\Core\Response;
use App\Exceptions\DomainException;
use App\Services\EmailService;
use App\Services\MailSettings;
use App\Support\Crypto;

/**
 * Email Automation module: the NGO's email log (only its own emails), manual retries, its own
 * SMTP server (optional) and which automatic emails are switched on.
 */
final class EmailController extends NgoController
{
    public function index(): string
    {
        $tid = $this->tenantId();
        $f = ['q' => $this->request->str('q'), 'status' => $this->request->str('status'), 'category' => $this->request->str('category')];
        $where = ['tenant_id = :tid'];
        $params = ['tid' => $tid];
        if ($f['q'] !== '') {
            $where[] = '(to_email LIKE :q OR subject LIKE :q2)';
            $params += ['q' => "%{$f['q']}%", 'q2' => "%{$f['q']}%"];
        }
        if (in_array($f['status'], ['queued', 'sending', 'sent', 'failed'], true)) {
            $where[] = 'status = :status';
            $params['status'] = $f['status'];
        }
        if (preg_match('/^[a-z_]{1,40}$/', $f['category'])) {
            $where[] = 'category = :cat';
            $params['cat'] = $f['category'];
        }
        $from = ' FROM email_outbox WHERE ' . implode(' AND ', $where);
        return $this->view('ngo/emails/index', [
            'title' => 'Email log',
            'page' => new Paginator(
                'SELECT id, category, to_email, to_name, subject, status, attempts, max_attempts, next_attempt_at, last_error, sent_at, created_at' . $from . ' ORDER BY id DESC',
                'SELECT COUNT(*)' . $from, $params, $this->request->int('page', 1)
            ),
            'f' => $f,
            'stats' => EmailService::stats($tid),
            'base' => 'ngo/emails',
        ]);
    }

    public function show(): string
    {
        $row = EmailService::find((int) $this->request->param('id'), $this->tenantId()) ?? $this->notFound('email');
        return $this->view('ngo/emails/show', [
            'title' => 'Email to ' . $row['to_email'],
            'row' => $row,
            'body' => EmailService::displayBody($row),
            'attempts' => EmailService::attempts((int) $row['id']),
            'base' => 'ngo/emails',
            'canRetry' => can('emails.manage'),
        ]);
    }

    public function retry(): Response
    {
        $id = (int) $this->request->param('id');
        try {
            $status = EmailService::retry($id, $this->tenantId(), $this->user());
        } catch (DomainException $e) {
            return $this->redirect('ngo/emails/' . $id, 'error', $e->getMessage());
        }
        return $this->redirect('ngo/emails/' . $id, $status === 'sent' ? 'success' : 'warning',
            $status === 'sent' ? 'Email sent.' : 'The retry did not succeed. See the latest attempt below.');
    }

    public function settings(): string
    {
        $tid = $this->tenantId();
        return $this->view('ngo/emails/settings', [
            'title' => 'Email settings',
            's' => MailSettings::tenantRaw($tid),
            'effective' => MailSettings::forTenant($tid),
            'cryptoReady' => Crypto::available(),
        ]);
    }

    public function saveSettings(): Response
    {
        $tid = $this->tenantId();
        $data = [
            'mail_smtp_enabled' => $this->request->str('mail_smtp_enabled') === '1' ? '1' : '0',
            'mail_smtp_host' => $this->request->str('mail_smtp_host'),
            'mail_smtp_port' => $this->request->str('mail_smtp_port') ?: '587',
            'mail_smtp_encryption' => $this->request->str('mail_smtp_encryption'),
            'mail_smtp_username' => $this->request->str('mail_smtp_username'),
            'mail_from_address' => mb_strtolower($this->request->str('mail_from_address')),
            'mail_from_name' => $this->request->str('mail_from_name'),
            'mail_reply_to' => mb_strtolower($this->request->str('mail_reply_to')),
        ];
        foreach (array_keys(MailSettings::AUTOMATIONS) as $k) {
            $data[$k] = $this->request->str($k) === '1' ? '1' : '0';
        }
        $errors = [];
        if ($data['mail_smtp_host'] !== '' && !preg_match('/^[A-Za-z0-9.\-]{1,190}$/', $data['mail_smtp_host'])) {
            $errors['mail_smtp_host'] = 'Enter a host name such as smtp.hostinger.com.';
        }
        if (!preg_match('/^\d{1,5}$/', $data['mail_smtp_port']) || (int) $data['mail_smtp_port'] < 1 || (int) $data['mail_smtp_port'] > 65535) {
            $errors['mail_smtp_port'] = 'Enter a port between 1 and 65535.';
        }
        if (!isset(MailSettings::ENCRYPTIONS[$data['mail_smtp_encryption']])) {
            $errors['mail_smtp_encryption'] = 'Choose the encryption.';
        }
        foreach (['mail_from_address', 'mail_reply_to'] as $k) {
            if ($data[$k] !== '' && !filter_var($data[$k], FILTER_VALIDATE_EMAIL)) {
                $errors[$k] = 'Enter a valid email address.';
            }
        }
        if (mb_strlen($data['mail_from_name']) > 120 || mb_strlen($data['mail_smtp_username']) > 190) {
            $errors['mail_from_name'] = 'Too long.';
        }
        if ($data['mail_smtp_enabled'] === '1' && ($data['mail_smtp_host'] === '' || $data['mail_from_address'] === '')) {
            $errors['mail_smtp_host'] = 'To use your own SMTP server, enter its host and a From address.';
        }
        if ($errors) {
            $this->failValidation($errors, 'ngo/emails/settings');
        }
        $password = $this->request->str('clear_password') === '1' ? '' : (is_string($this->request->body['mail_smtp_pass'] ?? null) && $this->request->body['mail_smtp_pass'] !== '' ? $this->request->body['mail_smtp_pass'] : null);
        try {
            MailSettings::saveTenant($tid, $data, $password, $this->user());
        } catch (DomainException $e) {
            $this->failValidation([], 'ngo/emails/settings', $e->getMessage());
        }
        return $this->redirect('ngo/emails/settings', 'success', 'Email settings saved.');
    }

    public function test(): Response
    {
        $user = $this->user();
        $to = mb_strtolower($this->request->str('to')) ?: $user['email'];
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->redirect('ngo/emails/settings', 'error', 'Enter a valid email address for the test.');
        }
        $id = EmailService::send([
            'to' => $to,
            'subject' => 'Test email from your NGO workspace',
            'body' => "This is a test email sent from the email settings page.\n\nIf you received it, your organization's emails are being delivered.\n",
            'tenant_id' => $this->tenantId(),
            'category' => 'test',
            'max_attempts' => 1,
            'created_by' => (int) $user['id'],
        ]);
        $row = EmailService::find($id, $this->tenantId());
        return $row && $row['status'] === 'sent'
            ? $this->redirect('ngo/emails/' . $id, 'success', 'Test email sent to ' . $to . ' via ' . $row['transport'] . '.')
            : $this->redirect('ngo/emails/' . $id, 'error', 'The test email failed: ' . ($row['last_error'] ?? 'unknown error'));
    }
}
