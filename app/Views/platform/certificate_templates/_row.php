<?php
/** One element row of the certificate builder. @var array $el (normalized element or defaults) */
use App\Services\CertificateTemplateService as CTS;

$el += ['text' => '', 'h' => '', 'size' => '', 'font' => 'helvetica', 'style' => '', 'align' => 'C', 'color' => '#222222'];
$opts = static function (array $options, $selected): string {
    $html = '';
    foreach ($options as $v => $label) {
        $html .= '<option value="' . e($v) . '"' . ((string) $v === (string) $selected ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html;
};
?>
<div class="cert-element-row border rounded p-2 mb-2" data-type="<?= e($el['type']) ?>">
  <div class="d-flex align-items-center gap-2 mb-2">
    <select class="form-select form-select-sm w-auto" name="el_type[]" aria-label="Element type" data-field="type"><?= $opts(CTS::ELEMENT_TYPES, $el['type']) ?></select>
    <span class="small text-body-secondary flex-grow-1 text-truncate" data-summary></span>
    <div class="btn-group btn-group-sm" role="group" aria-label="Element actions">
      <button type="button" class="btn btn-light" data-move="-1" title="Move up" aria-label="Move up"><i class="bi bi-arrow-up" aria-hidden="true"></i></button>
      <button type="button" class="btn btn-light" data-move="1" title="Move down" aria-label="Move down"><i class="bi bi-arrow-down" aria-hidden="true"></i></button>
      <button type="button" class="btn btn-light" data-duplicate title="Duplicate" aria-label="Duplicate"><i class="bi bi-copy" aria-hidden="true"></i></button>
      <button type="button" class="btn btn-light text-danger" data-remove title="Remove" aria-label="Remove"><i class="bi bi-trash" aria-hidden="true"></i></button>
    </div>
  </div>
  <div class="mb-2 el-text-only"><textarea class="form-control" name="el_text[]" rows="2" maxlength="500" aria-label="Text with placeholders" data-field="text"><?= e($el['text']) ?></textarea></div>
  <div class="row g-1">
    <div class="col-3"><label class="form-label small mb-0">X mm<input class="form-control" type="number" step="0.5" min="0" name="el_x[]" value="<?= e($el['x']) ?>" data-field="x"></label></div>
    <div class="col-3"><label class="form-label small mb-0">Y mm<input class="form-control" type="number" step="0.5" min="0" name="el_y[]" value="<?= e($el['y']) ?>" data-field="y"></label></div>
    <div class="col-3"><label class="form-label small mb-0">Width<input class="form-control" type="number" step="0.5" min="1" name="el_w[]" value="<?= e($el['w']) ?>" data-field="w"></label></div>
    <div class="col-3 el-h"><label class="form-label small mb-0">Height<input class="form-control" type="number" step="0.5" min="0.5" name="el_h[]" value="<?= e($el['h']) ?>" data-field="h"></label></div>
    <div class="col-3 el-size"><label class="form-label small mb-0"><span class="el-size-label">Size pt</span><input class="form-control" type="number" step="0.1" min="0.1" max="96" name="el_size[]" value="<?= e($el['size']) ?>" data-field="size"></label></div>
    <div class="col-4 el-text-only"><label class="form-label small mb-0">Font<select class="form-select" name="el_font[]" data-field="font"><?= $opts(CTS::FONTS, $el['font']) ?></select></label></div>
    <div class="col-3 el-text-only"><label class="form-label small mb-0">Style<select class="form-select" name="el_style[]" data-field="style"><?= $opts(CTS::STYLES, $el['style']) ?></select></label></div>
    <div class="col-3 el-text-only"><label class="form-label small mb-0">Align<select class="form-select" name="el_align[]" data-field="align"><?= $opts(CTS::ALIGNS, $el['align']) ?></select></label></div>
    <div class="col-2 el-color"><label class="form-label small mb-0">Colour<input class="form-control form-control-color w-100" type="color" name="el_color[]" value="<?= e($el['color']) ?>" data-field="color"></label></div>
  </div>
</div>
