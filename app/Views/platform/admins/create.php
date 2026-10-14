<?php
$heading = 'Add Fameweb administrator'; $crumbs = ['Administrators' => 'admin/admins'];
include __DIR__ . '/../../partials/page_header.php';
$opts = []; foreach ($roles as $r) { $opts[$r['id']] = $r['name'] . ' — ' . $r['description']; }
?>
<form method="post" action="<?= e(url('admin/admins')) ?>" class="card needs-validation" novalidate data-loading style="max-width:640px">
  <div class="card-body">
    <?= csrf_field() ?>
    <?= form_input('name', 'Full name', '', 'text', ['required' => true, 'maxlength' => 120]) ?>
    <?= form_input('email', 'Email', '', 'email', ['required' => true]) ?>
    <?= form_select('role_id', 'Role', $opts, '', ['required' => true, 'placeholder' => 'Choose a role']) ?>
    <p class="small text-body-secondary">They receive a single-use activation link and choose their own password.</p>
    <button class="btn btn-primary" type="submit">Send invitation</button>
    <a class="btn btn-light" href="<?= e(url('admin/admins')) ?>">Cancel</a>
  </div>
</form>
