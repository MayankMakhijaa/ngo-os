<?php
declare(strict_types=1);

namespace App\Pdf;

use App\Core\Database;
use App\Services\StorageService;
use App\Services\TenantService;

/** NGO letterhead data and the shared header block used by receipts and salary slips. */
final class Branding
{
    /** Name, contact details, colour and logo bytes of an NGO, read from the database. */
    public static function forTenant(int $tenantId): array
    {
        $t = Database::instance()->fetch('SELECT * FROM tenants WHERE id = ?', [$tenantId]) ?? [];
        $settings = TenantService::settings($tenantId);
        return [
            'name' => $t['name'] ?? '',
            'display_name' => ($settings['display_name'] ?? '') ?: ($t['name'] ?? ''),
            'address' => $t['address'] ?? '',
            'phone' => $t['phone'] ?? '',
            'email' => $t['email'] ?? '',
            'website' => $t['website'] ?? '',
            'registration_number' => $t['registration_number'] ?? '',
            'reference_code' => $t['reference_code'] ?? '',
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($settings['primary_color'] ?? '')) ? $settings['primary_color'] : '#2b59c3',
            'logo_object_id' => $t['logo_object_id'] ?? null,
        ];
    }

    public static function logoBytes(int $tenantId, ?int $objectId): ?string
    {
        $obj = StorageService::find($objectId, $tenantId);
        if (!$obj) {
            return null;
        }
        try {
            return StorageService::contents($obj);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Letterhead: logo, NGO name, address and contacts, then a coloured rule.
     * $right: lines printed right-aligned (e.g. PAN and 80G number). Returns the y below the header.
     */
    public static function header(PdfDocument $pdf, array $ngo, ?string $logo, float $margin = 15, array $right = []): float
    {
        $y = 12.0;
        $x = $margin;
        $width = $pdf->width() - 2 * $margin;
        if ($logo !== null && $pdf->image($logo, $x, $y, 24, 24)) {
            $x += 28;
        }
        $rightW = $right ? 70 : 0;
        $textW = $width - ($x - $margin) - $rightW;
        $pdf->setTextColor('#111111');
        $pdf->setFont('helvetica', 'B', 15);
        $h = $pdf->textBox($x, $y, $textW, $ngo['name'], 'L', 6.2, 2);
        $pdf->setFont('helvetica', '', 8.5);
        $pdf->setTextColor('#444444');
        $contact = array_filter([$ngo['phone'] ? 'Phone: ' . $ngo['phone'] : '', $ngo['email'] ? 'Email: ' . $ngo['email'] : '', $ngo['website'] ?? '']);
        $h += $pdf->textBox($x, $y + $h + 0.5, $textW, (string) $ngo['address'], 'L', 3.8, 3);
        if ($contact) {
            $h += $pdf->textBox($x, $y + $h + 1, $textW, implode('   ', $contact), 'L', 3.8, 2);
        }
        if ($right) {
            $ry = $y;
            foreach ($right as $line) {
                $pdf->setFont('helvetica', '', 8.5);
                $ry += $pdf->textBox($margin + $width - $rightW, $ry, $rightW, $line, 'R', 4.0, 2);
            }
            $h = max($h, $ry - $y);
        }
        $bottom = max($y + $h, $logo !== null ? $y + 24 : 0) + 4;
        $pdf->setDrawColor($ngo['color']);
        $pdf->setLineWidth(0.8);
        $pdf->line($margin, $bottom, $margin + $width, $bottom);
        $pdf->setLineWidth(0.2);
        $pdf->setDrawColor('#000000');
        $pdf->setTextColor('#111111');
        return $bottom + 6;
    }
}
