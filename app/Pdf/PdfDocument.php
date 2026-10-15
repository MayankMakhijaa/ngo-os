<?php
declare(strict_types=1);

namespace App\Pdf;

/**
 * Minimal PDF writer in plain PHP, so documents can be generated on shared hosting without
 * extensions or third-party libraries. Supports the 14 standard PDF fonts (Helvetica, Times,
 * Courier with bold/italic; text is Windows-1252 encoded), text with wrapping and alignment,
 * lines, rectangles and JPEG/PNG/WebP images (non-JPEG images are converted with GD when it is
 * installed). Coordinates are millimetres from the top-left corner of the page.
 */
final class PdfDocument
{
    private const K = 72 / 25.4; // points per millimetre

    public const SIZES = [
        'A4'   => [210.0, 297.0],
        'CR80' => [53.98, 85.6], // ID card, portrait
    ];

    private const BASE_FONTS = [
        'helvetica' => 'Helvetica', 'helveticaB' => 'Helvetica-Bold', 'helveticaI' => 'Helvetica-Oblique', 'helveticaBI' => 'Helvetica-BoldOblique',
        'times' => 'Times-Roman', 'timesB' => 'Times-Bold', 'timesI' => 'Times-Italic', 'timesBI' => 'Times-BoldItalic',
        'courier' => 'Courier', 'courierB' => 'Courier-Bold', 'courierI' => 'Courier-Oblique', 'courierBI' => 'Courier-BoldOblique',
    ];

    /** @var array<int, array{w: float, h: float, content: string}> */
    private array $pages = [];
    private int $page = -1;
    private float $defaultW;
    private float $defaultH;
    /** @var array<string, int> font key => resource number */
    private array $fonts = [];
    /** @var array<string, array{n:int, w:int, h:int, cs:string, bpc:int, data:string}> */
    private array $images = [];
    private string $fontKey = 'helvetica';
    private float $fontSize = 12;
    private string $textColor = '0 g';
    private string $title = '';

    public function __construct(string|array $size = 'A4', string $orientation = 'P')
    {
        [$w, $h] = is_array($size) ? $size : (self::SIZES[$size] ?? self::SIZES['A4']);
        if (strtoupper($orientation) === 'L') {
            [$w, $h] = [max($w, $h), min($w, $h)];
        } else {
            [$w, $h] = [min($w, $h), max($w, $h)];
        }
        $this->defaultW = (float) $w;
        $this->defaultH = (float) $h;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function addPage(): void
    {
        $this->pages[] = ['w' => $this->defaultW, 'h' => $this->defaultH, 'content' => ''];
        $this->page = count($this->pages) - 1;
        // Re-apply the current font for the new content stream.
        $this->setFont(...$this->splitFontKey());
    }

    public function width(): float
    {
        return $this->defaultW;
    }

    public function height(): float
    {
        return $this->defaultH;
    }

    // ------------------------------------------------------------------ graphics state

    /** @param string $family helvetica | times | courier  @param string $style '', 'B', 'I' or 'BI' */
    public function setFont(string $family, string $style = '', float $size = 0): void
    {
        $family = strtolower($family);
        if (!in_array($family, ['helvetica', 'times', 'courier'], true)) {
            $family = 'helvetica';
        }
        $style = strtoupper($style);
        $style = (str_contains($style, 'B') ? 'B' : '') . (str_contains($style, 'I') ? 'I' : '');
        $this->fontKey = $family . $style;
        if ($size > 0) {
            $this->fontSize = $size;
        }
        if (!isset($this->fonts[$this->fontKey])) {
            $this->fonts[$this->fontKey] = count($this->fonts) + 1;
        }
        if ($this->page >= 0) {
            $this->out(sprintf('BT /F%d %.2F Tf ET', $this->fonts[$this->fontKey], $this->fontSize));
        }
    }

    public function setFontSize(float $size): void
    {
        $this->setFont(...[...$this->splitFontKey(), $size]);
    }

    public function setTextColor(string $hex): void
    {
        $this->textColor = self::rgb($hex) . ' rg';
    }

    public function setDrawColor(string $hex): void
    {
        $this->out(self::rgb($hex) . ' RG');
    }

    public function setFillColor(string $hex): void
    {
        $this->out(self::rgb($hex) . ' rg');
    }

    public function setLineWidth(float $mm): void
    {
        $this->out(sprintf('%.2F w', $mm * self::K));
    }

    // ------------------------------------------------------------------ drawing

    public function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $h = $this->pageH();
        $this->out(sprintf('%.2F %.2F m %.2F %.2F l S', $x1 * self::K, ($h - $y1) * self::K, $x2 * self::K, ($h - $y2) * self::K));
    }

