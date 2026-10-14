<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Exceptions\DomainException;
use App\Pdf\Branding;
use App\Pdf\SalarySlipPdf;

/**
 * Monthly salary / stipend / honorarium slips for members, volunteers and interns. One slip per
 * person per month; amounts are stored, the PDF is generated on download (no storage used).
 */
final class SalarySlipService
{
    public const PAY_TYPES = ['salary' => 'Salary', 'stipend' => 'Stipend', 'honorarium' => 'Honorarium'];
    public const MODES = ['bank_transfer' => 'Bank transfer', 'upi' => 'UPI', 'cheque' => 'Cheque', 'cash' => 'Cash'];

    public static function find(int $tenantId, int $id): ?array
    {
        return Database::instance()->fetch(
            'SELECT s.*, p.name AS person_name, p.member_code, p.person_type FROM salary_slips s JOIN people p ON p.id = s.person_id
              WHERE s.id = ? AND s.tenant_id = ?',
            [$id, $tenantId]
        );
    }

    public static function listFor(int $tenantId, int $personId): array
    {
        return Database::instance()->fetchAll(
            'SELECT * FROM salary_slips WHERE tenant_id = ? AND person_id = ? ORDER BY period_month DESC',
            [$tenantId, $personId]
        );
    }

    /**
     * @param array{period_month:string, pay_type:string, earnings:array, deductions:array, working_days?:string,
     *              paid_days?:string, payment_date?:string, payment_mode?:string, notes?:string} $data
     *        earnings / deductions: list of ['label' => ..., 'amount' => ...]
     */
    public static function create(array $actor, array $person, array $data): int
    {
        $tenantId = (int) $actor['tenant_id'];
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $data['period_month'] ?? '')) {
            throw new DomainException('Choose the month the slip is for.');
        }
        if ($data['period_month'] > date('Y-m', strtotime('+1 month'))) {
            throw new DomainException('Slips cannot be created for months more than one month ahead.');
        }
        $payType = $data['pay_type'] ?? 'salary';
        if (!isset(self::PAY_TYPES[$payType])) {
            throw new DomainException('Choose salary, stipend or honorarium.');
        }
        $earnings = self::lines($data['earnings'] ?? []);
        $deductions = self::lines($data['deductions'] ?? []);
        if (!$earnings) {
            throw new DomainException('Add at least one earning with an amount.');
        }
        $gross = round(array_sum(array_column($earnings, 'amount')), 2);
        $totalDed = round(array_sum(array_column($deductions, 'amount')), 2);
        if ($totalDed > $gross) {
            throw new DomainException('Deductions cannot be more than the gross amount.');
        }
        $mode = $data['payment_mode'] ?? '';
        if ($mode !== '' && !isset(self::MODES[$mode])) {
            throw new DomainException('Choose a valid payment mode.');
        }
        $days = static function ($v): ?float {
            $v = trim((string) $v);
            if ($v === '') {
                return null;
            }
            if (!is_numeric($v) || (float) $v < 0 || (float) $v > 31) {
                throw new DomainException('Working and paid days must be between 0 and 31.');
            }
            return round((float) $v, 1);
        };
        $working = $days($data['working_days'] ?? '');
        $paid = $days($data['paid_days'] ?? '');
        if ($working !== null && $paid !== null && $paid > $working) {
            throw new DomainException('Paid days cannot be more than working days.');
        }
        $paymentDate = trim((string) ($data['payment_date'] ?? ''));
        if ($paymentDate !== '' && !\App\Core\Validator::isDate($paymentDate)) {
            throw new DomainException('Payment date must be a valid date.');
        }

        $db = Database::instance();
        if ($db->fetchColumn('SELECT id FROM salary_slips WHERE tenant_id = ? AND person_id = ? AND period_month = ?', [$tenantId, (int) $person['id'], $data['period_month']])) {
            throw new DomainException('A slip for ' . date('F Y', strtotime($data['period_month'] . '-01')) . ' already exists for this person.');
        }
        return $db->transaction(function (Database $db) use ($tenantId, $person, $data, $payType, $earnings, $deductions, $gross, $totalDed, $working, $paid, $paymentDate, $mode, $actor) {
            $slipNo = 'SLIP-' . str_replace('-', '', $data['period_month']) . '-' . sprintf('%05d', SequenceService::next($tenantId, 'salary_slip'));
            $id = $db->insert('salary_slips', [
                'tenant_id' => $tenantId,
                'person_id' => (int) $person['id'],
                'slip_no' => $slipNo,
                'period_month' => $data['period_month'],
                'pay_type' => $payType,
                'earnings' => json_encode($earnings),
                'deductions' => json_encode($deductions),
                'gross_amount' => $gross,
                'total_deductions' => $totalDed,
                'net_amount' => round($gross - $totalDed, 2),
                'working_days' => $working,
                'paid_days' => $paid,
                'payment_date' => $paymentDate ?: null,
                'payment_mode' => $mode ?: null,
                'notes' => trim((string) ($data['notes'] ?? '')) !== '' ? mb_substr(trim((string) $data['notes']), 0, 255) : null,
                'created_by' => (int) $actor['id'],
            ]);
            PeopleService::history($tenantId, (int) $person['id'], 'salary_slip',
                self::PAY_TYPES[$payType] . ' slip ' . $slipNo . ' for ' . date('F Y', strtotime($data['period_month'] . '-01')) . ' created', (int) $actor['id']);
            AuditLogger::log('salary_slip.created', 'salary_slip', $id, ['person_id' => (int) $person['id'], 'month' => $data['period_month']], $tenantId, $actor);
            return $id;
        });
    }

    public static function pdf(int $tenantId, array $slip): string
    {
        $person = PeopleService::find($tenantId, (int) $slip['person_id']) ?? throw new DomainException('Person not found.');
        $ngo = Branding::forTenant($tenantId);
        return SalarySlipPdf::render($ngo, Branding::logoBytes($tenantId, $ngo['logo_object_id'] ? (int) $ngo['logo_object_id'] : null), $person, $slip);
    }

    /** @return array<int, array{label:string, amount:float}> */
    private static function lines(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $label = trim((string) ($r['label'] ?? ''));
            $amount = trim((string) ($r['amount'] ?? ''));
            if ($label === '' && $amount === '') {
                continue;
            }
            if ($label === '' || mb_strlen($label) > 60) {
                throw new DomainException('Every amount needs a label (up to 60 characters).');
            }
            if (!is_numeric($amount) || (float) $amount < 0 || (float) $amount > 99999999) {
                throw new DomainException('Amounts must be numbers of zero or more.');
            }
            if ((float) $amount == 0.0) {
                continue;
            }
            $out[] = ['label' => $label, 'amount' => round((float) $amount, 2)];
            if (count($out) > 15) {
                throw new DomainException('Use at most 15 earning and 15 deduction lines.');
            }
        }
        return $out;
    }
}
