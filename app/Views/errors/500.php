<div class="text-center">
  <div class="display-5 fw-bold text-body-tertiary">500</div>
  <h1 class="h4 mt-2">Something went wrong</h1>
  <p class="text-body-secondary">The error has been logged. If it keeps happening, contact support and quote reference <strong><?= e($ref) ?></strong>.</p>
  <?php if (!empty($exception)): ?>
    <pre class="text-start small bg-body-tertiary p-3 rounded overflow-auto" style="max-height:300px"><?= e(get_class($exception) . ': ' . $exception->getMessage() . "\n" . $exception->getFile() . ':' . $exception->getLine() . "\n" . $exception->getTraceAsString()) ?></pre>
  <?php endif; ?>
  <a class="btn btn-primary" href="<?= e(url('/')) ?>">Home</a>
</div>
