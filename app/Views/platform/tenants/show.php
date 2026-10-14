<?php
/** @var array $t @var ?array $subscription */
$heading = $t['name'];
$subtitle = $t['reference_code'] . ' · Tenant ID ' . $t['uuid'];
$crumbs = ['NGOs' => 'admin/ngos'];
$actions = '';
if (can('tenants.manage')) {
    $actions .= '<a class="btn btn-outline-secondary" href="' . e(url('admin/ngos/' . $t['id'] . '/edit')) . '"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit</a>';
    $statusForm = static function (string $status, string $label, string $btn, string $msg, bool $reason) use ($t) {
        return '<form method="post" action="' . e(url('admin/ngos/' . $t['id'] . '/status')) . '" data-confirm="' . e($msg) . '"'
            . ($reason ? ' data-confirm-reason="reason"' : '') . ' data-confirm-button="' . e($label) . '">'
            . csrf_field() . '<input type="hidden" name="status" value="' . e($status) . '">'
            . ($reason ? '<input type="hidden" name="reason" value="">' : '')
            . '<button type="submit" class="btn ' . $btn . '">' . e($label) . '</button></form>';
    };
    if ($t['status'] !== 'active') {
        $actions .= $statusForm('active', 'Activate', 'btn-success', 'Activate ' . $t['name'] . '? Its users will be able to sign in again. No data is changed.', false);
    }
    if ($t['status'] === 'active') {
        $actions .= $statusForm('suspended', 'Suspend', 'btn-warning', 'Suspend ' . $t['name'] . '? All of its users are signed out immediately and cannot sign in. All records are kept and access returns when you reactivate it.', true);
    }
    if ($t['status'] !== 'deactivated') {
        $actions .= $statusForm('deactivated', 'Deactivate', 'btn-outline-danger', 'Deactivate ' . $t['name'] . '? Use this when the NGO has left the platform. Users lose access; records are kept and the NGO can be reactivated later.', true);
    }
}
include __DIR__ . '/../../partials/page_header.php';
$cur = $subscription['currency'] ?? config('app.currency');
?>
<?php if ($t['status'] !== 'active'): ?>
  <div class="alert alert-<?= $t['status'] === 'suspended' ? 'warning' : 'secondary' ?>">
    <strong>This NGO is <?= e($t['status']) ?>.</strong> Its users cannot sign in. <?= $t['status_reason'] ? 'Reason: ' . e($t['status_reason']) . '.' : '' ?>
    Since <?= e(format_datetime($t['status_changed_at'])) ?>.
  </div>
<?php endif; ?>

<?php if (!empty($inviteLink)): ?>
  <div class="alert alert-info">
    <h2 class="h6"><i class="bi bi-envelope-paper me-1" aria-hidden="true"></i>Single-use onboarding link</h2>
    <p class="small mb-2">Share this link with the NGO Super Admin over a trusted channel. It works once, expires automatically and is not shown again (it was also emailed if email is configured).</p>
    <div class="input-group">
      <input class="form-control font-monospace small" id="inviteLink" value="<?= e($inviteLink) ?>" readonly aria-label="Onboarding link">
      <button class="btn btn-outline-primary" type="button" data-copy="#inviteLink"><i class="bi bi-clipboard me-1" aria-hidden="true"></i>Copy</button>
    </div>
  </div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3" role="tablist">
  <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-overview" type="button" role="tab" aria-controls="tab-overview" aria-selected="true">Overview</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-subscription" type="button" role="tab" aria-controls="tab-subscription" aria-selected="false">Subscription &amp; payments</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-users" type="button" role="tab" aria-controls="tab-users" aria-selected="false">Users <span class="badge text-bg-light"><?= count($users) ?></span></button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-activity" type="button" role="tab" aria-controls="tab-activity" aria-selected="false">Activity</button></li>
</ul>

