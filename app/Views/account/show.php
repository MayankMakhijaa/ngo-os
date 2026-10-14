<?php $heading = 'My account'; $subtitle = 'Your profile and password'; include __DIR__ . '/../partials/page_header.php'; ?>
<div class="row g-4">
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-body">
      <h2 class="h6 mb-3">Profile</h2>
      <form method="post" action="<?= e(url('account/profile')) ?>" class="needs-validation" novalidate data-loading>
        <?= csrf_field() ?>
        <?= form_input('name', 'Full name', $me['name'], 'text', ['required' => true, 'maxlength' => 120]) ?>
        <?= form_input('email_display', 'Email (sign-in)', $me['email'], 'email', ['readonly' => true, 'help' => 'Ask an administrator to change your sign-in email.']) ?>
        <?= form_input('phone', 'Phone', $me['phone'] ?? '', 'tel', ['maxlength' => 30]) ?>
        <dl class="row small mb-3">
          <dt class="col-5 text-body-secondary">Role</dt><dd class="col-7"><?= e($me['role_name'] ?? '—') ?></dd>
          <dt class="col-5 text-body-secondary">Last sign-in</dt><dd class="col-7"><?= e(format_datetime($me['last_login_at'])) ?></dd>
          <dt class="col-5 text-body-secondary">Password changed</dt><dd class="col-7"><?= e(format_datetime($me['password_changed_at'])) ?></dd>
        </dl>
        <button class="btn btn-primary" type="submit">Save profile</button>
      </form>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-body">
      <h2 class="h6 mb-3">Change password</h2>
      <form method="post" action="<?= e(url('account/password')) ?>" class="needs-validation" novalidate data-loading>
        <?= csrf_field() ?>
        <?= form_input('current_password', 'Current password', '', 'password', ['required' => true, 'autocomplete' => 'current-password']) ?>
        <?php include __DIR__ . '/../partials/password_fields.php'; ?>
        <button class="btn btn-primary" type="submit">Change password</button>
        <p class="form-text mt-2">Changing your password signs you out on every other device.</p>
      </form>
    </div></div>
  </div>
</div>
