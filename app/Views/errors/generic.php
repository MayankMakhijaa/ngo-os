<div class="text-center">
  <div class="display-5 fw-bold text-body-tertiary"><?= (int) $status ?></div>
  <h1 class="h4 mt-2"><?= e($title) ?></h1>
  <p class="text-body-secondary"><?= e($message !== '' ? $message : 'Something went wrong.') ?></p>
  <div class="d-flex gap-2 justify-content-center">
    <?php if (!empty($action)): ?>
      <a class="btn btn-primary" href="<?= e($action['url']) ?>"><?= e($action['label']) ?></a>
      <a class="btn btn-outline-secondary" href="<?= e(url('/')) ?>">Home</a>
    <?php else: ?>
      <a class="btn btn-primary" href="<?= e(url('/')) ?>">Home</a>
    <?php endif; ?>
  </div>
</div>
