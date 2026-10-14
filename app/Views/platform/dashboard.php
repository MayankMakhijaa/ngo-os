<?php
/** @var array $stats */

$t = $stats['tenants']; $s = $stats['subscriptions']; $cur = $stats['currency'];
$heading = 'SaaS dashboard'; $subtitle = 'Live figures from the platform database';
$actions = can('tenants.manage') ? '<a class="btn btn-primary" href="' . e(url('admin/ngos/create')) . '"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Register NGO</a>' : '';
include __DIR__ . '/../partials/page_header.php';
?>
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3"><?= stat_card('Total NGOs', number_format($t['total']), 'buildings', 'primary', $t['deactivated'] . ' deactivated', url('admin/ngos')) ?></div>
  <div class="col-6 col-xl-3"><?= stat_card('Active NGOs', number_format($t['active']), 'check-circle', 'success', null, url('admin/ngos', ['status' => 'active'])) ?></div>
  <div class="col-6 col-xl-3"><?= stat_card('Suspended NGOs', number_format($t['suspended']), 'pause-circle', 'danger', null, url('admin/ngos', ['status' => 'suspended'])) ?></div>
  <div class="col-6 col-xl-3"><?= stat_card('NGOs on trial', number_format($s['trial'] ?? 0), 'hourglass-split', 'info', null, url('admin/ngos', ['sub_status' => 'trial'])) ?></div>
  <div class="col-6 col-xl-3"><?= stat_card('Active subscriptions', number_format($s['active'] ?? 0), 'arrow-repeat', 'success', ($s['past_due'] ?? 0) . ' past due', url('admin/subscriptions', ['status' => 'active'])) ?></div>
  <div class="col-6 col-xl-3"><?= stat_card('Expired subscriptions', number_format($s['expired'] ?? 0), 'calendar-x', 'secondary', ($s['cancelled'] ?? 0) . ' cancelled', url('admin/subscriptions', ['status' => 'expired'])) ?></div>
  <div class="col-6 col-xl-3"><?= stat_card('NGO users', number_format($stats['ngo_users']), 'people', 'primary', 'Active and invited') ?></div>
  <div class="col-6 col-xl-3"><?= stat_card('Storage used', format_bytes($stats['storage_bytes']), 'hdd', 'warning', 'Across all NGOs', url('admin/usage')) ?></div>
  <div class="col-6 col-xl-3"><?= stat_card('Members, volunteers & interns', '—', 'person-hearts', 'secondary', 'Available when those modules launch') ?></div>
  <div class="col-6 col-xl-3"><?= stat_card('MRR', $stats['mrr']['counted'] > 0 ? format_money($stats['mrr']['mrr'], $cur) : '—', 'graph-up-arrow', 'success',
      $stats['mrr']['counted'] > 0 ? $stats['mrr']['counted'] . ' paying subscriptions' . ($stats['mrr']['custom_excluded'] ? ', ' . $stats['mrr']['custom_excluded'] . ' custom excluded' : '') : 'No active paid subscriptions yet') ?></div>
  <div class="col-6 col-xl-3"><?= stat_card('Revenue this month', format_money($stats['revenue']['this_month'], $cur), 'cash-coin', 'success', 'Recorded paid payments') ?></div>
  <div class="col-6 col-xl-3"><?= stat_card('Revenue all time', format_money($stats['revenue']['total'], $cur), 'bank', 'primary', number_format((int) $stats['revenue']['payments']) . ' paid payments', url('admin/payments')) ?></div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card h-100"><div class="card-body">
      <h2 class="h6">Recorded revenue, last 12 months</h2>
      <?php if ((float) $stats['revenue']['total'] > 0): ?>
        <div class="chart-box"><canvas id="revenueChart" aria-label="Bar chart of recorded revenue per month" role="img"></canvas></div>
        <script type="application/json" id="revenueData"><?= json_encode(['labels' => array_map(static fn ($ym) => date('M Y', strtotime($ym . '-01')), array_keys($stats['revenue_series'])), 'values' => array_values($stats['revenue_series']), 'currency' => $cur], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
      <?php else: ?>
        <?= empty_state('No payments recorded yet', 'Revenue appears here once payments are recorded against subscriptions.', 'cash-stack') ?>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100"><div class="card-body">
      <h2 class="h6">Subscription status</h2>
      <?php $subTotal = array_sum($s); if ($subTotal > 0): ?>
        <div class="chart-box chart-box-sm"><canvas id="subChart" aria-label="Doughnut chart of current subscription statuses" role="img"></canvas></div>
        <script type="application/json" id="subData"><?= json_encode(['labels' => array_map('humanize', array_keys($s)), 'values' => array_values($s)], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
      <?php else: ?>
        <?= empty_state('No subscriptions yet', '', 'pie-chart') ?>
      <?php endif; ?>
    </div></div>
  </div>
</div>

<div class="row g-3">
  <div class="col-xl-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center"><h2 class="h6 mb-0">Recently registered NGOs</h2><a class="small" href="<?= e(url('admin/ngos')) ?>">View all</a></div>
      <?php if (!$stats['recent_tenants']): ?>
        <?= empty_state('No NGOs yet', 'Register the first NGO to get started.', 'buildings', can('tenants.manage') ? url('admin/ngos/create') : null, 'Register NGO') ?>
      <?php else: ?>
      <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr><th scope="col">NGO</th><th scope="col">Plan</th><th scope="col">Status</th><th scope="col">Registered</th></tr></thead>
        <tbody>
        <?php foreach ($stats['recent_tenants'] as $r): ?>
          <tr>
            <td><a href="<?= e(url('admin/ngos/' . $r['id'])) ?>" class="fw-medium"><?= e($r['name']) ?></a><div class="small text-body-secondary"><?= e($r['reference_code']) ?></div></td>
            <td><?= e($r['plan_name'] ?? '—') ?><?= $r['sub_status'] ? '<div>' . status_badge($r['sub_status']) . '</div>' : '' ?></td>
            <td><?= status_badge($r['status']) ?></td>
            <td class="small"><?= e(format_date($r['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-xl-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center"><h2 class="h6 mb-0">Upcoming expirations (30 days)</h2><a class="small" href="<?= e(url('admin/subscriptions', ['expiring' => '30'])) ?>">View all</a></div>
      <?php if (!$stats['expiring']): ?>
        <?= empty_state('Nothing expiring soon', 'No current subscription ends in the next 30 days.', 'calendar-check') ?>
      <?php else: ?>
      <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr><th scope="col">NGO</th><th scope="col">Plan</th><th scope="col">Status</th><th scope="col">Ends</th></tr></thead>
        <tbody>
        <?php foreach ($stats['expiring'] as $r): $days = (int) floor((strtotime($r['end_date']) - strtotime(date('Y-m-d'))) / 86400); ?>
          <tr>
            <td><a href="<?= e(url('admin/ngos/' . $r['tenant_id'])) ?>"><?= e($r['tenant_name']) ?></a></td>
            <td><?= e($r['plan_name']) ?></td>
            <td><?= status_badge($r['status']) ?></td>
            <td class="small"><?= e(format_date($r['end_date'])) ?><div class="<?= $days < 0 ? 'text-danger' : 'text-body-secondary' ?>"><?= $days < 0 ? abs($days) . ' days ago' : ($days === 0 ? 'today' : "in $days days") ?></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center"><h2 class="h6 mb-0">Recent platform activity</h2><?php if (can('platform.audit.view')): ?><a class="small" href="<?= e(url('admin/audit')) ?>">Full audit log</a><?php endif; ?></div>
      <?php if (!$stats['activity']): ?>
        <?= empty_state('No activity yet', '', 'activity') ?>
      <?php else: ?>
      <ul class="list-group list-group-flush">
        <?php foreach ($stats['activity'] as $a): ?>
          <li class="list-group-item d-flex justify-content-between gap-3 small">
            <div class="min-w-0"><span class="fw-medium"><?= e(\App\Services\DashboardService::describe($a['action'])) ?></span>
              <?= $a['tenant_name'] ? '<span class="text-body-secondary"> · ' . e($a['tenant_name']) . '</span>' : '' ?>
              <div class="text-body-secondary text-truncate"><?= e($a['actor_name'] ?? ucfirst($a['actor_type'])) ?><?= $a['ip'] ? ' · ' . e($a['ip']) : '' ?></div></div>
            <time class="text-body-secondary text-nowrap" datetime="<?= e($a['created_at']) ?>"><?= e(time_ago($a['created_at'])) ?></time>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>
</div>
