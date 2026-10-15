<?php
/** @var array $type @var \App\Core\Paginator $page @var array $f @var array $categories @var array $usage @var array $counts */
use App\Services\PeopleService;

$writable = \App\Services\SubscriptionService::isWritable($subscription);
$base = 'ngo/people/' . $type['slug'];
$heading = $type['label'];
$subtitle = $usage['limit'] !== null
    ? $usage['used_display'] . ' of ' . $usage['limit_display'] . ' active or pending members, volunteers and interns on your plan'
    : 'Registration, verification, ID cards and records';
$actions = '';
if (can('people.export') && in_array('data_export', \App\Services\SubscriptionService::enabledModules((int) $tenant['id']), true)) {
    $actions .= '<a class="btn btn-outline-secondary" href="' . e(url($base . '/export')) . '"><i class="bi bi-download me-1" aria-hidden="true"></i>Export CSV</a>';
}
if (can('people.create') && $writable) {
    $actions .= '<a class="btn btn-primary" href="' . e(url($base . '/create')) . '"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Register ' . e(strtolower($type['singular'])) . '</a>';
}
include __DIR__ . '/../../partials/page_header.php';
?>
<?php if ($usage['state'] === 'reached'): ?>
  <div class="alert alert-warning small">Your plan's limit for members, volunteers and interns is reached. Mark people who have left as inactive or completed, or ask Fameweb to upgrade your plan.</div>
<?php elseif ($usage['state'] === 'warning'): ?>
  <div class="alert alert-info small">You are close to your plan's limit (<?= e($usage['remaining_display']) ?> left).</div>
<?php endif; ?>
<div class="row g-3 mb-3">
  <?php foreach (PeopleService::STATUSES as $k => $label): ?>
    <div class="col-6 col-md-3"><?= stat_card($label, number_format((int) ($counts[$k] ?? 0)), $k === 'active' ? 'check-circle' : ($k === 'pending' ? 'hourglass-split' : ($k === 'inactive' ? 'pause-circle' : 'mortarboard')),
        $k === 'active' ? 'success' : ($k === 'pending' ? 'warning' : 'secondary'), null, url($base, ['status' => $k])) ?></div>
  <?php endforeach; ?>
</div>
<div class="card">
  <div class="card-body border-bottom">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-md-4"><label class="form-label small" for="q">Search</label><input class="form-control form-control-sm" type="search" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Name, ID, phone or email"></div>
      <div class="col-6 col-md-2"><label class="form-label small" for="status">Status</label>
        <select class="form-select form-select-sm" id="status" name="status"><option value="">All</option>
          <?php foreach (PeopleService::STATUSES as $k => $label): ?><option value="<?= e($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
      <div class="col-6 col-md-2"><label class="form-label small" for="category">Category</label>
        <select class="form-select form-select-sm" id="category" name="category"><option value="">All</option>
          <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $f['category'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-6 col-md-2"><label class="form-label small" for="verification">Verification</label>
        <select class="form-select form-select-sm" id="verification" name="verification"><option value="">All</option>
          <?php foreach (['not_submitted' => 'Not submitted', 'pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected'] as $k => $label): ?><option value="<?= $k ?>"<?= $f['verification'] === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2 d-flex gap-2"><button class="btn btn-sm btn-primary" type="submit">Filter</button><a class="btn btn-sm btn-light" href="<?= e(url($base)) ?>">Reset</a></div>
    </form>
  </div>
  <?php if (!$page->items): ?>
    <?= empty_state('No ' . strtolower($type['label']) . ' found', array_filter($f) ? 'Try different filters.' : 'Register the first ' . strtolower($type['singular']) . ' to get started.', $type['icon'],
        !array_filter($f) && can('people.create') && $writable ? url($base . '/create') : null, 'Register ' . strtolower($type['singular'])) ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th scope="col">ID</th><th scope="col">Name</th><th scope="col">Category</th><th scope="col">Status</th><th scope="col">Identity</th><th scope="col">Joined</th></tr></thead>
    <tbody><?php foreach ($page->items as $p): ?>
      <tr><td class="font-monospace small"><?= e($p['member_code']) ?></td>
        <td><a class="fw-medium" href="<?= e(url($base . '/' . $p['id'])) ?>"><?= e($p['name']) ?></a>
          <div class="small text-body-secondary"><?= e(implode(' · ', array_filter([$p['phone'], $p['email']]))) ?></div></td>
        <td class="small"><?= e($p['category_name'] ?? '—') ?></td>
        <td><?= status_badge($p['status']) ?></td>
        <td><?= verification_badge($p['verification_status']) ?></td>
        <td class="small"><?= e(format_date($p['joined_on'])) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
  <?php include __DIR__ . '/../../partials/pagination.php'; ?>
</div>
