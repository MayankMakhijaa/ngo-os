<?php
declare(strict_types=1);

namespace App\Controllers\Platform;

use App\Core\Database;
use App\Core\Paginator;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Exceptions\DomainException;
use App\Exceptions\LimitExceededException;
use App\Services\AuditLogger;
use App\Services\SubscriptionService;
use App\Services\TenantService;
use App\Services\UsageService;

final class TenantController extends PlatformController
{
    private const PROFILE_RULES = [
        'name' => 'required|max:190',
        'address' => 'required|max:1000',
        'contact_name' => 'required|max:120',
        'email' => 'required|email',
        'phone' => 'required|phone',
        'website' => 'url|max:255',
        'registration_number' => 'max:100',
        'notes' => 'max:5000',
    ];
    private const LABELS = [
        'name' => 'NGO name', 'address' => 'Address', 'contact_name' => 'Contact person', 'email' => 'Official email',
        'phone' => 'Contact phone', 'website' => 'Website', 'registration_number' => 'Registration number',
        'admin_name' => 'Super Admin name', 'admin_email' => 'Super Admin email', 'plan_id' => 'Plan',
        'start_date' => 'Start date', 'end_date' => 'Expiry / renewal date',
    ];

    public function index(): string
    {
        $q = $this->request->str('q');
        $status = $this->request->str('status');
        $subStatus = $this->request->str('sub_status');
        $planId = $this->request->int('plan_id');
        $where = ['1=1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(t.name LIKE :q OR t.reference_code LIKE :q2 OR t.email LIKE :q3 OR t.contact_name LIKE :q4)';
            $params += ['q' => "%$q%", 'q2' => "%$q%", 'q3' => "%$q%", 'q4' => "%$q%"];
        }
        if (in_array($status, TenantService::STATUSES, true)) {
            $where[] = 't.status = :status';
            $params['status'] = $status;
        }
        if (in_array($subStatus, SubscriptionService::STATUSES, true)) {
            $where[] = 's.status = :sub_status';
            $params['sub_status'] = $subStatus;
        }
        if ($planId > 0) {
            $where[] = 's.plan_id = :plan_id';
            $params['plan_id'] = $planId;
        }
        $from = ' FROM tenants t
                  LEFT JOIN subscriptions s ON s.tenant_id = t.id AND s.is_current = 1
                  LEFT JOIN subscription_plans p ON p.id = s.plan_id
                 WHERE ' . implode(' AND ', $where);
        $sort = match ($this->request->str('sort')) {
            'name' => 't.name ASC',
            'expiry' => 's.end_date IS NULL, s.end_date ASC',
            default => 't.created_at DESC, t.id DESC',
        };
        $page = new Paginator(
            'SELECT t.*, s.status AS sub_status, s.end_date, p.name AS plan_name,
                    (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id AND u.status IN (\'active\',\'invited\')) AS user_count'
            . $from . ' ORDER BY ' . $sort,
            'SELECT COUNT(*)' . $from,
            $params,
            $this->request->int('page', 1),
            (int) config('app.per_page', 20)
        );
        return $this->view('platform/tenants/index', [
            'title' => 'NGOs',
            'page' => $page,
            'plans' => Database::instance()->fetchAll('SELECT id, name FROM subscription_plans ORDER BY name'),
            'filters' => compact('q', 'status', 'subStatus', 'planId') + ['sort' => $this->request->str('sort')],
        ]);
    }

    public function create(): string
    {
        return $this->view('platform/tenants/create', [
            'title' => 'Register NGO',
            'plans' => Database::instance()->fetchAll("SELECT * FROM subscription_plans WHERE status = 'active' ORDER BY monthly_price"),
        ]);
    }

    public function store(): Response
    {
        $input = $this->request->only(array_merge(array_keys(self::PROFILE_RULES), [
            'status', 'admin_name', 'admin_email', 'admin_phone', 'plan_id', 'sub_status', 'billing_cycle', 'start_date', 'end_date', 'price',
        ]));
        $v = Validator::make($input, self::PROFILE_RULES + [
            'status' => 'required|in:active,suspended',
            'admin_name' => 'required|max:120',
            'admin_email' => 'required|email',
            'admin_phone' => 'phone',
            'plan_id' => 'required|integer',
            'sub_status' => 'required|in:trial,active',
            'billing_cycle' => 'required|in:monthly,annual,custom',
            'start_date' => 'required|date',
            'end_date' => 'date',
            'price' => 'numeric|min_value:0',
        ], self::LABELS);
        if ($v->fails()) {
            $this->failValidation($v->errors, 'admin/ngos/create');
        }
        try {
            $result = TenantService::create(
                $input,
                [
                    'plan_id' => (int) $input['plan_id'], 'status' => $input['sub_status'], 'billing_cycle' => $input['billing_cycle'],
                    'start_date' => $input['start_date'], 'end_date' => $input['end_date'] ?: null, 'price' => $input['price'],
                ],
                ['name' => $input['admin_name'], 'email' => $input['admin_email'], 'phone' => $input['admin_phone']],
                $this->request->file('logo')
            );
        } catch (DomainException|LimitExceededException $e) {
            $this->failValidation([], 'admin/ngos/create', $e->getMessage());
        }
        // The onboarding link is shown once to the admin who created the NGO, never stored in plain text.
        Session::flash('invite_link', $result['invite_link']);
        if ($result['warnings']) {
            Session::flash('warning', implode(' ', $result['warnings']));
        }
        return $this->redirect('admin/ngos/' . $result['tenant_id'], 'success', 'NGO registered. Share the single-use onboarding link with its Super Admin.');
    }

    public function show(): string
    {
        $id = (int) $this->request->param('id');
        $db = Database::instance();
        $tenant = $db->fetch('SELECT t.*, so.uuid AS logo_uuid FROM tenants t LEFT JOIN storage_objects so ON so.id = t.logo_object_id AND so.deleted_at IS NULL WHERE t.id = ?', [$id]) ?? $this->notFound('NGO');
        // Viewing an NGO's users and activity is access to its operational data: audit it.
        AuditLogger::log('tenant.viewed', 'tenant', $id, [], $id);
        $subscription = SubscriptionService::current($id);
        return $this->view('platform/tenants/show', [
            'title' => $tenant['name'],
            't' => $tenant,
            'subscription' => $subscription,
            'history' => SubscriptionService::history($id),
            'payments' => SubscriptionService::payments($id, 20),
            'events' => SubscriptionService::events($id, 20),
            'usage' => UsageService::summary($id, $subscription),
            'users' => $db->fetchAll(
                'SELECT u.id, u.name, u.email, u.status, u.last_login_at, u.created_at, r.name AS role_name
                   FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id AND ur.is_primary = 1
                   LEFT JOIN roles r ON r.id = ur.role_id
                  WHERE u.tenant_id = ? ORDER BY u.id',
                [$id]
            ),
            'activity' => $db->fetchAll(
                'SELECT a.*, u.name AS actor_name FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
                  WHERE a.tenant_id = ? ORDER BY a.id DESC LIMIT 15',
                [$id]
            ),
            'settings' => TenantService::settings($id),
            'inviteLink' => Session::getFlash('invite_link'),
            'paymentNonce' => bin2hex(random_bytes(16)),
        ]);
    }

    public function edit(): string
    {
        $id = (int) $this->request->param('id');
        $tenant = Database::instance()->fetch('SELECT * FROM tenants WHERE id = ?', [$id]) ?? $this->notFound('NGO');
        return $this->view('platform/tenants/edit', ['title' => 'Edit ' . $tenant['name'], 't' => $tenant]);
    }

    public function update(): Response
    {
        $id = (int) $this->request->param('id');
        Database::instance()->fetch('SELECT id FROM tenants WHERE id = ?', [$id]) ?? $this->notFound('NGO');
        $data = $this->validate(self::PROFILE_RULES, 'admin/ngos/' . $id . '/edit', self::LABELS);
        TenantService::update($id, $data, $data['notes']);
        $logo = $this->request->file('logo');
        if ($logo) {
            try {
                TenantService::replaceLogo($id, $logo);
            } catch (DomainException|LimitExceededException $e) {
                return $this->redirect('admin/ngos/' . $id . '/edit', 'error', 'Details saved, but the logo was not: ' . $e->getMessage());
            }
        }
        return $this->redirect('admin/ngos/' . $id, 'success', 'NGO details updated.');
    }

    public function status(): Response
    {
        $id = (int) $this->request->param('id');
        Database::instance()->fetch('SELECT id FROM tenants WHERE id = ?', [$id]) ?? $this->notFound('NGO');
        $status = $this->request->str('status');
        $reason = mb_substr($this->request->str('reason'), 0, 255);
        if ($status !== 'active' && $reason === '') {
            return $this->redirect('admin/ngos/' . $id, 'error', 'Please give a reason for suspending or deactivating the NGO.');
        }
        try {
            TenantService::changeStatus($id, $status, $reason);
        } catch (DomainException $e) {
            return $this->redirect('admin/ngos/' . $id, 'error', $e->getMessage());
        }
        $msg = match ($status) {
            'active' => 'NGO activated. Its users can sign in again; no data was changed.',
            'suspended' => 'NGO suspended. Its users were signed out and cannot sign in. All records are preserved.',
            default => 'NGO deactivated. Its users were signed out and cannot sign in. All records are preserved.',
        };
        return $this->redirect('admin/ngos/' . $id, 'success', $msg);
    }

    public function invite(): Response
    {
        $id = (int) $this->request->param('id');
        try {
            $link = TenantService::reissueInvite($id);
        } catch (DomainException $e) {
            return $this->redirect('admin/ngos/' . $id, 'error', $e->getMessage());
        }
        Session::flash('invite_link', $link);
        return $this->redirect('admin/ngos/' . $id, 'success', 'A new single-use onboarding link was created. Earlier links no longer work.');
    }
}
