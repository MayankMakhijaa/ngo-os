<?php
$heading = 'Usage'; $subtitle = 'Calculated from your stored data against your plan' . ($sub ? ' (' . $sub['plan_name'] . ')' : '');
include __DIR__ . '/../partials/page_header.php';
?>
<?php $alerts = array_filter($usage, static fn ($m) => in_array($m['state'], ['warning', 'reached'], true)); if ($alerts): ?>
  <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
    <?php foreach ($alerts as $m): ?><div><?= e($m['label']) ?>: <?= $m['state'] === 'reached' ? 'limit reached, new additions are blocked' : e($m['percent']) . '% used' ?>.</div><?php endforeach; ?>
    Existing data is never removed. Ask Fameweb about a larger plan.
  </div>
<?php endif; ?>
<div class="row g-3">
  <?php foreach ($usage as $m): ?>
    <div class="col-md-6 col-xl-4">
      <div class="card h-100"><div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-2"><h2 class="h6 mb-0"><?= e($m['label']) ?></h2>
          <?= match ($m['state']) { 'reached' => '<span class="badge text-bg-danger">Limit reached</span>', 'warning' => '<span class="badge text-bg-warning">Approaching limit</span>', 'unavailable' => '<span class="badge text-bg-light border">Coming soon</span>', default => '' } ?></div>
        <div class="fs-4 fw-semibold mb-2"><?= $m['state'] === 'unavailable' ? '—' : e($m['used_display']) ?></div>
        <?= usage_bar($m) ?>
      </div></div>
    </div>
  <?php endforeach; ?>
</div>
