<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper. All values are bound through prepared statements; identifiers passed to
 * insert()/update() come from application code only and are additionally validated.
 */
final class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;
    private int $txDepth = 0;

    private function __construct(array $cfg)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'], $cfg['port'], $cfg['database'], $cfg['charset'] ?? 'utf8mb4'
        );
        $this->pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        // Store timestamps in the app timezone consistently.
        $offset = (new \DateTime('now'))->format('P');
        $this->pdo->exec("SET time_zone = '" . $offset . "'");
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
    }

    public static function instance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new Database((array) config('database'));
        }
        return self::$instance;
    }

    /** Used by the installer to test credentials and by tests to reset the connection. */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchColumn(string $sql, array $params = []): mixed
    {
        $v = $this->query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    public function insert(string $table, array $data): int
    {
        self::assertIdentifier($table);
        $cols = array_keys($data);
        array_walk($cols, [self::class, 'assertIdentifier']);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn ($c) => "`$c`", $cols)),
            implode(', ', array_map(static fn ($c) => ':' . $c, $cols))
        );
        $this->query($sql, $data);
        return (int) $this->pdo->lastInsertId();
    }

    /** UPDATE with a mandatory WHERE built from equality conditions. */
    public function update(string $table, array $data, array $where): int
    {
        if (!$where) {
            throw new \InvalidArgumentException('update() requires a WHERE condition');
        }
        self::assertIdentifier($table);
        $params = [];
        $sets = [];
        foreach ($data as $col => $value) {
            self::assertIdentifier($col);
            $sets[] = "`$col` = :s_$col";
            $params["s_$col"] = $value;
        }
        $conds = [];
        foreach ($where as $col => $value) {
            self::assertIdentifier($col);
            if ($value === null) {
                $conds[] = "`$col` IS NULL";
            } else {
                $conds[] = "`$col` = :w_$col";
                $params["w_$col"] = $value;
            }
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), implode(' AND ', $conds));
        return $this->execute($sql, $params);
    }

    /**
     * Run a callback inside a transaction. Nested calls join the outer transaction.
     * Any exception rolls everything back and is re-thrown.
     */
    public function transaction(callable $callback): mixed
    {
        if ($this->txDepth === 0) {
            $this->pdo->beginTransaction();
        }
        $this->txDepth++;
        try {
            $result = $callback($this);
            $this->txDepth--;
            if ($this->txDepth === 0) {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            $this->txDepth--;
            if ($this->txDepth === 0 && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public static function isDuplicateKey(\Throwable $e): bool
    {
        return $e instanceof \PDOException && ($e->errorInfo[1] ?? null) === 1062;
    }

    private static function assertIdentifier(string $name): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException('Invalid SQL identifier');
        }
    }
}
