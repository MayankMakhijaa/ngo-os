<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class PasswordService
{
    private const COMMON = ['password123', 'password1234', '1234567890', 'qwerty12345', 'welcome1234', 'admin12345', 'letmein1234', 'iloveyou123'];

    /** @return string[] human readable problems; empty when the password is acceptable */
    public static function validate(string $password, string $email = '', ?string $confirmation = null): array
    {
        $errors = [];
        $min = (int) config('security.password.min_length', 10);
        if (mb_strlen($password) < $min) {
            $errors[] = "Password must be at least $min characters.";
        }
        if (strlen($password) > 72) {
            $errors[] = 'Password may not be longer than 72 bytes.';
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $errors[] = 'Password must contain at least one letter and one number.';
        }
        $local = strtolower((string) strstr($email, '@', true));
        if ($local !== '' && strlen($local) >= 4 && str_contains(strtolower($password), $local)) {
            $errors[] = 'Password must not contain your email name.';
        }
        if (in_array(strtolower($password), self::COMMON, true)) {
            $errors[] = 'This password is too common.';
        }
        if ($confirmation !== null && !hash_equals($password, $confirmation)) {
            $errors[] = 'Password confirmation does not match.';
        }
        return $errors;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Set a new password, activate an invited account, clear any "must change" flag and
     * invalidate every existing session of the user (session_version bump).
     */
    public static function setPassword(int $userId, string $password): void
    {
        Database::instance()->execute(
            "UPDATE users
                SET password_hash = ?, password_changed_at = NOW(), must_change_password = 0, session_version = session_version + 1,
                    status = IF(status = 'invited', 'active', status)
              WHERE id = ?",
            [self::hash($password), $userId]
        );
    }
}