<div class="tab-content">
<!-- Overview -->
<div class="tab-pane fade show active" id="tab-overview" role="tabpanel" tabindex="0">
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card h-100"><div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <?php if ($t['logo_uuid']): ?>
            <img src="<?= e(url('files/' . $t['logo_uuid'])) ?>" alt="<?= e($t['name']) ?> logo" class="tenant-logo">
          <?php else: ?>
            <span class="tenant-logo tenant-logo-empty" aria-hidden="true"><i class="bi bi-building"></i></span>
          <?php endif; ?>
          <div><h2 class="h6 mb-0">Profile</h2><?= status_badge($t['status']) ?> <?= $t['is_demo'] ? '<span class="badge text-bg-light border">Demo record</span>' : '' ?></div>
        </div>
        <dl class="row small mb-0">
          <dt class="col-sm-4 text-body-secondary">Reference code</dt><dd class="col-sm-8 font-monospace"><?= e($t['reference_code']) ?></dd>
          <dt class="col-sm-4 text-body-secondary">Tenant ID</dt><dd class="col-sm-8 font-monospace small text-break"><?= e($t['uuid']) ?></dd>
          <dt class="col-sm-4 text-body-secondary">Address</dt><dd class="col-sm-8"><?= nl2br(e($t['address'])) ?></dd>
          <dt class="col-sm-4 text-body-secondary">Contact</dt><dd class="col-sm-8"><?= e($t['contact_name']) ?></dd>
          <dt class="col-sm-4 text-body-secondary">Email</dt><dd class="col-sm-8"><a href="mailto:<?= e($t['email']) ?>"><?= e($t['email']) ?></a></dd>
          <dt class="col-sm-4 text-body-secondary">Phone</dt><dd class="col-sm-8"><?= e($t['phone']) ?></dd>
          <dt class="col-sm-4 text-body-secondary">Website</dt><dd class="col-sm-8"><?= $t['website'] ? '<a href="' . e($t['website']) . '" target="_blank" rel="noopener noreferrer">' . e($t['website']) . '</a>' : '—' ?></dd>
          <dt class="col-sm-4 text-body-secondary">Registration no.</dt><dd class="col-sm-8"><?= e($t['registration_number'] ?: '—') ?></dd>
          <dt class="col-sm-4 text-body-secondary">Created</dt><dd class="col-sm-8"><?= e(format_datetime($t['created_at'])) ?></dd>
          <dt class="col-sm-4 text-body-secondary">Last modified</dt><dd class="col-sm-8"><?= e(format_datetime($t['updated_at'])) ?></dd>
          <dt class="col-sm-4 text-body-secondary">Internal notes</dt><dd class="col-sm-8"><?= $t['notes'] ? nl2br(e($t['notes'])) : '—' ?></dd>
        </dl>
        <?php if (can('tenants.manage')): ?>
          <form method="post" action="<?= e(url('admin/ngos/' . $t['id'] . '/invite')) ?>" class="mt-3" data-confirm="Create a new single-use onboarding link for this NGO's Super Admin? Any earlier link stops working.">
            <?= csrf_field() ?><button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-link-45deg me-1" aria-hidden="true"></i>New Super Admin onboarding link</button>
          </form>
        <?php endif; ?>
      </div></div>
    </div>
    <div class="col-lg-6">
      <div class="card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between align-items-start">
          <h2 class="h6">Current subscription</h2>
          <?php if (can('subscriptions.manage')): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/ngos/' . $t['id'] . '/subscription')) ?>"><?= $subscription ? 'Change plan' : 'Assign plan' ?></a><?php endif; ?>
        </div>
        <?php if (!$subscription): ?>
          <?= empty_state('No subscription', 'The NGO workspace is read-only until a plan is assigned.', 'credit-card') ?>
        <?php else: ?>
          <dl class="row small mb-0">
            <dt class="col-sm-4 text-body-secondary">Plan</dt><dd class="col-sm-8 fw-medium"><?= e($subscription['plan_name']) ?></dd>
            <dt class="col-sm-4 text-body-secondary">Status</dt><dd class="col-sm-8"><?= status_badge($subscription['status']) ?></dd>
            <dt class="col-sm-4 text-body-secondary">Billing</dt><dd class="col-sm-8"><?= e(humanize($subscription['billing_cycle'])) ?> · <?= e(format_money($subscription['price'], $cur)) ?></dd>
            <dt class="col-sm-4 text-body-secondary">Started</dt><dd class="col-sm-8"><?= e(format_date($subscription['start_date'])) ?></dd>
            <?php if ($subscription['trial_ends_at']): ?><dt class="col-sm-4 text-body-secondary">Trial ends</dt><dd class="col-sm-8"><?= e(format_date($subscription['trial_ends_at'])) ?></dd><?php endif; ?>
            <dt class="col-sm-4 text-body-secondary">Expiry / renewal</dt><dd class="col-sm-8"><?= e(format_date($subscription['end_date'])) ?></dd>
          </dl>
        <?php endif; ?>
      </div></div>
      <div class="card"><div class="card-body">
        <h2 class="h6 mb-3">Usage against plan</h2>
        <?php foreach ($usage as $m): if (!in_array($m['key'], ['users', 'members', 'storage', 'documents', 'emails'], true)) continue; ?>
          <div class="mb-3"><div class="small fw-medium mb-1"><?= e($m['label']) ?></div><?= usage_bar($m) ?></div>
        <?php endforeach; ?>
      </div></div>
    </div>
  </div>
