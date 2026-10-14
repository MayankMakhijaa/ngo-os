<?php
// Flash messages: rendered as accessible inline alerts (errors) and as toasts (everything).
$messages = \App\Core\Session::messages();
?>
<?php foreach ($messages as $m): if ($m['type'] !== 'error') continue; ?>
  <div class="alert alert-danger d-flex gap-2 align-items-start" role="alert">
    <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i><div><?= e($m['text']) ?></div>
  </div>
<?php endforeach; ?>
<div class="toast-container position-fixed top-0 end-0 p-3" aria-live="polite" aria-atomic="true">
  <?php foreach ($messages as $m):
      $tone = ['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'primary'][$m['type']];
      $icon = ['success' => 'check-circle-fill', 'error' => 'x-octagon-fill', 'warning' => 'exclamation-triangle-fill', 'info' => 'info-circle-fill'][$m['type']]; ?>
    <div class="toast app-toast border-0 border-start border-4 border-<?= $tone ?>" role="<?= $m['type'] === 'error' ? 'alert' : 'status' ?>"
         data-bs-delay="<?= $m['type'] === 'warning' || $m['type'] === 'error' ? 12000 : 6000 ?>">
      <div class="toast-body d-flex gap-2 align-items-start">
        <i class="bi bi-<?= $icon ?> text-<?= $tone ?>" aria-hidden="true"></i>
        <div class="flex-grow-1"><?= e($m['text']) ?></div>
        <button type="button" class="btn-close btn-sm" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    </div>
  <?php endforeach; ?>
</div>
