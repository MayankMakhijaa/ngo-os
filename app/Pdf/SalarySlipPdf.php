<?php
declare(strict_types=1);

namespace App\Pdf;

use App\Support\IndianNumber;

final class SalarySlipPdf
{
    public static function render(array $ngo, ?string $logo, array $person, array $slip): string
    {
        $types = \App\Services\SalarySlipService::PAY_TYPES;
        $modes = \App\Services\SalarySlipService::MODES;
        $month = date('F Y', strtotime($slip['period_month'] . '-01'));
        $pdf = new PdfDocument('A4', 'P');
        $pdf->setTitle(($types[$slip['pay_type']] ?? 'Salary') . ' slip ' . $slip['slip_no']);
        $pdf->addPage();
        $m = 15.0;
        $w = $pdf->width() - 2 * $m;
        $y = Branding::header($pdf, $ngo, $logo, $m);

        $pdf->setFont('helvetica', 'B', 13);
        $pdf->textBox($m, $y, $w, ($types[$slip['pay_type']] ?? 'Salary') . ' slip for ' . $month, 'C');
        $pdf->setFont('helvetica', '', 8.5);
        $pdf->setTextColor('#555555');
        $pdf->textBox($m, $y + 6.5, $w, 'Slip no. ' . $slip['slip_no'], 'C');
        $pdf->setTextColor('#111111');
        $y += 15;

        $details = [
            ['Name', $person['name']], ['ID', $person['member_code']],
            ['Type', ucfirst($person['person_type'])], ['Designation', $person['designation'] ?: '-'],
            ['Department', $person['department'] ?: '-'], ['Date of joining', $person['joined_on'] ? date('d M Y', strtotime($person['joined_on'])) : '-'],
            ['Bank', $person['bank_name'] ?: '-'], ['Account', $person['bank_account_last4'] ? 'XXXXXX' . $person['bank_account_last4'] : '-'],
            ['IFSC', $person['bank_ifsc'] ?: '-'], ['Payment mode', $slip['payment_mode'] ? ($modes[$slip['payment_mode']] ?? $slip['payment_mode']) : '-'],
            ['Working days', $slip['working_days'] !== null ? rtrim(rtrim((string) $slip['working_days'], '0'), '.') : '-'],
            ['Paid days', $slip['paid_days'] !== null ? rtrim(rtrim((string) $slip['paid_days'], '0'), '.') : '-'],
            ['Payment date', $slip['payment_date'] ? date('d M Y', strtotime($slip['payment_date'])) : '-'],
        ];
        $pdf->setDrawColor('#cccccc');
        $rowH = 6.5;
        $rows = (int) ceil(count($details) / 2);
        $pdf->rect($m, $y, $w, $rows * $rowH);
        foreach ($details as $i => [$label, $value]) {
            $col = $i % 2;
            $row = intdiv($i, 2);
            $cx = $m + $col * ($w / 2) + 3;
            $cy = $y + $row * $rowH + 4.4;
            $pdf->setFont('helvetica', '', 8.5);
            $pdf->setTextColor('#666666');
            $pdf->text($cx, $cy, $label);
            $pdf->setFont('helvetica', 'B', 9);
            $pdf->setTextColor('#111111');
            $pdf->text($cx + 30, $cy, mb_strimwidth((string) $value, 0, 40, '...'));
        }
        $y += $rows * $rowH + 8;

        // Earnings and deductions side by side
        $earn = json_decode((string) $slip['earnings'], true) ?: [];
        $ded = json_decode((string) $slip['deductions'], true) ?: [];
        $half = $w / 2;
        $pdf->setFillColor('#f1f3f7');
        $pdf->rect($m, $y, $w, 7.5, 'F');
        $pdf->setFont('helvetica', 'B', 9.5);
        $pdf->text($m + 3, $y + 5, 'Earnings');
        $pdf->textBox($m + $half - 43, $y + 1.6, 40, 'Amount (Rs.)', 'R');
        $pdf->text($m + $half + 3, $y + 5, 'Deductions');
        $pdf->textBox($m + $w - 43, $y + 1.6, 40, 'Amount (Rs.)', 'R');
        $y += 7.5;
        $lines = max(count($earn), count($ded), 1);
        $pdf->setFont('helvetica', '', 9.5);
        for ($i = 0; $i < $lines; $i++) {
            $ly = $y + $i * 6.5;
            if (isset($earn[$i])) {
                $pdf->text($m + 3, $ly + 4.5, $earn[$i]['label']);
                $pdf->textBox($m + $half - 43, $ly + 1.2, 40, IndianNumber::format($earn[$i]['amount']), 'R');
            }
            if (isset($ded[$i])) {
                $pdf->text($m + $half + 3, $ly + 4.5, $ded[$i]['label']);
                $pdf->textBox($m + $w - 43, $ly + 1.2, 40, IndianNumber::format($ded[$i]['amount']), 'R');
            }
        }
        $tableBottom = $y + $lines * 6.5;
        $pdf->rect($m, $y - 7.5, $w, $tableBottom - $y + 7.5 + 7.5);
        $pdf->line($m + $half, $y - 7.5, $m + $half, $tableBottom + 7.5);
        $pdf->line($m, $tableBottom, $m + $w, $tableBottom);
        $pdf->setFont('helvetica', 'B', 9.5);
        $pdf->text($m + 3, $tableBottom + 5, 'Gross earnings');
        $pdf->textBox($m + $half - 43, $tableBottom + 1.6, 40, IndianNumber::format($slip['gross_amount']), 'R');
        $pdf->text($m + $half + 3, $tableBottom + 5, 'Total deductions');
        $pdf->textBox($m + $w - 43, $tableBottom + 1.6, 40, IndianNumber::format($slip['total_deductions']), 'R');
        $y = $tableBottom + 14;

        $pdf->setFillColor('#eef6ee');
        $pdf->setDrawColor('#7fb77f');
        $pdf->rect($m, $y, $w, 16, 'DF');
        $pdf->setFont('helvetica', 'B', 12);
        $pdf->text($m + 4, $y + 7, 'Net pay: Rs. ' . IndianNumber::format($slip['net_amount']));
        $pdf->setFont('helvetica', 'I', 9);
        $pdf->text($m + 4, $y + 12.5, IndianNumber::rupeesInWords($slip['net_amount']));
        $y += 24;
        if ($slip['notes']) {
            $pdf->setFont('helvetica', '', 9);
            $y += $pdf->textBox($m, $y, $w, 'Notes: ' . $slip['notes']) + 4;
        }
        $pdf->setFont('helvetica', '', 8);
        $pdf->setTextColor('#777777');
        $pdf->textBox($m, $pdf->height() - 20, $w, 'This is a computer-generated slip issued by ' . $ngo['name'] . ' and does not need a signature. Generated on ' . date('d M Y') . '.', 'C');
        return $pdf->output();
    }
}
