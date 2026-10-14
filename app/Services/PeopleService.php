<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Exceptions\DomainException;
use App\Support\Aadhaar;

/**
 * Members, volunteers and interns ("people"). Each type is its own plan module and has its own
 * member ID series per NGO (MEM-00001, VOL-00001, INT-00001; prefixes editable in Organization
 * settings). Pending and active people count against the plan's member limit; inactive and
 * completed records are kept but do not count.
 *
 * Aadhaar: the full number is only checked (format + Verhoeff checksum) and never stored, logged
 * or kept in the session; only the last 4 digits are saved.
 */
final class PeopleService
{
    public const TYPES = [
        'members'    => ['type' => 'member',    'module' => 'members',    'label' => 'Members',    'singular' => 'Member',    'prefix' => 'MEM', 'icon' => 'person-vcard'],
        'volunteers' => ['type' => 'volunteer', 'module' => 'volunteers', 'label' => 'Volunteers', 'singular' => 'Volunteer', 'prefix' => 'VOL', 'icon' => 'hand-thumbs-up'],
        'interns'    => ['type' => 'intern',    'module' => 'interns',    'label' => 'Interns',    'singular' => 'Intern',    'prefix' => 'INT', 'icon' => 'mortarboard'],
    ];

    public const STATUSES = [
        'pending' => 'Pending approval', 'active' => 'Active', 'inactive' => 'Inactive', 'completed' => 'Completed / alumni',
    ];
    /** Statuses that count against the plan's member limit. */
    public const COUNTED = ['pending', 'active'];

    public const GENDERS = ['female' => 'Female', 'male' => 'Male', 'other' => 'Other', 'undisclosed' => 'Prefer not to say'];
    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    /** Editable profile columns. */
    public const FIELDS = ['name', 'guardian_name', 'gender', 'date_of_birth', 'blood_group', 'email', 'phone', 'address', 'city',
        'state', 'pincode', 'emergency_contact', 'category_id', 'designation', 'department', 'joined_on', 'ends_on',
        'monthly_amount', 'bank_name', 'bank_ifsc', 'notes'];

    public static function typeBySlug(string $slug): ?array
    {
        return isset(self::TYPES[$slug]) ? self::TYPES[$slug] + ['slug' => $slug] : null;
    }

    public static function slugForType(string $type): string
    {
        foreach (self::TYPES as $slug => $t) {
            if ($t['type'] === $type) {
                return $slug;
            }
        }
        return 'members';
    }

    /** Types whose module is on the NGO's plan. */
    public static function enabledTypes(int $tenantId): array
    {
        $modules = SubscriptionService::enabledModules($tenantId);
        return array_filter(self::TYPES, static fn ($t) => in_array($t['module'], $modules, true));
    }

    public static function find(int $tenantId, int $id): ?array
    {
        return Database::instance()->fetch(
            'SELECT p.*, c.name AS category_name, so.uuid AS photo_uuid, vu.name AS verified_by_name
               FROM people p
               LEFT JOIN person_categories c ON c.id = p.category_id AND c.tenant_id = p.tenant_id
               LEFT JOIN storage_objects so ON so.id = p.photo_object_id AND so.deleted_at IS NULL
               LEFT JOIN users vu ON vu.id = p.verified_by
              WHERE p.id = ? AND p.tenant_id = ?',
            [$id, $tenantId]
        );
    }

    public static function prefix(int $tenantId, string $type): string
    {
        $slug = self::slugForType($type);
        $custom = TenantService::settings($tenantId)[$type . '_id_prefix'] ?? '';
        return $custom !== '' ? $custom : self::TYPES[$slug]['prefix'];
    }

    /**
     * Register a person. $data uses FIELDS keys plus status, bank_account and aadhaar_number
     * (both optional; only their last 4 digits are kept). Returns the new id.
     */
    public static function create(array $actor, string $type, array $data): int
    {
        $tenantId = (int) $actor['tenant_id'];
        $row = self::clean($tenantId, $type, $data);
        $status = $data['status'] ?? 'active';
        if (!isset(self::STATUSES[$status])) {
            throw new DomainException('Please choose a valid status.');
        }
        $db = Database::instance();
        $id = $db->transaction(function (Database $db) use ($tenantId, $type, $row, $status, $actor) {
            // Serialise registrations per NGO so parallel requests cannot both pass the limit.
            $db->fetch('SELECT id FROM tenants WHERE id = ? FOR UPDATE', [$tenantId]);
            if (in_array($status, self::COUNTED, true)) {
                UsageService::assertCanAdd($tenantId, 'members', 1);
            }
            $code = self::prefix($tenantId, $type) . '-' . sprintf('%05d', SequenceService::next($tenantId, $type));
            $id = $db->insert('people', $row + [
                'tenant_id' => $tenantId,
                'person_type' => $type,
                'member_code' => $code,
                'status' => $status,
                'joined_on' => $row['joined_on'] ?? date('Y-m-d'),
                'created_by' => (int) $actor['id'],
            ]);
            self::history($tenantId, $id, 'registered', ucfirst($type) . ' registered with ID ' . $code . ' (' . self::STATUSES[$status] . ')', (int) $actor['id']);
            AuditLogger::log('person.created', 'person', $id, ['type' => $type, 'member_code' => $code, 'status' => $status], $tenantId, $actor);
            return $id;
        });

        $person = self::find($tenantId, $id);
        if ($person && $person['email'] && MailSettings::automationOn($tenantId, 'auto_member_welcome')) {
            $tenant = $db->fetch('SELECT name FROM tenants WHERE id = ?', [$tenantId]);
            $ngo = TenantService::settings($tenantId)['display_name'] ?: $tenant['name'];
            Mailer::send($person['email'], 'Welcome to ' . $ngo, sprintf(
                "Dear %s,\n\nYou are now registered with %s as a %s.\n\nYour %s ID is: %s\n\nPlease keep it for future reference.\n\nRegards,\n%s\n",
                $person['name'], $ngo, $type, $type, $person['member_code'], $ngo
            ), $tenantId, 'person-welcome:' . $id, ['category' => 'member_welcome', 'to_name' => $person['name']]);
        }
        return $id;
    }

