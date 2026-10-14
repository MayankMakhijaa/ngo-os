<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;
use App\Exceptions\DomainException;
use App\Pdf\Branding;
use App\Pdf\ReceiptPdf;

/**
 * 80G donation receipts. The receipt is built automatically from the NGO's logo, name and
 * address plus its 80G details (PAN, registration number under section 80G, validity) and
 * the donor details. Fields follow section 80G and rule 18AB of the Income-tax Rules as we
 * understand them; the NGO can edit the wording per NGO. The software does not certify the
 * receipt as legally valid: each NGO should have its CA confirm the format.
 *
 * Numbers are sequential per NGO per financial year (April-March), e.g. RCPT/2026-27/00001.
 * Issued receipts are never edited; cancelling keeps the row and number.
 */
final class ReceiptService
{
    public const MODES = [
        'cash' => 'Cash', 'cheque' => 'Cheque', 'demand_draft' => 'Demand draft', 'bank_transfer' => 'Bank transfer (NEFT/RTGS/IMPS)',
        'upi' => 'UPI', 'card' => 'Debit / credit card', 'kind' => 'In kind (goods)',
    ];
    /** Modes that need a reference number (cheque no., UTR, transaction id). */
    public const MODES_WITH_REFERENCE = ['cheque', 'demand_draft', 'bank_transfer', 'upi', 'card'];
    public const DONATION_TYPES = ['others' => 'General donation', 'corpus' => 'Corpus donation', 'specific_grant' => 'Specific grant'];
    /** Donor identification types accepted for Form 10BD. Aadhaar is deliberately not collected. */
    public const ID_TYPES = [
        'pan' => 'PAN', 'passport' => 'Passport', 'voter_id' => 'Voter ID', 'driving_licence' => 'Driving licence',
        'ration_card' => 'Ration card', 'tax_id' => 'Tax ID (foreign donor)', 'none' => 'Not provided',
    ];

    /** Per-NGO settings (tenant_settings keys) with defaults. */
    public const SETTINGS = [
        'r80g_pan' => '',
        'r80g_reg_no' => '',
        'r80g_reg_date' => '',
        'r80g_valid_from' => '',
        'r80g_valid_to' => '',
        'r80g_signatory_name' => '',
        'r80g_signatory_designation' => '',
        'r80g_signature_object_id' => '',
        'r80g_prefix' => 'RCPT',
        'r80g_title' => 'Donation Receipt',
        'r80g_subtitle' => 'Under section 80G of the Income-tax Act, 1961',
        'r80g_declaration' => 'Received with thanks from {{donor_name}} a sum of Rs. {{amount}} ({{amount_words}}) by {{payment_mode}} towards {{donation_type}}{{purpose_suffix}}. This donation is eligible for deduction under section 80G(5) of the Income-tax Act, 1961, subject to the conditions laid down in the Act.',
        'r80g_footer' => 'The certificate of donation in Form 10BE will be issued after the statement of donations (Form 10BD) is filed for the financial year.',
        'r80g_color' => '',
        'r80g_show_logo' => '1',
        'r80g_cash_limit' => '2000',
    ];

    public const PLACEHOLDERS = [
        'donor_name', 'amount', 'amount_words', 'payment_mode', 'donation_type', 'purpose', 'purpose_suffix', 'receipt_no', 'date', 'ngo_name', 'financial_year',
    ];

    public static function settings(int $tenantId): array
    {
        $rows = Database::instance()->fetchAll("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ? AND setting_key LIKE 'r80g\\_%'", [$tenantId]);
        $stored = array_intersect_key(array_column($rows, 'setting_value', 'setting_key'), self::SETTINGS);
        return array_merge(self::SETTINGS, array_filter($stored, static fn ($v) => $v !== null));
    }

