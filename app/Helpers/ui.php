<?php
declare(strict_types=1);

/*
 * Reusable UI components for views. Every value is escaped here, so templates can call these
 * helpers with raw data. They render Bootstrap 5 markup with accessible labels and
 * server-side validation feedback.
 */

function form_input(string $name, string $label, mixed $value = '', string $type = 'text', array $opt = []): string
{
    $id = $opt['id'] ?? 'f_' . preg_replace('/[^a-z0-9_]/i', '_', $name);
    $required = !empty($opt['required']);
    $value = old($name, $value);
    $err = field_error($name);
    $attrs = '';
    foreach (['placeholder', 'maxlength', 'min', 'max', 'step', 'pattern', 'autocomplete', 'inputmode', 'accept'] as $a) {
        if (isset($opt[$a])) {
            $attrs .= ' ' . $a . '="' . e($opt[$a]) . '"';
        }
    }
    if ($required) $attrs .= ' required';
    if (!empty($opt['autofocus'])) $attrs .= ' autofocus';
    if (!empty($opt['readonly'])) $attrs .= ' readonly';
    if (!empty($opt['disabled'])) $attrs .= ' disabled';
    $help = isset($opt['help']) ? '<div class="form-text" id="' . e($id) . '_help">' . e($opt['help']) . '</div>' : '';
    $describedBy = isset($opt['help']) ? ' aria-describedby="' . e($id) . '_help"' : '';
    $valAttr = $type === 'password' || $type === 'file' ? '' : ' value="' . e(is_scalar($value) ? $value : '') . '"';
    $html = '<div class="' . e($opt['wrap'] ?? 'mb-3') . '">';
    $html .= '<label class="form-label" for="' . e($id) . '">' . e($label) . ($required ? ' <span class="text-danger" aria-hidden="true">*</span>' : '') . '</label>';
    $html .= '<input type="' . e($type) . '" class="form-control' . invalid_class($name) . '" id="' . e($id) . '" name="' . e($name) . '"' . $valAttr . $attrs . $describedBy . '>';
    $html .= $err ? '<div class="invalid-feedback">' . e($err) . '</div>' : '<div class="invalid-feedback">Please check this field.</div>';
    return $html . $help . '</div>';
}

function form_textarea(string $name, string $label, mixed $value = '', array $opt = []): string
{
    $id = 'f_' . $name;
    $required = !empty($opt['required']);
    $err = field_error($name);
    $html = '<div class="' . e($opt['wrap'] ?? 'mb-3') . '"><label class="form-label" for="' . e($id) . '">' . e($label)
        . ($required ? ' <span class="text-danger" aria-hidden="true">*</span>' : '') . '</label>';
    $html .= '<textarea class="form-control' . invalid_class($name) . '" id="' . e($id) . '" name="' . e($name) . '" rows="' . (int) ($opt['rows'] ?? 3) . '"'
        . ($required ? ' required' : '') . (isset($opt['maxlength']) ? ' maxlength="' . (int) $opt['maxlength'] . '"' : '') . '>'
        . e(old($name, $value)) . '</textarea>';
    $html .= '<div class="invalid-feedback">' . e($err ?? 'Please check this field.') . '</div>';
    if (isset($opt['help'])) {
        $html .= '<div class="form-text">' . e($opt['help']) . '</div>';
    }
    return $html . '</div>';
}

/** @param array<string|int,string> $options value => label */
function form_select(string $name, string $label, array $options, mixed $selected = '', array $opt = []): string
{
    $id = 'f_' . preg_replace('/[^a-z0-9_]/i', '_', $name);
    $required = !empty($opt['required']);
    $selected = (string) old($name, $selected);
    $err = field_error($name);
    $html = '<div class="' . e($opt['wrap'] ?? 'mb-3') . '"><label class="form-label" for="' . e($id) . '">' . e($label)
        . ($required ? ' <span class="text-danger" aria-hidden="true">*</span>' : '') . '</label>';
    $html .= '<select class="form-select' . invalid_class($name) . '" id="' . e($id) . '" name="' . e($name) . '"' . ($required ? ' required' : '') . '>';
    if (isset($opt['placeholder'])) {
        $html .= '<option value="">' . e($opt['placeholder']) . '</option>';
    }
    foreach ($options as $value => $text) {
        $html .= '<option value="' . e($value) . '"' . ((string) $value === $selected ? ' selected' : '') . '>' . e($text) . '</option>';
    }
    $html .= '</select><div class="invalid-feedback">' . e($err ?? 'Please choose an option.') . '</div>';
    if (isset($opt['help'])) {
        $html .= '<div class="form-text">' . e($opt['help']) . '</div>';
    }
    return $html . '</div>';
}

