<?php
/** @var array $u @var bool $canManage */
$writable = \App\Services\SubscriptionService::isWritable($subscription);
$isMe = (int) $u['id'] === (int) auth_user()['id'];
$heading = $u['name']; $subtitle = $u['email']; $crumbs = ['Users' => 'ngo/users'];
$actions = '';
if ($canManage && $writable && can('users.edit')) {
    $actions .= '<a class="btn btn-outline-secondary" href="' . e(url('ngo/users/' . $u['id'] . '/edit')) . '"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit</a>';
}
if ($canManage && can('users.assign_permissions')) {
    $actions .= '<a class="btn btn-outline-secondary" href="' . e(url('ngo/users/' . $u['id'] . '/permissions')) . '"><i class="bi bi-ui-checks me-1" aria-hidden="true"></i>Permissions</a>';
}
if ($canManage && $writable && can('users.deactivate')) {
    $to = $u['status'] === 'inactive' ? 'active' : 'inactive';
    $actions .= '<form method="post" action="' . e(url('ngo/users/' . $u['id'] . '/status')) . '" data-confirm="'
        . e($to === 'inactive' ? 'Deactivate ' . $u['name'] . '? They are signed out immediately and cannot sign in. Their records are kept.' : 'Reactivate ' . $u['name'] . '? They count towards your plan\'s user limit again.') . '">'
        . csrf_field() . '<input type="hidden" name="status" value="' . $to . '"><button class="btn ' . ($to === 'inactive' ? 'btn-outline-danger' : 'btn-success') . '" type="submit">'
        . ($to === 'inactive' ? 'Deactivate' : 'Reactivate') . '</button></form>';
}
include __DIR__ . '/../../partials/page_header.php';
?>
<?php if (!empty($link)): ?>
  <div class="alert alert-info">
    <div class="small mb-2"><strong>Single-use link</strong> (shown once, also emailed if email is configured). Share it only with <?= e($u['name']) ?>:</div>
    <div class="input-group"><input class="form-control font-monospace small" id="accessLink" value="<?= e($link) ?>" readonly aria-label="Single-use link">
      <button class="btn btn-outline-primary" type="button" data-copy="#accessLink"><i class="bi bi-clipboard me-1" aria-hidden="true"></i>Copy</button></div>
  </div>
<?php endif; ?>
<?php if ($isMe): ?><div class="alert alert-light border small">This is your own account. Your role, status and permissions can only be changed by another administrator.</div>
<?php elseif (!$canManage): ?><div class="alert alert-light border small">You can view this user but not manage them, because they have permissions you do not have.</div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card mb-3"><div class="card-body">
      <dl class="row small mb-0">
        <dt class="col-5 text-body-secondary">Status</dt><dd class="col-7"><?= status_badge($u['status']) ?></dd>
        <dt class="col-5 text-body-secondary">Role</dt><dd class="col-7"><?= e($u['role_name'] ?? '—') ?></dd>
        <dt class="col-5 text-body-secondary">Phone</dt><dd class="col-7"><?= e($u['phone'] ?: '—') ?></dd>
        <dt class="col-5 text-body-secondary">Last sign-in</dt><dd class="col-7"><?= e(format_datetime($u['last_login_at'])) ?></dd>
        <dt class="col-5 text-body-secondary">Password changed</dt><dd class="col-7"><?= e(format_datetime($u['password_changed_at'])) ?></dd>
        <dt class="col-5 text-body-secondary">Created</dt><dd class="col-7"><?= e(format_datetime($u['created_at'])) ?></dd>
      </dl>
    </div></div>
    <?php if ($canManage && $writable && can('users.assign_roles') && $roles): ?>
    <form method="post" action="<?= e(url('ngo/users/' . $u['id'] . '/role')) ?>" class="card mb-3" data-confirm="Change <?= e($u['name']) ?>'s role? Their access changes immediately.">
      <div class="card-body">
        <?= csrf_field() ?>
        <h2 class="h6">Role</h2>
        <?php $opts = []; foreach ($roles as $r) { $opts[$r['id']] = $r['name']; } ?>
        <?= form_select('role_id', 'Primary role', $opts, $u['role_id'], ['required' => true]) ?>
        <button class="btn btn-sm btn-primary" type="submit">Change role</button>
      </div>
    </form>
    <?php endif; ?>
    <?php if ($canManage && $writable && can('users.invite') && $u['status'] !== 'inactive'): ?>
    <form method="post" action="<?= e(url('ngo/users/' . $u['id'] . '/link')) ?>" class="card" data-confirm="Create a new single-use <?= $u['status'] === 'invited' ? 'invitation' : 'password reset' ?> link for <?= e($u['name']) ?>? Earlier links stop working.">
      <div class="card-body">
        <?= csrf_field() ?>
        <h2 class="h6"><?= $u['status'] === 'invited' ? 'Invitation' : 'Password reset' ?></h2>
        <p class="small text-body-secondary"><?= $u['status'] === 'invited' ? 'The user has not activated their account yet.' : 'Send a secure link so the user can choose a new password. You never see or set their password.' ?></p>
        <button class="btn btn-sm btn-outline-primary" type="submit"><?= $u['status'] === 'invited' ? 'Send new invitation' : 'Send reset link' ?></button>
      </div>
    </form>
    <?php endif; ?>
  </div>
  <div class="col-lg-7">
    <div class="card mb-3"><div class="card-body">
      <h2 class="h6">Effective permissions</h2>
      <?php if (!$effective): ?><p class="small text-body-secondary mb-0">No permissions.</p><?php else: ?>
        <div class="d-flex flex-wrap gap-1"><?php foreach ($effective as $k): ?><span class="badge text-bg-light border fw-normal"><?= e($catalog[$k]['label'] ?? $k) ?></span><?php endforeach; ?></div>
      <?php endif; ?>
    </div></div>
    <div class="card">
      <div class="card-header"><h2 class="h6 mb-0">Recent activity</h2></div>
      <?php if (!$activity): ?><?= empty_state('No activity yet') ?><?php else: ?>
      <ul class="list-group list-group-flush"><?php foreach ($activity as $a): ?>
        <li class="list-group-item small d-flex justify-content-between gap-2"><div><span class="fw-medium"><?= e(\App\Services\DashboardService::describe($a['action'])) ?></span>
          <span class="text-body-secondary">· by <?= e($a['actor_name'] ?? 'system') ?></span></div><time class="text-body-secondary text-nowrap"><?= e(format_datetime($a['created_at'])) ?></time></li>
      <?php endforeach; ?></ul>
      <?php endif; ?>
    </div>
  </div>
</div>
