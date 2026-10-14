<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Exceptions\DomainException;

/** NGO-defined categories for members, volunteers and interns (e.g. Life member, Field volunteer). */
final class CategoryService
{
    public const APPLIES = ['all' => 'All types', 'member' => 'Members', 'volunteer' => 'Volunteers', 'intern' => 'Interns'];

    public static function listForTenant(int $tenantId, ?string $type = null): array
    {
        $sql = 'SELECT c.*, (SELECT COUNT(*) FROM people p WHERE p.category_id = c.id) AS people_count
                  FROM person_categories c WHERE c.tenant_id = ?';
        $params = [$tenantId];
        if ($type !== null) {
            $sql .= " AND c.applies_to IN ('all', ?)";
            $params[] = $type;
        }
        return Database::instance()->fetchAll($sql . ' ORDER BY c.name', $params);
    }

    public static function find(int $tenantId, int $id): ?array
    {
        return Database::instance()->fetch('SELECT * FROM person_categories WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
    }

    public static function save(array $actor, ?array $category, array $data): int
    {
        $tenantId = (int) $actor['tenant_id'];
        $name = trim($data['name'] ?? '');
        $applies = $data['applies_to'] ?? 'all';
        if ($name === '' || mb_strlen($name) > 80) {
            throw new DomainException('Category name is required (up to 80 characters).');
        }
        if (!isset(self::APPLIES[$applies])) {
            throw new DomainException('Please choose who the category applies to.');
        }
        $db = Database::instance();
        $dup = $db->fetchColumn('SELECT id FROM person_categories WHERE tenant_id = ? AND name = ? AND id <> ?', [$tenantId, $name, (int) ($category['id'] ?? 0)]);
        if ($dup) {
            throw new DomainException('A category with this name already exists.');
        }
        $row = ['name' => $name, 'applies_to' => $applies, 'description' => trim($data['description'] ?? '') ?: null];
        if ($category === null) {
            $id = $db->insert('person_categories', $row + ['tenant_id' => $tenantId, 'created_by' => (int) $actor['id']]);
            AuditLogger::log('category.created', 'person_category', $id, ['name' => $name], $tenantId, $actor);
            return $id;
        }
        $db->update('person_categories', $row, ['id' => (int) $category['id'], 'tenant_id' => $tenantId]);
        AuditLogger::log('category.updated', 'person_category', $category['id'], ['name' => $name], $tenantId, $actor);
        return (int) $category['id'];
    }

    public static function delete(array $actor, array $category): void
    {
        $tenantId = (int) $actor['tenant_id'];
        // People keep their records; their category is cleared (FK ON DELETE SET NULL).
        Database::instance()->execute('DELETE FROM person_categories WHERE id = ? AND tenant_id = ?', [(int) $category['id'], $tenantId]);
        AuditLogger::log('category.deleted', 'person_category', $category['id'], ['name' => $category['name']], $tenantId, $actor);
    }
}
