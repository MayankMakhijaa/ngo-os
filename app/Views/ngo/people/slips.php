<?php
/** @var array $rows @var string $month */
$heading = 'Salary & stipend slips';
$subtitle = 'All slips created for ' . date('F Y', strtotime($month . '-01'));
include __DIR__ . '/../../partials/page_header.php';
$total = array_sum(array_map(static fn ($r) => (float) $r['net_amount'], $rows));
?>
<div class="card">
  <div class="card-body border-bottom">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-sm-4 col-md-3"><label class="form-label small" for="month">Month</label><input class="form-control form-control-sm" type="month" id="month" name="month" value="<?= e($month) ?>"></div>
      <div class="col-auto"><button class="btn btn-sm btn-primary" type="submit">Show</button></div>
      <div class="col text-end small text-body-secondary">To create a slip, open the person's profile.</div>
    </form>
  </div>
  <?php if (!$rows): ?>
    <?= empty_state('No slips for this month', 'Open a member, volunteer or intern and choose "New slip".', 'receipt') ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th scope="col">Slip no.</th><th scope="col">Name</th><th scope="col">Type</th><th scope="col" class="text-end">Gross</th><th scope="col" class="text-end">Deductions</th><th scope="col" class="text-end">Net pay</th><th scope="col"><span class="visually-hidden">Download</span></th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr><td class="font-monospace small"><?= e($r['slip_no']) ?></td>
        <td><a href="<?= e(url('ngo/people/' . \App\Services\PeopleService::slugForType($r['person_type']) . '/' . $r['person_id'])) ?>"><?= e($r['person_name']) ?></a><div class="small text-body-secondary"><?= e($r['member_code']) ?></div></td>
        <td class="small"><?= e(\App\Services\SalarySlipService::PAY_TYPES[$r['pay_type']] ?? $r['pay_type']) ?></td>
        <td class="text-end"><?= e(format_money($r['gross_amount'])) ?></td><td class="text-end"><?= e(format_money($r['total_deductions'])) ?></td>
        <td class="text-end fw-medium"><?= e(format_money($r['net_amount'])) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-light" href="<?= e(url('ngo/salary-slips/' . $r['id'] . '/pdf')) ?>"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF</a></td></tr>
    <?php endforeach; ?></tbody>
    <tfoot><tr><th colspan="5" class="text-end">Total net pay</th><th class="text-end"><?= e(format_money($total)) ?></th><th></th></tr></tfoot>
  </table></div>
  <?php endif; ?>
</div>
