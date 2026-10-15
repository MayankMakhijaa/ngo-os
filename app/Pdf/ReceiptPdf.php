<?php
declare(strict_types=1);

namespace App\Pdf;

use App\Services\ReceiptService;
use App\Support\IndianNumber;

/** 80G donation receipt, built from the NGO snapshot stored on the receipt. */
final class ReceiptPdf
{
    public static function render(array $ngo, array $r, ?string $logo, ?string $signature, bool $sample = false): string
    {
        $pdf = new PdfDocument('A4', 'P');
        $pdf->setTitle('Donation receipt ' . $r['receipt_no']);
        $pdf->addPage();
        if (($r['status'] ?? 'issued') === 'cancelled' || $sample) {
            // Watermark first, so the receipt text stays readable on top of it.
            $pdf->setFont('helvetica', 'B', 72);
            $pdf->setTextColor($sample ? '#e3e8f0' : '#f6c9c9');
            $pdf->textBox(0, 130, $pdf->width(), $sample ? 'SAMPLE' : 'CANCELLED', 'C');
        }
        $m = 15.0;
        $w = $pdf->width() - 2 * $m;
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($ngo['color'] ?? '')) ? $ngo['color'] : '#2b59c3';
        $fmt = static fn (?string $d) => $d ? date('d M Y', strtotime($d)) : '';

        $right = array_values(array_filter([
            'PAN: ' . ($ngo['pan'] ?? ''),
            '80G Reg. No.: ' . ($ngo['reg_no'] ?? ''),
            !empty($ngo['reg_date']) ? 'Approved on: ' . $fmt($ngo['reg_date']) : '',
            (!empty($ngo['valid_from']) || !empty($ngo['valid_to'])) ? 'Valid: ' . trim($fmt($ngo['valid_from'] ?? null) . ' to ' . $fmt($ngo['valid_to'] ?? null)) : '',
            !empty($ngo['registration_number']) ? 'Regn. No.: ' . $ngo['registration_number'] : '',
        ]));
        $y = Branding::header($pdf, ['name' => $ngo['name'] ?? '', 'address' => $ngo['address'] ?? '', 'phone' => $ngo['phone'] ?? '',
            'email' => $ngo['email'] ?? '', 'website' => $ngo['website'] ?? '', 'color' => $color], $logo, $m, $right);

        $pdf->setTextColor($color);
        $pdf->setFont('helvetica', 'B', 16);
        $pdf->textBox($m, $y, $w, (string) ($ngo['title'] ?? 'Donation Receipt'), 'C');
        $y += 7.5;
        if (!empty($ngo['subtitle'])) {
            $pdf->setTextColor('#444444');
            $pdf->setFont('helvetica', '', 9.5);
            $y += $pdf->textBox($m, $y, $w, $ngo['subtitle'], 'C');
        }
        $y += 5;

        $pdf->setTextColor('#111111');
        $pdf->setFont('helvetica', 'B', 10);
        $pdf->text($m, $y + 4, 'Receipt No.: ' . $r['receipt_no']);
        $date = 'Date: ' . $fmt($r['donation_date']);
        $pdf->text($m + $w - $pdf->textWidth($date), $y + 4, $date);
        $y += 9;

        // Donor and payment details table
        $modes = ReceiptService::MODES;
        $idTypes = ReceiptService::ID_TYPES;
        $rows = [
            ['Received from', $r['donor_name']],
            ['Address', preg_replace('/\s+/', ' ', (string) $r['donor_address'])],
            [$r['donor_id_type'] === 'none' ? 'Donor ID' : 'Donor ' . $idTypes[$r['donor_id_type']], $r['donor_id_type'] === 'none' ? 'Not provided' : (string) $r['donor_id_number']],
            ['Amount', 'Rs. ' . IndianNumber::format($r['amount'])],
            ['Amount in words', IndianNumber::rupeesInWords($r['amount'])],
            ['Mode of payment', ($modes[$r['payment_mode']] ?? $r['payment_mode']) . ($r['payment_reference'] ? ' - Ref. ' . $r['payment_reference'] : '') . ($r['bank_name'] ? ' (' . $r['bank_name'] . ')' : '')],
            ['Nature of donation', ReceiptService::DONATION_TYPES[$r['donation_type']] ?? 'General donation'],
        ];
        if (!empty($r['purpose'])) {
            $rows[] = ['Purpose', $r['purpose']];
        }
        $labelW = 45.0;
        $pdf->setDrawColor('#cccccc');
        foreach ($rows as [$label, $value]) {
            $pdf->setFont('helvetica', $label === 'Amount' ? 'B' : '', $label === 'Amount' ? 11 : 9.5);
            $lines = $pdf->wrap((string) $value, $w - $labelW - 6);
            $h = max(7.5, count($lines) * 4.6 + 3);
            $pdf->setFillColor('#f4f6fa');
            $pdf->rect($m, $y, $labelW, $h, 'DF');
            $pdf->rect($m + $labelW, $y, $w - $labelW, $h);
            $pdf->setFont('helvetica', 'B', 9);
            $pdf->setTextColor('#444444');
            $pdf->text($m + 2.5, $y + 5, $label);
            $pdf->setTextColor('#111111');
            $pdf->setFont('helvetica', $label === 'Amount' ? 'B' : '', $label === 'Amount' ? 11 : 9.5);
            $pdf->textBox($m + $labelW + 3, $y + 1.6, $w - $labelW - 6, (string) $value, 'L', 4.6);
            $y += $h;
        }
        $y += 7;

