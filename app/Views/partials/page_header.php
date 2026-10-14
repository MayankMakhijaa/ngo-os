<?php /** @var string $heading @var ?string $subtitle @var ?string $actions @var array $crumbs */ ?>
<div class="page-header d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
  <div class="min-w-0">
    <?php if (!empty($crumbs)): ?>
    <nav aria-label="Breadcrumb"><ol class="breadcrumb small mb-1">
      <?php foreach ($crumbs as $label => $href): ?>
        <li class="breadcrumb-item"><a href="<?= e(url($href)) ?>"><?= e($label) ?></a></li>
      <?php endforeach; ?>
      <li class="breadcrumb-item active" aria-current="page"><?= e($heading) ?></li>
    </ol></nav>
    <?php endif; ?>
    <h1 class="h3 mb-0 text-truncate"><?= e($heading) ?></h1>
    <?php if (!empty($subtitle)): ?><p class="text-body-secondary mb-0 mt-1"><?= e($subtitle) ?></p><?php endif; ?>
  </div>
  <?php if (!empty($actions)): ?><div class="d-flex flex-wrap gap-2"><?= $actions ?></div><?php endif; ?>
</div>
