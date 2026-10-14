<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Aadhaar number checks. The application never stores a full Aadhaar number: it validates the
 * 12 digits (format + Verhoeff checksum, as issued by UIDAI) and keeps only the last 4.
 */
final class Aadhaar
{
    private const D = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9], [1, 2, 3, 4, 0, 6, 7, 8, 9, 5], [2, 3, 4, 0, 1, 7, 8, 9, 5, 6],
        [3, 4, 0, 1, 2, 8, 9, 5, 6, 7], [4, 0, 1, 2, 3, 9, 5, 6, 7, 8], [5, 9, 8, 7, 6, 0, 4, 3, 2, 1],
        [6, 5, 9, 8, 7, 1, 0, 4, 3, 2], [7, 6, 5, 9, 8, 2, 1, 0, 4, 3], [8, 7, 6, 5, 9, 3, 2, 1, 0, 4],
        [9, 8, 7, 6, 5, 4, 3, 2, 1, 0],
    ];
    private const P = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9], [1, 5, 7, 6, 2, 8, 3, 0, 9, 4], [5, 8, 0, 3, 7, 9, 6, 1, 4, 2],
        [8, 9, 1, 6, 0, 4, 3, 5, 2, 7], [9, 4, 5, 3, 1, 2, 6, 8, 7, 0], [4, 2, 8, 6, 5, 7, 3, 9, 0, 1],
        [2, 7, 9, 3, 8, 0, 6, 4, 1, 5], [7, 0, 4, 6, 9, 1, 3, 2, 5, 8],
    ];

    public static function normalize(string $input): string
    {
        return preg_replace('/[\s-]+/', '', $input) ?? '';
    }

    public static function isValid(string $input): bool
    {
        $n = self::normalize($input);
        if (!preg_match('/^[2-9]\d{11}$/', $n)) {
            return false;
        }
        $c = 0;
        foreach (array_reverse(str_split($n)) as $i => $digit) {
            $c = self::D[$c][self::P[$i % 8][(int) $digit]];
        }
        return $c === 0;
    }

    public static function last4(string $input): string
    {
        return substr(self::normalize($input), -4);
    }

    public static function masked(?string $last4): string
    {
        return $last4 ? 'XXXX XXXX ' . $last4 : '—';
    }
}
