<?php
declare(strict_types=1);

namespace App\Controllers\Ngo;

use App\Core\Database;
use App\Core\Paginator;
use App\Core\Response;
use App\Core\Session;
use App\Exceptions\DomainException;
use App\Exceptions\LimitExceededException;
use App\Services\AuditLogger;
use App\Services\ReceiptService;
use App\Services\StorageService;

/**
 * 80G donation receipts. The receipt is generated from the NGO's name, logo and address plus
 * its 80G settings; issued receipts are never edited, only cancelled.
 */
final class ReceiptController extends NgoController
{
    private function receipt(): array
    {
        return ReceiptService::find($this->tenantId(), (int) $this->request->param('id')) ?? $this->notFound('receipt');
    }

    public function index(): string
    {
        $tid = $this->tenantId();
        $fys = array_column(Database::instance()->fetchAll(
            'SELECT DISTINCT financial_year FROM donation_receipts WHERE tenant_id = ? ORDER BY financial_year DESC', [$tid]
        ), 'financial_year');
        $current = ReceiptService::financialYear(date('Y-m-d'));
        if (!in_array($current, $fys, true)) {
            array_unshift($fys, $current);
        }
        $f = ['q' => $this->request->str('q'), 'status' => $this->request->str('status'), 'fy' => $this->request->str('fy')];
        $where = ['tenant_id = :tid'];
        $params = ['tid' => $tid];
        if ($f['q'] !== '') {
            $where[] = '(donor_name LIKE :q OR receipt_no LIKE :q2 OR donor_email LIKE :q3 OR donor_id_number LIKE :q4)';
            $params += ['q' => "%{$f['q']}%", 'q2' => "%{$f['q']}%", 'q3' => "%{$f['q']}%", 'q4' => "%{$f['q']}%"];
        }
        if (in_array($f['status'], ['issued', 'cancelled'], true)) {
            $where[] = 'status = :status';
            $params['status'] = $f['status'];
        }
        if (in_array($f['fy'], $fys, true)) {
            $where[] = 'financial_year = :fy';
            $params['fy'] = $f['fy'];
        }
        $from = ' FROM donation_receipts WHERE ' . implode(' AND ', $where);
        $totals = Database::instance()->fetch(
            "SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS total, COALESCE(SUM(IF(eligible_80g = 1, amount, 0)),0) AS eligible
               FROM donation_receipts WHERE tenant_id = ? AND financial_year = ? AND status = 'issued'",
            [$tid, in_array($f['fy'], $fys, true) ? $f['fy'] : $current]
        );
        return $this->view('ngo/receipts/index', [
            'title' => '80G receipts',
            'page' => new Paginator(
                'SELECT id, receipt_no, donor_name, donor_email, amount, donation_date, payment_mode, eligible_80g, status' . $from . ' ORDER BY id DESC',
                'SELECT COUNT(*)' . $from, $params, $this->request->int('page', 1)
            ),
            'f' => $f,
            'fys' => $fys,
            'totalsFy' => in_array($f['fy'], $fys, true) ? $f['fy'] : $current,
            'totals' => $totals,
            'missing' => ReceiptService::missingSettings(ReceiptService::settings($tid)),
        ]);
    }

    public function create(): string
    {
        $settings = ReceiptService::settings($this->tenantId());
        return $this->view('ngo/receipts/create', [
            'title' => 'New 80G receipt',
            'missing' => ReceiptService::missingSettings($settings),
            'settings' => $settings,
            // One key per form render: a double submit returns the first receipt instead of a second number.
            'formKey' => bin2hex(random_bytes(16)),
        ]);
    }

    public function store(): Response
    {
        $key = $this->request->str('form_key');
        $key = preg_match('/^[a-f0-9]{32}$/', $key) ? 'form:' . $key : null;
        try {
            $id = ReceiptService::create($this->user(), $this->request->only([
                'donor_name', 'donor_address', 'donor_email', 'donor_phone', 'donor_id_type', 'donor_id_number', 'amount',
                'donation_date', 'payment_mode', 'payment_reference', 'bank_name', 'donation_type', 'purpose',
            ]), $key);
        } catch (DomainException|LimitExceededException $e) {
            $this->failValidation([], 'ngo/receipts/create', $e->getMessage());
        }
        $r = ReceiptService::find($this->tenantId(), $id);
        $msg = 'Receipt ' . $r['receipt_no'] . ' issued.';
        if (!$r['eligible_80g']) {
            $msg .= ' It is marked as not eligible for 80G deduction (' . ($r['payment_mode'] === 'kind' ? 'donation in kind' : 'cash above the limit') . ').';
        }
        return $this->redirect('ngo/receipts/' . $id, 'success', $msg);
    }

