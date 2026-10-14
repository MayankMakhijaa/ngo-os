<h1 class="h4 mb-1">Choose a new password</h1>
<?php if (!$valid): ?>
  <div class="alert alert-warning mt-3">This password reset link is invalid, already used or expired.</div>
  <a class="btn btn-primary w-100" href="<?= e(url('forgot-password')) ?>">Request a new link</a>
<?php else: ?>
  <p class="text-body-secondary small mb-4">For <strong><?= e($email) ?></strong>. All other sessions will be signed out.</p>
  <form method="post" action="<?= e(url('reset-password/' . $token)) ?>" class="needs-validation" novalidate data-loading>
    <?= csrf_field() ?>
    <?php include __DIR__ . '/../partials/password_fields.php'; ?>
    <button class="btn btn-primary w-100" type="submit">Save new password</button>
  </form>
<?php endif; ?>
