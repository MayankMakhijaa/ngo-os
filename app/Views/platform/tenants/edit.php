<?php
$heading = 'Edit NGO'; $subtitle = $t['name'] . ' · ' . $t['reference_code'];
$crumbs = ['NGOs' => 'admin/ngos', $t['name'] => 'admin/ngos/' . $t['id']];
include __DIR__ . '/../../partials/page_header.php';
?>
<form method="post" action="<?= e(url('admin/ngos/' . $t['id'])) ?>" enctype="multipart/form-data" class="needs-validation" novalidate data-loading>
  <?= csrf_field() ?>
  <div class="card"><div class="card-body">
    <?php include __DIR__ . '/_profile_fields.php'; ?>
    <p class="small text-body-secondary mb-0">Account status is changed from the NGO page with the Activate / Suspend / Deactivate actions so each change is confirmed and audited.</p>
  </div></div>
  <div class="d-flex gap-2 mt-3">
    <button class="btn btn-primary" type="submit">Save changes</button>
    <a class="btn btn-light" href="<?= e(url('admin/ngos/' . $t['id'])) ?>">Cancel</a>
  </div>
</form>
