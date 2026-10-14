<div class="text-center">
  <div class="display-5 fw-bold text-body-tertiary"><?= (int) $status ?></div>
  <h1 class="h4 mt-2"><?= e($title) ?></h1>
  <p class="text-body-secondary"><?= e($message !== '' ? $message : 'Something went wrong.') ?></p>
  <div class="d-flex gap-2 justify-content-center">
    <a class="btn btn-primary" href="<?= e(url('/')) ?>">Home</a>
  </div>
</div>
