<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Exceptions\DomainException;
use App\Pdf\Branding;
use App\Pdf\CertificatePdf;

/**
 * Certificates issued by an NGO to its members, volunteers and interns from a template that
 * Fameweb assigned to it. Each certificate has a number unique within the NGO, keeps a copy of
 * the layout and values used, counts against the plan's certificate allowance and can be
 * revoked (the record and number stay). PDFs are generated on download.
 */
final class CertificateService
{
    public static function find(int $tenantId, int $id): ?array
    {
        return Database::instance()->fetch(
            'SELECT c.*, p.name AS person_name, p.member_code, p.person_type, p.email AS person_email, t.name AS template_name, t.orientation
               FROM certificates c JOIN people p ON p.id = c.person_id
               LEFT JOIN certificate_templates t ON t.id = c.template_id
              WHERE c.id = ? AND c.tenant_id = ?',
            [$id, $tenantId]
        );
    }

    /** Placeholder values for a person (plus the fields entered when issuing). */
    public static function values(int $tenantId, array $person, array $extra): array
    {
        $ngo = Branding::forTenant($tenantId);
        $fmt = static fn (?string $d) => $d ? date('d M Y', strtotime($d)) : '';
        return [
            'name' => $person['name'],
            'member_id' => $person['member_code'],
            'person_type' => ucfirst($person['person_type']),
            'category' => $person['category_name'] ?? '',
            'designation' => $person['designation'] ?? '',
            'department' => $person['department'] ?? '',
            'joined_on' => $fmt($person['joined_on'] ?? null),
            'ends_on' => $fmt($person['ends_on'] ?? null),
            'ngo_name' => $ngo['name'],
            'ngo_address' => preg_replace('/\s+/', ' ', (string) $ngo['address']),
            'ngo_registration' => $ngo['registration_number'],
            'title' => $extra['title'] ?? '',
            'description' => $extra['description'] ?? '',
            'event' => $extra['event'] ?? '',
            'start_date' => $fmt($extra['start_date'] ?? null),
            'end_date' => $fmt($extra['end_date'] ?? null),
            'issue_date' => $fmt($extra['issued_on'] ?? date('Y-m-d')),
            'certificate_no' => $extra['certificate_no'] ?? '',
            'signatory_name' => $extra['signatory_name'] ?? '',
            'signatory_designation' => $extra['signatory_designation'] ?? '',
        ];
    }

    public static function issue(array $actor, array $person, array $template, array $data): int
    {
        $tenantId = (int) $actor['tenant_id'];
        $title = trim($data['title'] ?? '');
        if ($title === '' || mb_strlen($title) > 150) {
            throw new DomainException('Certificate title is required (up to 150 characters).');
        }
        $issuedOn = trim($data['issued_on'] ?? '') ?: date('Y-m-d');
        foreach (['issued_on' => $issuedOn, 'start_date' => $data['start_date'] ?? '', 'end_date' => $data['end_date'] ?? ''] as $k => $d) {
            if ($d !== '' && !\App\Core\Validator::isDate($d)) {
                throw new DomainException('Dates must be valid (YYYY-MM-DD).');
            }
        }
        if (!empty($data['start_date']) && !empty($data['end_date']) && $data['end_date'] < $data['start_date']) {
            throw new DomainException('The programme end date cannot be before its start date.');
        }
        if ($person['status'] === 'pending') {
            throw new DomainException('Approve this person before issuing a certificate.');
        }
        $extra = [
            'title' => $title,
            'description' => mb_substr(trim($data['description'] ?? ''), 0, 500),
            'event' => mb_substr(trim($data['event'] ?? ''), 0, 150),
            'start_date' => $data['start_date'] ?? '',
            'end_date' => $data['end_date'] ?? '',
            'issued_on' => $issuedOn,
            'signatory_name' => mb_substr(trim($data['signatory_name'] ?? ''), 0, 120),
            'signatory_designation' => mb_substr(trim($data['signatory_designation'] ?? ''), 0, 120),
        ];
        $db = Database::instance();
        $id = $db->transaction(function (Database $db) use ($tenantId, $person, $template, $extra, $issuedOn, $actor) {
            $db->fetch('SELECT id FROM tenants WHERE id = ? FOR UPDATE', [$tenantId]);
            UsageService::assertCanAdd($tenantId, 'certificates', 1);
            $no = sprintf('CERT-%s-%05d', substr($issuedOn, 0, 4), SequenceService::next($tenantId, 'certificate'));
            $values = self::values($tenantId, $person, $extra + ['certificate_no' => $no]);
            $id = $db->insert('certificates', [
                'tenant_id' => $tenantId,
                'person_id' => (int) $person['id'],
                'template_id' => (int) $template['id'],
                'certificate_no' => $no,
                'title' => $extra['title'],
                'layout_snapshot' => json_encode(['orientation' => $template['orientation'], 'layout' => json_decode($template['layout'], true)], JSON_UNESCAPED_UNICODE),
                'data_snapshot' => json_encode($values, JSON_UNESCAPED_UNICODE),
                'issued_on' => $issuedOn,
                'issued_by' => (int) $actor['id'],
            ]);
            UsageService::record($tenantId, 'certificates', 1, 'certificate:' . $id, 'certificate', $id, (int) $actor['id']);
            PeopleService::history($tenantId, (int) $person['id'], 'certificate_issued', 'Certificate ' . $no . ' issued: ' . $extra['title'], (int) $actor['id']);
            AuditLogger::log('certificate.issued', 'certificate', $id, ['certificate_no' => $no, 'person_id' => (int) $person['id'], 'template' => $template['name']], $tenantId, $actor);
            return $id;
        });
        if ($person['email'] && MailSettings::automationOn($tenantId, 'auto_certificate_issued')) {
            self::email($actor, self::find($tenantId, $id));
        }
        return $id;
    }

