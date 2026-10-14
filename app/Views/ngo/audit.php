<?php
$heading = 'Audit log'; $subtitle = 'Security and administrative events in your organization, including any Fameweb staff access';
$actions = can('audit.export') ? '<a class="btn btn-outline-secondary" href="' . e(url('ngo/audit/export') . query_with(['page' => null])) . '"><i class="bi bi-download me-1" aria-hidden="true"></i>Export CSV</a>' : '';
include __DIR__ . '/../partials/page_header.php';
?>
<div class="card">
  <div class="card-body border-bottom">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-md-3"><label class="form-label small" for="action">Event type</label>
        <select class="form-select form-select-sm" id="action" name="action"><option value="">All</option>
          <?php foreach (['auth.' => 'Sign-in & passwords', 'user.' => 'Users', 'role.' => 'Roles', 'tenant.' => 'Organization', 'subscription.' => 'Subscription', 'authz.' => 'Access denied', 'file.' => 'Files'] as $k => $l): ?>
            <option value="<?= e($k) ?>"<?= $f['action'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3"><label class="form-label small" for="user_id">User</label>
        <select class="form-select form-select-sm" id="user_id" name="user_id"><option value="">All</option>
          <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"<?= $f['user_id'] === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-6 col-md-2"><label class="form-label small" for="from">From</label><input class="form-control form-control-sm" type="date" id="from" name="from" value="<?= e($f['from']) ?>"></div>
      <div class="col-6 col-md-2"><label class="form-label small" for="to">To</label><input class="form-control form-control-sm" type="date" id="to" name="to" value="<?= e($f['to']) ?>"></div>
      <div class="col-md-2 d-flex gap-2"><button class="btn btn-sm btn-primary" type="submit">Filter</button><a class="btn btn-sm btn-light" href="<?= e(url('ngo/audit')) ?>">Reset</a></div>
    </form>
  </div>
  <?php if (!$page->items): ?><?= empty_state('No events match', '', 'journal-text') ?><?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
    <thead><tr><th scope="col">Time</th><th scope="col">Event</th><th scope="col">By</th><th scope="col">IP</th><th scope="col">Details</th></tr></thead>
    <tbody><?php foreach ($page->items as $a): ?>
      <tr><td class="small text-nowrap"><?= e(format_datetime($a['created_at'])) ?></td>
        <td class="small"><span class="fw-medium"><?= e(\App\Services\DashboardService::describe($a['action'])) ?></span><?= $a['entity_type'] ? '<div class="text-body-secondary">' . e($a['entity_type']) . ($a['entity_id'] ? ' #' . e($a['entity_id']) : '') . '</div>' : '' ?></td>
        <td class="small"><?= e($a['actor_name'] ?? ucfirst($a['actor_type'])) ?></td>
        <td class="small font-monospace"><?= e($a['ip'] ?? '') ?></td>
        <td class="small text-body-secondary audit-meta"><?= e(audit_meta($a['metadata'])) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
  <?php include __DIR__ . '/../partials/pagination.php'; ?>
</div>