    /** @param string $style D = stroke, F = fill, DF = both */
    public function rect(float $x, float $y, float $w, float $h, string $style = 'D'): void
    {
        $op = match (strtoupper($style)) { 'F' => 'f', 'DF', 'FD' => 'B', default => 'S' };
        $this->out(sprintf('%.2F %.2F %.2F %.2F re %s', $x * self::K, ($this->pageH() - $y) * self::K, $w * self::K, -$h * self::K, $op));
    }

    /** Text with its baseline at $y. */
    public function text(float $x, float $y, string $text): void
    {
        $enc = self::encode($text);
        if ($enc === '') {
            return;
        }
        $this->out(sprintf('q %s BT /F%d %.2F Tf %.2F %.2F Td (%s) Tj ET Q', $this->textColor, $this->fonts[$this->fontKey], $this->fontSize,
            $x * self::K, ($this->pageH() - $y) * self::K, self::escape($enc)));
    }

    /** Width of a string in millimetres with the current font. */
    public function textWidth(string $text): float
    {
        return $this->encodedWidth(self::encode($text));
    }

    /**
     * Wrapped text inside a box of width $w whose top edge is $y. Returns the height used.
     * $align: L, C, R. Explicit newlines start a new line. $maxLines truncates with an ellipsis.
     */
    public function textBox(float $x, float $y, float $w, string $text, string $align = 'L', ?float $lineHeight = null, ?int $maxLines = null): float
    {
        $lineHeight ??= $this->fontSize * 0.3528 * 1.25;
        $lines = $this->wrap($text, $w);
        if ($maxLines !== null && count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $last = rtrim($lines[$maxLines - 1]);
            while ($last !== '' && $this->textWidth($last . '...') > $w) {
                $last = mb_substr($last, 0, -1);
            }
            $lines[$maxLines - 1] = $last . '...';
        }
        $ascent = $this->fontSize * 0.3528 * 0.8;
        foreach ($lines as $i => $line) {
            $lw = $this->textWidth($line);
            $lx = match (strtoupper($align)) { 'C' => $x + ($w - $lw) / 2, 'R' => $x + $w - $lw, default => $x };
            $this->text($lx, $y + $ascent + $i * $lineHeight, $line);
        }
        return count($lines) * $lineHeight;
    }

