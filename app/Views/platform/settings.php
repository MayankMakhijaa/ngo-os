<?php
$heading = 'Platform settings'; $subtitle = 'Platform-wide configuration. Secrets and database credentials live in .env, never here.';
include __DIR__ . '/../partials/page_header.php';
?>
<div class="row g-4">
  <div class="col-lg-7">
    <form method="post" action="<?= e(url('admin/settings')) ?>" class="card needs-validation" novalidate data-confirm="Save platform settings? Changes to security settings are recorded in the audit log.">
      <div class="card-body">
        <?= csrf_field() ?>
        <h2 class="h6 mb-3">General</h2>
        <?= form_input('platform_name', 'Platform name', $settings['platform_name'], 'text', ['required' => true, 'maxlength' => 100]) ?>
        <?= form_input('support_email', 'Support email', $settings['support_email'], 'email') ?>
        <h2 class="h6 mt-4 mb-3">Subscriptions &amp; usage</h2>
        <div class="row">
          <div class="col-sm-4"><?= form_input('default_trial_days', 'Default trial (days)', $settings['default_trial_days'], 'number', ['required' => true, 'min' => 0, 'max' => 365, 'help' => 'Used when a plan has no trial length']) ?></div>
          <div class="col-sm-4"><?= form_input('subscription_grace_days', 'Grace period (days)', $settings['subscription_grace_days'], 'number', ['required' => true, 'min' => 0, 'max' => 90, 'help' => 'Past Due before Expired']) ?></div>
          <div class="col-sm-4"><?= form_input('usage_warning_percent', 'Usage warning at (%)', $settings['usage_warning_percent'], 'number', ['required' => true, 'min' => 50, 'max' => 99]) ?></div>
        </div>
        <h2 class="h6 mt-4 mb-3">Security</h2>
        <div class="row">
          <div class="col-sm-6"><?= form_input('invite_ttl_hours', 'Invitation link validity (hours)', $settings['invite_ttl_hours'], 'number', ['required' => true, 'min' => 1, 'max' => 336]) ?></div>
          <div class="col-sm-6"><?= form_input('reset_ttl_minutes', 'Password reset link validity (minutes)', $settings['reset_ttl_minutes'], 'number', ['required' => true, 'min' => 10, 'max' => 1440]) ?></div>
        </div>
        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" role="switch" name="allow_password_reset" value="1" id="allow_reset"<?= $settings['allow_password_reset'] === '1' ? ' checked' : '' ?>>
          <label class="form-check-label" for="allow_reset">Allow self-service "Forgot password"</label>
        </div>
        <button class="btn btn-primary" type="submit">Save settings</button>
      </div>
    </form>
  </div>
  <div class="col-lg-5">
    <div class="card mb-4"><div class="card-body">
      <h2 class="h6">Environment (read-only)</h2>
      <dl class="row small mb-0">
        <?php foreach ($env as $k => $v): ?><dt class="col-6 text-body-secondary"><?= e($k) ?></dt><dd class="col-6"><?= e($v) ?></dd><?php endforeach; ?>
      </dl>
    </div></div>
    <div class="card"><div class="card-body">
      <h2 class="h6">Database schema</h2>
      <p class="small mb-2">Applied migrations: <?= count($applied) ?>. Latest: <span class="font-monospace"><?= e(end($applied) ?: '—') ?></span></p>
      <?php if ($pending): ?>
        <div class="alert alert-warning small">Pending upgrades: <?= e(implode(', ', $pending)) ?>. Back up the database first.</div>
        <form method="post" action="<?= e(url('admin/settings/migrate')) ?>" data-confirm="Apply pending database upgrades now? Make sure you have a fresh database backup." data-loading>
          <?= csrf_field() ?><button class="btn btn-warning btn-sm" type="submit">Apply upgrades</button>
        </form>
      <?php else: ?>
        <p class="small text-success mb-0"><i class="bi bi-check-circle" aria-hidden="true"></i> Schema is up to date.</p>
      <?php endif; ?>
    </div></div>
  </div>
</div>
