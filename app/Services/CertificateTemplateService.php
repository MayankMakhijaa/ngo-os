<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Exceptions\DomainException;

/**
 * Certificate templates designed by Fameweb (platform) in the certificate builder and assigned
 * to specific NGOs. A layout is JSON: page style plus positioned elements (text with
 * {{placeholders}}, the NGO logo, lines and filled boxes), all in millimetres. The same layout
 * drives the live HTML preview in the builder and the PDF.
 */
final class CertificateTemplateService
{
    public const PLACEHOLDERS = [
        'name' => 'Person\'s full name', 'member_id' => 'Member / volunteer / intern ID', 'person_type' => 'Member, Volunteer or Intern',
        'category' => 'Category', 'designation' => 'Designation', 'department' => 'Department',
        'joined_on' => 'Joining date', 'ends_on' => 'End / validity date', 'title' => 'Certificate title (entered when issuing)',
        'description' => 'Description / reason (entered when issuing)', 'event' => 'Event or programme name', 'start_date' => 'Programme start date',
        'end_date' => 'Programme end date', 'issue_date' => 'Date of issue', 'certificate_no' => 'Certificate number',
        'ngo_name' => 'NGO name', 'ngo_address' => 'NGO address', 'ngo_registration' => 'NGO registration number',
        'signatory_name' => 'Signatory name', 'signatory_designation' => 'Signatory designation',
    ];

    public const SAMPLE = [
        'name' => 'Ananya Sharma', 'member_id' => 'VOL-00042', 'person_type' => 'Volunteer', 'category' => 'Field volunteer',
        'designation' => 'Programme Associate', 'department' => 'Education', 'joined_on' => '01 Jun 2026', 'ends_on' => '30 Nov 2026',
        'title' => 'Certificate of Appreciation', 'description' => 'for outstanding contribution to the Digital Literacy Drive',
        'event' => 'Digital Literacy Drive 2026', 'start_date' => '01 Jul 2026', 'end_date' => '30 Sep 2026', 'issue_date' => '14 Oct 2026',
        'certificate_no' => 'CERT-2026-00001', 'ngo_name' => 'Sample Foundation', 'ngo_address' => '12 Example Road, New Delhi 110001',
        'ngo_registration' => 'REG/2015/1234', 'signatory_name' => 'R. K. Mehta', 'signatory_designation' => 'Secretary',
    ];

    public const FONTS = ['helvetica' => 'Sans (Helvetica)', 'times' => 'Serif (Times)', 'courier' => 'Monospace (Courier)'];
    public const STYLES = ['' => 'Regular', 'B' => 'Bold', 'I' => 'Italic', 'BI' => 'Bold italic'];
    public const ALIGNS = ['L' => 'Left', 'C' => 'Centre', 'R' => 'Right'];
    public const ELEMENT_TYPES = ['text' => 'Text', 'logo' => 'NGO logo', 'line' => 'Line', 'rect' => 'Filled box'];
    public const BORDERS = ['double' => 'Double frame', 'single' => 'Single frame', 'corners' => 'Thick frame', 'none' => 'No frame'];
    public const MAX_ELEMENTS = 40;

    public static function all(): array
    {
        return Database::instance()->fetchAll(
            'SELECT t.*, (SELECT COUNT(*) FROM certificate_template_tenants x WHERE x.template_id = t.id) AS tenant_count,
                    (SELECT COUNT(*) FROM certificates c WHERE c.template_id = t.id) AS issued_count
               FROM certificate_templates t ORDER BY t.name'
        );
    }

    public static function find(int $id): ?array
    {
        return Database::instance()->fetch('SELECT * FROM certificate_templates WHERE id = ?', [$id]);
    }

    /** Active templates assigned to an NGO. */
    public static function forTenant(int $tenantId): array
    {
        return Database::instance()->fetchAll(
            "SELECT t.* FROM certificate_templates t JOIN certificate_template_tenants x ON x.template_id = t.id
              WHERE x.tenant_id = ? AND t.status = 'active' ORDER BY t.name",
            [$tenantId]
        );
    }

