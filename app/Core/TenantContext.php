<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Holds the tenant (NGO) of the current request. It is set only by TenantMiddleware from the
 * authenticated user's database record, never from request input. Tenant-scoped models call
 * TenantContext::id(), which throws when no tenant is resolved, so a query can never run
 * "unscoped" by accident.
 */
final class TenantContext
{
    private static ?array $tenant = null;
    private static ?array $subscription = null;

    public static function set(array $tenant, ?array $subscription): void
    {
        self::$tenant = $tenant;
        self::$subscription = $subscription;
    }

    public static function clear(): void
    {
        self::$tenant = null;
        self::$subscription = null;
    }

    public static function has(): bool
    {
        return self::$tenant !== null;
    }

    public static function id(): int
    {
        if (self::$tenant === null) {
            throw new \LogicException('Tenant context is not set for this request.');
        }
        return (int) self::$tenant['id'];
    }

    public static function tenant(): array
    {
        if (self::$tenant === null) {
            throw new \LogicException('Tenant context is not set for this request.');
        }
        return self::$tenant;
    }

    public static function subscription(): ?array
    {
        return self::$subscription;
    }
}
