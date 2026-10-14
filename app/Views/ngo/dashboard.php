<?php
$me = auth_user();
$heading = 'Welcome, ' . $me['name']; $subtitle = $tenant['name'] . ' · ' . ($me['role_name'] ?? '');
$actions = (can('users.create') && \App\Services\SubscriptionService::isWritable($subscription))
    ? '<a class="btn btn-primary" href="' . e(url('ngo/users/create')) . '"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Add user</a>' : '';
include __DIR__ . '/../partials/page_header.php';
$u = $stats['users'];
?>
<div class="row g-3 mb-4">
  <?php if (can('users.view')): ?>
  <div class="col-6 col-xl-3"><?= stat_card('Active users', number_format($u['active']), 'person-check', 'success', null, url('ngo/users', ['status' => 'active'])) ?></div>
  <div class="col-6 col-xl-3"><?= stat_card('Pending invitations', number_format($u['invited']), 'envelope', 'warning', null, url('ngo/users', ['status' => 'invited'])) ?></div>
  <div class="col-6 col-xl-3"><?= stat_card('Inactive users', number_format($u['inactive']), 'person-dash', 'secondary', null, url('ngo/users', ['status' => 'inactive'])) ?></div>
  <?php endif; ?>
  <div class="col-6 col-xl-3"><?= stat_card('Subscription', $subscription ? $subscription['plan_name'] : 'None', 'credit-card', $subscription && \App\Services\SubscriptionService::isWritable($subscription) ? 'primary' : 'danger',
      $subscription ? humanize($subscription['status']) . ($subscription['end_date'] ? ' · until ' . format_date($subscription['end_date']) : '') : 'Contact Fameweb', can('subscription.view') ? url('ngo/subscription') : null) ?></div>
  <div class="col-6 col-xl-3"><?= stat_card('Members, volunteers & interns', '—', 'person-hearts', 'secondary', 'Coming in a later phase') ?></div>
</div>

<div class="row g-3">
  <?php if ($usage !== null): ?>
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-body">
      <div class="d-flex justify-content-between"><h2 class="h6">Plan usage</h2><a class="small" href="<?= e(url('ngo/usage')) ?>">Details</a></div>
      <?php foreach (['users', 'storage', 'members'] as $k): $m = $usage[$k]; ?>
        <div class="mb-3"><div class="small fw-medium mb-1"><?= e($m['label']) ?>
          <?= $m['state'] === 'warning' ? '<span class="badge text-bg-warning ms-1">Approaching limit</span>' : ($m['state'] === 'reached' ? '<span class="badge text-bg-danger ms-1">Limit reached</span>' : '') ?></div>
          <?= usage_bar($m) ?></div>
      <?php endforeach; ?>
    </div></div>
  </div>
  <?php endif; ?>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><h2 class="h6 mb-0">Recent activity</h2></div>
      <?php if (!$stats['activity']): ?><?= empty_state('No activity yet', '', 'activity') ?><?php else: ?>
      <ul class="list-group list-group-flush">
        <?php foreach ($stats['activity'] as $a): ?>
          <li class="list-group-item small d-flex justify-content-between gap-2"><div><span class="fw-medium"><?= e(\App\Services\DashboardService::describe($a['action'])) ?></span>
            <span class="text-body-secondary">· <?= e($a['actor_name'] ?? ucfirst($a['actor_type'])) ?></span></div>
            <time class="text-body-secondary text-nowrap"><?= e(time_ago($a['created_at'])) ?></time></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>
  <?php if (can('users.view') && $stats['recent_logins']): ?>
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-header"><h2 class="h6 mb-0">Recent sign-ins</h2></div>
      <ul class="list-group list-group-flush">
        <?php foreach ($stats['recent_logins'] as $r): ?>
          <li class="list-group-item small d-flex justify-content-between"><a href="<?= e(url('ngo/users/' . $r['id'])) ?>"><?= e($r['name']) ?></a>
            <span class="text-body-secondary"><?= e($r['role_name'] ?? '') ?> · <?= e(time_ago($r['last_login_at'])) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <?php endif; ?>
</div>
