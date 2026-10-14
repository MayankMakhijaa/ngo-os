<?php
$heading = 'Audit log'; $subtitle = 'Append-only record of security and administrative events';
$actions = '';
if (can('platform.audit.export')) {
    $actions .= '<a class="btn btn-outline-secondary" href="' . e(url('admin/audit/export') . query_with(['page' => null])) . '"><i class="bi bi-download me-1" aria-hidden="true"></i>Export CSV</a>';
}
$actions .= '<form method="post" action="' . e(url('admin/audit/verify')) . '" data-loading>' . csrf_field() . '<button class="btn btn-outline-primary" type="submit"><i class="bi bi-shield-check me-1" aria-hidden="true"></i>Verify integrity</button></form>';
include __DIR__ . '/../partials/page_header.php';
?>
<div class="card">
  <div class="card-body border-bottom">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-md-3"><label class="form-label small" for="q">Search</label><input class="form-control form-control-sm" type="search" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Actor, email, IP, details"></div>
      <div class="col-md-2"><label class="form-label small" for="action">Action</label>
        <select class="form-select form-select-sm" id="action" name="action"><option value="">All</option>
          <?php foreach (['auth.' => 'Authentication', 'tenant.' => 'NGO', 'subscription.' => 'Subscription', 'payment.' => 'Payment', 'plan.' => 'Plan', 'user.' => 'User', 'role.' => 'Role', 'settings.' => 'Settings', 'authz.' => 'Access denied'] as $k => $l): ?>
            <option value="<?= e($k) ?>"<?= $f['action'] === $k ? ' selected' : '' ?>><?= e($l) ?> (all)</option>
          <?php endforeach; ?>
          <?php foreach ($actionList as $a): ?><option value="<?= e($a) ?>"<?= $f['action'] === $a ? ' selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-2"><label class="form-label small" for="tenant_id">NGO</label>
        <select class="form-select form-select-sm" id="tenant_id" name="tenant_id"><option value="">All</option>
          <?php foreach ($tenants as $tn): ?><option value="<?= (int) $tn['id'] ?>"<?= $f['tenant_id'] === (int) $tn['id'] ? ' selected' : '' ?>><?= e($tn['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-1"><label class="form-label small" for="actor_type">Actor</label>
        <select class="form-select form-select-sm" id="actor_type" name="actor_type"><option value="">All</option>
          <?php foreach (['platform' => 'Fameweb', 'tenant' => 'NGO', 'system' => 'System', 'guest' => 'Guest'] as $k => $l): ?><option value="<?= $k ?>"<?= $f['actor_type'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="col-6 col-md-1"><label class="form-label small" for="from">From</label><input class="form-control form-control-sm" type="date" id="from" name="from" value="<?= e($f['from']) ?>"></div>
      <div class="col-6 col-md-1"><label class="form-label small" for="to">To</label><input class="form-control form-control-sm" type="date" id="to" name="to" value="<?= e($f['to']) ?>"></div>
      <div class="col-md-2 d-flex gap-2"><button class="btn btn-sm btn-primary" type="submit">Filter</button><a class="btn btn-sm btn-light" href="<?= e(url('admin/audit')) ?>">Reset</a></div>
    </form>
  </div>
  <?php if (!$page->items): ?><?= empty_state('No audit entries match', '', 'journal-text') ?><?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
    <thead><tr><th scope="col">Time</th><th scope="col">Event</th><th scope="col">Actor</th><th scope="col">NGO</th><th scope="col">Entity</th><th scope="col">IP</th><th scope="col">Details</th></tr></thead>
    <tbody><?php foreach ($page->items as $a): ?>
      <tr>
        <td class="small text-nowrap"><?= e(format_datetime($a['created_at'])) ?></td>
        <td><span class="fw-medium small"><?= e(\App\Services\DashboardService::describe($a['action'])) ?></span><div class="small text-body-secondary font-monospace"><?= e($a['action']) ?></div></td>
        <td class="small"><?= e($a['actor_name'] ?? ucfirst($a['actor_type'])) ?><div class="text-body-secondary"><?= e($a['actor_role'] ?? $a['actor_type']) ?></div></td>
        <td class="small"><?= $a['tenant_id'] ? '<a href="' . e(url('admin/ngos/' . $a['tenant_id'])) . '">' . e($a['tenant_name'] ?? '#' . $a['tenant_id']) . '</a>' : '<span class="text-body-secondary">Platform</span>' ?></td>
        <td class="small"><?= e($a['entity_type'] ?? '') ?><?= $a['entity_id'] ? ' #' . e($a['entity_id']) : '' ?></td>
        <td class="small font-monospace"><?= e($a['ip'] ?? '') ?></td>
        <td class="small text-body-secondary audit-meta"><?= e(audit_meta($a['metadata'])) ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
  <?php include __DIR__ . '/../partials/pagination.php'; ?>
</div>