    public static function update(array $actor, array $person, array $data): void
    {
        $tenantId = (int) $actor['tenant_id'];
        $row = self::clean($tenantId, $person['person_type'], $data);
        $changed = [];
        foreach ($row as $k => $v) {
            if ((string) ($person[$k] ?? '') !== (string) ($v ?? '')) {
                $changed[] = $k;
            }
        }
        if (!$changed) {
            return;
        }
        if (in_array('aadhaar_last4', $changed, true) && $person['verification_status'] === 'verified') {
            // A different Aadhaar number needs a fresh check.
            $row['verification_status'] = 'pending';
        }
        $db = Database::instance();
        $db->transaction(function (Database $db) use ($row, $person, $tenantId, $changed, $actor): void {
            $db->update('people', $row, ['id' => (int) $person['id'], 'tenant_id' => $tenantId]);
            $labels = array_map(static fn ($f) => str_replace(['_id', '_'], ['', ' '], $f), $changed);
            self::history($tenantId, (int) $person['id'], 'updated', 'Details updated: ' . implode(', ', $labels), (int) $actor['id']);
            AuditLogger::log('person.updated', 'person', $person['id'], ['fields' => $changed], $tenantId, $actor);
        });
    }

    public static function setStatus(array $actor, array $person, string $status, string $note = ''): void
    {
        if (!isset(self::STATUSES[$status])) {
            throw new DomainException('Please choose a valid status.');
        }
        if ($person['status'] === $status) {
            throw new DomainException('The status is already ' . strtolower(self::STATUSES[$status]) . '.');
        }
        $tenantId = (int) $actor['tenant_id'];
        Database::instance()->transaction(function (Database $db) use ($actor, $person, $status, $note, $tenantId): void {
            $db->fetch('SELECT id FROM tenants WHERE id = ? FOR UPDATE', [$tenantId]);
            if (in_array($status, self::COUNTED, true) && !in_array($person['status'], self::COUNTED, true)) {
                UsageService::assertCanAdd($tenantId, 'members', 1);
            }
            $update = ['status' => $status];
            if (in_array($status, ['inactive', 'completed'], true) && !$person['ends_on']) {
                $update['ends_on'] = date('Y-m-d');
            }
            $db->update('people', $update, ['id' => (int) $person['id'], 'tenant_id' => $tenantId]);
            self::history($tenantId, (int) $person['id'], 'status_changed',
                'Status changed from ' . self::STATUSES[$person['status']] . ' to ' . self::STATUSES[$status] . ($note !== '' ? ': ' . mb_substr($note, 0, 150) : ''),
                (int) $actor['id']);
            AuditLogger::log('person.status_changed', 'person', $person['id'], ['from' => $person['status'], 'to' => $status, 'note' => $note], $tenantId, $actor);
        });
    }

