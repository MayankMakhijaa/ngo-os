<?php
/** @var array $missing @var array $settings @var string $formKey */
use App\Services\ReceiptService;

$heading = 'New 80G receipt';
$subtitle = 'The receipt number is assigned in sequence for the financial year when you save.';
$crumbs = ['80G receipts' => 'ngo/receipts'];
include __DIR__ . '/../../partials/page_header.php';
?>
<?php if ($missing): ?>
  <div class="alert alert-warning">Complete the 80G settings first: <?= e(implode(', ', $missing)) ?>.</div>
<?php else: ?>
<form method="post" action="<?= e(url('ngo/receipts')) ?>" class="needs-validation" novalidate data-loading data-receipt-form data-cash-limit="<?= (int) $settings['r80g_cash_limit'] ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="form_key" value="<?= e($formKey) ?>">
  <div class="row g-4">
    <div class="col-lg-6">
      <div class="card h-100"><div class="card-body">
        <h2 class="h6 mb-3">Donor</h2>
        <?= form_input('donor_name', 'Donor name', '', 'text', ['required' => true, 'maxlength' => 150, 'autofocus' => true]) ?>
        <?= form_textarea('donor_address', 'Donor address', '', ['required' => true, 'rows' => 2, 'maxlength' => 1000, 'help' => 'Required for the Form 10BD statement']) ?>
        <div class="row">
          <div class="col-sm-6"><?= form_input('donor_email', 'Email', '', 'email', ['maxlength' => 190, 'help' => 'The receipt can be emailed here']) ?></div>
          <div class="col-sm-6"><?= form_input('donor_phone', 'Phone', '', 'tel', ['maxlength' => 30]) ?></div>
          <div class="col-sm-5"><?= form_select('donor_id_type', 'Donor ID type', ReceiptService::ID_TYPES, 'pan', ['required' => true]) ?></div>
          <div class="col-sm-7"><?= form_input('donor_id_number', 'ID number', '', 'text', ['maxlength' => 30, 'placeholder' => 'ABCDE1234F', 'help' => 'PAN is needed for the donor to claim the deduction. Aadhaar is not collected.', 'wrap' => 'mb-0']) ?></div>
        </div>
      </div></div>
    </div>
    <div class="col-lg-6">
      <div class="card h-100"><div class="card-body">
        <h2 class="h6 mb-3">Donation</h2>
        <div class="row">
          <div class="col-sm-6"><?= form_input('amount', 'Amount (Rs.)', '', 'number', ['required' => true, 'min' => '1', 'step' => '0.01']) ?></div>
          <div class="col-sm-6"><?= form_input('donation_date', 'Date received', date('Y-m-d'), 'date', ['required' => true, 'max' => date('Y-m-d')]) ?></div>
          <div class="col-sm-6"><?= form_select('payment_mode', 'Mode of payment', ReceiptService::MODES, 'upi', ['required' => true]) ?></div>
          <div class="col-sm-6"><?= form_input('payment_reference', 'Cheque / UTR / transaction no.', '', 'text', ['maxlength' => 100]) ?></div>
          <div class="col-sm-6"><?= form_input('bank_name', 'Bank (for cheque / draft)', '', 'text', ['maxlength' => 120]) ?></div>
          <div class="col-sm-6"><?= form_select('donation_type', 'Donation type', ReceiptService::DONATION_TYPES, 'others', ['required' => true]) ?></div>
        </div>
        <?= form_input('purpose', 'Purpose (optional)', '', 'text', ['maxlength' => 255, 'placeholder' => 'e.g. Education programme']) ?>
        <div class="alert alert-warning small d-none mb-0" data-ineligible-note>Cash donations above Rs. <?= e(number_format((int) $settings['r80g_cash_limit'])) ?> and donations in kind are not eligible for deduction under section 80G. The receipt will say so.</div>
      </div></div>
    </div>
  </div>
  <div class="mt-3 d-flex gap-2"><button class="btn btn-primary" type="submit">Issue receipt</button><a class="btn btn-light" href="<?= e(url('ngo/receipts')) ?>">Cancel</a></div>
  <p class="form-text">Issued receipts cannot be edited. If something is wrong, cancel the receipt and issue a new one.</p>
</form>
<?php endif; ?>