    public static function email(array $actor, array $cert): bool
    {
        if (!$cert['person_email']) {
            throw new DomainException('This person has no email address.');
        }
        if ($cert['status'] !== 'issued') {
            throw new DomainException('A revoked certificate cannot be emailed.');
        }
        $tenantId = (int) $cert['tenant_id'];
        $ngo = Branding::forTenant($tenantId);
        return Mailer::send($cert['person_email'], $cert['title'] . ' from ' . $ngo['display_name'], sprintf(
            "Dear %s,\n\nPlease find attached your certificate (%s, no. %s) from %s.\n\nWith best wishes,\n%s\n",
            $cert['person_name'], $cert['title'], $cert['certificate_no'], $ngo['display_name'], $ngo['display_name']
        ), $tenantId, 'certificate-email:' . $cert['id'] . ':' . time(), [
            'category' => 'certificate', 'to_name' => $cert['person_name'], 'attachments' => [['type' => 'certificate', 'id' => (int) $cert['id']]],
            'created_by' => (int) $actor['id'],
        ]);
    }

    public static function revoke(array $actor, array $cert, string $reason): void
    {
        if ($cert['status'] === 'revoked') {
            throw new DomainException('This certificate is already revoked.');
        }
        if (trim($reason) === '') {
            throw new DomainException('Please give a reason for revoking the certificate.');
        }
        $tenantId = (int) $actor['tenant_id'];
        Database::instance()->transaction(function (Database $db) use ($cert, $reason, $actor, $tenantId): void {
            $db->update('certificates', ['status' => 'revoked', 'revoked_reason' => mb_substr($reason, 0, 255), 'revoked_at' => date('Y-m-d H:i:s'), 'revoked_by' => (int) $actor['id']],
                ['id' => (int) $cert['id'], 'tenant_id' => $tenantId]);
            PeopleService::history($tenantId, (int) $cert['person_id'], 'certificate_revoked', 'Certificate ' . $cert['certificate_no'] . ' revoked: ' . mb_substr($reason, 0, 120), (int) $actor['id']);
            AuditLogger::log('certificate.revoked', 'certificate', $cert['id'], ['certificate_no' => $cert['certificate_no'], 'reason' => $reason], $tenantId, $actor);
        });
    }

    public static function pdf(int $tenantId, array $cert): string
    {
        $snap = json_decode((string) $cert['layout_snapshot'], true) ?: [];
        $values = json_decode((string) $cert['data_snapshot'], true) ?: [];
        $ngo = Branding::forTenant($tenantId);
        $logo = Branding::logoBytes($tenantId, $ngo['logo_object_id'] ? (int) $ngo['logo_object_id'] : null);
        return CertificatePdf::render((array) ($snap['layout'] ?? []), (string) ($snap['orientation'] ?? 'landscape'), $values, $logo, $cert['status'] === 'revoked');
    }

    /** Sample PDF of a template with the NGO's own name and logo (or Fameweb sample data). */
    public static function preview(array $template, ?int $tenantId): string
    {
        $values = CertificateTemplateService::SAMPLE;
        $logo = null;
        if ($tenantId !== null) {
            $ngo = Branding::forTenant($tenantId);
            $values = array_merge($values, ['ngo_name' => $ngo['name'], 'ngo_address' => preg_replace('/\s+/', ' ', (string) $ngo['address']), 'ngo_registration' => $ngo['registration_number']]);
            $logo = Branding::logoBytes($tenantId, $ngo['logo_object_id'] ? (int) $ngo['logo_object_id'] : null);
        }
        return CertificatePdf::render(json_decode($template['layout'], true) ?: [], $template['orientation'], $values, $logo, false, $logo === null);
    }

    /** Attachment for the email outbox; null when the certificate no longer exists or is revoked. */
    public static function attachment(int $tenantId, int $id): ?array
    {
        $cert = self::find($tenantId, $id);
        if (!$cert || $cert['status'] !== 'issued') {
            return null;
        }
        return ['filename' => $cert['certificate_no'] . '.pdf', 'mime' => 'application/pdf', 'content' => self::pdf($tenantId, $cert)];
    }
}