    /** Record the outcome of an identity check. Requires an uploaded Aadhaar document. */
    public static function verify(array $actor, array $person, string $decision, string $note): void
    {
        if (!in_array($decision, ['verified', 'rejected'], true)) {
            throw new DomainException('Choose verified or rejected.');
        }
        if ($decision === 'rejected' && trim($note) === '') {
            throw new DomainException('Please give a reason for the rejection so the member knows what to fix.');
        }
        $tenantId = (int) $actor['tenant_id'];
        $db = Database::instance();
        $hasDoc = (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM person_documents WHERE tenant_id = ? AND person_id = ? AND doc_type = 'aadhaar' AND deleted_at IS NULL",
            [$tenantId, (int) $person['id']]
        );
        if ($hasDoc === 0) {
            throw new DomainException('Upload the Aadhaar document before recording a verification.');
        }
        if ($decision === 'verified' && !$person['aadhaar_last4']) {
            throw new DomainException('Enter the Aadhaar number (only the last 4 digits are kept) before marking it verified.');
        }
        $db->transaction(function (Database $db) use ($actor, $person, $decision, $note, $tenantId): void {
            $db->update('people', [
                'verification_status' => $decision,
                'verification_note' => $note !== '' ? mb_substr($note, 0, 255) : null,
                'verified_by' => (int) $actor['id'],
                'verified_at' => date('Y-m-d H:i:s'),
            ], ['id' => (int) $person['id'], 'tenant_id' => $tenantId]);
            self::history($tenantId, (int) $person['id'], 'verification',
                'Aadhaar ' . ($decision === 'verified' ? 'verified' : 'rejected') . ($note !== '' ? ': ' . mb_substr($note, 0, 150) : ''), (int) $actor['id']);
            AuditLogger::log('person.verification', 'person', $person['id'], ['decision' => $decision], $tenantId, $actor);
        });
    }

    public static function setPhoto(array $actor, array $person, array $file): void
    {
        $tenantId = (int) $actor['tenant_id'];
        $db = Database::instance();
        $tenant = $db->fetch('SELECT * FROM tenants WHERE id = ?', [$tenantId]);
        $stored = StorageService::storeUpload($tenant, $file, 'photo', (array) config('security.uploads.logo_mimes'),
            (int) config('security.uploads.max_logo_bytes'), (int) $actor['id'], \App\Support\Crypto::available());
        $db->update('people', ['photo_object_id' => $stored['id']], ['id' => (int) $person['id'], 'tenant_id' => $tenantId]);
        if ($person['photo_object_id']) {
            StorageService::delete((int) $person['photo_object_id'], $tenantId, (int) $actor['id']);
        }
        self::history($tenantId, (int) $person['id'], 'photo', 'Photo updated', (int) $actor['id']);
    }

    public static function history(int $tenantId, int $personId, string $event, string $summary, ?int $actorId): void
    {
        Database::instance()->insert('person_history', [
            'tenant_id' => $tenantId,
            'person_id' => $personId,
            'event' => $event,
            'summary' => mb_substr($summary, 0, 255),
            'actor_user_id' => $actorId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function historyFor(int $tenantId, int $personId, int $limit = 100): array
    {
        return Database::instance()->fetchAll(
            'SELECT h.*, u.name AS actor_name FROM person_history h LEFT JOIN users u ON u.id = h.actor_user_id
              WHERE h.tenant_id = ? AND h.person_id = ? ORDER BY h.id DESC LIMIT ' . max(1, min(500, $limit)),
            [$tenantId, $personId]
        );
    }

    /** Validate and normalise profile input. Throws DomainException with a field-level message. */
    private static function clean(int $tenantId, string $type, array $data): array
    {
        $row = [];
        foreach (self::FIELDS as $f) {
            if (!array_key_exists($f, $data)) {
                continue;
            }
            $v = is_string($data[$f]) ? trim($data[$f]) : $data[$f];
            $row[$f] = ($v === '' || $v === null) ? null : $v;
        }
        if (array_key_exists('name', $row) && $row['name'] === null) {
            throw new DomainException('Name is required.');
        }
        if (isset($row['email'])) {
            $row['email'] = mb_strtolower($row['email']);
        }
        if (isset($row['category_id'])) {
            $cat = Database::instance()->fetch('SELECT * FROM person_categories WHERE id = ? AND tenant_id = ?', [(int) $row['category_id'], $tenantId]);
            if (!$cat || !in_array($cat['applies_to'], ['all', $type], true)) {
                throw new DomainException('Please choose a valid category.');
            }
            $row['category_id'] = (int) $row['category_id'];
        }
        if (isset($row['bank_ifsc'])) {
            $row['bank_ifsc'] = strtoupper($row['bank_ifsc']);
        }
        if (isset($row['monthly_amount'])) {
            $row['monthly_amount'] = round((float) $row['monthly_amount'], 2);
        }
        if (array_key_exists('bank_account', $data)) {
            $acct = preg_replace('/\D+/', '', (string) $data['bank_account']);
            if ($acct !== '') {
                if (strlen($acct) < 6 || strlen($acct) > 20) {
                    throw new DomainException('The bank account number must have 6 to 20 digits.');
                }
                $row['bank_account_last4'] = substr($acct, -4);
            }
        }
        if (array_key_exists('aadhaar_number', $data) && trim((string) $data['aadhaar_number']) !== '') {
            if (!Aadhaar::isValid((string) $data['aadhaar_number'])) {
                throw new DomainException('The Aadhaar number is not valid. Check the 12 digits and try again.');
            }
            $row['aadhaar_last4'] = Aadhaar::last4((string) $data['aadhaar_number']);
        }
        if (isset($row['joined_on'], $row['ends_on']) && $row['ends_on'] < $row['joined_on']) {
            throw new DomainException('The end date cannot be before the joining date.');
        }
        return $row;
    }
}
