<?php
/** @var ?array $template @var string $name @var string $orientation @var array $layout @var array $tenants @var array $assigned */
use App\Services\CertificateTemplateService as CTS;

$canManage = can('certificate_templates.manage');
$heading = $template ? $template['name'] : 'New certificate template';
$subtitle = $template ? 'Version ' . $template['version'] . ' · changes apply to certificates issued from now on' : 'Design the certificate, then choose which NGOs can use it';
$crumbs = ['Certificate templates' => 'admin/certificate-templates'];
$actions = $template ? '<a class="btn btn-outline-secondary" href="' . e(url('admin/certificate-templates/' . $template['id'] . '/preview')) . '" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF preview (saved version)</a>' : '';
include __DIR__ . '/../../partials/page_header.php';
[$pw, $ph] = CTS::pageSize($orientation);
$page = $layout['page'];
?>
<form method="post" action="<?= e(url($template ? 'admin/certificate-templates/' . $template['id'] : 'admin/certificate-templates')) ?>" class="needs-validation" novalidate data-loading data-cert-builder>
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-xl-7">
      <div class="card mb-3"><div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
          <h2 class="h6 mb-0">Live preview</h2>
          <span class="small text-body-secondary">Click an element to edit it; drag to move. Sample data is shown in place of placeholders.</span>
        </div>
        <div class="cert-canvas-wrap"><div class="cert-canvas" id="certCanvas" data-sample="<?= e(json_encode(CTS::SAMPLE)) ?>" role="img" aria-label="Certificate preview"></div></div>
        <p class="form-text mb-0">The PDF uses the same positions in millimetres; fonts are the standard PDF fonts, so line breaks can differ slightly from this preview. Use "PDF preview" after saving to check.</p>
      </div></div>
      <div class="card"><div class="card-body">
        <h2 class="h6">Placeholders</h2>
        <p class="small text-body-secondary mb-2">Click to insert into the selected text element. Each is filled from the person, the NGO or the details entered when issuing.</p>
        <div class="d-flex flex-wrap gap-1">
          <?php foreach (CTS::PLACEHOLDERS as $k => $label): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary placeholder-chip" data-placeholder="<?= e($k) ?>" title="<?= e($label) ?>">{{<?= e($k) ?>}}</button>
          <?php endforeach; ?>
        </div>
      </div></div>
    </div>
    <div class="col-xl-5">
      <div class="card mb-3"><div class="card-body">
        <?= form_input('name', 'Template name', $name, 'text', ['required' => true, 'maxlength' => 120]) ?>
        <?= form_input('description', 'Description (shown to Fameweb only)', $template['description'] ?? '', 'text', ['maxlength' => 255]) ?>
        <div class="row">
          <div class="col-6"><?= form_select('orientation', 'Orientation', ['landscape' => 'Landscape (A4)', 'portrait' => 'Portrait (A4)'], $orientation) ?></div>
          <div class="col-6"><?= form_select('status', 'Status', ['active' => 'Active', 'inactive' => 'Inactive'], $template['status'] ?? 'active') ?></div>
          <div class="col-4"><label class="form-label" for="page_background">Background</label><input class="form-control form-control-color w-100" type="color" id="page_background" name="page_background" value="<?= e($page['background']) ?>"></div>
          <div class="col-5"><?= form_select('page_border', 'Frame', CTS::BORDERS, $page['border']) ?></div>
          <div class="col-3"><label class="form-label" for="page_border_color">Frame colour</label><input class="form-control form-control-color w-100" type="color" id="page_border_color" name="page_border_color" value="<?= e($page['border_color']) ?>"></div>
        </div>
      </div></div>
      <div class="card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h2 class="h6 mb-0">Elements</h2>
          <div class="btn-group btn-group-sm" role="group" aria-label="Add element">
            <button type="button" class="btn btn-outline-primary" data-add="text"><i class="bi bi-fonts me-1" aria-hidden="true"></i>Text</button>
            <button type="button" class="btn btn-outline-primary" data-add="logo">Logo</button>
            <button type="button" class="btn btn-outline-primary" data-add="line">Line</button>
            <button type="button" class="btn btn-outline-primary" data-add="rect">Box</button>
          </div>
        </div>
        <div id="certElements" data-max="<?= CTS::MAX_ELEMENTS ?>">
          <?php foreach ($layout['elements'] as $el): include __DIR__ . '/_row.php'; endforeach; ?>
        </div>
        <template id="certElementTpl"><?php $el = ['type' => 'text', 'text' => 'New text', 'x' => 20, 'y' => 20, 'w' => 100, 'h' => 20, 'size' => 14, 'color' => '#222222']; include __DIR__ . '/_row.php'; ?></template>
      </div></div>
      <div class="card"><div class="card-body">
        <h2 class="h6">Assigned NGOs</h2>
        <p class="small text-body-secondary">Only these NGOs see and can issue this template (their plan also needs the Certificates module).</p>
        <?php if (!$tenants): ?><p class="small mb-0">No NGOs yet.</p><?php endif; ?>
        <div class="assign-list" style="max-height:260px;overflow:auto">
          <?php foreach ($tenants as $t): ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="tenant_ids[]" value="<?= (int) $t['id'] ?>" id="tenant_<?= (int) $t['id'] ?>"<?= in_array((int) $t['id'], $assigned, true) ? ' checked' : '' ?>>
              <label class="form-check-label small" for="tenant_<?= (int) $t['id'] ?>"><?= e($t['name']) ?> <span class="text-body-secondary"><?= e($t['reference_code']) ?></span><?= $t['status'] !== 'active' ? ' <span class="badge text-bg-secondary">' . e($t['status']) . '</span>' : '' ?></label>
              <?php if ($template && in_array((int) $t['id'], $assigned, true)): ?><a class="small ms-1" href="<?= e(url('admin/certificate-templates/' . $template['id'] . '/preview', ['tenant_id' => $t['id']])) ?>" target="_blank" rel="noopener">preview with logo</a><?php endif; ?></div>
          <?php endforeach; ?>
        </div>
      </div></div>
    </div>
  </div>
  <?php if ($canManage): ?><div class="mt-3 d-flex gap-2"><button class="btn btn-primary" type="submit"><?= $template ? 'Save template' : 'Create template' ?></button><a class="btn btn-light" href="<?= e(url('admin/certificate-templates')) ?>">Cancel</a></div><?php endif; ?>
</form>
