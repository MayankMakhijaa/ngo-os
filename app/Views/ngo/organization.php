<?php
$editable = can('organization.edit') && \App\Services\SubscriptionService::isWritable($subscription);
$heading = 'Organization'; $subtitle = 'Profile and organization-level settings';
include __DIR__ . '/../partials/page_header.php';
$ro = !$editable;
?>
<form method="post" action="<?= e(url('ngo/organization')) ?>" enctype="multipart/form-data" class="needs-validation" novalidate data-loading>
  <?= csrf_field() ?>
  <div class="row g-4">
    <div class="col-lg-7">
      <div class="card"><div class="card-body">
        <h2 class="h6 mb-3">Profile</h2>
        <div class="row">
          <div class="col-sm-8"><?= form_input('name_display', 'NGO name', $t['name'], 'text', ['readonly' => true, 'help' => 'Managed by Fameweb']) ?></div>
          <div class="col-sm-4"><?= form_input('ref_display', 'Reference code', $t['reference_code'], 'text', ['readonly' => true]) ?></div>
        </div>
        <?= form_textarea('address', 'Registered / operating address', $t['address'], ['required' => true, 'rows' => 2, 'maxlength' => 1000]) ?>
        <div class="row">
          <div class="col-sm-6"><?= form_input('contact_name', 'Contact person', $t['contact_name'], 'text', ['required' => true, 'readonly' => $ro]) ?></div>
          <div class="col-sm-6"><?= form_input('phone', 'Contact phone', $t['phone'], 'tel', ['required' => true, 'readonly' => $ro]) ?></div>
          <div class="col-sm-6"><?= form_input('email', 'Official email', $t['email'], 'email', ['required' => true, 'readonly' => $ro]) ?></div>
          <div class="col-sm-6"><?= form_input('website', 'Website', $t['website'] ?? '', 'url', ['readonly' => $ro, 'placeholder' => 'https://']) ?></div>
        </div>
        <?= form_input('registration_number', 'Registration number', $t['registration_number'] ?? '', 'text', ['readonly' => $ro, 'maxlength' => 100]) ?>
        <div class="d-flex align-items-center gap-3">
          <?php if ($t['logo_object_id']): ?><img src="<?= e(url('ngo/organization/logo')) ?>" alt="Current logo" class="tenant-logo"><?php endif; ?>
          <?php if ($editable): ?><div class="flex-grow-1"><?= form_input('logo', $t['logo_object_id'] ? 'Replace logo' : 'Logo', '', 'file', ['accept' => 'image/png,image/jpeg,image/webp', 'help' => 'PNG, JPG or WebP up to 2 MB; counts towards storage', 'wrap' => 'mb-0']) ?></div><?php endif; ?>
        </div>
      </div></div>
    </div>
    <div class="col-lg-5">
      <div class="card"><div class="card-body">
        <h2 class="h6 mb-3">Settings</h2>
        <?= form_input('display_name', 'Display name in the portal', $settings['display_name'], 'text', ['maxlength' => 190, 'readonly' => $ro]) ?>
        <?= form_input('notification_email', 'Notification email', $settings['notification_email'], 'email', ['readonly' => $ro]) ?>
        <?= form_select('timezone', 'Time zone', array_combine($timezones, $timezones), $settings['timezone'], ['required' => true]) ?>
        <?= form_select('date_format', 'Date format', ['d M Y' => date('d M Y'), 'd/m/Y' => date('d/m/Y'), 'Y-m-d' => date('Y-m-d'), 'm/d/Y' => date('m/d/Y')], $settings['date_format'], ['required' => true]) ?>
        <label class="form-label" for="f_primary_color">Brand colour (used by future branded documents)</label>
        <input type="color" class="form-control form-control-color mb-3" id="f_primary_color" name="primary_color" value="<?= e(old('primary_color', $settings['primary_color'])) ?>"<?= $ro ? ' disabled' : '' ?>>
      </div></div>
    </div>
  </div>
  <?php if ($editable): ?><div class="mt-3"><button class="btn btn-primary" type="submit">Save changes</button></div><?php endif; ?>
</form>
