<?php
$heading = 'NGOs'; $subtitle = 'Every NGO workspace on the platform';
$actions = can('tenants.manage') ? '<a class="btn btn-primary" href="' . e(url('admin/ngos/create')) . '"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Register NGO</a>' : '';
include __DIR__ . '/../../partials/page_header.php';
?>
<div class="card">
  <div class="card-body border-bottom">
    <form class="row g-2 align-items-end" method="get" action="<?= e(url('admin/ngos')) ?>">
      <div class="col-12 col-md-4"><label class="form-label small" for="q">Search</label>
        <input class="form-control form-control-sm" type="search" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Name, reference code, email, contact"></div>
      <div class="col-6 col-md-2"><label class="form-label small" for="status">Account status</label>
        <select class="form-select form-select-sm" id="status" name="status"><option value="">All</option>
          <?php foreach (\App\Services\TenantService::STATUSES as $st): ?><option value="<?= e($st) ?>"<?= $filters['status'] === $st ? ' selected' : '' ?>><?= e(humanize($st)) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-6 col-md-2"><label class="form-label small" for="sub_status">Subscription</label>
        <select class="form-select form-select-sm" id="sub_status" name="sub_status"><option value="">All</option>
          <?php foreach (\App\Services\SubscriptionService::STATUSES as $st): ?><option value="<?= e($st) ?>"<?= $filters['subStatus'] === $st ? ' selected' : '' ?>><?= e(humanize($st)) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-6 col-md-2"><label class="form-label small" for="plan_id">Plan</label>
        <select class="form-select form-select-sm" id="plan_id" name="plan_id"><option value="">All</option>
          <?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $filters['planId'] === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-6 col-md-2 d-flex gap-2">
        <button class="btn btn-sm btn-primary flex-grow-1" type="submit">Filter</button>
        <a class="btn btn-sm btn-light" href="<?= e(url('admin/ngos')) ?>">Reset</a>
      </div>
    </form>
  </div>
  <?php if (!$page->items): ?>
    <?= empty_state('No NGOs found', $filters['q'] || $filters['status'] || $filters['subStatus'] || $filters['planId'] ? 'Try different filters.' : 'Register the first NGO to get started.', 'buildings',
        can('tenants.manage') ? url('admin/ngos/create') : null, 'Register NGO') ?>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr>
        <th scope="col"><a href="<?= e(query_with(['sort' => 'name', 'page' => null])) ?>">NGO</a></th>
        <th scope="col">Contact</th><th scope="col">Plan</th><th scope="col">Users</th>
        <th scope="col"><a href="<?= e(query_with(['sort' => 'expiry', 'page' => null])) ?>">Renewal</a></th>
        <th scope="col">Status</th><th scope="col"><a href="<?= e(query_with(['sort' => null, 'page' => null])) ?>">Registered</a></th>
      </tr></thead>
      <tbody>
      <?php foreach ($page->items as $t): ?>
        <tr>
          <td><a class="fw-medium" href="<?= e(url('admin/ngos/' . $t['id'])) ?>"><?= e($t['name']) ?></a>
            <div class="small text-body-secondary"><?= e($t['reference_code']) ?><?= $t['is_demo'] ? ' · <span class="badge text-bg-light border">Demo</span>' : '' ?></div></td>
          <td class="small"><?= e($t['contact_name']) ?><div class="text-body-secondary"><?= e($t['email']) ?></div></td>
          <td><?= e($t['plan_name'] ?? '—') ?><?= $t['sub_status'] ? '<div>' . status_badge($t['sub_status']) . '</div>' : '' ?></td>
          <td><?= (int) $t['user_count'] ?></td>
          <td class="small"><?= e(format_date($t['end_date'])) ?></td>
          <td><?= status_badge($t['status']) ?></td>
          <td class="small"><?= e(format_date($t['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php include __DIR__ . '/../../partials/pagination.php'; ?>
</div>