function empty_state(string $title, string $text = '', string $icon = 'inbox', ?string $actionUrl = null, ?string $actionLabel = null): string
{
    $html = '<div class="empty-state text-center py-5"><i class="bi bi-' . e($icon) . ' display-6 text-body-tertiary" aria-hidden="true"></i>'
        . '<h3 class="h6 mt-3 mb-1">' . e($title) . '</h3>';
    if ($text !== '') {
        $html .= '<p class="text-body-secondary small mb-3">' . e($text) . '</p>';
    }
    if ($actionUrl && $actionLabel) {
        $html .= '<a class="btn btn-sm btn-primary" href="' . e($actionUrl) . '">' . e($actionLabel) . '</a>';
    }
    return $html . '</div>';
}

function stat_card(string $label, string $value, string $icon, string $tone = 'primary', ?string $sub = null, ?string $href = null): string
{
    $inner = '<div class="card stat-card h-100"><div class="card-body d-flex align-items-start gap-3">'
        . '<div class="stat-icon bg-' . e($tone) . '-subtle text-' . e($tone) . '-emphasis"><i class="bi bi-' . e($icon) . '" aria-hidden="true"></i></div>'
        . '<div class="min-w-0"><div class="stat-label">' . e($label) . '</div><div class="stat-value">' . e($value) . '</div>'
        . ($sub !== null ? '<div class="stat-sub">' . e($sub) . '</div>' : '') . '</div></div></div>';
    return $href ? '<a class="text-decoration-none text-reset d-block h-100" href="' . e($href) . '">' . $inner . '</a>' : $inner;
}

/** Progress bar for usage vs limit. */
function usage_bar(array $m): string
{
    if ($m['state'] === 'unavailable') {
        return '<div class="small text-body-secondary"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Module not available yet</div>';
    }
    if ($m['limit'] === null) {
        return '<div class="small text-body-secondary">' . e($m['used_display']) . ' used · Unlimited</div>';
    }
    $tone = match ($m['state']) { 'reached' => 'danger', 'warning' => 'warning', default => 'success' };
    $pct = (float) ($m['percent'] ?? 0);
    return '<div class="progress" role="progressbar" aria-label="' . e($m['label']) . ' usage" aria-valuenow="' . e($pct) . '" aria-valuemin="0" aria-valuemax="100" style="height:.5rem">'
        . '<div class="progress-bar bg-' . $tone . '" style="width:' . e($pct) . '%"></div></div>'
        . '<div class="d-flex justify-content-between small mt-1"><span>' . e($m['used_display']) . ' of ' . e($m['limit_display']) . '</span>'
        . '<span class="text-body-secondary">' . e($pct) . '% · ' . e($m['remaining_display']) . ' left</span></div>';
}

/** Hidden inputs that preserve current query params (for filter forms + pagination). */
function query_with(array $changes): string
{
    $q = array_merge($_GET, $changes);
    $q = array_filter($q, static fn ($v) => $v !== null && $v !== '' && !is_array($v));
    return $q ? '?' . http_build_query($q) : '?';
}

function audit_meta(?string $json): string
{
    if (!$json) {
        return '';
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return '';
    }
    $parts = [];
    foreach ($data as $k => $v) {
        $parts[] = $k . ': ' . (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $v);
    }
    return mb_strimwidth(implode('; ', $parts), 0, 220, '…');
}
