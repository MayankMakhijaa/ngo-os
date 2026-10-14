<?php
/** @var array $templates @var array $presets */
$heading = 'Certificate templates';
$subtitle = 'Design certificates in the builder and assign them to NGOs; NGOs issue them as PDFs';
$actions = '';
if (can('certificate_templates.manage')) {
    $actions .= '<div class="dropdown"><button class="btn btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New template</button><ul class="dropdown-menu dropdown-menu-end">';
    foreach ($presets as $key => $p) {
        $actions .= '<li><a class="dropdown-item" href="' . e(url('admin/certificate-templates/create', ['preset' => $key])) . '">Start from: ' . e($p['name']) . '</a></li>';
    }
    $actions .= '</ul></div>';
}
include __DIR__ . '/../../partials/page_header.php';
?>
<div class="card">
  <?php if (!$templates): ?>
    <?= empty_state('No templates yet', 'Start from one of the ready-made designs and adjust it in the builder.', 'award',
        can('certificate_templates.manage') ? url('admin/certificate-templates/create', ['preset' => 'appreciation']) : null, 'Create a template') ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th scope="col">Template</th><th scope="col">Orientation</th><th scope="col" class="text-end">NGOs</th><th scope="col" class="text-end">Issued</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody><?php foreach ($templates as $t): ?>
      <tr><td><a class="fw-medium" href="<?= e(url('admin/certificate-templates/' . $t['id'] . '/edit')) ?>"><?= e($t['name']) ?></a>
          <?php if ($t['description']): ?><div class="small text-body-secondary"><?= e($t['description']) ?></div><?php endif; ?>
          <div class="small text-body-secondary">Version <?= (int) $t['version'] ?> · updated <?= e(time_ago($t['updated_at'])) ?></div></td>
        <td class="small"><?= e(ucfirst($t['orientation'])) ?></td>
        <td class="text-end"><?= (int) $t['tenant_count'] ?></td>
        <td class="text-end"><?= (int) $t['issued_count'] ?></td>
        <td><?= status_badge($t['status']) ?></td>
        <td class="text-end text-nowrap">
          <a class="btn btn-sm btn-light" href="<?= e(url('admin/certificate-templates/' . $t['id'] . '/preview')) ?>" target="_blank" rel="noopener">PDF preview</a>
          <?php if (can('certificate_templates.manage')): ?>
          <form class="d-inline" method="post" action="<?= e(url('admin/certificate-templates/' . $t['id'] . '/toggle')) ?>" data-confirm="<?= $t['status'] === 'active' ? 'Deactivate this template? Assigned NGOs can no longer issue from it; issued certificates are unaffected.' : 'Activate this template for its assigned NGOs?' ?>">
            <?= csrf_field() ?><button class="btn btn-sm <?= $t['status'] === 'active' ? 'btn-outline-secondary' : 'btn-outline-success' ?>" type="submit"><?= $t['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form>
          <?php endif; ?>
        </td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>
