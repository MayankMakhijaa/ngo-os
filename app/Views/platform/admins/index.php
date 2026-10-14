<?php
$heading = 'Fameweb administrators'; $subtitle = 'People who can access the Fameweb Admin panel';
$actions = '<a class="btn btn-primary" href="' . e(url('admin/admins/create')) . '"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add administrator</a>';
include __DIR__ . '/../../partials/page_header.php';
$meId = (int) auth_user()['id'];
?>
<?php if (!empty($link)): ?>
  <div class="alert alert-info"><div class="small mb-2">Single-use link (shown once). Share it securely:</div>
    <div class="input-group"><input class="form-control font-monospace small" id="adminLink" value="<?= e($link) ?>" readonly aria-label="Single-use link">
      <button class="btn btn-outline-primary" type="button" data-copy="#adminLink">Copy</button></div></div>
<?php endif; ?>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th scope="col">Name</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Last sign-in</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
  <tbody><?php foreach ($admins as $a): ?>
    <tr><td><?= e($a['name']) ?><?= (int) $a['id'] === $meId ? ' <span class="badge text-bg-light border">You</span>' : '' ?><div class="small text-body-secondary"><?= e($a['email']) ?></div></td>
      <td><?= e($a['role_name'] ?? '—') ?></td><td><?= status_badge($a['status']) ?></td><td class="small"><?= e(time_ago($a['last_login_at'])) ?></td>
      <td class="text-end">
        <?php if ((int) $a['id'] !== $meId): ?>
        <div class="d-inline-flex gap-1">
          <?php if ($a['status'] !== 'inactive'): ?>
          <form method="post" action="<?= e(url('admin/admins/' . $a['id'] . '/link')) ?>" data-confirm="Create a new single-use <?= $a['status'] === 'invited' ? 'invitation' : 'password reset' ?> link for <?= e($a['name']) ?>?"><?= csrf_field() ?>
            <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $a['status'] === 'invited' ? 'New invite link' : 'Reset link' ?></button></form>
          <?php endif; ?>
          <form method="post" action="<?= e(url('admin/admins/' . $a['id'] . '/status')) ?>" data-confirm="<?= $a['status'] === 'inactive' ? 'Reactivate ' . e($a['name']) . '?' : 'Deactivate ' . e($a['name']) . '? They are signed out immediately.' ?>"><?= csrf_field() ?>
            <button class="btn btn-sm <?= $a['status'] === 'inactive' ? 'btn-outline-success' : 'btn-outline-danger' ?>" type="submit"><?= $a['status'] === 'inactive' ? 'Reactivate' : 'Deactivate' ?></button></form>
        </div>
        <?php endif; ?>
      </td></tr>
  <?php endforeach; ?></tbody>
</table></div></div>
