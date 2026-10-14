<?php
declare(strict_types=1);

namespace App\Core;

/** Offset pagination for list pages. */
final class Paginator
{
    public readonly int $page;
    public readonly int $perPage;
    public readonly int $total;
    public readonly array $items;

    public function __construct(string $selectSql, string $countSql, array $params, int $page, int $perPage = 20)
    {
        $db = Database::instance();
        $this->perPage = max(1, min(100, $perPage));
        $this->total = (int) $db->fetchColumn($countSql, $params);
        $last = max(1, (int) ceil($this->total / $this->perPage));
        $this->page = max(1, min($page, $last));
        $offset = ($this->page - 1) * $this->perPage;
        $this->items = $db->fetchAll($selectSql . ' LIMIT ' . $this->perPage . ' OFFSET ' . $offset, $params);
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function from(): int
    {
        return $this->total === 0 ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->page * $this->perPage);
    }
}
