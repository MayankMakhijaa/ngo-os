<?php
$writable = \App\Services\SubscriptionService::isWritable($subscription);
$heading = 'Users'; $subtitle = $usage['limit'] !== null ? $usage['used_display'] . ' of ' . $usage['limit_display'] . ' users on your plan' : $usage['used_display'] . ' users';
$actions = '';
if (can('users.export')) {
    $actions .= '<a class="btn btn-outline-secondary" href="' . e(url('ngo/users/export')) . '"><i class="bi bi-download me-1" aria-hidden="true"></i>Export CSV</a>';
}
if (can('users.create') && $writable) {
    $actions .= '<a class="btn btn-primary" href="' . e(url('ngo/users/create')) . '"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Add user</a>';
}
include __DIR__ . '/../../partials/page_header.php';
?>
<?php if ($usage['state'] === 'reached'): ?>
  <div class="alert alert-warning small">Your plan's user limit is reached. Deactivate users you no longer need, or ask Fameweb to upgrade your plan, before adding more.</div>
<?php elseif ($usage['state'] === 'warning'): ?>
  <div class="alert alert-info small">You are close to your plan's user limit (<?= e($usage['remaining_display']) ?> left).</div>
<?php endif; ?>
<div class="card">
  <div class="card-body border-bottom">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-md-4"><label class="form-label small" for="q">Search</label><input class="form-control form-control-sm" type="search" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Name, email or phone"></div>
      <div class="col-6 col-md-3"><label class="form-label small" for="status">Status</label>
        <select class="form-select form-select-sm" id="status" name="status"><option value="">All</option>
          <?php foreach (['active', 'invited', 'inactive'] as $s): ?><option value="<?= $s ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
      <div class="col-6 col-md-3"><label class="form-label small" for="role_id">Role</label>
        <select class="form-select form-select-sm" id="role_id" name="role_id"><option value="">All</option>
          <?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>"<?= $f['roleId'] === (int) $r['id'] ? ' selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2 d-flex gap-2"><button class="btn btn-sm btn-primary" type="submit">Filter</button><a class="btn btn-sm btn-light" href="<?= e(url('ngo/users')) ?>">Reset</a></div>
    </form>
  </div>
  <?php if (!$page->items): ?>
    <?= empty_state('No users found', $f['q'] || $f['status'] || $f['roleId'] ? 'Try different filters.' : '', 'people') ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th scope="col">Name</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Last sign-in</th><th scope="col">Added</th></tr></thead>
    <tbody><?php foreach ($page->items as $u): ?>
      <tr><td><a class="fw-medium" href="<?= e(url('ngo/users/' . $u['id'])) ?>"><?= e($u['name']) ?></a><?= (int) $u['id'] === (int) auth_user()['id'] ? ' <span class="badge text-bg-light border">You</span>' : '' ?>
          <div class="small text-body-secondary"><?= e($u['email']) ?></div></td>
        <td><?= e($u['role_name'] ?? '—') ?></td><td><?= status_badge($u['status']) ?></td>
        <td class="small"><?= e(time_ago($u['last_login_at'])) ?></td><td class="small"><?= e(format_date($u['created_at'])) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
  <?php include __DIR__ . '/../../partials/pagination.php'; ?>
</div>
