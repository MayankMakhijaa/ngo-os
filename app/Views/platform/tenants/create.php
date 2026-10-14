<?php
$heading = 'Register NGO'; $subtitle = 'Creates the workspace, its Super Admin invitation and its subscription in one step';
$crumbs = ['NGOs' => 'admin/ngos'];
include __DIR__ . '/../../partials/page_header.php';
$t = [];
?>
<?php if (!$plans): ?>
  <div class="alert alert-warning">There is no active subscription plan yet. <?php if (can('plans.manage')): ?><a href="<?= e(url('admin/plans/create')) ?>">Create a plan</a> first.<?php endif; ?></div>
<?php else: ?>
<form method="post" action="<?= e(url('admin/ngos')) ?>" enctype="multipart/form-data" class="needs-validation" novalidate data-loading>
  <?= csrf_field() ?>
  <div class="row g-4">
    <div class="col-xl-7">
      <div class="card"><div class="card-body">
        <h2 class="h6 mb-3">Organization</h2>
        <?php include __DIR__ . '/_profile_fields.php'; ?>
        <?= form_select('status', 'Account status', ['active' => 'Active', 'suspended' => 'Suspended (create now, enable later)'], 'active', ['required' => true]) ?>
      </div></div>
    </div>
    <div class="col-xl-5">
      <div class="card mb-4"><div class="card-body">
        <h2 class="h6 mb-1">Super Admin</h2>
        <p class="small text-body-secondary">They receive a single-use link to set their own password. No password is ever emailed.</p>
        <?= form_input('admin_name', 'Full name', '', 'text', ['required' => true, 'maxlength' => 120]) ?>
        <?= form_input('admin_email', 'Email (sign-in)', '', 'email', ['required' => true, 'maxlength' => 190]) ?>
        <?= form_input('admin_phone', 'Phone', '', 'tel', ['maxlength' => 20]) ?>
      </div></div>
      <div class="card"><div class="card-body">
        <h2 class="h6 mb-3">Subscription</h2>
        <?php $planOptions = []; foreach ($plans as $p) { $planOptions[$p['id']] = $p['name'] . ' — ' . format_money($p['monthly_price'], $p['currency']) . '/mo, ' . format_money($p['annual_price'], $p['currency']) . '/yr'; } ?>
        <?= form_select('plan_id', 'Plan', $planOptions, '', ['required' => true, 'placeholder' => 'Choose a plan']) ?>
        <div class="row">
          <div class="col-6"><?= form_select('sub_status', 'Start as', ['trial' => 'Trial', 'active' => 'Active (paid)'], 'trial', ['required' => true]) ?></div>
          <div class="col-6"><?= form_select('billing_cycle', 'Billing cycle', ['monthly' => 'Monthly', 'annual' => 'Annual', 'custom' => 'Custom'], 'monthly', ['required' => true]) ?></div>
          <div class="col-6"><?= form_input('start_date', 'Start date', date('Y-m-d'), 'date', ['required' => true]) ?></div>
          <div class="col-6"><?= form_input('end_date', 'Expiry / renewal date', '', 'date', ['help' => 'Empty = calculated from the trial length or billing cycle']) ?></div>
        </div>
        <?= form_input('price', 'Agreed price (optional)', '', 'number', ['min' => 0, 'step' => '0.01', 'help' => 'Empty = plan price for the cycle (0 during trial)']) ?>
      </div></div>
    </div>
  </div>
  <div class="d-flex gap-2 mt-4">
    <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Register NGO</button>
    <a class="btn btn-light" href="<?= e(url('admin/ngos')) ?>">Cancel</a>
  </div>
</form>
<?php endif; ?>
