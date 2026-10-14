<?php /** @var \App\Core\Paginator $page */ ?>
<?php if ($page->total > 0): ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-2 border-top small">
  <div class="text-body-secondary">Showing <?= $page->from() ?>–<?= $page->to() ?> of <?= number_format($page->total) ?></div>
  <?php if ($page->lastPage() > 1): ?>
  <nav aria-label="Pagination">
    <ul class="pagination pagination-sm mb-0">
      <li class="page-item<?= $page->page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= e(query_with(['page' => $page->page - 1])) ?>" aria-label="Previous page">&laquo;</a></li>
      <?php
        $start = max(1, $page->page - 2); $end = min($page->lastPage(), $page->page + 2);
        for ($i = $start; $i <= $end; $i++): ?>
        <li class="page-item<?= $i === $page->page ? ' active' : '' ?>"><a class="page-link" href="<?= e(query_with(['page' => $i])) ?>"<?= $i === $page->page ? ' aria-current="page"' : '' ?>><?= $i ?></a></li>
      <?php endfor; ?>
      <li class="page-item<?= $page->page >= $page->lastPage() ? ' disabled' : '' ?>"><a class="page-link" href="<?= e(query_with(['page' => $page->page + 1])) ?>" aria-label="Next page">&raquo;</a></li>
    </ul>
  </nav>
  <?php endif; ?>
</div>
<?php endif; ?>
