<?php
$heading = 'Payments'; $subtitle = 'Manually recorded subscription payments';
include __DIR__ . '/../../partials/page_header.php';
?>
<div class="card">
  <div class="card-body border-bottom">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-md-6"><label class="form-label small" for="q">Search NGO or reference</label><input class="form-control form-control-sm" type="search" id="q" name="q" value="<?= e($q) ?>"></div>
      <div class="col-md-3 d-flex gap-2"><button class="btn btn-sm btn-primary" type="submit">Search</button><a class="btn btn-sm btn-light" href="<?= e(url('admin/payments')) ?>">Reset</a></div>
    </form>
  </div>
  <?php if (!$page->items): ?><?= empty_state('No payments recorded', 'Record payments from an NGO page, Subscription & payments tab.', 'cash-stack') ?><?php else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th scope="col">#</th><th scope="col">Date</th><th scope="col">NGO</th><th scope="col">Plan</th><th scope="col">Amount</th><th scope="col">Method / reference</th><th scope="col">Status</th><th scope="col">Recorded by</th></tr></thead>
    <tbody><?php foreach ($page->items as $p): ?>
      <tr><td><?= (int) $p['id'] ?></td><td class="small"><?= e(format_date($p['paid_at'])) ?></td>
        <td><a href="<?= e(url('admin/ngos/' . $p['tenant_id'])) ?>"><?= e($p['tenant_name']) ?></a></td><td><?= e($p['plan_name']) ?></td>
        <td><?= e(format_money($p['amount'], $p['currency'])) ?></td>
        <td class="small"><?= e(humanize($p['method'])) ?><?= $p['reference'] ? '<div class="text-body-secondary">' . e($p['reference']) . '</div>' : '' ?></td>
        <td><?= status_badge($p['status']) ?></td><td class="small"><?= e($p['recorder'] ?? '—') ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
  <?php include __DIR__ . '/../../partials/pagination.php'; ?>
</div>
