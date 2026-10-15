<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Exceptions\DomainException;

/**
 * Identity and other documents of members, volunteers and interns. Files are encrypted at rest,
 * count against the NGO's storage limit and are only downloadable through the person's page by
 * users holding people.documents. Uploading an Aadhaar copy moves the person to "pending
 * verification" until someone with people.verify records the outcome.
 */
final class PersonDocumentService
{
    public const TYPES = [
        'aadhaar' => 'Aadhaar card', 'pan' => 'PAN card', 'address_proof' => 'Address proof', 'photo_id' => 'Other photo ID',
        'education' => 'Education certificate', 'agreement' => 'Agreement / consent form', 'other' => 'Other',
    ];
    public const MIMES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    public const MAX_BYTES = 5 * 1024 * 1024;

    public static function listFor(int $tenantId, int $personId): array
    {
        return Database::instance()->fetchAll(
            'SELECT d.*, so.size_bytes, so.mime_type, so.original_name, u.name AS uploaded_by_name
               FROM person_documents d JOIN storage_objects so ON so.id = d.storage_object_id
               LEFT JOIN users u ON u.id = d.uploaded_by
              WHERE d.tenant_id = ? AND d.person_id = ? AND d.deleted_at IS NULL ORDER BY d.id DESC',
            [$tenantId, $personId]
        );
    }

    public static function find(int $tenantId, int $personId, int $docId): ?array
    {
        return Database::instance()->fetch(
            'SELECT d.*, so.uuid, so.stored_path, so.mime_type, so.original_name, so.size_bytes
               FROM person_documents d JOIN storage_objects so ON so.id = d.storage_object_id AND so.deleted_at IS NULL
              WHERE d.id = ? AND d.tenant_id = ? AND d.person_id = ? AND d.deleted_at IS NULL',
            [$docId, $tenantId, $personId]
        );
    }

    public static function upload(array $actor, array $person, array $file, string $docType, string $title = ''): int
    {
        if (!isset(self::TYPES[$docType])) {
            throw new DomainException('Please choose the document type.');
        }
        $tenantId = (int) $actor['tenant_id'];
        $db = Database::instance();
        $tenant = $db->fetch('SELECT * FROM tenants WHERE id = ?', [$tenantId]);
        $stored = StorageService::storeUpload($tenant, $file, 'document', self::MIMES, self::MAX_BYTES, (int) $actor['id'], true);
        try {
            return $db->transaction(function (Database $db) use ($tenantId, $person, $docType, $title, $stored, $actor) {
                $id = $db->insert('person_documents', [
                    'tenant_id' => $tenantId,
                    'person_id' => (int) $person['id'],
                    'doc_type' => $docType,
                    'title' => $title !== '' ? mb_substr($title, 0, 120) : null,
                    'storage_object_id' => $stored['id'],
                    'uploaded_by' => (int) $actor['id'],
                ]);
                if ($docType === 'aadhaar') {
                    $db->update('people', ['verification_status' => 'pending', 'verification_note' => null, 'verified_by' => null, 'verified_at' => null],
                        ['id' => (int) $person['id'], 'tenant_id' => $tenantId]);
                }
                PeopleService::history($tenantId, (int) $person['id'], 'document_uploaded',
                    self::TYPES[$docType] . ' uploaded' . ($docType === 'aadhaar' ? ' (verification pending)' : ''), (int) $actor['id']);
                AuditLogger::log('person.document_uploaded', 'person', $person['id'], ['doc_type' => $docType, 'size' => $stored['size']], $tenantId, $actor);
                return $id;
            });
        } catch (\Throwable $e) {
            StorageService::delete((int) $stored['id'], $tenantId, (int) $actor['id']);
            throw $e;
        }
    }

    public static function delete(array $actor, array $person, array $doc): void
    {
        $tenantId = (int) $actor['tenant_id'];
        $db = Database::instance();
        $db->transaction(function (Database $db) use ($tenantId, $person, $doc, $actor): void {
            $db->execute('UPDATE person_documents SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND tenant_id = ?',
                [(int) $actor['id'], (int) $doc['id'], $tenantId]);
            StorageService::delete((int) $doc['storage_object_id'], $tenantId, (int) $actor['id']);
            if ($doc['doc_type'] === 'aadhaar') {
                $left = (int) $db->fetchColumn("SELECT COUNT(*) FROM person_documents WHERE tenant_id = ? AND person_id = ? AND doc_type = 'aadhaar' AND deleted_at IS NULL",
                    [$tenantId, (int) $person['id']]);
                if ($left === 0) {
                    $db->update('people', ['verification_status' => 'not_submitted'], ['id' => (int) $person['id'], 'tenant_id' => $tenantId]);
                }
            }
            PeopleService::history($tenantId, (int) $person['id'], 'document_deleted', self::TYPES[$doc['doc_type']] . ' deleted', (int) $actor['id']);
            AuditLogger::log('person.document_deleted', 'person', $person['id'], ['doc_type' => $doc['doc_type']], $tenantId, $actor);
        });
    }
}
