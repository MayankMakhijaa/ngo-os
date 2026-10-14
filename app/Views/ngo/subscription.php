<?php
$heading = 'Subscription'; $subtitle = 'Your plan, its status and payment history. Contact Fameweb to change your plan.';
include __DIR__ . '/../partials/page_header.php';
$unl = static fn ($v) => $v === null ? 'Unlimited' : number_format((int) $v);
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card h-100"><div class="card-body">
      <?php if (!$sub): ?>
        <?= empty_state('No subscription', 'Your workspace is read-only until Fameweb assigns a plan.', 'credit-card') ?>
      <?php else: ?>
        <div class="d-flex justify-content-between align-items-start mb-3"><h2 class="h5 mb-0"><?= e($sub['plan_name']) ?></h2><?= status_badge($sub['status']) ?></div>
        <dl class="row small mb-0">
          <dt class="col-6 text-body-secondary">Billing</dt><dd class="col-6"><?= e(humanize($sub['billing_cycle'])) ?> · <?= e(format_money($sub['price'], $sub['currency'])) ?></dd>
          <dt class="col-6 text-body-secondary">Started</dt><dd class="col-6"><?= e(format_date($sub['start_date'])) ?></dd>
          <?php if ($sub['trial_ends_at']): ?><dt class="col-6 text-body-secondary">Trial ends</dt><dd class="col-6"><?= e(format_date($sub['trial_ends_at'])) ?></dd><?php endif; ?>
          <dt class="col-6 text-body-secondary">Renews / expires</dt><dd class="col-6"><?= e(format_date($sub['end_date'])) ?></dd>
          <dt class="col-6 text-body-secondary">NGO users</dt><dd class="col-6"><?= e($unl($sub['max_users'])) ?></dd>
          <dt class="col-6 text-body-secondary">Members &amp; volunteers</dt><dd class="col-6"><?= e($unl($sub['max_members'])) ?></dd>
          <dt class="col-6 text-body-secondary">Storage</dt><dd class="col-6"><?= $sub['max_storage_mb'] === null ? 'Unlimited' : e(format_bytes((int) $sub['max_storage_mb'] * 1048576)) ?></dd>
        </dl>
        <h3 class="h6 mt-3">Included modules</h3>
        <ul class="small mb-0"><?php foreach ((array) config('modules') as $k => $m): if (!in_array($k, $modules, true)) continue; ?>
          <li><?= e($m['label']) ?><?= $m['available'] ? '' : ' <span class="text-body-secondary">(coming soon)</span>' ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-7">
    <div class="card mb-3"><div class="card-header"><h2 class="h6 mb-0">Payments</h2></div>
      <?php if (!$payments): ?><?= empty_state('No payments recorded yet', '', 'cash') ?><?php else: ?>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th scope="col">Date</th><th scope="col">Amount</th><th scope="col">Method</th><th scope="col">Status</th><th scope="col">Covers</th></tr></thead>
        <tbody><?php foreach ($payments as $p): ?><tr><td class="small"><?= e(format_date($p['paid_at'])) ?></td><td><?= e(format_money($p['amount'], $p['currency'])) ?></td>
          <td class="small"><?= e(humanize($p['method'])) ?></td><td><?= status_badge($p['status']) ?></td>
          <td class="small"><?= $p['period_end'] ? e(format_date($p['period_start'])) . ' – ' . e(format_date($p['period_end'])) : '—' ?></td></tr><?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
    </div>
    <div class="card"><div class="card-header"><h2 class="h6 mb-0">Plan history</h2></div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th scope="col">Plan</th><th scope="col">Status</th><th scope="col">Period</th></tr></thead>
        <tbody><?php foreach ($history as $h): ?><tr<?= $h['is_current'] ? ' class="table-primary"' : '' ?>><td><?= e($h['plan_name']) ?><?= $h['is_current'] ? ' <span class="badge text-bg-primary">Current</span>' : '' ?></td>
          <td><?= status_badge($h['status']) ?></td><td class="small"><?= e(format_date($h['start_date'])) ?> – <?= e(format_date($h['end_date'])) ?></td></tr><?php endforeach; ?>
        <?php if (!$history): ?><tr><td colspan="3" class="small text-body-secondary">No history.</td></tr><?php endif; ?></tbody>
      </table></div>
    </div>
  </div>
</div>
