<?php
$writable = \App\Services\SubscriptionService::isWritable($subscription);
$heading = 'Permissions'; $subtitle = $u['name'] . ' · role: ' . ($u['role_name'] ?? 'none');
$crumbs = ['Users' => 'ngo/users', $u['name'] => 'ngo/users/' . $u['id']];
include __DIR__ . '/../../partials/page_header.php';
$moduleMap = (array) config('permissions.permission_modules');
$groups = [];
foreach ($catalog as $p) { $groups[$p['module']][] = $p; }
$canEdit = $canManage && $writable;
?>
<p class="small text-body-secondary">A user gets their role's permissions. Here you can add (<strong>Grant</strong>) or remove (<strong>Deny</strong>) individual permissions for this user only. You can only change permissions you hold yourself.</p>
<form method="post" action="<?= e(url('ngo/users/' . $u['id'] . '/permissions')) ?>" data-confirm="Save permission changes for <?= e($u['name']) ?>? They take effect immediately.">
  <?= csrf_field() ?>
  <div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th scope="col">Permission</th><th scope="col">From role</th><th scope="col">Override</th><th scope="col">Result</th></tr></thead>
    <tbody>
    <?php foreach ($groups as $module => $perms): ?>
      <tr class="table-light"><th colspan="4" scope="rowgroup" class="small text-uppercase text-body-secondary"><?= e(humanize($module)) ?></th></tr>
      <?php foreach ($perms as $p): $k = $p['perm_key'];
        $current = $overrides[$k] ?? 'inherit';
        $modOff = isset($moduleMap[$k]) && !in_array($moduleMap[$k], $modules, true);
        $editable = $canEdit && in_array($k, $delegable, true); ?>
        <tr>
          <td><?= e($p['label']) ?><div class="small text-body-secondary font-monospace"><?= e($k) ?></div>
            <?= $modOff ? '<div class="small text-warning-emphasis">Module not in your plan</div>' : '' ?></td>
          <td><?= in_array($k, $roleKeys, true) ? '<i class="bi bi-check-lg text-success" aria-label="Yes"></i>' : '<span class="text-body-tertiary" aria-label="No">—</span>' ?></td>
          <td>
            <?php if ($editable): ?>
              <select class="form-select form-select-sm" name="perm[<?= e($k) ?>]" aria-label="Override for <?= e($p['label']) ?>">
                <option value="inherit"<?= $current === 'inherit' ? ' selected' : '' ?>>Use role</option>
                <option value="grant"<?= $current === 'grant' ? ' selected' : '' ?>>Grant</option>
                <option value="deny"<?= $current === 'deny' ? ' selected' : '' ?>>Deny</option>
              </select>
            <?php else: ?>
              <span class="small text-body-secondary"><?= e(['inherit' => 'Use role', 'grant' => 'Granted', 'deny' => 'Denied'][$current]) ?></span>
            <?php endif; ?>
          </td>
          <td><?= in_array($k, $effective, true) ? '<span class="badge text-bg-success">Allowed</span>' : '<span class="badge text-bg-light border">Not allowed</span>' ?></td>
        </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table></div></div>
  <?php if ($canEdit): ?><div class="mt-3"><button class="btn btn-primary" type="submit">Save permissions</button>
    <a class="btn btn-light" href="<?= e(url('ngo/users/' . $u['id'])) ?>">Cancel</a></div><?php endif; ?>
</form>
