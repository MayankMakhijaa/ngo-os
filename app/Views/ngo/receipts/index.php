<?php
/** @var \App\Core\Paginator $page @var array $f @var array $fys @var string $totalsFy @var array $totals @var array $missing */
use App\Services\ReceiptService;

$writable = \App\Services\SubscriptionService::isWritable($subscription);
$heading = '80G donation receipts';
$subtitle = 'Generated from your NGO name, logo and 80G details';
$actions = '';
if (can('receipts.settings')) {
    $actions .= '<a class="btn btn-outline-secondary" href="' . e(url('ngo/receipts/settings')) . '"><i class="bi bi-sliders me-1" aria-hidden="true"></i>80G settings</a>';
}
if (can('receipts.export')) {
    $actions .= '<a class="btn btn-outline-secondary" href="' . e(url('ngo/receipts/export', ['fy' => $totalsFy])) . '"><i class="bi bi-download me-1" aria-hidden="true"></i>Form 10BD data (' . e($totalsFy) . ')</a>';
}
if (can('receipts.create') && $writable && !$missing) {
    $actions .= '<a class="btn btn-primary" href="' . e(url('ngo/receipts/create')) . '"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New receipt</a>';
}
include __DIR__ . '/../../partials/page_header.php';
?>
<?php if ($missing): ?>
  <div class="alert alert-warning small">Before issuing receipts, complete the 80G settings: <?= e(implode(', ', $missing)) ?>.
    <?php if (can('receipts.settings')): ?><a href="<?= e(url('ngo/receipts/settings')) ?>">Open 80G settings</a><?php else: ?>Ask your administrator to complete them.<?php endif; ?></div>
<?php endif; ?>
<div class="row g-3 mb-3">
  <div class="col-md-4"><?= stat_card('Receipts in FY ' . $totalsFy, number_format((int) $totals['n']), 'file-earmark-text') ?></div>
  <div class="col-md-4"><?= stat_card('Donations received', format_money($totals['total']), 'cash-coin', 'success') ?></div>
  <div class="col-md-4"><?= stat_card('Eligible under 80G', format_money($totals['eligible']), 'patch-check', 'info', 'Excludes cancelled, in-kind and cash above the limit') ?></div>
</div>
<div class="card">
  <div class="card-body border-bottom">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-md-5"><label class="form-label small" for="q">Search</label><input class="form-control form-control-sm" type="search" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Donor, receipt no., email or PAN"></div>
      <div class="col-6 col-md-2"><label class="form-label small" for="fy">Financial year</label>
        <select class="form-select form-select-sm" id="fy" name="fy"><option value="">All</option>
          <?php foreach ($fys as $fy): ?><option value="<?= e($fy) ?>"<?= $f['fy'] === $fy ? ' selected' : '' ?>><?= e($fy) ?></option><?php endforeach; ?></select></div>
      <div class="col-6 col-md-2"><label class="form-label small" for="status">Status</label>
        <select class="form-select form-select-sm" id="status" name="status"><option value="">All</option><option value="issued"<?= $f['status'] === 'issued' ? ' selected' : '' ?>>Issued</option><option value="cancelled"<?= $f['status'] === 'cancelled' ? ' selected' : '' ?>>Cancelled</option></select></div>
      <div class="col-md-3 d-flex gap-2"><button class="btn btn-sm btn-primary" type="submit">Filter</button><a class="btn btn-sm btn-light" href="<?= e(url('ngo/receipts')) ?>">Reset</a></div>
    </form>
  </div>
  <?php if (!$page->items): ?>
    <?= empty_state('No receipts found', array_filter($f) ? 'Try different filters.' : 'Issue your first 80G receipt.', 'file-earmark-text') ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th scope="col">Receipt no.</th><th scope="col">Donor</th><th scope="col">Date</th><th scope="col">Mode</th><th scope="col" class="text-end">Amount</th><th scope="col">80G</th><th scope="col">Status</th></tr></thead>
    <tbody><?php foreach ($page->items as $r): ?>
      <tr><td><a class="font-monospace small fw-medium" href="<?= e(url('ngo/receipts/' . $r['id'])) ?>"><?= e($r['receipt_no']) ?></a></td>
        <td><?= e($r['donor_name']) ?><div class="small text-body-secondary"><?= e($r['donor_email'] ?? '') ?></div></td>
        <td class="small"><?= e(format_date($r['donation_date'])) ?></td>
        <td class="small"><?= e(ReceiptService::MODES[$r['payment_mode']] ?? $r['payment_mode']) ?></td>
        <td class="text-end"><?= e(format_money($r['amount'])) ?></td>
        <td><?= $r['eligible_80g'] ? '<span class="badge bg-success-subtle text-success-emphasis">Eligible</span>' : '<span class="badge text-bg-secondary">Not eligible</span>' ?></td>
        <td><?= status_badge($r['status']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
  <?php include __DIR__ . '/../../partials/pagination.php'; ?>
</div>
<p class="small text-body-secondary mt-3 mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>The receipt format follows section 80G and rule 18AB as commonly applied, but it is not a legal certification. Have your chartered accountant review the wording in 80G settings before relying on it.</p>
