<?php
/** @var array $row @var string $body @var array $attempts @var string $base @var bool $canRetry */
$isPlatform = str_starts_with($base, 'admin');
$heading = $row['subject'];
$subtitle = 'To ' . ($row['to_name'] ? $row['to_name'] . ' <' . $row['to_email'] . '>' : $row['to_email']);
$crumbs = ['Email log' => $base];
$actions = '';
if ($canRetry && in_array($row['status'], ['failed', 'queued'], true)) {
    $actions .= '<form method="post" action="' . e(url($base . '/' . $row['id'] . '/retry')) . '" data-loading>' . csrf_field()
        . '<button class="btn btn-primary" type="submit"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Retry now</button></form>';
}
include __DIR__ . '/../../partials/page_header.php';
$atts = json_decode((string) $row['attachments'], true) ?: [];
?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card mb-3"><div class="card-body">
      <dl class="row small mb-0">
        <dt class="col-5 text-body-secondary">Status</dt><dd class="col-7"><?= status_badge($row['status']) ?></dd>
        <?php if ($isPlatform): ?><dt class="col-5 text-body-secondary">NGO</dt><dd class="col-7"><?= e($row['tenant_name'] ?? 'Platform email') ?></dd><?php endif; ?>
        <dt class="col-5 text-body-secondary">Type</dt><dd class="col-7"><?= e(humanize($row['category'])) ?></dd>
        <dt class="col-5 text-body-secondary">Attempts</dt><dd class="col-7"><?= (int) $row['attempts'] ?> of <?= (int) $row['max_attempts'] ?></dd>
        <?php if ($row['status'] === 'queued'): ?><dt class="col-5 text-body-secondary">Next attempt</dt><dd class="col-7"><?= e(format_datetime($row['next_attempt_at'])) ?></dd><?php endif; ?>
        <dt class="col-5 text-body-secondary">Transport</dt><dd class="col-7"><?= e($row['transport'] ?? '—') ?></dd>
        <dt class="col-5 text-body-secondary">Created</dt><dd class="col-7"><?= e(format_datetime($row['created_at'])) ?></dd>
        <dt class="col-5 text-body-secondary">Sent</dt><dd class="col-7"><?= e(format_datetime($row['sent_at'])) ?></dd>
        <dt class="col-5 text-body-secondary">Attachments</dt><dd class="col-7"><?= $atts ? e(implode(', ', array_map(static fn ($a) => ucfirst((string) ($a['type'] ?? 'file')) . ' PDF', $atts))) : '—' ?></dd>
      </dl>
      <?php if ($row['last_error']): ?><div class="alert alert-danger small mt-3 mb-0 text-break"><strong>Last error:</strong> <?= e($row['last_error']) ?></div><?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-8">
    <div class="card mb-3"><div class="card-body">
      <h2 class="h6">Message</h2>
      <pre class="small mb-0 email-body" style="white-space:pre-wrap"><?= e($body) ?></pre>
      <div class="form-text">Single-use links (invitations, password resets) are hidden here and removed from stored copies after delivery.</div>
    </div></div>
    <div class="card"><div class="card-body">
      <h2 class="h6">Delivery attempts</h2>
      <?php if (!$attempts): ?><p class="small text-body-secondary mb-0">Not attempted yet.</p><?php else: ?>
      <div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th scope="col">#</th><th scope="col">When</th><th scope="col">Via</th><th scope="col">Result</th><th scope="col">Server response</th></tr></thead>
        <tbody><?php foreach ($attempts as $a): ?>
          <tr><td><?= (int) $a['attempt_no'] ?></td><td class="small text-nowrap"><?= e(format_datetime($a['attempted_at'])) ?></td><td class="small"><?= e($a['transport']) ?></td>
            <td><?= $a['success'] ? '<span class="badge text-bg-success">Delivered</span>' : '<span class="badge text-bg-danger">Failed</span>' ?></td>
            <td class="small text-break"><?= e($a['response'] ?? '') ?><?= $a['duration_ms'] !== null ? ' <span class="text-body-secondary">(' . (int) $a['duration_ms'] . ' ms)</span>' : '' ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <?php endif; ?>
    </div></div>
  </div>
</div>
