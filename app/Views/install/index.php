<h1 class="h4 mb-1">Install</h1>
<p class="text-body-secondary small">Creates the database tables, default roles and permissions, and the first Fameweb Super Admin. This page disappears once installation is complete.</p>
<ul class="list-group list-group-flush small mb-3">
  <?php foreach ($requirements as $label => $ok): ?>
    <li class="list-group-item d-flex justify-content-between px-0">
      <span><?= e($label) ?></span>
      <?= $ok ? '<span class="text-success"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> OK</span>' : '<span class="text-danger"><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Missing</span>' ?>
    </li>
  <?php endforeach; ?>
  <li class="list-group-item d-flex justify-content-between px-0">
    <span>Database connection</span>
    <?= $dbError === null && $requirements['.env file present'] ? '<span class="text-success"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> OK</span>' : '<span class="text-danger"><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Failed</span>' ?>
  </li>
</ul>
<?php if ($dbError): ?><div class="alert alert-danger small"><?= e($dbError) ?></div><?php endif; ?>
<?php if (!$ready): ?>
  <div class="alert alert-warning small">Fix the items above (see README, "Installation"), then reload this page.</div>
<?php else: ?>
<form method="post" action="<?= e(url('install')) ?>" class="needs-validation" novalidate data-loading>
  <?= csrf_field() ?>
  <?= form_input('install_token', 'Installation token (INSTALL_TOKEN from .env)', '', 'password', ['required' => true, 'autocomplete' => 'off']) ?>
  <hr>
  <h2 class="h6">First Fameweb Super Admin</h2>
  <?= form_input('name', 'Full name', '', 'text', ['required' => true, 'maxlength' => 120]) ?>
  <?= form_input('email', 'Email address', '', 'email', ['required' => true, 'autocomplete' => 'username']) ?>
  <?php include __DIR__ . '/../partials/password_fields.php'; ?>
  <button class="btn btn-primary w-100" type="submit">Install</button>
</form>
<?php endif; ?>
