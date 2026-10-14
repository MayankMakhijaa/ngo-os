<?php
/** @var array $effective @var array $s @var bool $cryptoReady */
use App\Services\MailSettings;

$heading = 'Email settings';
$subtitle = 'How platform emails (invitations, password resets) and NGO emails are delivered';
$crumbs = ['Email log' => 'admin/emails'];
include __DIR__ . '/../../partials/page_header.php';
?>
<?php if (!$cryptoReady): ?>
  <div class="alert alert-warning small"><strong>APP_KEY is not set.</strong> Run <code>php bin/generate-key.php</code> and add the printed line to <code>.env</code>. Without it, SMTP passwords cannot be saved here, identity documents cannot be encrypted and email bodies are only encoded.</div>
<?php endif; ?>
<div class="alert alert-light border small">
  Currently using <strong><?= e(MailSettings::DRIVERS[$effective['driver']]) ?></strong><?= $effective['driver'] === 'smtp' ? ' via ' . e($effective['host'] . ':' . $effective['port']) : '' ?>, from <strong><?= e($effective['from_name'] . ' <' . $effective['from'] . '>') ?></strong>
  (<?= $effective['source'] === 'settings' ? 'settings saved on this page' : 'MAIL_* values in .env' ?>). Leave "How to send" empty to use the .env values.
  NGOs without their own SMTP server send through these settings with their own name as sender. Queued retries are delivered by <code>bin/cron.php</code>; schedule it every 5 to 15 minutes in hPanel.
</div>
<form method="post" action="<?= e(url('admin/emails/settings')) ?>" class="card needs-validation" novalidate data-loading autocomplete="off">
  <div class="card-body">
    <?= csrf_field() ?>
    <div class="row">
      <div class="col-md-4"><?= form_select('mail_driver', 'How to send', MailSettings::DRIVERS, $s['mail_driver'], ['placeholder' => 'Use .env (' . config('mail.driver') . ')']) ?></div>
      <div class="col-md-5"><?= form_input('smtp_host', 'SMTP host', $s['smtp_host'], 'text', ['maxlength' => 190, 'placeholder' => 'smtp.hostinger.com']) ?></div>
      <div class="col-md-3"><?= form_input('smtp_port', 'Port', $s['smtp_port'] ?: '465', 'number', ['min' => 1, 'max' => 65535]) ?></div>
      <div class="col-md-4"><?= form_select('smtp_encryption', 'Encryption', MailSettings::ENCRYPTIONS, $s['smtp_encryption'] ?: 'ssl') ?></div>
      <div class="col-md-4"><?= form_input('smtp_username', 'Username', $s['smtp_username'], 'text', ['maxlength' => 190, 'autocomplete' => 'off']) ?></div>
      <div class="col-md-4"><?= form_input('smtp_pass', 'Password', '', 'password', ['autocomplete' => 'new-password', 'placeholder' => $s['smtp_password_enc'] !== '' ? 'Saved (leave blank to keep)' : '', 'help' => 'Stored encrypted with APP_KEY; never shown again.']) ?></div>
      <div class="col-md-6"><?= form_input('mail_from_address', 'From address', $s['mail_from_address'], 'email', ['placeholder' => (string) config('mail.from')]) ?></div>
      <div class="col-md-6"><?= form_input('mail_from_name', 'From name', $s['mail_from_name'], 'text', ['maxlength' => 120, 'placeholder' => (string) config('mail.from_name')]) ?></div>
    </div>
    <?php if ($s['smtp_password_enc'] !== ''): ?><div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="clear_password" value="1" id="clear_password"><label class="form-check-label small" for="clear_password">Remove the saved password</label></div><?php endif; ?>
    <button class="btn btn-primary" type="submit">Save settings</button>
  </div>
</form>
<form method="post" action="<?= e(url('admin/emails/test')) ?>" class="card mt-4" data-loading>
  <div class="card-body row g-2 align-items-end">
    <?= csrf_field() ?>
    <div class="col-sm-8"><?= form_input('to', 'Send a test email to', auth_user()['email'], 'email', ['wrap' => 'mb-0']) ?></div>
    <div class="col-sm-4"><button class="btn btn-outline-primary w-100" type="submit">Send test email</button></div>
  </div>
</form>
