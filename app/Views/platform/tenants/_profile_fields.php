<?php /** @var array $t current values */ ?>
<div class="row">
  <div class="col-md-8"><?= form_input('name', 'NGO name', $t['name'] ?? '', 'text', ['required' => true, 'maxlength' => 190]) ?></div>
  <div class="col-md-4"><?= form_input('registration_number', 'NGO registration number', $t['registration_number'] ?? '', 'text', ['maxlength' => 100, 'help' => 'Optional']) ?></div>
</div>
<?= form_textarea('address', 'Registered / operating address', $t['address'] ?? '', ['required' => true, 'rows' => 2, 'maxlength' => 1000]) ?>
<div class="row">
  <div class="col-md-6"><?= form_input('contact_name', 'Contact person', $t['contact_name'] ?? '', 'text', ['required' => true, 'maxlength' => 120]) ?></div>
  <div class="col-md-6"><?= form_input('phone', 'Contact phone', $t['phone'] ?? '', 'tel', ['required' => true, 'maxlength' => 20, 'placeholder' => '+91 98765 43210']) ?></div>
  <div class="col-md-6"><?= form_input('email', 'Official email', $t['email'] ?? '', 'email', ['required' => true, 'maxlength' => 190]) ?></div>
  <div class="col-md-6"><?= form_input('website', 'Website', $t['website'] ?? '', 'url', ['maxlength' => 255, 'placeholder' => 'https://', 'help' => 'Optional']) ?></div>
</div>
<?= form_input('logo', 'Organization logo', '', 'file', ['accept' => 'image/png,image/jpeg,image/webp', 'help' => 'Optional. PNG, JPG or WebP, up to 2 MB. Stored privately and counted against storage.']) ?>
<?= form_textarea('notes', 'Internal notes', $t['notes'] ?? '', ['rows' => 3, 'maxlength' => 5000, 'help' => 'Visible to Fameweb administrators only.']) ?>
