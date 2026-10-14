<?php
$heading = 'Subscriptions'; $subtitle = 'Current subscription of every NGO';
include __DIR__ . '/../../partials/page_header.php';
?>
<div class="row g-3 mb-3">
  <div class="col-sm-6 col-xl-3"><?= stat_card('Monthly recurring revenue', $mrr['counted'] ? format_money($mrr['mrr'], (string) config('app.currency')) : '—', 'graph-up-arrow', 'success', $mrr['counted'] . ' active / past-due subscriptions' . ($mrr['custom_excluded'] ? ' · ' . $mrr['custom_excluded'] . ' custom cycle excluded' : '')) ?></div>
</div>
<div class="card">
  <div class="card-body border-bottom">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-md-4"><label class="form-label small" for="q">Search NGO</label><input class="form-control form-control-sm" type="search" id="q" name="q" value="<?= e($q) ?>"></div>
      <div class="col-md-3"><label class="form-label small" for="status">Status</label>
        <select class="form-select form-select-sm" id="status" name="status"><option value="">All</option>
          <?php foreach (\App\Services\SubscriptionService::STATUSES as $s): ?><option value="<?= e($s) ?>"<?= $status === $s ? ' selected' : '' ?>><?= e(humanize($s)) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3"><div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="expiring" value="30" id="expiring"<?= $expiring === '30' ? ' checked' : '' ?>><label class="form-check-label small" for="expiring">Ending within 30 days</label></div></div>
      <div class="col-md-2 d-flex gap-2"><button class="btn btn-sm btn-primary" type="submit">Filter</button><a class="btn btn-sm btn-light" href="<?= e(url('admin/subscriptions')) ?>">Reset</a></div>
    </form>
  </div>
  <?php if (!$page->items): ?><?= empty_state('No subscriptions match', '', 'arrow-repeat') ?><?php else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th scope="col">NGO</th><th scope="col">Plan</th><th scope="col">Status</th><th scope="col">Cycle / price</th><th scope="col">Started</th><th scope="col">Expiry / renewal</th><th scope="col">Paid to date</th></tr></thead>
    <tbody><?php foreach ($page->items as $s): ?>
      <tr><td><a class="fw-medium" href="<?= e(url('admin/ngos/' . $s['tenant_id'])) ?>"><?= e($s['tenant_name']) ?></a><div class="small text-body-secondary"><?= e($s['reference_code']) ?></div></td>
        <td><?= e($s['plan_name']) ?></td><td><?= status_badge($s['status']) ?></td>
        <td class="small"><?= e(humanize($s['billing_cycle'])) ?> · <?= e(format_money($s['price'], $s['currency'])) ?></td>
        <td class="small"><?= e(format_date($s['start_date'])) ?></td><td class="small"><?= e(format_date($s['end_date'])) ?></td>
        <td><?= e(format_money($s['paid_total'], $s['currency'])) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
  <?php include __DIR__ . '/../../partials/pagination.php'; ?>
</div>
