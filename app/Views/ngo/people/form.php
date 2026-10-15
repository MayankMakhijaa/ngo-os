<?php
/** @var array $type @var ?array $p @var array $categories @var ?array $usage @var string $nextCode */
use App\Services\PeopleService;

$base = 'ngo/people/' . $type['slug'];
$heading = $p ? 'Edit ' . $p['name'] : 'Register ' . strtolower($type['singular']);
$subtitle = $p ? $type['singular'] . ' ID ' . $p['member_code'] : 'The ' . strtolower($type['singular']) . ' ID (' . $nextCode . ') is assigned automatically when you save.';
$crumbs = [$type['label'] => $base] + ($p ? [$p['name'] => $base . '/' . $p['id']] : []);
include __DIR__ . '/../../partials/page_header.php';
$cats = [];
foreach ($categories as $c) {
    $cats[$c['id']] = $c['name'];
}
$isIntern = $type['type'] === 'intern';
?>
<form method="post" action="<?= e(url($p ? $base . '/' . $p['id'] : $base)) ?>" class="needs-validation" novalidate data-loading autocomplete="off">
  <?= csrf_field() ?>
  <div class="row g-4">
    <div class="col-lg-7">
      <div class="card mb-4"><div class="card-body">
        <h2 class="h6 mb-3">Personal details</h2>
        <div class="row">
          <div class="col-sm-7"><?= form_input('name', 'Full name', $p['name'] ?? '', 'text', ['required' => true, 'maxlength' => 120, 'autofocus' => !$p]) ?></div>
          <div class="col-sm-5"><?= form_input('guardian_name', "Father's / guardian's name", $p['guardian_name'] ?? '', 'text', ['maxlength' => 120]) ?></div>
          <div class="col-sm-4"><?= form_select('gender', 'Gender', PeopleService::GENDERS, $p['gender'] ?? '', ['placeholder' => '—']) ?></div>
          <div class="col-sm-4"><?= form_input('date_of_birth', 'Date of birth', $p['date_of_birth'] ?? '', 'date', ['max' => date('Y-m-d')]) ?></div>
          <div class="col-sm-4"><?= form_select('blood_group', 'Blood group', array_combine(PeopleService::BLOOD_GROUPS, PeopleService::BLOOD_GROUPS), $p['blood_group'] ?? '', ['placeholder' => '—', 'help' => 'Printed on the ID card']) ?></div>
          <div class="col-sm-6"><?= form_input('phone', 'Phone', $p['phone'] ?? '', 'tel', ['maxlength' => 30]) ?></div>
          <div class="col-sm-6"><?= form_input('email', 'Email', $p['email'] ?? '', 'email', ['maxlength' => 190, 'help' => 'Used for certificates and automatic emails']) ?></div>
        </div>
        <?= form_textarea('address', 'Address', $p['address'] ?? '', ['rows' => 2, 'maxlength' => 1000]) ?>
        <div class="row">
          <div class="col-sm-5"><?= form_input('city', 'City', $p['city'] ?? '', 'text', ['maxlength' => 80]) ?></div>
          <div class="col-sm-4"><?= form_input('state', 'State', $p['state'] ?? '', 'text', ['maxlength' => 80]) ?></div>
          <div class="col-sm-3"><?= form_input('pincode', 'PIN code', $p['pincode'] ?? '', 'text', ['inputmode' => 'numeric', 'pattern' => '\d{6}', 'maxlength' => 6]) ?></div>
        </div>
        <?= form_input('emergency_contact', 'Emergency contact (name and phone)', $p['emergency_contact'] ?? '', 'text', ['maxlength' => 120, 'wrap' => 'mb-0']) ?>
      </div></div>

      <div class="card"><div class="card-body">
        <h2 class="h6 mb-1">Identity and bank details</h2>
        <p class="small text-body-secondary">For privacy only the <strong>last 4 digits</strong> of the Aadhaar and bank account numbers are stored; the full numbers are never saved, logged or shown. Upload the Aadhaar card itself on the profile page for verification (stored encrypted).</p>
        <div class="row">
          <div class="col-sm-6"><?= form_input('aadhaar_number', 'Aadhaar number', '', 'text', ['inputmode' => 'numeric', 'maxlength' => 14, 'autocomplete' => 'off',
              'placeholder' => !empty($p['aadhaar_last4']) ? 'XXXX XXXX ' . $p['aadhaar_last4'] . ' (leave blank to keep)' : '12 digits',
              'help' => 'Checked with the Aadhaar check digit; only XXXX XXXX ' . (!empty($p['aadhaar_last4']) ? $p['aadhaar_last4'] : '1234') . ' is kept']) ?></div>
        </div>
        <div class="row">
          <div class="col-sm-4"><?= form_input('bank_name', 'Bank name', $p['bank_name'] ?? '', 'text', ['maxlength' => 120]) ?></div>
          <div class="col-sm-4"><?= form_input('bank_account', 'Account number', '', 'text', ['inputmode' => 'numeric', 'maxlength' => 24, 'autocomplete' => 'off',
              'placeholder' => !empty($p['bank_account_last4']) ? 'XXXX' . $p['bank_account_last4'] . ' (leave blank to keep)' : '']) ?></div>
          <div class="col-sm-4"><?= form_input('bank_ifsc', 'IFSC', $p['bank_ifsc'] ?? '', 'text', ['maxlength' => 11, 'placeholder' => 'SBIN0001234']) ?></div>
        </div>
      </div></div>
    </div>

    <div class="col-lg-5">
      <div class="card mb-4"><div class="card-body">
        <h2 class="h6 mb-3"><?= e($type['singular']) ?> record</h2>
        <?php if (!$p): ?>
          <?= form_select('status', 'Status', ['active' => 'Active (approved)', 'pending' => 'Pending approval'], 'active', ['required' => true, 'help' => 'Pending and active records count towards your plan limit']) ?>
        <?php endif; ?>
        <?= form_select('category_id', 'Category', $cats, $p['category_id'] ?? '', ['placeholder' => $cats ? 'No category' : 'No categories yet',
            'help' => can('people.categories') ? 'Manage categories under People > Categories' : null]) ?>
        <div class="row">
          <div class="col-sm-6"><?= form_input('designation', $isIntern ? 'Role / project' : 'Designation', $p['designation'] ?? '', 'text', ['maxlength' => 120]) ?></div>
          <div class="col-sm-6"><?= form_input('department', 'Department / programme', $p['department'] ?? '', 'text', ['maxlength' => 120]) ?></div>
          <div class="col-sm-6"><?= form_input('joined_on', 'Joining date', $p['joined_on'] ?? date('Y-m-d'), 'date') ?></div>
          <div class="col-sm-6"><?= form_input('ends_on', $isIntern ? 'Internship end date' : 'Valid until', $p['ends_on'] ?? '', 'date', ['help' => 'Printed on the ID card']) ?></div>
        </div>
        <?= form_input('monthly_amount', $isIntern ? 'Monthly stipend (Rs.)' : 'Monthly salary / honorarium (Rs.)', $p['monthly_amount'] ?? '', 'number', ['min' => 0, 'step' => '0.01',
            'help' => 'Optional. Pre-fills salary / stipend slips', 'wrap' => 'mb-0']) ?>
      </div></div>
      <div class="card"><div class="card-body">
        <?= form_textarea('notes', 'Internal notes', $p['notes'] ?? '', ['rows' => 3, 'maxlength' => 2000, 'help' => 'Visible to your team only']) ?>
      </div></div>
    </div>
  </div>
  <div class="mt-3 d-flex gap-2">
    <button class="btn btn-primary" type="submit"><?= $p ? 'Save changes' : 'Register ' . e(strtolower($type['singular'])) ?></button>
    <a class="btn btn-light" href="<?= e(url($p ? $base . '/' . $p['id'] : $base)) ?>">Cancel</a>
  </div>
</form>
