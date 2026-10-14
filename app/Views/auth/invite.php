<h1 class="h4 mb-1">Activate your account</h1>
<?php if (!$valid): ?>
  <div class="alert alert-warning mt-3">This invitation link is invalid, already used or expired. Ask your administrator for a new one.</div>
  <a class="btn btn-outline-primary w-100" href="<?= e(url('login')) ?>">Go to sign in</a>
<?php else: ?>
  <p class="text-body-secondary small mb-4">Welcome, <strong><?= e($name) ?></strong>. Choose a password for <strong><?= e($email) ?></strong>. This link works only once.</p>
  <form method="post" action="<?= e(url('invite/' . $token)) ?>" class="needs-validation" novalidate data-loading>
    <?= csrf_field() ?>
    <?php include __DIR__ . '/../partials/password_fields.php'; ?>
    <button class="btn btn-primary w-100" type="submit">Activate account</button>
  </form>
<?php endif; ?>
