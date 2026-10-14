<?php
declare(strict_types=1);

namespace App\Controllers\Platform;

use App\Core\Database;
use App\Core\Paginator;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Exceptions\DomainException;
use App\Services\SubscriptionService;

final class SubscriptionController extends PlatformController
{
    public function index(): string
    {
        $status = $this->request->str('status');
        $q = $this->request->str('q');
        $expiring = $this->request->str('expiring');
        $where = ['s.is_current = 1'];
        $params = [];
        if (in_array($status, SubscriptionService::STATUSES, true)) {
            $where[] = 's.status = :status';
            $params['status'] = $status;
        }
        if ($q !== '') {
            $where[] = '(t.name LIKE :q OR t.reference_code LIKE :q2)';
            $params += ['q' => "%$q%", 'q2' => "%$q%"];
        }
        if ($expiring === '30') {
            $where[] = 's.end_date IS NOT NULL AND s.end_date <= :exp';
            $params['exp'] = date('Y-m-d', strtotime('+30 days'));
        }
        $from = ' FROM subscriptions s JOIN tenants t ON t.id = s.tenant_id JOIN subscription_plans p ON p.id = s.plan_id WHERE ' . implode(' AND ', $where);
        $page = new Paginator(
            'SELECT s.*, t.name AS tenant_name, t.reference_code, p.name AS plan_name,
                    (SELECT COALESCE(SUM(amount),0) FROM subscription_payments sp WHERE sp.subscription_id = s.id AND sp.status = \'paid\') AS paid_total'
            . $from . ' ORDER BY s.end_date IS NULL, s.end_date ASC',
            'SELECT COUNT(*)' . $from,
            $params,
            $this->request->int('page', 1)
        );
        return $this->view('platform/subscriptions/index', [
            'title' => 'Subscriptions', 'page' => $page, 'status' => $status, 'q' => $q, 'expiring' => $expiring,
            'mrr' => SubscriptionService::mrr(),
        ]);
    }

    public function payments(): string
    {
        $q = $this->request->str('q');
        $where = ['1=1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(t.name LIKE :q OR sp.reference LIKE :q2)';
            $params += ['q' => "%$q%", 'q2' => "%$q%"];
        }
        $from = ' FROM subscription_payments sp JOIN tenants t ON t.id = sp.tenant_id JOIN subscriptions s ON s.id = sp.subscription_id
                  JOIN subscription_plans p ON p.id = s.plan_id WHERE ' . implode(' AND ', $where);
        $page = new Paginator(
            'SELECT sp.*, t.name AS tenant_name, p.name AS plan_name, u.name AS recorder' . str_replace(' WHERE ', ' LEFT JOIN users u ON u.id = sp.recorded_by WHERE ', $from)
            . ' ORDER BY sp.paid_at DESC, sp.id DESC',
            'SELECT COUNT(*)' . $from,
            $params,
            $this->request->int('page', 1)
        );
        return $this->view('platform/subscriptions/payments', ['title' => 'Payments', 'page' => $page, 'q' => $q]);
    }

    public function assignForm(): string
    {
        $id = (int) $this->request->param('id');
        $db = Database::instance();
        $tenant = $db->fetch('SELECT * FROM tenants WHERE id = ?', [$id]) ?? $this->notFound('NGO');
        return $this->view('platform/subscriptions/assign', [
            'title' => 'Change plan: ' . $tenant['name'],
            't' => $tenant,
            'current' => SubscriptionService::current($id),
            'plans' => $db->fetchAll("SELECT * FROM subscription_plans WHERE status = 'active' ORDER BY monthly_price"),
        ]);
    }

    public function assign(): Response
    {
        $id = (int) $this->request->param('id');
        Database::instance()->fetch('SELECT id FROM tenants WHERE id = ?', [$id]) ?? $this->notFound('NGO');
        $back = 'admin/ngos/' . $id . '/subscription';
        $data = $this->validate([
            'plan_id' => 'required|integer',
            'status' => 'required|in:trial,active',
            'billing_cycle' => 'required|in:monthly,annual,custom',
            'start_date' => 'required|date',
            'end_date' => 'date',
            'price' => 'numeric|min_value:0',
            'notes' => 'max:1000',
        ], $back, ['plan_id' => 'Plan', 'end_date' => 'Expiry / renewal date']);
        try {
            [, $warnings] = SubscriptionService::assign($id, (int) $data['plan_id'], $data);
        } catch (DomainException $e) {
            $this->failValidation([], $back, $e->getMessage());
        }
        if ($warnings) {
            Session::flash('warning', implode(' ', $warnings));
        }
        return $this->redirect('admin/ngos/' . $id, 'success', 'Subscription saved. The previous subscription was kept in the history.');
    }

    public function update(): Response
    {
        $subId = (int) $this->request->param('id');
        $sub = Database::instance()->fetch('SELECT * FROM subscriptions WHERE id = ?', [$subId]) ?? $this->notFound('subscription');
        $back = 'admin/ngos/' . $sub['tenant_id'];
        $data = $this->validate([
            'status' => 'required|in:' . implode(',', SubscriptionService::STATUSES),
            'end_date' => 'date',
            'note' => 'max:255',
        ], $back);
        try {
            SubscriptionService::update($subId, $data['status'], $data['end_date'] ?: null, $data['note'] ?: null);
        } catch (DomainException $e) {
            return $this->redirect($back, 'error', $e->getMessage());
        }
        return $this->redirect($back, 'success', 'Subscription updated.');
    }

    public function recordPayment(): Response
    {
        $subId = (int) $this->request->param('id');
        $sub = Database::instance()->fetch('SELECT * FROM subscriptions WHERE id = ?', [$subId]) ?? $this->notFound('subscription');
        $back = 'admin/ngos/' . $sub['tenant_id'];
        $input = $this->request->only(['amount', 'status', 'method', 'reference', 'paid_at', 'period_start', 'period_end', 'notes', 'nonce']);
        $input['extend'] = $this->request->str('extend') === '1';
        $v = Validator::make($input, [
            'amount' => 'required|numeric|min_value:0.01|max_value:99999999',
            'status' => 'required|in:pending,paid,failed,refunded',
            'method' => 'required|in:cash,bank_transfer,upi,cheque,card,other',
            'reference' => 'max:120',
            'paid_at' => 'required|date',
            'period_start' => 'date',
            'period_end' => 'date',
            'notes' => 'max:1000',
            'nonce' => 'required|regex:/^[a-f0-9]{32}$/',
        ], ['paid_at' => 'Payment date', 'period_end' => 'Period end']);
        if ($input['period_start'] !== '' && $input['period_end'] !== '' && $input['period_end'] < $input['period_start']) {
            $v->addError('period_end', 'Period end must be after period start.');
        }
        if ($v->fails()) {
            $this->failValidation($v->errors, $back);
        }
        [$paymentId, $created] = SubscriptionService::recordPayment($subId, $input, 'manual:' . $input['nonce']);
        return $this->redirect($back, $created ? 'success' : 'info', $created
            ? 'Payment #' . $paymentId . ' recorded.'
            : 'This payment was already recorded (#' . $paymentId . '); the duplicate submission was ignored.');
    }
}
