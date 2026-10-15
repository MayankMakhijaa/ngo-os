<?php
/** @var array $s @var array $missing */
use App\Services\ReceiptService;

$writable = \App\Services\SubscriptionService::isWritable($subscription);
$heading = '80G receipt settings';
$subtitle = 'Your 80G details and the wording printed on every receipt';
$crumbs = ['80G receipts' => 'ngo/receipts'];
$actions = '<a class="btn btn-outline-secondary" href="' . e(url('ngo/receipts/settings/preview')) . '" target="_blank" rel="noopener"><i class="bi bi-eye me-1" aria-hidden="true"></i>Preview sample receipt</a>';
include __DIR__ . '/../../partials/page_header.php';
$ro = !$writable;
?>
<div class="alert alert-light border small">
  <strong>Please note:</strong> the default fields and wording follow section 80G of the Income-tax Act and rule 18AB (receipt details such as the NGO's PAN, its 80G approval number and validity, donor name, address and PAN, amount, mode and date) as we understand them.
  This software does not certify the receipt as legally valid. Ask your chartered accountant to confirm the wording below, especially the declaration. The NGO name, address and logo come from <a href="<?= e(url('ngo/organization')) ?>">Organization</a>.
</div>
<?php if ($missing): ?><div class="alert alert-warning small">Still needed before receipts can be issued: <?= e(implode(', ', $missing)) ?>.</div><?php endif; ?>
<form method="post" action="<?= e(url('ngo/receipts/settings')) ?>" enctype="multipart/form-data" class="needs-validation" novalidate data-loading>
  <?= csrf_field() ?>
  <div class="row g-4">
    <div class="col-lg-6">
      <div class="card mb-4"><div class="card-body">
        <h2 class="h6 mb-3">80G registration</h2>
        <div class="row">
          <div class="col-sm-6"><?= form_input('r80g_pan', 'NGO PAN', $s['r80g_pan'], 'text', ['required' => true, 'maxlength' => 10, 'placeholder' => 'AAATA1234A', 'readonly' => $ro]) ?></div>
          <div class="col-sm-6"><?= form_input('r80g_reg_no', '80G approval / registration number', $s['r80g_reg_no'], 'text', ['required' => true, 'maxlength' => 60, 'readonly' => $ro,
              'help' => 'The unique registration number in Form 10AC / 10AD']) ?></div>
          <div class="col-sm-4"><?= form_input('r80g_reg_date', 'Date of approval', $s['r80g_reg_date'], 'date', ['readonly' => $ro]) ?></div>
          <div class="col-sm-4"><?= form_input('r80g_valid_from', 'Valid from', $s['r80g_valid_from'], 'date', ['readonly' => $ro]) ?></div>
          <div class="col-sm-4"><?= form_input('r80g_valid_to', 'Valid until', $s['r80g_valid_to'], 'date', ['readonly' => $ro]) ?></div>
        </div>
      </div></div>
      <div class="card mb-4"><div class="card-body">
        <h2 class="h6 mb-3">Authorised signatory</h2>
        <div class="row">
          <div class="col-sm-6"><?= form_input('r80g_signatory_name', 'Name', $s['r80g_signatory_name'], 'text', ['required' => true, 'maxlength' => 120, 'readonly' => $ro]) ?></div>
          <div class="col-sm-6"><?= form_input('r80g_signatory_designation', 'Designation', $s['r80g_signatory_designation'], 'text', ['maxlength' => 120, 'readonly' => $ro, 'placeholder' => 'Treasurer']) ?></div>
        </div>
        <div class="d-flex align-items-center gap-3">
          <?php if ($s['r80g_signature_object_id'] !== ''): ?><img src="<?= e(url('ngo/receipts/settings/signature')) ?>" alt="Current signature" class="tenant-logo"><?php endif; ?>
          <?php if (!$ro): ?><div class="flex-grow-1"><?= form_input('signature', $s['r80g_signature_object_id'] !== '' ? 'Replace signature image' : 'Signature image (optional)', '', 'file', ['accept' => 'image/png,image/jpeg', 'help' => 'PNG or JPG up to 1 MB, printed above the signatory name', 'wrap' => 'mb-0']) ?>
            <?php if ($s['r80g_signature_object_id'] !== ''): ?><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="remove_signature" value="1" id="remove_signature"><label class="form-check-label small" for="remove_signature">Remove the signature image</label></div><?php endif; ?>
          </div><?php endif; ?>
        </div>
      </div></div>
      <div class="card"><div class="card-body">
        <h2 class="h6 mb-3">Numbering and look</h2>
        <div class="row">
          <div class="col-sm-4"><?= form_input('r80g_prefix', 'Receipt prefix', $s['r80g_prefix'], 'text', ['maxlength' => 12, 'readonly' => $ro, 'help' => 'e.g. RCPT/2026-27/00001']) ?></div>
          <div class="col-sm-4"><?= form_input('r80g_cash_limit', 'Cash limit for 80G (Rs.)', $s['r80g_cash_limit'], 'number', ['min' => 0, 'readonly' => $ro, 'help' => 'Section 80G(5D): Rs. 2,000']) ?></div>
          <div class="col-sm-4"><label class="form-label" for="f_r80g_color">Accent colour</label>
            <input type="color" class="form-control form-control-color" id="f_r80g_color" name="r80g_color" value="<?= e(old('r80g_color', $s['r80g_color'] ?: '#2b59c3')) ?>"<?= $ro ? ' disabled' : '' ?>></div>
        </div>
        <div class="form-check"><input type="hidden" name="r80g_show_logo" value="0"><input class="form-check-input" type="checkbox" name="r80g_show_logo" value="1" id="r80g_show_logo"<?= $s['r80g_show_logo'] === '1' ? ' checked' : '' ?><?= $ro ? ' disabled' : '' ?>>
          <label class="form-check-label" for="r80g_show_logo">Print the NGO logo on receipts</label></div>
      </div></div>
    </div>
    <div class="col-lg-6">
      <div class="card"><div class="card-body">
        <h2 class="h6 mb-3">Receipt wording</h2>
        <?= form_input('r80g_title', 'Title', $s['r80g_title'], 'text', ['maxlength' => 120, 'readonly' => $ro]) ?>
        <?= form_input('r80g_subtitle', 'Subtitle', $s['r80g_subtitle'], 'text', ['maxlength' => 200, 'readonly' => $ro]) ?>
        <?= form_textarea('r80g_declaration', 'Declaration', $s['r80g_declaration'], ['rows' => 6, 'maxlength' => 1500]) ?>
        <?= form_textarea('r80g_footer', 'Footer note', $s['r80g_footer'], ['rows' => 3, 'maxlength' => 600]) ?>
        <div class="small text-body-secondary">Placeholders you can use:
          <?php foreach (ReceiptService::PLACEHOLDERS as $ph): ?><code class="me-1">{{<?= e($ph) ?>}}</code><?php endforeach; ?></div>
      </div></div>
    </div>
  </div>
  <?php if (!$ro): ?><div class="mt-3"><button class="btn btn-primary" type="submit">Save settings</button></div><?php endif; ?>
</form>
