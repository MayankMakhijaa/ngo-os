<?php
$heading = $plan['name']; $crumbs = ['Plans' => 'admin/plans'];
$actions = '';
if (can('plans.manage')) {
    $actions .= '<a class="btn btn-outline-secondary" href="' . e(url('admin/plans/' . $plan['id'] . '/edit')) . '"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit</a>';
    $actions .= '<form method="post" action="' . e(url('admin/plans/' . $plan['id'] . '/toggle')) . '" data-confirm="'
        . e($plan['status'] === 'active' ? 'Deactivate this plan? It can no longer be assigned. NGOs already on it keep their subscription.' : 'Activate this plan so it can be assigned to NGOs?') . '">'
        . csrf_field() . '<button class="btn ' . ($plan['status'] === 'active' ? 'btn-outline-danger' : 'btn-success') . '" type="submit">' . ($plan['status'] === 'active' ? 'Deactivate' : 'Activate') . '</button></form>';
}
include __DIR__ . '/../../partials/page_header.php';
$unl = static fn ($v) => $v === null ? 'Unlimited' : number_format((int) $v);
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card h-100"><div class="card-body">
      <div class="mb-2"><?= status_badge($plan['status']) ?></div>
      <p><?= nl2br(e($plan['description'] ?? '')) ?></p>
      <dl class="row small mb-0">
        <dt class="col-6 text-body-secondary">Monthly price</dt><dd class="col-6"><?= e(format_money($plan['monthly_price'], $plan['currency'])) ?></dd>
        <dt class="col-6 text-body-secondary">Annual price</dt><dd class="col-6"><?= e(format_money($plan['annual_price'], $plan['currency'])) ?></dd>
        <dt class="col-6 text-body-secondary">Trial</dt><dd class="col-6"><?= $plan['trial_days'] ? (int) $plan['trial_days'] . ' days' : 'None' ?></dd>
        <dt class="col-6 text-body-secondary">Max NGO users</dt><dd class="col-6"><?= e($unl($plan['max_users'])) ?></dd>
        <dt class="col-6 text-body-secondary">Max members & volunteers</dt><dd class="col-6"><?= e($unl($plan['max_members'])) ?></dd>
        <dt class="col-6 text-body-secondary">Max storage</dt><dd class="col-6"><?= $plan['max_storage_mb'] === null ? 'Unlimited' : e(format_bytes((int) $plan['max_storage_mb'] * 1048576)) ?></dd>
        <dt class="col-6 text-body-secondary">Certificate allowance</dt><dd class="col-6"><?= e($unl($plan['max_certificates'])) ?></dd>
        <dt class="col-6 text-body-secondary">Created</dt><dd class="col-6"><?= e(format_datetime($plan['created_at'])) ?></dd>
        <dt class="col-6 text-body-secondary">Updated</dt><dd class="col-6"><?= e(format_datetime($plan['updated_at'])) ?></dd>
      </dl>
      <h2 class="h6 mt-3">Modules</h2>
      <ul class="small mb-0">
        <?php foreach ((array) config('modules') as $key => $m): if (!$m['core'] && !in_array($key, $planModules, true)) continue; ?>
          <li><?= e($m['label']) ?><?= $m['core'] ? ' <span class="text-body-secondary">(core)</span>' : '' ?></li>
        <?php endforeach; ?>
      </ul>
    </div></div>
  </div>
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><h2 class="h6 mb-0">NGOs on this plan</h2></div>
      <?php if (!$tenants): ?><?= empty_state('No NGOs on this plan yet', '', 'buildings') ?><?php else: ?>
      <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr><th scope="col">NGO</th><th scope="col">Status</th><th scope="col">Cycle</th><th scope="col">Renewal</th></tr></thead>
        <tbody><?php foreach ($tenants as $t): ?>
          <tr><td><a href="<?= e(url('admin/ngos/' . $t['id'])) ?>"><?= e($t['name']) ?></a><div class="small text-body-secondary"><?= e($t['reference_code']) ?></div></td>
            <td><?= status_badge($t['status']) ?></td><td><?= e(humanize($t['billing_cycle'])) ?></td><td class="small"><?= e(format_date($t['end_date'])) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>
</div>
