<?php
$heading = $current ? 'Change plan' : 'Assign plan'; $subtitle = $t['name'];
$crumbs = ['NGOs' => 'admin/ngos', $t['name'] => 'admin/ngos/' . $t['id']];
include __DIR__ . '/../../partials/page_header.php';
$opts = []; foreach ($plans as $p) { $opts[$p['id']] = $p['name'] . ' — ' . format_money($p['monthly_price'], $p['currency']) . '/mo · ' . format_money($p['annual_price'], $p['currency']) . '/yr'; }
?>
<div class="row g-4">
  <div class="col-lg-7">
    <form method="post" action="<?= e(url('admin/ngos/' . $t['id'] . '/subscription')) ?>" class="card needs-validation" novalidate
          data-confirm="<?= e($current ? 'Change this NGO\'s plan? The current subscription is closed and kept in the history, and the new plan\'s limits apply immediately. No data is deleted.' : 'Assign this plan?') ?>">
      <div class="card-body">
        <?= csrf_field() ?>
        <?= form_select('plan_id', 'New plan', $opts, '', ['required' => true, 'placeholder' => 'Choose a plan']) ?>
        <div class="row">
          <div class="col-sm-6"><?= form_select('status', 'Start as', ['active' => 'Active (paid)', 'trial' => 'Trial'], 'active', ['required' => true]) ?></div>
          <div class="col-sm-6"><?= form_select('billing_cycle', 'Billing cycle', ['monthly' => 'Monthly', 'annual' => 'Annual', 'custom' => 'Custom'], $current['billing_cycle'] ?? 'monthly', ['required' => true]) ?></div>
          <div class="col-sm-6"><?= form_input('start_date', 'Start date', date('Y-m-d'), 'date', ['required' => true]) ?></div>
          <div class="col-sm-6"><?= form_input('end_date', 'Expiry / renewal date', '', 'date', ['help' => 'Empty = calculated from cycle or trial']) ?></div>
        </div>
        <?= form_input('price', 'Agreed price (optional)', '', 'number', ['min' => 0, 'step' => '0.01', 'help' => 'Empty = plan price for the cycle']) ?>
        <?= form_textarea('notes', 'Notes', '', ['rows' => 2, 'maxlength' => 1000]) ?>
        <button class="btn btn-primary" type="submit">Save subscription</button>
        <a class="btn btn-light" href="<?= e(url('admin/ngos/' . $t['id'])) ?>">Cancel</a>
      </div>
    </form>
  </div>
  <div class="col-lg-5">
    <div class="card"><div class="card-body">
      <h2 class="h6">Current subscription</h2>
      <?php if (!$current): ?><p class="small text-body-secondary mb-0">None.</p><?php else: ?>
        <p class="mb-1 fw-medium"><?= e($current['plan_name']) ?> <?= status_badge($current['status']) ?></p>
        <p class="small text-body-secondary mb-0"><?= e(format_date($current['start_date'])) ?> – <?= e(format_date($current['end_date'])) ?> · <?= e(humanize($current['billing_cycle'])) ?></p>
      <?php endif; ?>
      <hr>
      <p class="small text-body-secondary mb-0">If the NGO already uses more than the new plan allows, nothing is deleted: you'll see a warning and new additions are blocked until usage is back under the limit.</p>
    </div></div>
  </div>
</div>
