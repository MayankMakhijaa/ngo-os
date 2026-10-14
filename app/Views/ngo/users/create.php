<?php
$heading = 'Add user'; $crumbs = ['Users' => 'ngo/users'];
include __DIR__ . '/../../partials/page_header.php';
$opts = []; foreach ($roles as $r) { $opts[$r['id']] = $r['name'] . ($r['description'] ? ' — ' . $r['description'] : ''); }
?>
<?php if ($usage['state'] === 'reached'): ?>
  <div class="alert alert-warning">Your plan allows <?= e($usage['limit_display']) ?> users and you have <?= e($usage['used_display']) ?>. Adding a user will be blocked until you deactivate someone or Fameweb upgrades your plan.</div>
<?php endif; ?>
<form method="post" action="<?= e(url('ngo/users')) ?>" class="card needs-validation" novalidate data-loading style="max-width:680px">
  <div class="card-body">
    <?= csrf_field() ?>
    <?= form_input('name', 'Full name', '', 'text', ['required' => true, 'maxlength' => 120]) ?>
    <?= form_input('email', 'Email (sign-in)', '', 'email', ['required' => true, 'maxlength' => 190]) ?>
    <?= form_input('phone', 'Phone', '', 'tel', ['maxlength' => 30]) ?>
    <?= form_select('role_id', 'Role', $opts, '', ['required' => true, 'placeholder' => 'Choose a role', 'help' => 'Only roles whose permissions you hold yourself are listed. You can fine-tune permissions after creating the user.']) ?>
    <p class="small text-body-secondary">The user receives a single-use invitation link and chooses their own password.</p>
    <button class="btn btn-primary" type="submit">Create and invite</button>
    <a class="btn btn-light" href="<?= e(url('ngo/users')) ?>">Cancel</a>
  </div>
</form>
