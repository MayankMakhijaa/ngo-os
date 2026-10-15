<?php
/** @var array $s @var array $effective @var bool $cryptoReady */
use App\Services\MailSettings;

$writable = \App\Services\SubscriptionService::isWritable($subscription);
$heading = 'Email settings';
$subtitle = 'Automatic emails and, optionally, your own SMTP server';
$crumbs = ['Email log' => 'ngo/emails'];
include __DIR__ . '/../../partials/page_header.php';
?>
<div class="alert alert-light border small">
  <?php if ($effective['source'] === 'tenant'): ?>
    Your emails are sent through <strong>your own SMTP server</strong> (<?= e($effective['host']) ?>) from <strong><?= e($effective['from']) ?></strong>.
  <?php else: ?>
    Your emails are sent through the Fameweb platform server as <strong><?= e($effective['from_name']) ?> &lt;<?= e($effective['from']) ?>&gt;</strong><?= $effective['reply_to'] ? ', with replies going to <strong>' . e($effective['reply_to']) . '</strong>' : '' ?>.
  <?php endif; ?>
  Failed deliveries are retried automatically (up to <?= (int) config('mail.max_attempts', 5) ?> attempts) and every attempt is listed in the email log.
</div>
<form method="post" action="<?= e(url('ngo/emails/settings')) ?>" class="needs-validation" novalidate data-loading autocomplete="off">
  <?= csrf_field() ?>
  <div class="row g-4">
    <div class="col-lg-5">
      <div class="card mb-4"><div class="card-body">
        <h2 class="h6 mb-3">Automatic emails</h2>
        <?php foreach (MailSettings::AUTOMATIONS as $k => $label): ?>
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" role="switch" name="<?= e($k) ?>" value="1" id="<?= e($k) ?>"<?= $s[$k] === '1' ? ' checked' : '' ?>>
            <label class="form-check-label small" for="<?= e($k) ?>"><?= e($label) ?></label>
          </div>
        <?php endforeach; ?>
      </div></div>
      <div class="card"><div class="card-body">
        <h2 class="h6 mb-3">Sender</h2>
        <?= form_input('mail_from_name', 'Sender name', $s['mail_from_name'], 'text', ['maxlength' => 120, 'placeholder' => $effective['from_name']]) ?>
        <?= form_input('mail_reply_to', 'Reply-to address', $s['mail_reply_to'], 'email', ['help' => 'Where replies go. Defaults to your notification email.', 'wrap' => 'mb-0']) ?>
      </div></div>
    </div>
    <div class="col-lg-7">
      <div class="card"><div class="card-body">
        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" role="switch" name="mail_smtp_enabled" value="1" id="mail_smtp_enabled"<?= $s['mail_smtp_enabled'] === '1' ? ' checked' : '' ?>>
          <label class="form-check-label fw-medium" for="mail_smtp_enabled">Send from our own domain (own SMTP server)</label>
        </div>
        <p class="small text-body-secondary">For example your Hostinger mailbox: host <code>smtp.hostinger.com</code>, port 465 with SSL, your full email address as username.</p>
        <div class="row">
          <div class="col-sm-8"><?= form_input('mail_smtp_host', 'SMTP host', $s['mail_smtp_host'], 'text', ['maxlength' => 190]) ?></div>
          <div class="col-sm-4"><?= form_input('mail_smtp_port', 'Port', $s['mail_smtp_port'], 'number', ['min' => 1, 'max' => 65535]) ?></div>
          <div class="col-sm-6"><?= form_select('mail_smtp_encryption', 'Encryption', MailSettings::ENCRYPTIONS, $s['mail_smtp_encryption']) ?></div>
          <div class="col-sm-6"><?= form_input('mail_from_address', 'From address', $s['mail_from_address'], 'email', ['maxlength' => 190]) ?></div>
          <div class="col-sm-6"><?= form_input('mail_smtp_username', 'Username', $s['mail_smtp_username'], 'text', ['maxlength' => 190, 'autocomplete' => 'off']) ?></div>
          <div class="col-sm-6"><?= form_input('mail_smtp_pass', 'Password', '', 'password', ['autocomplete' => 'new-password',
              'placeholder' => $s['mail_smtp_password_enc'] !== '' ? 'Saved (leave blank to keep)' : '',
              'help' => $cryptoReady ? 'Stored encrypted; never shown again.' : 'Cannot be stored: encryption (APP_KEY) is not set up on the server.']) ?></div>
        </div>
        <?php if ($s['mail_smtp_password_enc'] !== ''): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="clear_password" value="1" id="clear_password"><label class="form-check-label small" for="clear_password">Remove the saved password</label></div><?php endif; ?>
      </div></div>
    </div>
  </div>
  <?php if ($writable): ?><div class="mt-3"><button class="btn btn-primary" type="submit">Save settings</button></div><?php endif; ?>
</form>
<?php if ($writable): ?>
<form method="post" action="<?= e(url('ngo/emails/test')) ?>" class="card mt-4" data-loading>
  <div class="card-body row g-2 align-items-end">
    <?= csrf_field() ?>
    <div class="col-sm-8"><?= form_input('to', 'Send a test email to', auth_user()['email'], 'email', ['wrap' => 'mb-0']) ?></div>
    <div class="col-sm-4"><button class="btn btn-outline-primary w-100" type="submit">Send test email</button></div>
  </div>
</form>
<?php endif; ?>