    /** What is still missing before receipts can be issued. */
    public static function missingSettings(array $s): array
    {
        $missing = [];
        if (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $s['r80g_pan'])) {
            $missing[] = 'NGO PAN';
        }
        if (trim($s['r80g_reg_no']) === '') {
            $missing[] = '80G registration (approval) number';
        }
        if (trim($s['r80g_signatory_name']) === '') {
            $missing[] = 'authorised signatory';
        }
        return $missing;
    }

    public static function saveSettings(array $actor, array $data, ?array $signatureFile): void
    {
        $tenantId = (int) $actor['tenant_id'];
        $before = self::settings($tenantId);
        $data['r80g_pan'] = strtoupper(trim($data['r80g_pan'] ?? ''));
        if ($data['r80g_pan'] !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $data['r80g_pan'])) {
            throw new DomainException('The NGO PAN must look like AAAAA9999A.');
        }
        foreach (['r80g_reg_date', 'r80g_valid_from', 'r80g_valid_to'] as $d) {
            $data[$d] = trim($data[$d] ?? '');
            if ($data[$d] !== '' && !Validator::isDate($data[$d])) {
                throw new DomainException('80G dates must be valid dates.');
            }
        }
        if ($data['r80g_valid_from'] !== '' && $data['r80g_valid_to'] !== '' && $data['r80g_valid_to'] < $data['r80g_valid_from']) {
            throw new DomainException('The 80G validity end date cannot be before its start date.');
        }
        $data['r80g_prefix'] = strtoupper(trim($data['r80g_prefix'] ?? '')) ?: 'RCPT';
        if (!preg_match('/^[A-Z0-9\-]{1,12}$/', $data['r80g_prefix'])) {
            throw new DomainException('The receipt prefix can use letters, digits and hyphens (up to 12).');
        }
        $data['r80g_color'] = trim($data['r80g_color'] ?? '');
        if ($data['r80g_color'] !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $data['r80g_color'])) {
            $data['r80g_color'] = '';
        }
        $data['r80g_show_logo'] = ($data['r80g_show_logo'] ?? '') === '1' ? '1' : '0';
        $limit = trim($data['r80g_cash_limit'] ?? '2000');
        if (!preg_match('/^\d{1,9}$/', $limit)) {
            throw new DomainException('The cash limit must be a whole number of rupees.');
        }
        $data['r80g_cash_limit'] = $limit;
        foreach (['r80g_title' => 120, 'r80g_subtitle' => 200, 'r80g_declaration' => 1500, 'r80g_footer' => 600,
                  'r80g_reg_no' => 60, 'r80g_signatory_name' => 120, 'r80g_signatory_designation' => 120] as $k => $max) {
            $data[$k] = trim(str_replace("\r", '', $data[$k] ?? ''));
            if (mb_strlen($data[$k]) > $max) {
                throw new DomainException('A receipt setting is too long (' . str_replace('r80g_', '', $k) . ', max ' . $max . ' characters).');
            }
        }
        if ($data['r80g_title'] === '') {
            $data['r80g_title'] = self::SETTINGS['r80g_title'];
        }
        if ($data['r80g_declaration'] === '') {
            $data['r80g_declaration'] = self::SETTINGS['r80g_declaration'];
        }
        $db = Database::instance();
        if ($signatureFile) {
            $tenant = $db->fetch('SELECT * FROM tenants WHERE id = ?', [$tenantId]);
            $stored = StorageService::storeUpload($tenant, $signatureFile, 'signature', ['image/png' => 'png', 'image/jpeg' => 'jpg'],
                1024 * 1024, (int) $actor['id']);
            if ($before['r80g_signature_object_id'] !== '') {
                StorageService::delete((int) $before['r80g_signature_object_id'], $tenantId, (int) $actor['id']);
            }
            $data['r80g_signature_object_id'] = (string) $stored['id'];
        } elseif (($data['remove_signature'] ?? '') === '1' && $before['r80g_signature_object_id'] !== '') {
            StorageService::delete((int) $before['r80g_signature_object_id'], $tenantId, (int) $actor['id']);
            $data['r80g_signature_object_id'] = '';
        }
        $changed = [];
        foreach (self::SETTINGS as $k => $default) {
            if (!array_key_exists($k, $data) || (string) $data[$k] === (string) $before[$k]) {
                continue;
            }
            $changed[] = $k;
            $db->execute(
                'INSERT INTO tenant_settings (tenant_id, setting_key, setting_value, updated_by) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
                [$tenantId, $k, (string) $data[$k], (int) $actor['id']]
            );
        }
        if ($changed) {
            AuditLogger::log('receipts.settings_updated', 'tenant', $tenantId, ['fields' => $changed], $tenantId, $actor);
        }
    }

    /** Indian financial year of a date: 2026-05-10 => "2026-27", 2027-02-01 => "2026-27". */
    public static function financialYear(string $date): string
    {
        $y = (int) substr($date, 0, 4);
        $start = (int) substr($date, 5, 2) >= 4 ? $y : $y - 1;
        return $start . '-' . substr((string) ($start + 1), -2);
    }

    public static function find(int $tenantId, int $id): ?array
    {
        return Database::instance()->fetch('SELECT r.*, u.name AS issued_by_name FROM donation_receipts r LEFT JOIN users u ON u.id = r.issued_by
            WHERE r.id = ? AND r.tenant_id = ?', [$id, $tenantId]);
    }

    /**
     * Issue a receipt. $data: donor_name, donor_address, donor_email, donor_phone, donor_id_type, donor_id_number,
     * amount, donation_date, payment_mode, payment_reference, bank_name, donation_type, purpose.
     * $idempotencyKey (from the form) makes double submits return the first receipt.
     */
    public static function create(array $actor, array $data, ?string $idempotencyKey = null): int
    {
        $tenantId = (int) $actor['tenant_id'];
        $s = self::settings($tenantId);
        if ($missing = self::missingSettings($s)) {
            throw new DomainException('Complete the 80G settings first: ' . implode(', ', $missing) . '.');
        }
        $db = Database::instance();
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = $db->fetchColumn('SELECT id FROM donation_receipts WHERE tenant_id = ? AND idempotency_key = ?', [$tenantId, $idempotencyKey]);
            if ($existing) {
                return (int) $existing;
            }
        }
        $row = self::clean($data, $s);
        $ngo = Branding::forTenant($tenantId);
        $snapshot = [
            'name' => $ngo['name'], 'address' => $ngo['address'], 'phone' => $ngo['phone'], 'email' => $ngo['email'], 'website' => $ngo['website'],
            'registration_number' => $ngo['registration_number'], 'color' => $s['r80g_color'] ?: $ngo['color'],
            'logo_object_id' => $s['r80g_show_logo'] === '1' ? $ngo['logo_object_id'] : null,
            'pan' => $s['r80g_pan'], 'reg_no' => $s['r80g_reg_no'], 'reg_date' => $s['r80g_reg_date'],
            'valid_from' => $s['r80g_valid_from'], 'valid_to' => $s['r80g_valid_to'],
            'signatory_name' => $s['r80g_signatory_name'], 'signatory_designation' => $s['r80g_signatory_designation'],
            'signature_object_id' => $s['r80g_signature_object_id'] ?: null,
            'title' => $s['r80g_title'], 'subtitle' => $s['r80g_subtitle'], 'declaration' => $s['r80g_declaration'], 'footer' => $s['r80g_footer'],
            'cash_limit' => (int) $s['r80g_cash_limit'],
        ];
        $fy = self::financialYear($row['donation_date']);
        try {
            $id = $db->transaction(function (Database $db) use ($tenantId, $row, $snapshot, $fy, $s, $idempotencyKey, $actor) {
                $seq = SequenceService::next($tenantId, 'receipt:' . $fy);
                $no = $s['r80g_prefix'] . '/' . $fy . '/' . sprintf('%05d', $seq);
                $id = $db->insert('donation_receipts', $row + [
                    'tenant_id' => $tenantId,
                    'receipt_no' => $no,
                    'financial_year' => $fy,
                    'sequence_no' => $seq,
                    'ngo_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                    'idempotency_key' => $idempotencyKey ?: null,
                    'issued_by' => (int) $actor['id'],
                ]);
                UsageService::record($tenantId, 'invoices', 1, 'receipt:' . $id, 'donation_receipt', $id, (int) $actor['id']);
                AuditLogger::log('receipt.issued', 'donation_receipt', $id, ['receipt_no' => $no, 'amount' => $row['amount'], 'mode' => $row['payment_mode'],
                    'eligible_80g' => $row['eligible_80g']], $tenantId, $actor);
                return $id;
            });
        } catch (\PDOException $e) {
            if ($idempotencyKey && Database::isDuplicateKey($e)) {
                return (int) $db->fetchColumn('SELECT id FROM donation_receipts WHERE tenant_id = ? AND idempotency_key = ?', [$tenantId, $idempotencyKey]);
            }
            throw $e;
        }
        if ($row['donor_email'] && MailSettings::automationOn($tenantId, 'auto_receipt_donor')) {
            self::email($actor, self::find($tenantId, $id));
        }
        return $id;
    }

    public static function cancel(array $actor, array $receipt, string $reason): void
    {
        if ($receipt['status'] === 'cancelled') {
            throw new DomainException('This receipt is already cancelled.');
        }
        if (trim($reason) === '') {
            throw new DomainException('Please give a reason for cancelling the receipt.');
        }
        $tenantId = (int) $actor['tenant_id'];
        Database::instance()->update('donation_receipts', [
            'status' => 'cancelled', 'cancelled_reason' => mb_substr(trim($reason), 0, 255), 'cancelled_at' => date('Y-m-d H:i:s'), 'cancelled_by' => (int) $actor['id'],
        ], ['id' => (int) $receipt['id'], 'tenant_id' => $tenantId]);
        AuditLogger::log('receipt.cancelled', 'donation_receipt', $receipt['id'], ['receipt_no' => $receipt['receipt_no'], 'reason' => $reason], $tenantId, $actor);
    }

    public static function email(array $actor, array $receipt): bool
    {
        if (!$receipt['donor_email']) {
            throw new DomainException('The donor has no email address on this receipt.');
        }
        if ($receipt['status'] !== 'issued') {
            throw new DomainException('A cancelled receipt cannot be emailed.');
        }
        $tenantId = (int) $receipt['tenant_id'];
        $snap = json_decode((string) $receipt['ngo_snapshot'], true) ?: [];
        $ok = Mailer::send($receipt['donor_email'], 'Your donation receipt ' . $receipt['receipt_no'] . ' from ' . ($snap['name'] ?? ''), sprintf(
            "Dear %s,\n\nThank you for your donation of Rs. %s to %s.\n\nYour receipt %s is attached.\n\nWith gratitude,\n%s\n",
            $receipt['donor_name'], \App\Support\IndianNumber::format($receipt['amount']), $snap['name'] ?? '', $receipt['receipt_no'], $snap['name'] ?? ''
        ), $tenantId, 'receipt-email:' . $receipt['id'] . ':' . time(), [
            'category' => 'receipt', 'to_name' => $receipt['donor_name'], 'attachments' => [['type' => 'receipt', 'id' => (int) $receipt['id']]],
            'created_by' => (int) ($actor['id'] ?? 0) ?: null,
        ]);
        if ($ok) {
            Database::instance()->update('donation_receipts', ['emailed_at' => date('Y-m-d H:i:s')], ['id' => (int) $receipt['id'], 'tenant_id' => $tenantId]);
        }
        return $ok;
    }

    public static function pdf(int $tenantId, array $receipt): string
    {
        $snap = json_decode((string) $receipt['ngo_snapshot'], true) ?: [];
        $logo = Branding::logoBytes($tenantId, isset($snap['logo_object_id']) ? (int) $snap['logo_object_id'] : null);
        if ($logo === null && !empty($snap['logo_object_id'])) {
            // The logo was replaced after issue: fall back to the current one.
            $current = Branding::forTenant($tenantId)['logo_object_id'];
            $logo = Branding::logoBytes($tenantId, $current ? (int) $current : null);
        }
        $signature = Branding::logoBytes($tenantId, isset($snap['signature_object_id']) ? (int) $snap['signature_object_id'] : null);
        return ReceiptPdf::render($snap, $receipt, $logo, $signature);
    }

    /** A sample receipt with the NGO's current settings, for the settings page. */
    public static function samplePdf(int $tenantId): string
    {
        $s = self::settings($tenantId);
        $ngo = Branding::forTenant($tenantId);
        $snap = [
            'name' => $ngo['name'], 'address' => $ngo['address'], 'phone' => $ngo['phone'], 'email' => $ngo['email'], 'website' => $ngo['website'],
            'registration_number' => $ngo['registration_number'], 'color' => $s['r80g_color'] ?: $ngo['color'],
            'pan' => $s['r80g_pan'] ?: 'AAATX0000X', 'reg_no' => $s['r80g_reg_no'] ?: '(80G registration number)', 'reg_date' => $s['r80g_reg_date'],
            'valid_from' => $s['r80g_valid_from'], 'valid_to' => $s['r80g_valid_to'],
            'signatory_name' => $s['r80g_signatory_name'] ?: '(Authorised signatory)', 'signatory_designation' => $s['r80g_signatory_designation'],
            'title' => $s['r80g_title'], 'subtitle' => $s['r80g_subtitle'], 'declaration' => $s['r80g_declaration'], 'footer' => $s['r80g_footer'],
            'cash_limit' => (int) $s['r80g_cash_limit'],
        ];
        $receipt = [
            'receipt_no' => $s['r80g_prefix'] . '/' . self::financialYear(date('Y-m-d')) . '/00001', 'financial_year' => self::financialYear(date('Y-m-d')),
            'donation_date' => date('Y-m-d'), 'donor_name' => 'Sample Donor', 'donor_address' => '45 Sample Street, Mumbai 400001',
            'donor_email' => 'donor@example.com', 'donor_phone' => '', 'donor_id_type' => 'pan', 'donor_id_number' => 'ABCDE1234F',
            'amount' => '5000.00', 'payment_mode' => 'upi', 'payment_reference' => 'UPI-123456789', 'bank_name' => '',
            'donation_type' => 'others', 'purpose' => 'Education programme', 'eligible_80g' => 1, 'status' => 'issued',
        ];
        $logo = $s['r80g_show_logo'] === '1' ? Branding::logoBytes($tenantId, $ngo['logo_object_id'] ? (int) $ngo['logo_object_id'] : null) : null;
        $signature = Branding::logoBytes($tenantId, $s['r80g_signature_object_id'] !== '' ? (int) $s['r80g_signature_object_id'] : null);
        return ReceiptPdf::render($snap, $receipt, $logo, $signature, true);
    }

    public static function attachment(int $tenantId, int $id): ?array
    {
        $r = self::find($tenantId, $id);
        if (!$r || $r['status'] !== 'issued') {
            return null;
        }
        return ['filename' => str_replace('/', '-', $r['receipt_no']) . '.pdf', 'mime' => 'application/pdf', 'content' => self::pdf($tenantId, $r)];
    }

    /**
     * Rows for the annual statement of donations (Form 10BD), one per issued receipt that is
     * eligible under 80G. Cancelled receipts and donations in kind are left out.
     */
    public static function form10bdRows(int $tenantId, string $fy): array
    {
        $rows = Database::instance()->fetchAll(
            "SELECT * FROM donation_receipts WHERE tenant_id = ? AND financial_year = ? AND status = 'issued' AND payment_mode <> 'kind' ORDER BY sequence_no",
            [$tenantId, $fy]
        );
        // Identification codes as listed in the Form 10BD instructions (2 = Aadhaar is never collected here).
        $idCode = ['pan' => '1', 'tax_id' => '3', 'passport' => '4', 'voter_id' => '5', 'driving_licence' => '6', 'ration_card' => '7', 'none' => ''];
        $out = [];
        foreach ($rows as $i => $r) {
            $out[] = [
                $i + 1,
                $r['donor_id_type'] === 'none' ? '' : $r['donor_id_number'],
                $idCode[$r['donor_id_type']] ?? '',
                'Section 80G',
                $r['donor_name'],
                preg_replace('/\s+/', ' ', (string) $r['donor_address']),
                match ($r['donation_type']) { 'corpus' => 'Corpus', 'specific_grant' => 'Specific grant', default => 'Others' },
                $r['payment_mode'] === 'cash' ? 'Cash' : 'Electronic modes including account payee cheque/draft',
                number_format((float) $r['amount'], 2, '.', ''),
                $r['receipt_no'],
                $r['donation_date'],
                $r['eligible_80g'] ? 'Yes' : 'No',
            ];
        }
        return $out;
    }

    private static function clean(array $data, array $s): array
    {
        $str = static fn (string $k, int $max = 255) => mb_substr(trim((string) ($data[$k] ?? '')), 0, $max);
        $row = [
            'donor_name' => $str('donor_name', 150),
            'donor_address' => $str('donor_address', 1000),
            'donor_email' => mb_strtolower($str('donor_email', 190)) ?: null,
            'donor_phone' => $str('donor_phone', 30) ?: null,
            'donor_id_type' => $str('donor_id_type', 20) ?: 'pan',
            'donor_id_number' => strtoupper(preg_replace('/\s+/', '', $str('donor_id_number', 30))) ?: null,
            'amount' => $str('amount', 20),
            'donation_date' => $str('donation_date', 10),
            'payment_mode' => $str('payment_mode', 20),
            'payment_reference' => $str('payment_reference', 100) ?: null,
            'bank_name' => $str('bank_name', 120) ?: null,
            'donation_type' => $str('donation_type', 20) ?: 'others',
            'purpose' => $str('purpose', 255) ?: null,
        ];
        if ($row['donor_name'] === '') {
            throw new DomainException('Donor name is required.');
        }
        if ($row['donor_address'] === '') {
            throw new DomainException('Donor address is required (it is reported in Form 10BD).');
        }
        if ($row['donor_email'] !== null && !filter_var($row['donor_email'], FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Donor email is not valid.');
        }
        if (!isset(self::ID_TYPES[$row['donor_id_type']])) {
            throw new DomainException('Choose the donor\'s identification type.');
        }
        if ($row['donor_id_type'] === 'none') {
            $row['donor_id_number'] = null;
        } elseif ($row['donor_id_number'] === null) {
            throw new DomainException('Enter the donor\'s ' . self::ID_TYPES[$row['donor_id_type']] . ' number, or choose "Not provided".');
        } elseif ($row['donor_id_type'] === 'pan' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $row['donor_id_number'])) {
            throw new DomainException('The donor PAN must look like AAAAA9999A.');
        }
        if (!is_numeric($row['amount']) || (float) $row['amount'] <= 0 || (float) $row['amount'] > 9999999999) {
            throw new DomainException('Enter the donation amount in rupees (more than zero).');
        }
        $row['amount'] = round((float) $row['amount'], 2);
        if (!Validator::isDate($row['donation_date'])) {
            throw new DomainException('Enter the date the donation was received.');
        }
        if ($row['donation_date'] > date('Y-m-d')) {
            throw new DomainException('The donation date cannot be in the future.');
        }
        if (!isset(self::MODES[$row['payment_mode']])) {
            throw new DomainException('Choose how the donation was received.');
        }
        if (in_array($row['payment_mode'], self::MODES_WITH_REFERENCE, true) && $row['payment_reference'] === null) {
            throw new DomainException('Enter the cheque / draft / transaction reference number.');
        }
        if (!isset(self::DONATION_TYPES[$row['donation_type']])) {
            throw new DomainException('Choose the donation type.');
        }
        // Section 80G(5D): cash donations above Rs. 2,000 and donations in kind get no deduction.
        $row['eligible_80g'] = ($row['payment_mode'] === 'kind' || ($row['payment_mode'] === 'cash' && $row['amount'] > (int) $s['r80g_cash_limit'])) ? 0 : 1;
        return $row;
    }
}
