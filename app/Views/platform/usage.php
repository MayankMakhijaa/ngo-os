<?php
$heading = 'Platform usage'; $subtitle = 'Users, storage and activity per NGO, calculated from stored data';
include __DIR__ . '/../partials/page_header.php';
$warn = \App\Services\SettingsService::int('usage_warning_percent');
?>
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3"><?= stat_card('NGO users', number_format($totals['users']), 'people', 'primary', 'Active + invited') ?></div>
  <div class="col-6 col-xl-3"><?= stat_card('Storage used', format_bytes($totals['storage']), 'hdd', 'warning', number_format($totals['files']) . ' files') ?></div>
  <div class="col-6 col-xl-3"><?= stat_card('Emails this month', number_format($totals['emails']), 'envelope', 'info') ?></div>
  <div class="col-6 col-xl-3"><?= stat_card('Members, volunteers & interns', '—', 'person-hearts', 'secondary', 'Module not available yet') ?></div>
</div>
<div class="card">
  <div class="card-body border-bottom">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-md-5"><label class="form-label small" for="q">Search NGO</label><input class="form-control form-control-sm" id="q" type="search" name="q" value="<?= e($q) ?>"></div>
      <div class="col-md-4"><div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="alerts" value="1" id="alerts"<?= $alertsOnly ? ' checked' : '' ?>><label class="form-check-label small" for="alerts">Only NGOs at or above <?= (int) $warn ?>% of a limit</label></div></div>
      <div class="col-md-3 d-flex gap-2"><button class="btn btn-sm btn-primary" type="submit">Filter</button><a class="btn btn-sm btn-light" href="<?= e(url('admin/usage')) ?>">Reset</a></div>
    </form>
  </div>
  <?php if (!$rows): ?><?= empty_state('No NGOs to show', '', 'hdd-stack') ?><?php else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th scope="col">NGO</th><th scope="col">Plan</th><th scope="col">Users</th><th scope="col">Storage</th><th scope="col">Documents</th><th scope="col">Emails (month)</th><th scope="col">Last activity</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr<?= $r['alert'] ? ' class="table-warning"' : '' ?>>
        <td><a href="<?= e(url('admin/ngos/' . $r['id'])) ?>" class="fw-medium"><?= e($r['name']) ?></a><div class="small"><?= status_badge($r['status']) ?></div></td>
        <td><?= e($r['plan_name'] ?? '—') ?><?= $r['sub_status'] ? '<div>' . status_badge($r['sub_status']) . '</div>' : '' ?></td>
        <td><?= (int) $r['users_used'] ?> / <?= $r['max_users'] === null ? '∞' : (int) $r['max_users'] ?><?= $r['users_pct'] !== null ? '<div class="small text-body-secondary">' . (int) $r['users_pct'] . '%</div>' : '' ?></td>
        <td><?= e(format_bytes((int) $r['storage_used'])) ?> / <?= $r['max_storage_mb'] === null ? '∞' : e(format_bytes((int) $r['max_storage_mb'] * 1048576)) ?><?= $r['storage_pct'] !== null ? '<div class="small text-body-secondary">' . (int) $r['storage_pct'] . '%</div>' : '' ?></td>
        <td><?= (int) $r['documents_used'] ?></td><td><?= (int) $r['emails_month'] ?></td>
        <td class="small"><?= e(time_ago($r['last_activity'])) ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>
