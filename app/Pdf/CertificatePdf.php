<?php
declare(strict_types=1);

namespace App\Pdf;

use App\Services\CertificateTemplateService;

/** Renders a certificate layout (see CertificateTemplateService) with placeholder values. */
final class CertificatePdf
{
    public static function render(array $layout, string $orientation, array $values, ?string $logo, bool $revoked = false, bool $logoPlaceholder = false): string
    {
        [$w, $h] = CertificateTemplateService::pageSize($orientation);
        $pdf = new PdfDocument([$w, $h], $orientation === 'portrait' ? 'P' : 'L');
        $pdf->setTitle((string) ($values['title'] ?? 'Certificate'));
        $pdf->addPage();
        $page = $layout['page'] ?? [];
        if (($page['background'] ?? '#ffffff') !== '#ffffff') {
            $pdf->setFillColor($page['background']);
            $pdf->rect(0, 0, $w, $h, 'F');
        }
        $pdf->setDrawColor($page['border_color'] ?? '#1f3c88');
        switch ($page['border'] ?? 'double') {
            case 'double':
                $pdf->setLineWidth(1.2);
                $pdf->rect(8, 8, $w - 16, $h - 16);
                $pdf->setLineWidth(0.4);
                $pdf->rect(11.5, 11.5, $w - 23, $h - 23);
                break;
            case 'single':
                $pdf->setLineWidth(0.6);
                $pdf->rect(10, 10, $w - 20, $h - 20);
                break;
            case 'corners':
                $pdf->setLineWidth(3);
                $pdf->rect(6, 6, $w - 12, $h - 12);
                break;
        }
        $pdf->setLineWidth(0.2);

        foreach ($layout['elements'] ?? [] as $el) {
            switch ($el['type']) {
                case 'rect':
                    $pdf->setFillColor($el['color']);
                    $pdf->rect($el['x'], $el['y'], $el['w'], $el['h'], 'F');
                    break;
                case 'line':
                    $pdf->setDrawColor($el['color']);
                    $pdf->setLineWidth($el['size']);
                    $pdf->line($el['x'], $el['y'], $el['x'] + $el['w'], $el['y']);
                    $pdf->setLineWidth(0.2);
                    break;
                case 'logo':
                    if ($logo !== null) {
                        $pdf->image($logo, $el['x'], $el['y'], $el['w'], $el['h']);
                    } elseif ($logoPlaceholder) {
                        self::logoPlaceholder($pdf, $el);
                    }
                    break;
                case 'text':
                    $text = self::fill($el['text'], $values);
                    if (trim($text) === '') {
                        break;
                    }
                    $pdf->setFont($el['font'], $el['style'], $el['size']);
                    $pdf->setTextColor($el['color']);
                    $pdf->textBox($el['x'], $el['y'], $el['w'], $text, $el['align'], $el['size'] * 0.3528 * 1.3);
                    break;
            }
        }
        if ($revoked) {
            $pdf->setFont('helvetica', 'B', 54);
            $pdf->setTextColor('#d62828');
            $pdf->textBox(0, $h / 2 - 12, $w, 'REVOKED', 'C');
        }
        return $pdf->output();
    }

    private static function logoPlaceholder(PdfDocument $pdf, array $el): void
    {
        $pdf->setDrawColor('#b8c2d1');
        $pdf->rect($el['x'], $el['y'], $el['w'], $el['h']);
        $pdf->setFont('helvetica', '', 8);
        $pdf->setTextColor('#8a96a8');
        $pdf->textBox($el['x'], $el['y'] + $el['h'] / 2 - 2, $el['w'], 'NGO logo', 'C');
    }

    /** Replace {{placeholders}}; unknown or empty ones become empty strings. */
    public static function fill(string $text, array $values): string
    {
        $out = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static fn ($m) => (string) ($values[$m[1]] ?? ''), $text) ?? $text;
        // Tidy separators left behind by empty values ("ID:  |" etc.).
        return trim(preg_replace('/[ \t]{2,}/', ' ', $out) ?? $out);
    }
}