    public static function findForTenant(int $tenantId, int $templateId): ?array
    {
        return Database::instance()->fetch(
            "SELECT t.* FROM certificate_templates t JOIN certificate_template_tenants x ON x.template_id = t.id
              WHERE t.id = ? AND x.tenant_id = ? AND t.status = 'active'",
            [$templateId, $tenantId]
        );
    }

    public static function assignedTenantIds(int $templateId): array
    {
        return array_map('intval', array_column(Database::instance()->fetchAll(
            'SELECT tenant_id FROM certificate_template_tenants WHERE template_id = ?', [$templateId]
        ), 'tenant_id'));
    }

    public static function save(array $actor, ?array $template, array $meta, array $layout): int
    {
        $name = trim($meta['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 120) {
            throw new DomainException('Template name is required (up to 120 characters).');
        }
        $orientation = ($meta['orientation'] ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape';
        $status = ($meta['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $layout = self::normalize($layout, $orientation);
        if (!$layout['elements']) {
            throw new DomainException('Add at least one element to the certificate.');
        }
        $db = Database::instance();
        if ($db->fetchColumn('SELECT id FROM certificate_templates WHERE name = ? AND id <> ?', [$name, (int) ($template['id'] ?? 0)])) {
            throw new DomainException('Another template already uses this name.');
        }
        $row = [
            'name' => $name,
            'description' => trim($meta['description'] ?? '') !== '' ? mb_substr(trim($meta['description']), 0, 255) : null,
            'orientation' => $orientation,
            'layout' => json_encode($layout, JSON_UNESCAPED_UNICODE),
            'status' => $status,
        ];
        if ($template === null) {
            $id = $db->insert('certificate_templates', $row + ['created_by' => (int) $actor['id']]);
            AuditLogger::log('certificate_template.created', 'certificate_template', $id, ['name' => $name], null, $actor);
            return $id;
        }
        $db->execute('UPDATE certificate_templates SET version = version + 1 WHERE id = ?', [(int) $template['id']]);
        $db->update('certificate_templates', $row, ['id' => (int) $template['id']]);
        AuditLogger::log('certificate_template.updated', 'certificate_template', $template['id'], ['name' => $name], null, $actor);
        return (int) $template['id'];
    }

    /** Replace the set of NGOs a template is assigned to. */
    public static function assign(array $actor, array $template, array $tenantIds): void
    {
        $db = Database::instance();
        $tenantIds = array_values(array_unique(array_filter(array_map('intval', $tenantIds))));
        $valid = $tenantIds ? array_map('intval', array_column($db->fetchAll(
            'SELECT id FROM tenants WHERE id IN (' . implode(',', array_fill(0, count($tenantIds), '?')) . ')', $tenantIds
        ), 'id')) : [];
        $before = self::assignedTenantIds((int) $template['id']);
        $db->transaction(function (Database $db) use ($template, $valid, $before, $actor): void {
            foreach (array_diff($before, $valid) as $tid) {
                $db->execute('DELETE FROM certificate_template_tenants WHERE template_id = ? AND tenant_id = ?', [(int) $template['id'], $tid]);
                AuditLogger::log('certificate_template.unassigned', 'certificate_template', $template['id'], ['name' => $template['name']], $tid, $actor);
            }
            foreach (array_diff($valid, $before) as $tid) {
                $db->insert('certificate_template_tenants', ['template_id' => (int) $template['id'], 'tenant_id' => $tid, 'assigned_by' => (int) $actor['id']]);
                AuditLogger::log('certificate_template.assigned', 'certificate_template', $template['id'], ['name' => $template['name']], $tid, $actor);
            }
        });
    }

    /** Page size in mm for an orientation. */
    public static function pageSize(string $orientation): array
    {
        return $orientation === 'portrait' ? [210.0, 297.0] : [297.0, 210.0];
    }

    /** Validate and clamp a layout coming from the builder or a preset. */
    public static function normalize(array $layout, string $orientation): array
    {
        [$pw, $ph] = self::pageSize($orientation);
        $hex = static fn ($v, $d) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : $d;
        $num = static fn ($v, float $min, float $max, float $d) => is_numeric($v) ? max($min, min($max, round((float) $v, 1))) : $d;
        $page = (array) ($layout['page'] ?? []);
        $out = [
            'page' => [
                'background' => $hex($page['background'] ?? null, '#ffffff'),
                'border' => isset(self::BORDERS[$page['border'] ?? '']) ? $page['border'] : 'double',
                'border_color' => $hex($page['border_color'] ?? null, '#1f3c88'),
            ],
            'elements' => [],
        ];
        foreach (array_slice((array) ($layout['elements'] ?? []), 0, self::MAX_ELEMENTS) as $el) {
            $el = (array) $el;
            $type = $el['type'] ?? '';
            if (!isset(self::ELEMENT_TYPES[$type])) {
                continue;
            }
            $e = [
                'type' => $type,
                'x' => $num($el['x'] ?? 0, 0, $pw, 0),
                'y' => $num($el['y'] ?? 0, 0, $ph, 0),
                'w' => $num($el['w'] ?? 50, 1, $pw, 50),
            ];
            switch ($type) {
                case 'text':
                    $text = mb_substr(trim(str_replace("\r", '', (string) ($el['text'] ?? ''))), 0, 500);
                    if ($text === '') {
                        continue 2;
                    }
                    $e += [
                        'text' => $text,
                        'size' => $num($el['size'] ?? 14, 5, 96, 14),
                        'font' => isset(self::FONTS[$el['font'] ?? '']) ? $el['font'] : 'helvetica',
                        'style' => isset(self::STYLES[$el['style'] ?? 'x']) ? $el['style'] : '',
                        'align' => isset(self::ALIGNS[$el['align'] ?? '']) ? $el['align'] : 'C',
                        'color' => $hex($el['color'] ?? null, '#222222'),
                    ];
                    break;
                case 'logo':
                    $e['h'] = $num($el['h'] ?? 25, 1, $ph, 25);
                    break;
                case 'line':
                    $e += ['size' => $num($el['size'] ?? 0.4, 0.1, 5, 0.4), 'color' => $hex($el['color'] ?? null, '#333333')];
                    break;
                case 'rect':
                    $e += ['h' => $num($el['h'] ?? 10, 0.5, $ph, 10), 'color' => $hex($el['color'] ?? null, '#eeeeee')];
                    break;
            }
            $out['elements'][] = $e;
        }
        return $out;
    }

    /** Build a layout from the builder form's parallel arrays (el_type[], el_text[], ...). */
    public static function layoutFromForm(array $body): array
    {
        $types = (array) ($body['el_type'] ?? []);
        $elements = [];
        foreach ($types as $i => $type) {
            $get = static fn (string $k) => is_array($body[$k] ?? null) && isset($body[$k][$i]) && is_string($body[$k][$i]) ? $body[$k][$i] : null;
            $elements[] = [
                'type' => is_string($type) ? $type : '',
                'text' => $get('el_text'), 'x' => $get('el_x'), 'y' => $get('el_y'), 'w' => $get('el_w'), 'h' => $get('el_h'),
                'size' => $get('el_size'), 'font' => $get('el_font'), 'style' => $get('el_style') ?? '', 'align' => $get('el_align'),
                'color' => $get('el_color'),
            ];
        }
        return [
            'page' => [
                'background' => is_string($body['page_background'] ?? null) ? $body['page_background'] : null,
                'border' => is_string($body['page_border'] ?? null) ? $body['page_border'] : null,
                'border_color' => is_string($body['page_border_color'] ?? null) ? $body['page_border_color'] : null,
            ],
            'elements' => $elements,
        ];
    }

    public static function presets(): array
    {
        return [
            'appreciation' => ['name' => 'Appreciation (landscape)', 'orientation' => 'landscape', 'layout' => [
                'page' => ['background' => '#fffdf6', 'border' => 'double', 'border_color' => '#8a6d1f'],
                'elements' => [
                    ['type' => 'logo', 'x' => 128.5, 'y' => 18, 'w' => 40, 'h' => 24],
                    ['type' => 'text', 'text' => '{{ngo_name}}', 'x' => 20, 'y' => 46, 'w' => 257, 'size' => 15, 'font' => 'helvetica', 'style' => 'B', 'align' => 'C', 'color' => '#333333'],
                    ['type' => 'text', 'text' => '{{title}}', 'x' => 20, 'y' => 60, 'w' => 257, 'size' => 32, 'font' => 'times', 'style' => 'B', 'align' => 'C', 'color' => '#8a6d1f'],
                    ['type' => 'text', 'text' => 'This certificate is proudly presented to', 'x' => 20, 'y' => 84, 'w' => 257, 'size' => 13, 'font' => 'times', 'style' => 'I', 'align' => 'C', 'color' => '#444444'],
                    ['type' => 'text', 'text' => '{{name}}', 'x' => 20, 'y' => 96, 'w' => 257, 'size' => 30, 'font' => 'times', 'style' => 'BI', 'align' => 'C', 'color' => '#1a1a1a'],
                    ['type' => 'line', 'x' => 88.5, 'y' => 112, 'w' => 120, 'size' => 0.4, 'color' => '#8a6d1f'],
                    ['type' => 'text', 'text' => '{{description}}', 'x' => 40, 'y' => 118, 'w' => 217, 'size' => 13, 'font' => 'times', 'style' => '', 'align' => 'C', 'color' => '#333333'],
                    ['type' => 'text', 'text' => '{{person_type}} ID: {{member_id}}', 'x' => 20, 'y' => 138, 'w' => 257, 'size' => 10, 'font' => 'helvetica', 'style' => '', 'align' => 'C', 'color' => '#666666'],
                    ['type' => 'line', 'x' => 30, 'y' => 172, 'w' => 70, 'size' => 0.3, 'color' => '#333333'],
                    ['type' => 'text', 'text' => "Date: {{issue_date}}\nCertificate no. {{certificate_no}}", 'x' => 30, 'y' => 174, 'w' => 70, 'size' => 9.5, 'font' => 'helvetica', 'style' => '', 'align' => 'C', 'color' => '#444444'],
                    ['type' => 'line', 'x' => 197, 'y' => 172, 'w' => 70, 'size' => 0.3, 'color' => '#333333'],
                    ['type' => 'text', 'text' => "{{signatory_name}}\n{{signatory_designation}}", 'x' => 197, 'y' => 174, 'w' => 70, 'size' => 9.5, 'font' => 'helvetica', 'style' => '', 'align' => 'C', 'color' => '#444444'],
                ],
            ]],
            'internship' => ['name' => 'Internship completion (landscape)', 'orientation' => 'landscape', 'layout' => [
                'page' => ['background' => '#ffffff', 'border' => 'corners', 'border_color' => '#1f3c88'],
                'elements' => [
                    ['type' => 'rect', 'x' => 0, 'y' => 0, 'w' => 297, 'h' => 14, 'color' => '#1f3c88'],
                    ['type' => 'logo', 'x' => 24, 'y' => 24, 'w' => 34, 'h' => 22],
                    ['type' => 'text', 'text' => '{{ngo_name}}', 'x' => 64, 'y' => 26, 'w' => 200, 'size' => 16, 'font' => 'helvetica', 'style' => 'B', 'align' => 'L', 'color' => '#1f3c88'],
                    ['type' => 'text', 'text' => '{{ngo_address}}', 'x' => 64, 'y' => 34, 'w' => 200, 'size' => 9, 'font' => 'helvetica', 'style' => '', 'align' => 'L', 'color' => '#555555'],
                    ['type' => 'text', 'text' => 'INTERNSHIP CERTIFICATE', 'x' => 20, 'y' => 62, 'w' => 257, 'size' => 26, 'font' => 'helvetica', 'style' => 'B', 'align' => 'C', 'color' => '#1f3c88'],
                    ['type' => 'text', 'text' => 'This is to certify that', 'x' => 20, 'y' => 82, 'w' => 257, 'size' => 12, 'font' => 'helvetica', 'style' => '', 'align' => 'C', 'color' => '#444444'],
                    ['type' => 'text', 'text' => '{{name}}', 'x' => 20, 'y' => 92, 'w' => 257, 'size' => 26, 'font' => 'times', 'style' => 'B', 'align' => 'C', 'color' => '#111111'],
                    ['type' => 'text', 'text' => 'has successfully completed an internship with {{ngo_name}} in the {{department}} department from {{start_date}} to {{end_date}}. {{description}}', 'x' => 35, 'y' => 108, 'w' => 227, 'size' => 12, 'font' => 'helvetica', 'style' => '', 'align' => 'C', 'color' => '#333333'],
                    ['type' => 'text', 'text' => 'Intern ID: {{member_id}}   |   Certificate no. {{certificate_no}}   |   Issued on {{issue_date}}', 'x' => 20, 'y' => 140, 'w' => 257, 'size' => 9, 'font' => 'helvetica', 'style' => '', 'align' => 'C', 'color' => '#666666'],
                    ['type' => 'line', 'x' => 200, 'y' => 174, 'w' => 70, 'size' => 0.3, 'color' => '#333333'],
                    ['type' => 'text', 'text' => "{{signatory_name}}\n{{signatory_designation}}", 'x' => 200, 'y' => 176, 'w' => 70, 'size' => 9.5, 'font' => 'helvetica', 'style' => '', 'align' => 'C', 'color' => '#444444'],
                    ['type' => 'rect', 'x' => 0, 'y' => 202, 'w' => 297, 'h' => 8, 'color' => '#1f3c88'],
                ],
            ]],
            'membership' => ['name' => 'Membership (portrait)', 'orientation' => 'portrait', 'layout' => [
                'page' => ['background' => '#ffffff', 'border' => 'double', 'border_color' => '#0f6e4f'],
                'elements' => [
                    ['type' => 'logo', 'x' => 80, 'y' => 24, 'w' => 50, 'h' => 30],
                    ['type' => 'text', 'text' => '{{ngo_name}}', 'x' => 20, 'y' => 60, 'w' => 170, 'size' => 16, 'font' => 'helvetica', 'style' => 'B', 'align' => 'C', 'color' => '#0f6e4f'],
                    ['type' => 'text', 'text' => 'Registration no. {{ngo_registration}}', 'x' => 20, 'y' => 69, 'w' => 170, 'size' => 9, 'font' => 'helvetica', 'style' => '', 'align' => 'C', 'color' => '#666666'],
                    ['type' => 'text', 'text' => 'Certificate of Membership', 'x' => 20, 'y' => 95, 'w' => 170, 'size' => 28, 'font' => 'times', 'style' => 'B', 'align' => 'C', 'color' => '#0f6e4f'],
                    ['type' => 'text', 'text' => 'This is to certify that', 'x' => 20, 'y' => 125, 'w' => 170, 'size' => 13, 'font' => 'times', 'style' => 'I', 'align' => 'C', 'color' => '#444444'],
                    ['type' => 'text', 'text' => '{{name}}', 'x' => 20, 'y' => 138, 'w' => 170, 'size' => 26, 'font' => 'times', 'style' => 'B', 'align' => 'C', 'color' => '#111111'],
                    ['type' => 'text', 'text' => 'is a {{category}} member of {{ngo_name}} since {{joined_on}}, with membership ID {{member_id}}. {{description}}', 'x' => 30, 'y' => 156, 'w' => 150, 'size' => 12, 'font' => 'times', 'style' => '', 'align' => 'C', 'color' => '#333333'],
                    ['type' => 'text', 'text' => 'Valid till {{ends_on}}', 'x' => 20, 'y' => 186, 'w' => 170, 'size' => 11, 'font' => 'helvetica', 'style' => 'B', 'align' => 'C', 'color' => '#0f6e4f'],
                    ['type' => 'line', 'x' => 25, 'y' => 245, 'w' => 60, 'size' => 0.3, 'color' => '#333333'],
                    ['type' => 'text', 'text' => "Date: {{issue_date}}\nNo. {{certificate_no}}", 'x' => 25, 'y' => 247, 'w' => 60, 'size' => 9.5, 'font' => 'helvetica', 'style' => '', 'align' => 'C', 'color' => '#444444'],
                    ['type' => 'line', 'x' => 125, 'y' => 245, 'w' => 60, 'size' => 0.3, 'color' => '#333333'],
                    ['type' => 'text', 'text' => "{{signatory_name}}\n{{signatory_designation}}", 'x' => 125, 'y' => 247, 'w' => 60, 'size' => 9.5, 'font' => 'helvetica', 'style' => '', 'align' => 'C', 'color' => '#444444'],
                ],
            ]],
        ];
    }
}
