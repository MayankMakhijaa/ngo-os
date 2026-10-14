<?php
declare(strict_types=1);

namespace App\Core;

/** Plain-PHP templates in app/Views. Templates escape output with e(). */
final class View
{
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $template, array $data = [], ?string $layout = null): string
    {
        $content = self::partial($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::partial($layout, array_merge($data, ['content' => $content]));
    }

    public static function partial(string $template, array $data = []): string
    {
        $file = BASE_PATH . '/app/Views/' . $template . '.php';
        if (!preg_match('#^[a-z0-9_/\-]+$#i', $template) || !is_file($file)) {
            throw new \RuntimeException('View not found: ' . $template);
        }
        $data = array_merge(self::$shared, $data);
        return (static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return (string) ob_get_clean();
        })($file, $data);
    }
}
