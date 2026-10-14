<?php
$heading = 'Subscription plans'; $subtitle = 'Prices, limits and modules offered to NGOs';
$actions = can('plans.manage') ? '<a class="btn btn-primary" href="' . e(url('admin/plans/create')) . '"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Create plan</a>' : '';
include __DIR__ . '/../../partials/page_header.php';
$unl = static fn ($v, $suffix = '') => $v === null ? 'Unlimited' : number_format((int) $v) . $suffix;
?>
<div class="card">
  <div class="card-body border-bottom">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-md-5"><label class="form-label small" for="q">Search plans</label><input class="form-control form-control-sm" type="search" id="q" name="q" value="<?= e($q) ?>"></div>
      <div class="col-md-3"><label class="form-label small" for="status">Status</label>
        <select class="form-select form-select-sm" id="status" name="status"><option value="">All</option>
          <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option><option value="inactive"<?= $status === 'inactive' ? ' selected' : '' ?>>Inactive</option></select></div>
      <div class="col-md-4 d-flex gap-2"><button class="btn btn-sm btn-primary" type="submit">Filter</button><a class="btn btn-sm btn-light" href="<?= e(url('admin/plans')) ?>">Reset</a></div>
    </form>
  </div>
  <?php if (!$plans): ?>
    <?= empty_state('No plans found', 'Create a plan with prices and usage limits before registering NGOs.', 'layers', can('plans.manage') ? url('admin/plans/create') : null, 'Create plan') ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th scope="col">Plan</th><th scope="col">Monthly</th><th scope="col">Annual</th><th scope="col">Trial</th><th scope="col">Users</th><th scope="col">Members</th><th scope="col">Storage</th><th scope="col">NGOs</th><th scope="col">Status</th></tr></thead>
    <tbody>
    <?php foreach ($plans as $p): ?>
      <tr>
        <td><a class="fw-medium" href="<?= e(url('admin/plans/' . $p['id'])) ?>"><?= e($p['name']) ?></a><?= $p['is_demo'] ? ' <span class="badge text-bg-light border">Demo</span>' : '' ?>
          <div class="small text-body-secondary"><?= e($p['modules'] ? count(explode(',', $p['modules'])) . ' extra module(s)' : 'Core modules only') ?></div></td>
        <td><?= e(format_money($p['monthly_price'], $p['currency'])) ?></td>
        <td><?= e(format_money($p['annual_price'], $p['currency'])) ?></td>
        <td><?= $p['trial_days'] ? (int) $p['trial_days'] . ' days' : '—' ?></td>
        <td><?= e($unl($p['max_users'])) ?></td>
        <td><?= e($unl($p['max_members'])) ?></td>
        <td><?= $p['max_storage_mb'] === null ? 'Unlimited' : e(format_bytes((int) $p['max_storage_mb'] * 1048576)) ?></td>
        <td><?= (int) $p['tenant_count'] ?></td>
        <td><?= status_badge($p['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
