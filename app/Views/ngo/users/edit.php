<?php
$heading = 'Edit user'; $crumbs = ['Users' => 'ngo/users', $u['name'] => 'ngo/users/' . $u['id']];
include __DIR__ . '/../../partials/page_header.php';
?>
<form method="post" action="<?= e(url('ngo/users/' . $u['id'])) ?>" class="card needs-validation" novalidate data-loading style="max-width:680px">
  <div class="card-body">
    <?= csrf_field() ?>
    <?= form_input('name', 'Full name', $u['name'], 'text', ['required' => true, 'maxlength' => 120]) ?>
    <?= form_input('email', 'Email (sign-in)', $u['email'], 'email', ['required' => true, 'maxlength' => 190, 'help' => 'Changing the email signs the user out everywhere.']) ?>
    <?= form_input('phone', 'Phone', $u['phone'] ?? '', 'tel', ['maxlength' => 30]) ?>
    <button class="btn btn-primary" type="submit">Save</button>
    <a class="btn btn-light" href="<?= e(url('ngo/users/' . $u['id'])) ?>">Cancel</a>
  </div>
</form>
