<h1 class="h4 mb-1">Forgot your password?</h1>
<?php if (!$enabled): ?>
  <div class="alert alert-info mt-3">Self-service password reset is turned off. Please ask your administrator to send you a reset link.</div>
<?php else: ?>
<p class="text-body-secondary small mb-4">Enter your email and we'll send a single-use link to choose a new password.</p>
<form method="post" action="<?= e(url('forgot-password')) ?>" class="needs-validation" novalidate data-loading>
  <?= csrf_field() ?>
  <?= form_input('email', 'Email address', '', 'email', ['required' => true, 'autocomplete' => 'username', 'autofocus' => true]) ?>
  <button class="btn btn-primary w-100" type="submit">Send reset link</button>
</form>
<?php endif; ?>
<div class="text-center mt-3"><a class="small" href="<?= e(url('login')) ?>">Back to sign in</a></div>