    /** @return string[] lines that fit in $w with the current font */
    public function wrap(string $text, float $w): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $paragraph) {
            $words = preg_split('/\s+/', trim($paragraph)) ?: [];
            $line = '';
            foreach ($words as $word) {
                if ($word === '') {
                    continue;
                }
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($this->textWidth($try) <= $w || $line === '') {
                    $line = $try;
                    // A single word longer than the box is cut into pieces.
                    while ($this->textWidth($line) > $w && mb_strlen($line) > 1) {
                        $cut = mb_strlen($line);
                        while ($cut > 1 && $this->textWidth(mb_substr($line, 0, $cut)) > $w) {
                            $cut--;
                        }
                        $out[] = mb_substr($line, 0, $cut);
                        $line = mb_substr($line, $cut);
                    }
                } else {
                    $out[] = $line;
                    $line = $word;
                }
            }
            $out[] = $line;
        }
        return $out;
    }

    /**
     * Draw an image from a file path or binary string. Height 0 keeps the aspect ratio; when both
     * are given the image is fitted inside the box (aspect ratio kept, centred).
     * Returns false when the image cannot be decoded.
     */
    public function image(string $source, float $x, float $y, float $w, float $h = 0): bool
    {
        $info = $this->registerImage($source);
        if ($info === null) {
            return false;
        }
        $ratio = $info['h'] / max(1, $info['w']);
        if ($h <= 0) {
            $h = $w * $ratio;
        } else {
            $boxRatio = $h / $w;
            if ($ratio > $boxRatio) {
                $nw = $h / $ratio;
                $x += ($w - $nw) / 2;
                $w = $nw;
            } else {
                $nh = $w * $ratio;
                $y += ($h - $nh) / 2;
                $h = $nh;
            }
        }
        $this->out(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /I%d Do Q', $w * self::K, $h * self::K, $x * self::K, ($this->pageH() - $y - $h) * self::K, $info['n']));
        return true;
    }

    // ------------------------------------------------------------------ output

    public function output(): string
    {
        if (!$this->pages) {
            $this->addPage();
        }
        $objects = []; // object number => body
        $next = 3;     // 1 = catalog, 2 = page tree
        $fontObj = [];
        foreach ($this->fonts as $key => $n) {
            $fontObj[$n] = $next;
            $objects[$next++] = '<< /Type /Font /Subtype /Type1 /BaseFont /' . self::BASE_FONTS[$key] . ' /Encoding /WinAnsiEncoding >>';
        }
        $imageObj = [];
        foreach ($this->images as $img) {
            $imageObj[$img['n']] = $next;
            $dict = sprintf('<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent %d /Filter /DCTDecode%s /Length %d >>',
                $img['w'], $img['h'], $img['cs'], $img['bpc'], $img['cs'] === 'DeviceCMYK' ? ' /Decode [1 0 1 0 1 0 1 0]' : '', strlen($img['data']));
            $objects[$next++] = $dict . "\nstream\n" . $img['data'] . "\nendstream";
        }
        $resources = '<< /ProcSet [/PDF /Text /ImageB /ImageC] /Font <<';
        foreach ($fontObj as $n => $obj) {
            $resources .= ' /F' . $n . ' ' . $obj . ' 0 R';
        }
        $resources .= ' >> /XObject <<';
        foreach ($imageObj as $n => $obj) {
            $resources .= ' /I' . $n . ' ' . $obj . ' 0 R';
        }
        $resources .= ' >> >>';
        $resObj = $next++;
        $objects[$resObj] = $resources;

        $kids = [];
        foreach ($this->pages as $p) {
            $content = $p['content'];
            $filter = '';
            if (function_exists('gzcompress')) {
                $content = (string) gzcompress($content);
                $filter = ' /Filter /FlateDecode';
            }
            $contentObj = $next++;
            $objects[$contentObj] = '<< /Length ' . strlen($content) . $filter . " >>\nstream\n" . $content . "\nendstream";
            $pageObj = $next++;
            $objects[$pageObj] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources %d 0 R /Contents %d 0 R >>',
                $p['w'] * self::K, $p['h'] * self::K, $resObj, $contentObj);
            $kids[] = $pageObj . ' 0 R';
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $infoObj = $next++;
        $objects[$infoObj] = '<< /Producer (Fameweb NGO Cloud)' . ($this->title !== '' ? ' /Title (' . self::escape(self::encode($this->title)) . ')' : '')
            . ' /CreationDate (D:' . date('YmdHis') . ') >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= $n . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 " . $count . "\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . $count . ' /Root 1 0 R /Info ' . $infoObj . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }

    // ------------------------------------------------------------------ internals

    private function out(string $op): void
    {
        if ($this->page < 0) {
            $this->addPage();
        }
        $this->pages[$this->page]['content'] .= $op . "\n";
    }

    private function pageH(): float
    {
        return $this->pages[$this->page]['h'] ?? $this->defaultH;
    }

    /** @return array{0:string,1:string} */
    private function splitFontKey(): array
    {
        preg_match('/^([a-z]+)([BI]*)$/', $this->fontKey, $m);
        return [$m[1] ?? 'helvetica', $m[2] ?? ''];
    }

    private function encodedWidth(string $enc): float
    {
        $widths = FontMetrics::widths($this->fontKey);
        $default = FontMetrics::defaultWidth($this->fontKey);
        $total = 0;
        $len = strlen($enc);
        for ($i = 0; $i < $len; $i++) {
            $total += $widths[ord($enc[$i])] ?? $default;
        }
        return $total * $this->fontSize / 1000 / self::K;
    }

    /** UTF-8 to Windows-1252 (the encoding of the standard PDF fonts). */
    public static function encode(string $text): string
    {
        $text = str_replace(["\u{20B9}", "\u{2009}", "\u{00A0}"], ['Rs.', ' ', ' '], $text);
        $text = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $text) ?? '';
        if (function_exists('iconv')) {
            $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
            if ($out !== false) {
                return $out;
            }
        }
        return (string) mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }

    private static function escape(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $s);
    }

    private static function rgb(string $hex): string
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '000000';
        }
        return sprintf('%.3F %.3F %.3F', hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255);
    }

    private function registerImage(string $source): ?array
    {
        $data = (strlen($source) < 4096 && !str_contains($source, "\0") && is_file($source)) ? (string) file_get_contents($source) : $source;
        if ($data === '') {
            return null;
        }
        $key = sha1($data);
        if (isset($this->images[$key])) {
            return $this->images[$key];
        }
        $info = @getimagesizefromstring($data);
        if ($info === false) {
            return null;
        }
        if ($info[2] !== IMAGETYPE_JPEG) {
            $data = self::toJpeg($data);
            if ($data === null) {
                return null;
            }
            $info = @getimagesizefromstring($data);
            if ($info === false) {
                return null;
            }
        }
        $channels = (int) ($info['channels'] ?? 3);
        $cs = match ($channels) { 1 => 'DeviceGray', 4 => 'DeviceCMYK', default => 'DeviceRGB' };
        return $this->images[$key] = [
            'n' => count($this->images) + 1, 'w' => (int) $info[0], 'h' => (int) $info[1],
            'cs' => $cs, 'bpc' => (int) ($info['bits'] ?? 8), 'data' => $data,
        ];
    }

    /** Convert PNG / WebP / GIF to JPEG on a white background (needs the GD extension). */
    private static function toJpeg(string $data): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $src = @imagecreatefromstring($data);
        if ($src === false) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $dst = imagecreatetruecolor($w, $h);
        imagefill($dst, 0, 0, (int) imagecolorallocate($dst, 255, 255, 255));
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
        ob_start();
        imagejpeg($dst, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        return $jpeg === '' ? null : $jpeg;
    }
}
