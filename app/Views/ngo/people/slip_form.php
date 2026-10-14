<?php
/** @var array $type @var array $p @var ?array $last */
use App\Services\SalarySlipService;

$base = 'ngo/people/' . $type['slug'] . '/' . $p['id'];
$isIntern = $type['type'] === 'intern';
$heading = 'New ' . ($isIntern ? 'stipend' : 'salary') . ' slip';
$subtitle = $p['name'] . ' · ' . $p['member_code'];
$crumbs = [$type['label'] => 'ngo/people/' . $type['slug'], $p['name'] => $base];
include __DIR__ . '/../../partials/page_header.php';

// Pre-fill from the previous slip, else from the monthly amount on the profile.
$earnings = $last ? (json_decode($last['earnings'], true) ?: []) : [];
$deductions = $last ? (json_decode($last['deductions'], true) ?: []) : [];
if (!$earnings) {
    $earnings = [['label' => $isIntern ? 'Stipend' : 'Basic pay', 'amount' => $p['monthly_amount'] ?? '']];
}
$oldEL = old('earning_label', null);
$oldEA = old('earning_amount', null);
$oldDL = old('deduction_label', null);
$oldDA = old('deduction_amount', null);
if (is_array($oldEL)) {
    $earnings = array_map(null, $oldEL, is_array($oldEA) ? $oldEA : []);
    $earnings = array_map(static fn ($r) => ['label' => $r[0] ?? '', 'amount' => $r[1] ?? ''], $earnings);
}
if (is_array($oldDL)) {
    $deductions = array_map(static fn ($r) => ['label' => $r[0] ?? '', 'amount' => $r[1] ?? ''], array_map(null, $oldDL, is_array($oldDA) ? $oldDA : []));
}
$earnings = array_pad($earnings, max(5, count($earnings)), ['label' => '', 'amount' => '']);
$deductions = array_pad($deductions, max(4, count($deductions)), ['label' => '', 'amount' => '']);
$suggestE = $isIntern ? ['Stipend', 'Travel allowance', 'Project allowance'] : ['Basic pay', 'House rent allowance', 'Conveyance', 'Special allowance', 'Overtime', 'Bonus'];
$suggestD = ['Provident fund', 'Professional tax', 'Income tax (TDS)', 'ESI', 'Advance recovery', 'Leave without pay'];
$month = date('Y-m', strtotime('first day of last month'));
?>
<datalist id="earningLabels"><?php foreach ($suggestE as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist>
<datalist id="deductionLabels"><?php foreach ($suggestD as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist>
<form method="post" action="<?= e(url($base . '/salary-slips')) ?>" class="needs-validation" novalidate data-loading data-slip-form>
  <?= csrf_field() ?>
  <div class="row g-4">
    <div class="col-lg-4">
      <div class="card"><div class="card-body">
        <?= form_input('period_month', 'Month', $month, 'month', ['required' => true, 'max' => date('Y-m', strtotime('+1 month'))]) ?>
        <?= form_select('pay_type', 'Type', SalarySlipService::PAY_TYPES, $last['pay_type'] ?? ($isIntern ? 'stipend' : 'salary'), ['required' => true]) ?>
        <div class="row">
          <div class="col-6"><?= form_input('working_days', 'Working days', $last['working_days'] ?? '', 'number', ['min' => 0, 'max' => 31, 'step' => '0.5']) ?></div>
          <div class="col-6"><?= form_input('paid_days', 'Paid days', $last['paid_days'] ?? '', 'number', ['min' => 0, 'max' => 31, 'step' => '0.5']) ?></div>
        </div>
        <?= form_input('payment_date', 'Payment date', '', 'date') ?>
        <?= form_select('payment_mode', 'Payment mode', SalarySlipService::MODES, $last['payment_mode'] ?? 'bank_transfer', ['placeholder' => '—']) ?>
        <?= form_input('notes', 'Note on the slip (optional)', '', 'text', ['maxlength' => 255, 'wrap' => 'mb-0']) ?>
      </div></div>
    </div>
    <div class="col-lg-8">
      <div class="card"><div class="card-body">
        <div class="row g-4">
          <div class="col-md-6">
            <h2 class="h6">Earnings</h2>
            <?php foreach ($earnings as $i => $row): ?>
              <div class="input-group input-group-sm mb-2">
                <input class="form-control" name="earning_label[]" list="earningLabels" value="<?= e($row['label']) ?>" placeholder="Earning" aria-label="Earning <?= $i + 1 ?> label" maxlength="60">
                <span class="input-group-text">Rs.</span>
                <input class="form-control text-end" style="max-width:8rem" name="earning_amount[]" type="number" min="0" step="0.01" value="<?= e($row['amount']) ?>" aria-label="Earning <?= $i + 1 ?> amount" data-sum="earning">
              </div>
            <?php endforeach; ?>
          </div>
          <div class="col-md-6">
            <h2 class="h6">Deductions</h2>
            <?php foreach ($deductions as $i => $row): ?>
              <div class="input-group input-group-sm mb-2">
                <input class="form-control" name="deduction_label[]" list="deductionLabels" value="<?= e($row['label']) ?>" placeholder="Deduction" aria-label="Deduction <?= $i + 1 ?> label" maxlength="60">
                <span class="input-group-text">Rs.</span>
                <input class="form-control text-end" style="max-width:8rem" name="deduction_amount[]" type="number" min="0" step="0.01" value="<?= e($row['amount']) ?>" aria-label="Deduction <?= $i + 1 ?> amount" data-sum="deduction">
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <hr>
        <div class="d-flex flex-wrap gap-4 justify-content-end small" aria-live="polite">
          <div>Gross: <strong data-total="earning">—</strong></div>
          <div>Deductions: <strong data-total="deduction">—</strong></div>
          <div>Net pay: <strong class="fs-6" data-total="net">—</strong></div>
        </div>
        <p class="form-text mb-0">Rows left blank are ignored. The slip is generated as a PDF with your NGO's logo and name; amounts in words are added automatically.</p>
      </div></div>
    </div>
  </div>
  <div class="mt-3 d-flex gap-2"><button class="btn btn-primary" type="submit">Create slip</button><a class="btn btn-light" href="<?= e(url($base)) ?>#slips">Cancel</a></div>
</form>
