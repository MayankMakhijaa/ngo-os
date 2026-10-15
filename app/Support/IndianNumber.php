<?php
declare(strict_types=1);

namespace App\Support;

/** Indian number formatting (lakh / crore) and amounts in words for receipts and salary slips. */
final class IndianNumber
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    /** 1234567.5 => "12,34,567.50" */
    public static function format(float|int|string $amount): string
    {
        $amount = round((float) $amount, 2);
        $negative = $amount < 0;
        [$int, $dec] = explode('.', number_format(abs($amount), 2, '.', ''));
        $last3 = substr($int, -3);
        $rest = substr($int, 0, -3);
        if ($rest !== '') {
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $int = $rest . ',' . $last3;
        }
        return ($negative ? '-' : '') . $int . '.' . $dec;
    }

    /** 1250.50 => "Rupees One Thousand Two Hundred Fifty and Fifty Paise Only" */
    public static function rupeesInWords(float|int|string $amount): string
    {
        $amount = round(abs((float) $amount), 2);
        $rupees = (int) floor($amount);
        $paise = (int) round(($amount - $rupees) * 100);
        $words = 'Rupees ' . ($rupees === 0 ? 'Zero' : self::words($rupees));
        if ($paise > 0) {
            $words .= ' and ' . self::words($paise) . ' Paise';
        }
        return $words . ' Only';
    }

    public static function words(int $n): string
    {
        if ($n === 0) {
            return 'Zero';
        }
        $parts = [];
        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']] as [$div, $label]) {
            if ($n >= $div) {
                $q = intdiv($n, $div);
                $parts[] = ($div === 10000000 ? self::words($q) : self::belowHundred($q)) . ' ' . $label;
                $n %= $div;
            }
        }
        if ($n > 0) {
            $parts[] = self::belowHundred($n);
        }
        return implode(' ', $parts);
    }

    private static function belowHundred(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }
        return trim(self::TENS[intdiv($n, 10)] . ' ' . self::ONES[$n % 10]);
    }
}
