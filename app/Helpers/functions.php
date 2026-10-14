<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Session;
use App\Services\PermissionService;

/** Read an environment variable (from .env or the real environment). */
function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}

function env_bool(string $key, bool $default = false): bool
{
    $v = Env::get($key);
    if ($v === null || $v === '') {
        return $default;
    }
    return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Absolute path inside the writable storage directory. Defaults to <app>/storage;
 * STORAGE_PATH overrides it (used by the automated tests to keep their files apart).
 */
function storage_path(string $sub = ''): string
{
    $root = rtrim((string) (env('STORAGE_PATH') ?: BASE_PATH . '/storage'), '/');
    return $sub === '' ? $root : $root . '/' . ltrim($sub, '/');
}

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

/** HTML-escape output. Every dynamic value printed in a view goes through this. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Base path of the app below the domain, e.g. "" or "/ngo". */
function base_path_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $configured = (string) config('app.url', '');
    if ($configured !== '') {
        $base = rtrim((string) (parse_url($configured, PHP_URL_PATH) ?? ''), '/');
    } else {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $dir = rtrim(dirname($script), '/');
        // When the project root .htaccess forwards into /public, hide that segment.
        if (str_ends_with($dir, '/public') && !str_contains($_SERVER['REQUEST_URI'] ?? '', '/public/')) {
            $dir = substr($dir, 0, -7);
        }
        $base = ($dir === '.' || $dir === '/') ? '' : $dir;
    }
    return $base;
}

/** Build an application URL (relative to the domain root, safe for subfolders). */
function url(string $path = '', array $query = []): string
{
    $u = base_path_url() . '/' . ltrim($path, '/');
    if ($query) {
        $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');
        if ($query) {
            $u .= '?' . http_build_query($query);
        }
    }
    return $u === '' ? '/' : $u;
}

/** Absolute URL, used in emails and invitation links. */
function absolute_url(string $path = ''): string
{
    $configured = (string) config('app.url', '');
    if ($configured !== '') {
        $origin = preg_replace('#^(https?://[^/]+).*$#', '$1', $configured);
    } else {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host);
        $origin = ($https ? 'https' : 'http') . '://' . $host;
    }
    return $origin . url($path);
}

function asset(string $path): string
{
    $file = BASE_PATH . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? substr((string) filemtime($file), -6) : '1';
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function csrf_token(): string
{
    return Csrf::token();
}

/** Previously submitted value after a validation error. */
function old(string $key, mixed $default = ''): mixed
{
    $old = Session::getFlash('_old', null);
    if (is_array($old) && array_key_exists($key, $old)) {
        return $old[$key];
    }
    return $default;
}

/** Field validation error after a redirect back. */
function field_error(string $key): ?string
{
    $errors = Session::getFlash('_errors', null);
    return is_array($errors) && isset($errors[$key]) ? (string) $errors[$key] : null;
}

function invalid_class(string $key): string
{
    return field_error($key) ? ' is-invalid' : '';
}

/** Does the current user hold a permission? (UI convenience; routes enforce it server-side too.) */
function can(string $permission): bool
{
    $user = Auth::user();
    return $user !== null && PermissionService::instance()->userHas($user, $permission);
}

function auth_user(): ?array
{
    return Auth::user();
}

function format_bytes(int|float|null $bytes, int $precision = 1): string
{
    $bytes = (float) ($bytes ?? 0);
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, $i === 0 ? 0 : $precision) . ' ' . $units[$i];
}

function format_money(int|float|string|null $amount, string $currency = 'INR'): string
{
    $symbols = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'];
    $sym = $symbols[$currency] ?? ($currency . ' ');
    return $sym . number_format((float) ($amount ?? 0), 2);
}

function format_date(?string $value, string $format = 'd M Y'): string
{
    if ($value === null || $value === '' || str_starts_with($value, '0000')) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date($format, $ts) : '—';
}

function format_datetime(?string $value): string
{
    return format_date($value, 'd M Y, H:i');
}

function time_ago(?string $value): string
{
    if (!$value) {
        return 'never';
    }
    $diff = time() - strtotime($value);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' h ago';
    if ($diff < 86400 * 30) return floor($diff / 86400) . ' d ago';
    return format_date($value);
}

/** Bootstrap colour for a status badge. */
function status_badge(string $status): string
{
    $map = [
        'active' => 'success', 'trial' => 'info', 'past_due' => 'warning', 'expired' => 'secondary',
        'suspended' => 'danger', 'cancelled' => 'dark', 'deactivated' => 'dark', 'inactive' => 'secondary',
        'invited' => 'warning', 'paid' => 'success', 'pending' => 'warning', 'failed' => 'danger',
        'refunded' => 'secondary', 'none' => 'secondary',
    ];
    $class = $map[$status] ?? 'secondary';
    $label = ucwords(str_replace('_', ' ', $status));
    return '<span class="badge rounded-pill text-bg-' . $class . '">' . e($label) . '</span>';
}

function humanize(string $value): string
{
    return ucwords(str_replace(['_', '.'], ' ', $value));
}

function uuid4(): string
{
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function today(): string
{
    return date('Y-m-d');
}

require_once __DIR__ . '/ui.php';
