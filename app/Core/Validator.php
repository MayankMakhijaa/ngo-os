<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Server-side validation. Rules: required, email, url, max:n, min:n, numeric, integer,
 * min_value:n, max_value:n, date, in:a,b,c, phone, confirmed, regex:/.../
 */
final class Validator
{
    public array $errors = [];

    public function __construct(private array $data, private array $rules, private array $labels = [])
    {
    }

    public static function make(array $data, array $rules, array $labels = []): Validator
    {
        $v = new Validator($data, $rules, $labels);
        $v->run();
        return $v;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $value = $this->data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $label = $this->labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
            $empty = $value === null || $value === '' || $value === [];

            foreach ($rules as $rule) {
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
                if ($name !== 'required' && $empty) {
                    continue; // optional and empty: skip other checks
                }
                $error = match ($name) {
                    'required'  => $empty ? "$label is required." : null,
                    'email'     => filter_var($value, FILTER_VALIDATE_EMAIL) && mb_strlen((string) $value) <= 190 ? null : "$label must be a valid email address.",
                    'url'       => (filter_var($value, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', (string) $value)) ? null : "$label must be a valid http(s) URL.",
                    'max'       => mb_strlen((string) $value) <= (int) $param ? null : "$label may not be longer than $param characters.",
                    'min'       => mb_strlen((string) $value) >= (int) $param ? null : "$label must be at least $param characters.",
                    'numeric'   => is_numeric($value) ? null : "$label must be a number.",
                    'integer'   => (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value))) ? null : "$label must be a whole number.",
                    'min_value' => (is_numeric($value) && (float) $value >= (float) $param) ? null : "$label must be at least $param.",
                    'max_value' => (is_numeric($value) && (float) $value <= (float) $param) ? null : "$label may not be greater than $param.",
                    'date'      => self::isDate((string) $value) ? null : "$label must be a valid date (YYYY-MM-DD).",
                    'in'        => in_array((string) $value, explode(',', (string) $param), true) ? null : "$label has an invalid value.",
                    'phone'     => preg_match('/^\+?[0-9 ()\-]{7,20}$/', (string) $value) ? null : "$label must be a valid phone number.",
                    'confirmed' => ($value === ($this->data[$field . '_confirmation'] ?? null)) ? null : "$label confirmation does not match.",
                    'regex'     => preg_match((string) $param, (string) $value) ? null : "$label has an invalid format.",
                    default     => throw new \LogicException("Unknown validation rule $name"),
                };
                if ($error !== null) {
                    $this->errors[$field] = $error;
                    break;
                }
            }
        }
    }

    public static function isDate(string $value): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field] = $message;
    }
}
