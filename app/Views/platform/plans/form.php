<?php
/** @var ?array $plan */
$isEdit = $plan !== null;
$heading = $isEdit ? 'Edit plan' : 'Create plan';
$crumbs = ['Plans' => 'admin/plans'] + ($isEdit ? [$plan['name'] => 'admin/plans/' . $plan['id']] : []);
include __DIR__ . '/../../partials/page_header.php';
$mb = $plan['max_storage_mb'] ?? null;
$storageUnit = ($mb !== null && $mb >= 1024 && $mb % 1024 === 0) ? 'GB' : 'MB';
$storageValue = $mb === null ? '' : ($storageUnit === 'GB' ? $mb / 1024 : $mb);
$modules = array_filter((array) config('modules'), static fn ($m) => !$m['core']);
$selected = old('modules', $planModules);
$selected = is_array($selected) ? $selected : [];
?>
<form method="post" action="<?= e(url($isEdit ? 'admin/plans/' . $plan['id'] : 'admin/plans')) ?>" class="needs-validation" novalidate data-loading>
  <?= csrf_field() ?>
  <div class="row g-4">
    <div class="col-lg-7">
      <div class="card"><div class="card-body">
        <h2 class="h6 mb-3">Plan details</h2>
        <?= form_input('name', 'Plan name', $plan['name'] ?? '', 'text', ['required' => true, 'maxlength' => 100]) ?>
        <?= form_textarea('description', 'Description', $plan['description'] ?? '', ['rows' => 2, 'maxlength' => 2000]) ?>
        <div class="row">
          <div class="col-sm-4"><?= form_input('monthly_price', 'Monthly price (' . config('app.currency') . ')', $plan['monthly_price'] ?? '0', 'number', ['required' => true, 'min' => 0, 'step' => '0.01']) ?></div>
          <div class="col-sm-4"><?= form_input('annual_price', 'Annual price (' . config('app.currency') . ')', $plan['annual_price'] ?? '0', 'number', ['required' => true, 'min' => 0, 'step' => '0.01']) ?></div>
          <div class="col-sm-4"><?= form_input('trial_days', 'Trial duration (days)', $plan['trial_days'] ?? '', 'number', ['min' => 0, 'max' => 365, 'help' => 'Optional']) ?></div>
        </div>
        <?= form_select('status', 'Plan status', ['active' => 'Active (can be assigned)', 'inactive' => 'Inactive'], $plan['status'] ?? 'active', ['required' => true]) ?>
      </div></div>
    </div>
    <div class="col-lg-5">
      <div class="card mb-4"><div class="card-body">
        <h2 class="h6 mb-1">Usage limits</h2>
        <p class="small text-body-secondary">Leave empty for unlimited. Limits are enforced on the server; reaching one blocks new additions but never deletes data.</p>
        <?= form_input('max_users', 'Maximum NGO users', $plan['max_users'] ?? '', 'number', ['min' => 0]) ?>
        <?= form_input('max_members', 'Maximum members & volunteers', $plan['max_members'] ?? '', 'number', ['min' => 0, 'help' => 'Applies when the Members module launches']) ?>
        <label class="form-label" for="f_storage_value">Maximum document storage</label>
        <div class="input-group mb-3">
          <input type="number" class="form-control<?= invalid_class('storage_value') ?>" id="f_storage_value" name="storage_value" min="0" step="any" value="<?= e(old('storage_value', $storageValue)) ?>">
          <select class="form-select flex-grow-0 w-auto" name="storage_unit" aria-label="Storage unit">
            <?php foreach (['MB', 'GB'] as $u): ?><option<?= old('storage_unit', $storageUnit) === $u ? ' selected' : '' ?>><?= $u ?></option><?php endforeach; ?>
          </select>
          <div class="invalid-feedback"><?= e(field_error('storage_value') ?? 'Enter a number') ?></div>
        </div>
        <?= form_input('max_certificates', 'Certificate allowance', $plan['max_certificates'] ?? '', 'number', ['min' => 0, 'help' => 'Reserved for the certificate module']) ?>
      </div></div>
      <div class="card"><div class="card-body">
        <h2 class="h6 mb-1">Enabled modules</h2>
        <p class="small text-body-secondary">Dashboard, Users &amp; Roles, Organization and Subscription are always included.</p>
        <fieldset>
          <legend class="visually-hidden">Modules</legend>
          <?php foreach ($modules as $key => $m): ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="modules[]" value="<?= e($key) ?>" id="mod_<?= e($key) ?>"<?= in_array($key, $selected, true) ? ' checked' : '' ?>>
              <label class="form-check-label" for="mod_<?= e($key) ?>"><?= e($m['label']) ?><?= $m['available'] ? '' : ' <span class="badge text-bg-light border">Future module</span>' ?></label>
            </div>
          <?php endforeach; ?>
        </fieldset>
      </div></div>
    </div>
  </div>
  <div class="d-flex gap-2 mt-4">
    <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Save plan' : 'Create plan' ?></button>
    <a class="btn btn-light" href="<?= e(url($isEdit ? 'admin/plans/' . $plan['id'] : 'admin/plans')) ?>">Cancel</a>
  </div>
</form>