</div>

<!-- Subscription -->
<div class="tab-pane fade" id="tab-subscription" role="tabpanel" tabindex="0">
  <div class="row g-3">
    <?php if ($subscription && can('subscriptions.manage')): ?>
    <div class="col-lg-6">
      <div class="card h-100"><div class="card-body">
        <h2 class="h6">Update status or renewal date</h2>
        <form method="post" action="<?= e(url('admin/subscriptions/' . $subscription['id'])) ?>" class="needs-validation" novalidate
              data-confirm="Update this NGO's subscription? Expired, suspended or cancelled subscriptions make the NGO workspace read-only.">
          <?= csrf_field() ?>
          <div class="row">
            <div class="col-sm-6"><?= form_select('status', 'Status', array_combine(\App\Services\SubscriptionService::STATUSES, array_map('humanize', \App\Services\SubscriptionService::STATUSES)), $subscription['status'], ['required' => true]) ?></div>
            <div class="col-sm-6"><?= form_input('end_date', 'Expiry / renewal date', $subscription['end_date'] ?? '', 'date') ?></div>
          </div>
          <?= form_input('note', 'Note for the history', '', 'text', ['maxlength' => 255]) ?>
          <button class="btn btn-primary btn-sm" type="submit">Update subscription</button>
        </form>
      </div></div>
    </div>
    <?php endif; ?>
    <?php if ($subscription && can('payments.record')): ?>
    <div class="col-lg-6">
      <div class="card h-100"><div class="card-body">
        <h2 class="h6">Record a manual payment</h2>
        <form method="post" action="<?= e(url('admin/subscriptions/' . $subscription['id'] . '/payments')) ?>" class="needs-validation" novalidate data-loading>
          <?= csrf_field() ?>
          <input type="hidden" name="nonce" value="<?= e($paymentNonce) ?>">
          <div class="row">
            <div class="col-sm-6"><?= form_input('amount', 'Amount (' . $cur . ')', $subscription['price'] > 0 ? $subscription['price'] : '', 'number', ['required' => true, 'min' => '0.01', 'step' => '0.01']) ?></div>
            <div class="col-sm-6"><?= form_input('paid_at', 'Payment date', date('Y-m-d'), 'date', ['required' => true]) ?></div>
            <div class="col-sm-6"><?= form_select('method', 'Method', ['bank_transfer' => 'Bank transfer', 'upi' => 'UPI', 'cheque' => 'Cheque', 'cash' => 'Cash', 'card' => 'Card', 'other' => 'Other'], 'bank_transfer', ['required' => true]) ?></div>
            <div class="col-sm-6"><?= form_select('status', 'Payment status', ['paid' => 'Paid', 'pending' => 'Pending', 'failed' => 'Failed', 'refunded' => 'Refunded'], 'paid', ['required' => true]) ?></div>
            <div class="col-sm-6"><?= form_input('period_start', 'Covers from', $subscription['end_date'] ?? '', 'date') ?></div>
            <div class="col-sm-6"><?= form_input('period_end', 'Covers until', '', 'date') ?></div>
          </div>
          <?= form_input('reference', 'Reference (UTR / cheque no.)', '', 'text', ['maxlength' => 120]) ?>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="extend" value="1" id="extend" checked>
            <label class="form-check-label small" for="extend">If paid, renew the subscription until "Covers until" and mark it Active</label>
          </div>
          <button class="btn btn-primary btn-sm" type="submit">Record payment</button>
        </form>
      </div></div>
    </div>
    <?php endif; ?>
    <div class="col-12">
      <div class="card">
        <div class="card-header"><h2 class="h6 mb-0">Subscription history</h2></div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
          <thead><tr><th scope="col">#</th><th scope="col">Plan</th><th scope="col">Status</th><th scope="col">Cycle / price</th><th scope="col">Period</th><th scope="col">Ended</th></tr></thead>
          <tbody>
          <?php foreach ($history as $h): ?>
            <tr<?= $h['is_current'] ? ' class="table-primary"' : '' ?>>
              <td><?= (int) $h['id'] ?><?= $h['is_current'] ? ' <span class="badge text-bg-primary">Current</span>' : '' ?></td>
              <td><?= e($h['plan_name']) ?></td><td><?= status_badge($h['status']) ?></td>
              <td class="small"><?= e(humanize($h['billing_cycle'])) ?> · <?= e(format_money($h['price'], $h['currency'])) ?></td>
              <td class="small"><?= e(format_date($h['start_date'])) ?> – <?= e(format_date($h['end_date'])) ?></td>
              <td class="small"><?= $h['ended_at'] ? e(format_date($h['ended_at'])) . ' (' . e(humanize((string) $h['end_reason'])) . ')' : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$history): ?><tr><td colspan="6"><?= empty_state('No subscriptions yet') ?></td></tr><?php endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card h-100">
        <div class="card-header"><h2 class="h6 mb-0">Payments</h2></div>
        <?php if (!$payments): ?><?= empty_state('No payments recorded', '', 'cash') ?><?php else: ?>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
          <thead><tr><th scope="col">#</th><th scope="col">Date</th><th scope="col">Amount</th><th scope="col">Method</th><th scope="col">Status</th><th scope="col">Covers</th></tr></thead>
          <tbody><?php foreach ($payments as $p): ?>
            <tr><td><?= (int) $p['id'] ?></td><td class="small"><?= e(format_date($p['paid_at'])) ?></td><td><?= e(format_money($p['amount'], $p['currency'])) ?></td>
              <td class="small"><?= e(humanize($p['method'])) ?><?= $p['reference'] ? '<div class="text-body-secondary">' . e($p['reference']) . '</div>' : '' ?></td>
              <td><?= status_badge($p['status']) ?></td><td class="small"><?= $p['period_end'] ? e(format_date($p['period_start'])) . ' – ' . e(format_date($p['period_end'])) : '—' ?></td></tr>
          <?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card h-100">
        <div class="card-header"><h2 class="h6 mb-0">Status changes</h2></div>
        <?php if (!$events): ?><?= empty_state('No changes yet') ?><?php else: ?>
        <ul class="list-group list-group-flush small">
          <?php foreach ($events as $ev): ?>
            <li class="list-group-item"><div class="d-flex justify-content-between"><span><?= $ev['from_status'] ? status_badge($ev['from_status']) . ' → ' : '' ?><?= status_badge($ev['to_status']) ?></span>
              <time class="text-body-secondary"><?= e(format_datetime($ev['created_at'])) ?></time></div>
              <div class="text-body-secondary"><?= e($ev['note'] ?? '') ?> · <?= e($ev['actor_name'] ?? 'System') ?></div></li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Users -->
