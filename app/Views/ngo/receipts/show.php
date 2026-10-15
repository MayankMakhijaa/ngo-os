<?php
/** @var array $r @var array $snap */
use App\Services\ReceiptService;

$writable = \App\Services\SubscriptionService::isWritable($subscription);
$heading = 'Receipt ' . $r['receipt_no'];
$subtitle = $r['donor_name'] . ' · ' . format_money($r['amount']);
$crumbs = ['80G receipts' => 'ngo/receipts'];
$actions = '<a class="btn btn-primary" href="' . e(url('ngo/receipts/' . $r['id'] . '/pdf')) . '"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Download PDF</a>';
if ($r['status'] === 'issued' && $writable && can('receipts.create') && $r['donor_email']) {
    $actions .= '<form method="post" action="' . e(url('ngo/receipts/' . $r['id'] . '/email')) . '" data-loading>' . csrf_field()
        . '<button class="btn btn-outline-secondary" type="submit"><i class="bi bi-envelope me-1" aria-hidden="true"></i>' . ($r['emailed_at'] ? 'Email again' : 'Email to donor') . '</button></form>';
}
if ($r['status'] === 'issued' && $writable && can('receipts.cancel')) {
    $actions .= '<form method="post" action="' . e(url('ngo/receipts/' . $r['id'] . '/cancel')) . '" data-confirm="Cancel receipt ' . e($r['receipt_no']) . '? The number stays used and the PDF is marked CANCELLED. This cannot be undone." data-confirm-reason="reason" data-confirm-button="Cancel receipt">'
        . csrf_field() . '<input type="hidden" name="reason" value=""><button class="btn btn-outline-danger" type="submit">Cancel receipt</button></form>';
}
include __DIR__ . '/../../partials/page_header.php';
?>
<?php if ($r['status'] === 'cancelled'): ?>
  <div class="alert alert-danger small">Cancelled on <?= e(format_datetime($r['cancelled_at'])) ?>: <?= e($r['cancelled_reason']) ?></div>
<?php endif; ?>
<?php if (!$r['eligible_80g']): ?>
  <div class="alert alert-warning small">Not eligible for deduction under section 80G (<?= $r['payment_mode'] === 'kind' ? 'donation in kind' : 'cash above Rs. ' . e(number_format((int) ($snap['cash_limit'] ?? 2000))) ?>). The receipt states this.</div>
<?php endif; ?>
<div class="row g-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-body">
    <h2 class="h6">Donor</h2>
    <dl class="row small mb-0">
      <dt class="col-4 text-body-secondary">Name</dt><dd class="col-8"><?= e($r['donor_name']) ?></dd>
      <dt class="col-4 text-body-secondary">Address</dt><dd class="col-8"><?= e($r['donor_address']) ?></dd>
      <dt class="col-4 text-body-secondary"><?= e(ReceiptService::ID_TYPES[$r['donor_id_type']] ?? 'ID') ?></dt><dd class="col-8 font-monospace"><?= e($r['donor_id_number'] ?: '—') ?></dd>
      <dt class="col-4 text-body-secondary">Email</dt><dd class="col-8"><?= e($r['donor_email'] ?: '—') ?><?= $r['emailed_at'] ? '<div class="text-body-secondary">Emailed ' . e(format_datetime($r['emailed_at'])) . '</div>' : '' ?></dd>
      <dt class="col-4 text-body-secondary">Phone</dt><dd class="col-8"><?= e($r['donor_phone'] ?: '—') ?></dd>
    </dl>
  </div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-body">
    <h2 class="h6">Donation</h2>
    <dl class="row small mb-0">
      <dt class="col-4 text-body-secondary">Amount</dt><dd class="col-8 fw-medium"><?= e(format_money($r['amount'])) ?></dd>
      <dt class="col-4 text-body-secondary">Date</dt><dd class="col-8"><?= e(format_date($r['donation_date'])) ?> (FY <?= e($r['financial_year']) ?>)</dd>
      <dt class="col-4 text-body-secondary">Mode</dt><dd class="col-8"><?= e(ReceiptService::MODES[$r['payment_mode']] ?? $r['payment_mode']) ?><?= $r['payment_reference'] ? ' · ' . e($r['payment_reference']) : '' ?><?= $r['bank_name'] ? ' · ' . e($r['bank_name']) : '' ?></dd>
      <dt class="col-4 text-body-secondary">Type</dt><dd class="col-8"><?= e(ReceiptService::DONATION_TYPES[$r['donation_type']] ?? $r['donation_type']) ?></dd>
      <dt class="col-4 text-body-secondary">Purpose</dt><dd class="col-8"><?= e($r['purpose'] ?: '—') ?></dd>
      <dt class="col-4 text-body-secondary">Status</dt><dd class="col-8"><?= status_badge($r['status']) ?></dd>
      <dt class="col-4 text-body-secondary">Issued by</dt><dd class="col-8"><?= e(($r['issued_by_name'] ?? '—') . ' · ' . format_datetime($r['created_at'])) ?></dd>
      <dt class="col-4 text-body-secondary">NGO 80G no.</dt><dd class="col-8"><?= e(($snap['reg_no'] ?? '') . ' · PAN ' . ($snap['pan'] ?? '')) ?></dd>
    </dl>
  </div></div></div>
</div>
