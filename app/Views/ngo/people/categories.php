<?php
/** @var array $categories @var ?array $edit */
use App\Services\CategoryService;

$writable = \App\Services\SubscriptionService::isWritable($subscription);
$heading = 'Categories';
$subtitle = 'Group members, volunteers and interns (e.g. Life member, Field volunteer, Summer intern)';
include __DIR__ . '/../../partials/page_header.php';
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <?php if (!$categories): ?>
        <?= empty_state('No categories yet', 'Add your first category on the right.', 'tags') ?>
      <?php else: ?>
      <div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th scope="col">Name</th><th scope="col">Applies to</th><th scope="col" class="text-end">People</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody><?php foreach ($categories as $c): ?>
          <tr><td><div class="fw-medium"><?= e($c['name']) ?></div><?php if ($c['description']): ?><div class="small text-body-secondary"><?= e($c['description']) ?></div><?php endif; ?></td>
            <td class="small"><?= e(CategoryService::APPLIES[$c['applies_to']]) ?></td>
            <td class="text-end"><?= (int) $c['people_count'] ?></td>
            <td class="text-end text-nowrap"><?php if ($writable): ?>
              <a class="btn btn-sm btn-light" href="<?= e(url('ngo/people-categories', ['edit' => $c['id']])) ?>">Edit</a>
              <form class="d-inline" method="post" action="<?= e(url('ngo/people-categories/' . $c['id'] . '/delete')) ?>" data-confirm="Delete the category <?= e($c['name']) ?>? <?= (int) $c['people_count'] ?> people will have no category; their records are kept.">
                <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" type="submit">Delete</button></form>
            <?php endif; ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($writable): ?>
  <div class="col-lg-5">
    <form method="post" action="<?= e(url('ngo/people-categories')) ?>" class="card needs-validation" novalidate>
      <div class="card-body">
        <?= csrf_field() ?>
        <h2 class="h6"><?= $edit ? 'Edit category' : 'Add category' ?></h2>
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
        <?= form_input('name', 'Name', $edit['name'] ?? '', 'text', ['required' => true, 'maxlength' => 80]) ?>
        <?= form_select('applies_to', 'Applies to', CategoryService::APPLIES, $edit['applies_to'] ?? 'all', ['required' => true]) ?>
        <?= form_input('description', 'Description (optional)', $edit['description'] ?? '', 'text', ['maxlength' => 255]) ?>
        <button class="btn btn-primary" type="submit"><?= $edit ? 'Save' : 'Add category' ?></button>
        <?php if ($edit): ?><a class="btn btn-light" href="<?= e(url('ngo/people-categories')) ?>">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>