<div class="tab-pane fade" id="tab-users" role="tabpanel" tabindex="0">
  <div class="card">
    <?php if (!$users): ?><?= empty_state('No users') ?><?php else: ?>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
      <thead><tr><th scope="col">Name</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Last sign-in</th><th scope="col">Created</th></tr></thead>
      <tbody><?php foreach ($users as $u): ?>
        <tr><td><?= e($u['name']) ?><div class="small text-body-secondary"><?= e($u['email']) ?></div></td><td><?= e($u['role_name'] ?? '—') ?></td>
          <td><?= status_badge($u['status']) ?></td><td class="small"><?= e(time_ago($u['last_login_at'])) ?></td><td class="small"><?= e(format_date($u['created_at'])) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
    <div class="card-footer small text-body-secondary">Fameweb can view who has access but cannot sign in as NGO users or change their passwords. Viewing this page is recorded in the audit log.</div>
  </div>
</div>

<!-- Activity -->
<div class="tab-pane fade" id="tab-activity" role="tabpanel" tabindex="0">
  <div class="card">
    <?php if (!$activity): ?><?= empty_state('No activity yet') ?><?php else: ?>
    <ul class="list-group list-group-flush">
      <?php foreach ($activity as $a): ?>
        <li class="list-group-item small d-flex justify-content-between gap-3">
          <div class="min-w-0"><span class="fw-medium"><?= e(\App\Services\DashboardService::describe($a['action'])) ?></span>
            <span class="text-body-secondary">· <?= e($a['actor_name'] ?? ucfirst($a['actor_type'])) ?><?= $a['actor_type'] === 'platform' ? ' (Fameweb)' : '' ?></span>
            <div class="text-body-secondary text-truncate"><?= e(audit_meta($a['metadata'])) ?></div></div>
          <time class="text-body-secondary text-nowrap"><?= e(format_datetime($a['created_at'])) ?></time>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if (can('platform.audit.view')): ?><div class="card-footer"><a class="small" href="<?= e(url('admin/audit', ['tenant_id' => $t['id']])) ?>">Full audit log for this NGO</a></div><?php endif; ?>
  </div>
</div>
</div>