        $values = [
            'donor_name' => $r['donor_name'], 'amount' => IndianNumber::format($r['amount']), 'amount_words' => IndianNumber::rupeesInWords($r['amount']),
            'payment_mode' => strtolower($modes[$r['payment_mode']] ?? $r['payment_mode']), 'donation_type' => strtolower(ReceiptService::DONATION_TYPES[$r['donation_type']] ?? 'donation'),
            'purpose' => (string) ($r['purpose'] ?? ''), 'purpose_suffix' => !empty($r['purpose']) ? ' for ' . $r['purpose'] : '',
            'receipt_no' => $r['receipt_no'], 'date' => $fmt($r['donation_date']), 'ngo_name' => $ngo['name'] ?? '', 'financial_year' => $r['financial_year'],
        ];
        $pdf->setFont('helvetica', '', 10);
        $y += $pdf->textBox($m, $y, $w, CertificatePdf::fill((string) ($ngo['declaration'] ?? ''), $values), 'L', 5.2) + 4;

        if (empty($r['eligible_80g'])) {
            $reason = $r['payment_mode'] === 'kind'
                ? 'Donations in kind are not eligible for deduction under section 80G.'
                : 'Cash donations above Rs. ' . IndianNumber::format($ngo['cash_limit'] ?? 2000) . ' are not eligible for deduction under section 80G.';
            $pdf->setFillColor('#fff4e5');
            $pdf->setDrawColor('#f0a030');
            $pdf->setFont('helvetica', 'B', 9.5);
            $lines = $pdf->wrap('Note: ' . $reason, $w - 8);
            $boxH = count($lines) * 4.8 + 5;
            $pdf->rect($m, $y, $w, $boxH, 'DF');
            $pdf->setTextColor('#7a4a00');
            $pdf->textBox($m + 4, $y + 2.5, $w - 8, 'Note: ' . $reason, 'L', 4.8);
            $pdf->setTextColor('#111111');
            $y += $boxH + 6;
        }

        // Signature block
        $sy = max($y + 6, 205.0);
        $sx = $m + $w - 70;
        $pdf->setFont('helvetica', '', 9.5);
        $pdf->textBox($sx, $sy, 70, 'For ' . ($ngo['name'] ?? ''), 'C', 4.5, 2);
        if ($signature !== null) {
            $pdf->image($signature, $sx + 15, $sy + 10, 40, 16);
        }
        $pdf->setDrawColor('#555555');
        $pdf->line($sx + 5, $sy + 29, $sx + 65, $sy + 29);
        $pdf->setFont('helvetica', 'B', 9.5);
        $pdf->textBox($sx, $sy + 30.5, 70, (string) ($ngo['signatory_name'] ?? ''), 'C');
        $pdf->setFont('helvetica', '', 9);
        $pdf->textBox($sx, $sy + 35, 70, ($ngo['signatory_designation'] ?? '') ?: 'Authorised signatory', 'C');

        if (!empty($ngo['footer'])) {
            $pdf->setFont('helvetica', '', 8);
            $pdf->setTextColor('#666666');
            $lines = $pdf->wrap($ngo['footer'], $w);
            $fy = $pdf->height() - 14 - count($lines) * 3.8;
            $pdf->setDrawColor('#dddddd');
            $pdf->line($m, $fy - 2, $m + $w, $fy - 2);
            $pdf->textBox($m, $fy, $w, $ngo['footer'], 'C', 3.8);
        }
        return $pdf->output();
    }
}