    public function show(): string
    {
        $r = $this->receipt();
        return $this->view('ngo/receipts/show', [
            'title' => 'Receipt ' . $r['receipt_no'],
            'r' => $r,
            'snap' => json_decode((string) $r['ngo_snapshot'], true) ?: [],
        ]);
    }

    public function pdf(): Response
    {
        $r = $this->receipt();
        AuditLogger::log('receipt.downloaded', 'donation_receipt', $r['id'], ['receipt_no' => $r['receipt_no']]);
        return Response::pdf(str_replace('/', '-', $r['receipt_no']) . '.pdf', ReceiptService::pdf($this->tenantId(), $r));
    }

    public function cancel(): Response
    {
        $r = $this->receipt();
        try {
            ReceiptService::cancel($this->user(), $r, mb_substr($this->request->str('reason'), 0, 255));
        } catch (DomainException $e) {
            return $this->redirect('ngo/receipts/' . $r['id'], 'error', $e->getMessage());
        }
        return $this->redirect('ngo/receipts/' . $r['id'], 'success', 'Receipt cancelled. Its number stays used and the PDF is marked CANCELLED.');
    }

    public function email(): Response
    {
        $r = $this->receipt();
        try {
            $ok = ReceiptService::email($this->user(), $r);
        } catch (DomainException $e) {
            return $this->redirect('ngo/receipts/' . $r['id'], 'error', $e->getMessage());
        }
        return $this->redirect('ngo/receipts/' . $r['id'], $ok ? 'success' : 'warning', $ok
            ? 'Receipt emailed to ' . $r['donor_email'] . '.'
            : 'The email could not be sent right away. It will be retried automatically; see the email log.');
    }

    /** Statement of donations for Form 10BD, as CSV, for one financial year. */
    public function export(): Response
    {
        $fy = $this->request->str('fy');
        if (!preg_match('/^\d{4}-\d{2}$/', $fy)) {
            $fy = ReceiptService::financialYear(date('Y-m-d'));
        }
        $rows = ReceiptService::form10bdRows($this->tenantId(), $fy);
        AuditLogger::log('receipts.exported', 'donation_receipt', null, ['financial_year' => $fy, 'rows' => count($rows)]);
        return Response::csv('form-10bd-' . $fy . '.csv', ['Sr. No.', 'Pre Acknowledgement Number / ID number', 'ID Code', 'Section code', 'Name of donor',
            'Address of donor', 'Donation type', 'Mode of receipt', 'Amount of donation (Indian rupees)', 'Receipt number', 'Date of donation', 'Eligible for 80G'], $rows);
    }

    public function settings(): string
    {
        $s = ReceiptService::settings($this->tenantId());
        return $this->view('ngo/receipts/settings', [
            'title' => '80G receipt settings',
            's' => $s,
            'missing' => ReceiptService::missingSettings($s),
        ]);
    }

    public function saveSettings(): Response
    {
        $data = $this->request->only(array_keys(ReceiptService::SETTINGS));
        unset($data['r80g_signature_object_id']);
        $data['remove_signature'] = $this->request->str('remove_signature');
        try {
            ReceiptService::saveSettings($this->user(), $data, $this->request->file('signature'));
        } catch (DomainException|LimitExceededException $e) {
            $this->failValidation([], 'ngo/receipts/settings', $e->getMessage());
        }
        return $this->redirect('ngo/receipts/settings', 'success', '80G receipt settings saved.');
    }

    public function preview(): Response
    {
        return Response::pdf('receipt-sample.pdf', ReceiptService::samplePdf($this->tenantId()), true);
    }

    public function signature(): Response
    {
        $s = ReceiptService::settings($this->tenantId());
        $obj = StorageService::find($s['r80g_signature_object_id'] !== '' ? (int) $s['r80g_signature_object_id'] : null, $this->tenantId()) ?? $this->notFound('signature');
        return StorageService::stream($obj);
    }
}
