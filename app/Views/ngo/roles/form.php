<?php
/** @var ?array $role */
$isNew = $role === null;
$heading = $isNew ? 'Create custom role' : $role['name'];
$crumbs = ['Roles' => 'ngo/roles'];
$actions = '';
if (!$isNew && $editable && !(int) $role['is_system']) {
    $actions = '<form method="post" action="' . e(url('ngo/roles/' . $role['id'] . '/delete')) . '" data-confirm="Delete the role ' . e($role['name']) . '? This is only possible when no user has it.">'
        . csrf_field() . '<button class="btn btn-outline-danger" type="submit">Delete role</button></form>';
}
include __DIR__ . '/../../partials/page_header.php';
$moduleMap = (array) config('permissions.permission_modules');
$groups = [];
foreach ($catalog as $p) { $groups[$p['module']][] = $p; }
$selected = $isNew ? (array) old('permissions', []) : $roleKeys;
?>
<?php if (!$isNew && (int) $role['is_locked']): ?>
  <div class="alert alert-light border small">The NGO Super Admin role always has every NGO permission included in your plan. It cannot be edited.</div>
<?php elseif (!$isNew && (int) $role['id'] === (int) (auth_user()['role_id'] ?? 0)): ?>
  <div class="alert alert-light border small">This is your own role, so you cannot change its permissions.</div>
<?php endif; ?>
<form method="post" action="<?= e(url($isNew ? 'ngo/roles' : 'ngo/roles/' . $role['id'])) ?>" class="needs-validation" novalidate data-loading>
  <?= csrf_field() ?>
  <div class="row g-4">
    <div class="col-lg-4">
      <div class="card"><div class="card-body">
        <?= form_input('name', 'Role name', $role['name'] ?? '', 'text', ['required' => true, 'maxlength' => 100, 'readonly' => !$isNew && ((int) $role['is_system'] || !$editable)]) ?>
        <?= form_textarea('description', 'Description', $role['description'] ?? '', ['rows' => 3, 'maxlength' => 255]) ?>
        <?php if ($editable): ?><button class="btn btn-primary" type="submit"><?= $isNew ? 'Create role' : 'Save role' ?></button><?php endif; ?>
        <a class="btn btn-light" href="<?= e(url('ngo/roles')) ?>">Back</a>
      </div></div>
    </div>
    <div class="col-lg-8">
      <div class="card"><div class="card-body">
        <h2 class="h6">Permissions</h2>
        <p class="small text-body-secondary">You can only grant permissions you hold yourself.</p>
        <?php foreach ($groups as $module => $perms): ?>
          <fieldset class="mb-3"><legend class="h6 small text-uppercase text-body-secondary"><?= e(humanize($module)) ?></legend>
          <?php foreach ($perms as $p): $k = $p['perm_key'];
            $can = $editable && in_array($k, $delegable, true);
            $modOff = isset($moduleMap[$k]) && !in_array($moduleMap[$k], $modules, true); ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="permissions[]" value="<?= e($k) ?>" id="p_<?= e(str_replace('.', '_', $k)) ?>"
                <?= in_array($k, $selected, true) ? ' checked' : '' ?><?= $can ? '' : ' disabled' ?>>
              <label class="form-check-label" for="p_<?= e(str_replace('.', '_', $k)) ?>"><?= e($p['label']) ?>
                <?= $modOff ? '<span class="badge text-bg-light border ms-1">Not in plan</span>' : '' ?></label>
            </div>
          <?php endforeach; ?>
          </fieldset>
        <?php endforeach; ?>
      </div></div>
    </div>
  </div>
</form>
