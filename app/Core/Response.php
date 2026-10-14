<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** @var callable|null */
    private $streamer = null;

    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function redirect(string $path, int $status = 302): Response
    {
        $location = preg_match('#^https?://#', $path) ? $path : url($path);
        return new Response('', $status, ['Location' => $location]);
    }

    public static function json(array $data, int $status = 200): Response
    {
        return new Response(
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    /** Stream output (CSV exports, private file downloads) without buffering it in memory. */
    public static function stream(callable $streamer, array $headers): Response
    {
        $r = new Response('', 200, $headers);
        $r->streamer = $streamer;
        return $r;
    }

    public static function csv(string $filename, array $header, iterable $rows): Response
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        return self::stream(static function () use ($header, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($out, $header);
            foreach ($rows as $row) {
                // Neutralise spreadsheet formula injection.
                $row = array_map(static function ($v) {
                    $v = (string) ($v ?? '');
                    return ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) ? "'" . $v : $v;
                }, $row);
                fputcsv($out, $row);
            }
            fclose($out);
        }, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        if ($this->streamer) {
            ($this->streamer)();
            return;
        }
        echo $this->body;
    }
}
