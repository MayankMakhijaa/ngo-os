<?php
/** @var \App\Core\Paginator $page @var array $f @var array $stats @var string $base @var ?array $platform */
$isPlatform = str_starts_with($base, 'admin');
$heading = 'Email log';
$subtitle = $isPlatform ? 'Every email sent by the platform and NGOs, with delivery attempts' : 'Emails sent by your organization, with delivery attempts and retries';
$actions = '';
if ($isPlatform && can('platform.email.manage')) {
    $actions .= '<form method="post" action="' . e(url('admin/emails/process')) . '" data-loading>' . csrf_field() . '<button class="btn btn-outline-secondary" type="submit"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Process queue now</button></form>';
    $actions .= '<a class="btn btn-primary" href="' . e(url('admin/emails/settings')) . '"><i class="bi bi-gear me-1" aria-hidden="true"></i>Email settings</a>';
} elseif (!$isPlatform && can('emails.manage')) {
    $actions .= '<a class="btn btn-primary" href="' . e(url('ngo/emails/settings')) . '"><i class="bi bi-gear me-1" aria-hidden="true"></i>Email settings</a>';
}
include __DIR__ . '/../../partials/page_header.php';
$categories = ['invite' => 'Invitation', 'password_reset' => 'Password reset', 'member_welcome' => 'Member welcome', 'certificate' => 'Certificate', 'receipt' => '80G receipt', 'test' => 'Test', 'general' => 'Other'];
?>
<?php if ($isPlatform && $platform['driver'] === 'log'): ?>
  <div class="alert alert-warning small">The mail driver is <strong>log</strong>: emails are written to storage/logs/mail.log and do not leave the server. Configure SMTP in <a href="<?= e(url('admin/emails/settings')) ?>">Email settings</a>.</div>
<?php endif; ?>
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><?= stat_card('Sent (30 days)', number_format((int) $stats['sent']), 'send-check', 'success', null, url($base, ['status' => 'sent'])) ?></div>
  <div class="col-6 col-md-3"><?= stat_card('Waiting to retry', number_format((int) $stats['queued'] + (int) $stats['sending']), 'hourglass-split', 'info', null, url($base, ['status' => 'queued'])) ?></div>
  <div class="col-6 col-md-3"><?= stat_card('Failed (30 days)', number_format((int) $stats['failed']), 'exclamation-octagon', 'danger', null, url($base, ['status' => 'failed'])) ?></div>
  <div class="col-6 col-md-3"><?= stat_card('Retries', 'Automatic', 'arrow-repeat', 'secondary', 'Up to ' . (int) config('mail.max_attempts', 5) . ' attempts with back-off') ?></div>
</div>
<div class="card">
  <div class="card-body border-bottom">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-md-5"><label class="form-label small" for="q">Search</label><input class="form-control form-control-sm" type="search" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Recipient or subject<?= $isPlatform ? ' or NGO' : '' ?>"></div>
      <div class="col-6 col-md-2"><label class="form-label small" for="status">Status</label>
        <select class="form-select form-select-sm" id="status" name="status"><option value="">All</option>
          <?php foreach (['queued' => 'Waiting', 'sending' => 'Sending', 'sent' => 'Sent', 'failed' => 'Failed'] as $k => $l): ?><option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="col-6 col-md-2"><label class="form-label small" for="category">Type</label>
        <select class="form-select form-select-sm" id="category" name="category"><option value="">All</option>
          <?php foreach ($categories as $k => $l): ?><option value="<?= $k ?>"<?= $f['category'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3 d-flex gap-2"><button class="btn btn-sm btn-primary" type="submit">Filter</button><a class="btn btn-sm btn-light" href="<?= e(url($base)) ?>">Reset</a></div>
    </form>
  </div>
  <?php if (!$page->items): ?>
    <?= empty_state('No emails found', array_filter($f) ? 'Try different filters.' : 'Emails appear here as soon as they are sent.', 'envelope') ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th scope="col">Recipient</th><th scope="col">Subject</th><?php if ($isPlatform): ?><th scope="col">NGO</th><?php endif; ?><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Attempts</th><th scope="col">Created</th></tr></thead>
    <tbody><?php foreach ($page->items as $m): ?>
      <tr><td class="small"><a href="<?= e(url($base . '/' . $m['id'])) ?>"><?= e($m['to_email']) ?></a></td>
        <td class="small text-truncate" style="max-width:280px"><?= e($m['subject']) ?></td>
        <?php if ($isPlatform): ?><td class="small"><?= e($m['tenant_name'] ?? 'Platform') ?></td><?php endif; ?>
        <td class="small"><?= e($categories[$m['category']] ?? humanize($m['category'])) ?></td>
        <td><?= status_badge($m['status']) ?><?php if ($m['status'] === 'queued' && $m['attempts'] > 0): ?><div class="small text-body-secondary">next <?= e(format_datetime($m['next_attempt_at'])) ?></div><?php endif; ?></td>
        <td class="small"><?= (int) $m['attempts'] ?> / <?= (int) $m['max_attempts'] ?></td>
        <td class="small"><?= e(time_ago($m['created_at'])) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
  <?php include __DIR__ . '/../../partials/pagination.php'; ?>
</div>
