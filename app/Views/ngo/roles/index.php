<?php
$heading = 'Roles & permissions'; $subtitle = 'Default roles plus any custom roles you create';
$actions = can('roles.manage') && \App\Services\SubscriptionService::isWritable($subscription)
    ? '<a class="btn btn-primary" href="' . e(url('ngo/roles/create')) . '"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Create custom role</a>' : '';
include __DIR__ . '/../../partials/page_header.php';
?>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th scope="col">Role</th><th scope="col">Type</th><th scope="col">Permissions</th><th scope="col">Users</th></tr></thead>
  <tbody><?php foreach ($roles as $r): ?>
    <tr><td><a class="fw-medium" href="<?= e(url('ngo/roles/' . $r['id'])) ?>"><?= e($r['name']) ?></a><div class="small text-body-secondary"><?= e($r['description'] ?? '') ?></div></td>
      <td><?= (int) $r['is_locked'] ? '<span class="badge text-bg-dark">Fixed</span>' : ((int) $r['is_system'] ? '<span class="badge text-bg-primary">Default</span>' : '<span class="badge text-bg-info">Custom</span>') ?></td>
      <td><?= (int) $r['permission_count'] ?></td><td><?= (int) $r['user_count'] ?></td></tr>
  <?php endforeach; ?></tbody>
</table></div></div>
