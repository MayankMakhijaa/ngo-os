<?php
declare(strict_types=1);

namespace App\Pdf;

/** Two-sided ID card in the standard CR80 size (54 x 85.6 mm, portrait), ready for card printing. */
final class IdCardPdf
{
    public static function render(array $ngo, ?string $logo, array $person, ?string $photo): string
    {
        $pdf = new PdfDocument('CR80', 'P');
        $pdf->setTitle('ID card ' . $person['member_code']);
        $w = $pdf->width();
        $h = $pdf->height();
        $color = $ngo['color'];

        // ---------------- front
        $pdf->addPage();
        $pdf->setFillColor($color);
        $pdf->rect(0, 0, $w, 17, 'F');
        $tx = 3.0;
        if ($logo !== null) {
            $pdf->setFillColor('#ffffff');
            $pdf->rect(2.5, 2.5, 12, 12, 'F');
            if ($pdf->image($logo, 3, 3, 11, 11)) {
                $tx = 16.5;
            }
        }
        $pdf->setTextColor('#ffffff');
        $pdf->setFont('helvetica', 'B', 7.5);
        $pdf->textBox($tx, 3.2, $w - $tx - 2.5, $ngo['display_name'], 'L', 3.2, 3);
        $pdf->setFont('helvetica', '', 5);
        $pdf->text($tx, 15, strtoupper($person['person_type']) . ' ID CARD');

        $pw = 22.0;
        $ph = 26.0;
        $px = ($w - $pw) / 2;
        $py = 20.5;
        $pdf->setDrawColor($color);
        $pdf->setLineWidth(0.5);
        $pdf->rect($px - 0.6, $py - 0.6, $pw + 1.2, $ph + 1.2);
        if ($photo === null || !$pdf->image($photo, $px, $py, $pw, $ph)) {
            $pdf->setFillColor('#eef0f4');
            $pdf->rect($px, $py, $pw, $ph, 'F');
            $pdf->setTextColor('#9aa1ad');
            $pdf->setFont('helvetica', 'B', 16);
            $initials = implode('', array_map(static fn ($p) => mb_substr($p, 0, 1), array_slice(preg_split('/\s+/', trim($person['name'])) ?: [], 0, 2)));
            $pdf->textBox($px, $py + 9, $pw, mb_strtoupper($initials), 'C');
        }
        $pdf->setLineWidth(0.2);

        $y = $py + $ph + 3.2;
        $pdf->setTextColor('#111111');
        $pdf->setFont('helvetica', 'B', 9);
        $y += $pdf->textBox(2, $y, $w - 4, $person['name'], 'C', 3.8, 2);
        if ($person['designation']) {
            $pdf->setFont('helvetica', '', 6.5);
            $pdf->setTextColor('#555555');
            $y += $pdf->textBox(2, $y, $w - 4, $person['designation'], 'C', 3, 1);
        }
        $pdf->setFont('courier', 'B', 9);
        $pdf->setTextColor($color);
        $pdf->textBox(2, $y + 0.6, $w - 4, $person['member_code'], 'C');
        $y += 6;

        $rows = array_filter([
            'Category' => $person['category_name'] ?? null,
            'Valid till' => $person['ends_on'] ? date('d M Y', strtotime($person['ends_on'])) : null,
            'Blood group' => $person['blood_group'] ?: null,
            'Phone' => $person['phone'] ?: null,
        ]);
        foreach ($rows as $label => $value) {
            if ($y > $h - 9) {
                break;
            }
            $pdf->setFont('helvetica', '', 6);
            $pdf->setTextColor('#666666');
            $pdf->text(6, $y + 2.2, $label);
            $pdf->setFont('helvetica', 'B', 6.3);
            $pdf->setTextColor('#111111');
            $pdf->text(22, $y + 2.2, mb_strimwidth((string) $value, 0, 28, '...'));
            $y += 3.4;
        }
        $pdf->setFillColor($color);
        $pdf->rect(0, $h - 4.5, $w, 4.5, 'F');
        $pdf->setTextColor('#ffffff');
        $pdf->setFont('helvetica', '', 5);
        $pdf->textBox(1, $h - 3.7, $w - 2, $ngo['website'] ?: ($ngo['reference_code'] ?? ''), 'C');

        // ---------------- back
        $pdf->addPage();
        $pdf->setFillColor($color);
        $pdf->rect(0, 0, $w, 5, 'F');
        $pdf->setTextColor('#111111');
        $pdf->setFont('helvetica', 'B', 7);
        $y = 9.0;
        $y += $pdf->textBox(4, $y, $w - 8, 'If found, please return to:', 'C', 3.4);
        $pdf->setFont('helvetica', 'B', 7.5);
        $y += $pdf->textBox(4, $y + 1.5, $w - 8, $ngo['name'], 'C', 3.4, 3) + 1.5;
        $pdf->setFont('helvetica', '', 6.3);
        $pdf->setTextColor('#333333');
        $y += $pdf->textBox(4, $y + 1, $w - 8, (string) $ngo['address'], 'C', 2.9, 5) + 1;
        foreach (array_filter([$ngo['phone'] ? 'Phone: ' . $ngo['phone'] : '', $ngo['email'] ?: '']) as $line) {
            $y += $pdf->textBox(4, $y + 0.8, $w - 8, $line, 'C', 2.9, 1) + 0.8;
        }
        if (!empty($person['emergency_contact'])) {
            $y += 3;
            $pdf->setFont('helvetica', 'B', 6.3);
            $pdf->setTextColor('#111111');
            $y += $pdf->textBox(4, $y, $w - 8, 'Emergency contact', 'C', 2.9);
            $pdf->setFont('helvetica', '', 6.3);
            $y += $pdf->textBox(4, $y + 0.5, $w - 8, $person['emergency_contact'], 'C', 2.9, 2);
        }
        $pdf->setDrawColor('#999999');
        $pdf->line(12, $h - 16, $w - 12, $h - 16);
        $pdf->setFont('helvetica', '', 5.8);
        $pdf->setTextColor('#555555');
        $pdf->textBox(4, $h - 15, $w - 8, 'Authorised signatory', 'C');
        $pdf->setFont('helvetica', '', 5);
        $pdf->textBox(3, $h - 10, $w - 6, 'This card remains the property of ' . $ngo['name'] . ' and must be returned on request.', 'C', 2.4, 3);
        $pdf->setFillColor($color);
        $pdf->rect(0, $h - 2.5, $w, 2.5, 'F');
        return $pdf->output();
    }
}
