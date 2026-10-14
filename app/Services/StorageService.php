<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Response;
use App\Exceptions\DomainException;

/**
 * Private, tenant-partitioned file storage under storage/private/tenants/{tenant-uuid}/.
 * Files are never served directly by the web server: downloads go through a controller that
 * checks authentication, tenant ownership and permissions. Storage usage is computed from
 * storage_objects rows that are not deleted.
 */
final class StorageService
{
    public static function root(): string
    {
        return storage_path('private');
    }

    public static function ensureWorkspace(string $tenantUuid): string
    {
        $dir = self::root() . '/tenants/' . $tenantUuid;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create tenant workspace directory');
        }
        return $dir;
    }

    /**
     * Validate and store an uploaded file for a tenant.
     * @param array<string,string> $allowedMimes mime => extension
     */
    public static function storeUpload(array $tenant, array $file, string $category, array $allowedMimes, int $maxBytes, ?int $userId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new DomainException(match ($file['error'] ?? 0) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
                UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded. Please try again.',
                default => 'The file could not be uploaded.',
            });
        }
        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp) && !(PHP_SAPI === 'cli' && is_file($tmp))) {
            throw new DomainException('Invalid upload.');
        }
        $size = (int) filesize($tmp);
        if ($size <= 0 || $size > $maxBytes) {
            throw new DomainException('The file must be smaller than ' . format_bytes($maxBytes) . '.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        if (!isset($allowedMimes[$mime])) {
            throw new DomainException('This file type is not allowed. Allowed: ' . implode(', ', array_unique(array_values($allowedMimes))) . '.');
        }
        if (str_starts_with($mime, 'image/') && @getimagesize($tmp) === false) {
            throw new DomainException('The image file is not valid.');
        }
        UsageService::assertCanAdd((int) $tenant['id'], 'storage', $size);

        $uuid = uuid4();
        $relative = sprintf('tenants/%s/%s/%s/%s.%s', $tenant['uuid'], $category, date('Y/m'), $uuid, $allowedMimes[$mime]);
        $target = self::root() . '/' . $relative;
        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0750, true) && !is_dir(dirname($target))) {
            throw new \RuntimeException('Could not create storage directory');
        }
        $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $target) : copy($tmp, $target);
        if (!$moved) {
            throw new \RuntimeException('Could not store uploaded file');
        }
        @chmod($target, 0640);
        $original = preg_replace('/[^\w.\- ]+/u', '_', basename((string) ($file['name'] ?? 'file')));
        try {
            $id = Database::instance()->insert('storage_objects', [
                'uuid' => $uuid,
                'tenant_id' => (int) $tenant['id'],
                'category' => $category,
                'original_name' => mb_substr($original, 0, 255),
                'stored_path' => $relative,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'checksum_sha256' => hash_file('sha256', $target),
                'uploaded_by' => $userId,
            ]);
        } catch (\Throwable $e) {
            @unlink($target);
            throw $e;
        }
        AuditLogger::log('file.uploaded', 'storage_object', $id, ['category' => $category, 'size' => $size, 'mime' => $mime], (int) $tenant['id']);
        return ['id' => $id, 'uuid' => $uuid, 'size' => $size];
    }

    /** Soft-delete the metadata row and remove the physical file so storage usage drops. */
    public static function delete(int $objectId, int $tenantId, ?int $userId): void
    {
        $db = Database::instance();
        $obj = $db->fetch('SELECT * FROM storage_objects WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL', [$objectId, $tenantId]);
        if (!$obj) {
            return;
        }
        $db->execute('UPDATE storage_objects SET deleted_at = NOW(), deleted_by = ? WHERE id = ?', [$userId, $objectId]);
        $path = self::root() . '/' . $obj['stored_path'];
        if (is_file($path)) {
            @unlink($path);
        }
        AuditLogger::log('file.deleted', 'storage_object', $objectId, ['category' => $obj['category'], 'size' => (int) $obj['size_bytes']], $tenantId);
    }

    public static function stream(array $obj, bool $inline = true): Response
    {
        $path = realpath(self::root() . '/' . $obj['stored_path']);
        $root = realpath(self::root());
        if ($path === false || $root === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new \App\Core\HttpException(404, 'File not found.');
        }
        $name = preg_replace('/[^\w.\-]+/', '_', (string) $obj['original_name']);
        return Response::stream(static function () use ($path): void {
            readfile($path);
        }, [
            'Content-Type' => $obj['mime_type'],
            'Content-Length' => (string) filesize($path),
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
