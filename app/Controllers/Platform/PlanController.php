<?php
declare(strict_types=1);

namespace App\Controllers\Platform;

use App\Core\Database;
use App\Core\Response;
use App\Services\AuditLogger;

final class PlanController extends PlatformController
{
    private const RULES = [
        'name' => 'required|max:100',
        'description' => 'max:2000',
        'monthly_price' => 'required|numeric|min_value:0|max_value:9999999',
        'annual_price' => 'required|numeric|min_value:0|max_value:99999999',
        'trial_days' => 'integer|min_value:0|max_value:365',
        'max_users' => 'integer|min_value:0|max_value:1000000',
        'max_members' => 'integer|min_value:0|max_value:100000000',
        'storage_value' => 'numeric|min_value:0|max_value:10000000',
        'storage_unit' => 'required|in:MB,GB',
        'max_certificates' => 'integer|min_value:0|max_value:100000000',
        'status' => 'required|in:active,inactive',
    ];
    private const LABELS = [
        'monthly_price' => 'Monthly price', 'annual_price' => 'Annual price', 'trial_days' => 'Trial duration',
        'max_users' => 'Maximum NGO users', 'max_members' => 'Maximum members & volunteers',
        'storage_value' => 'Maximum storage', 'max_certificates' => 'Certificate allowance',
    ];

    public function index(): string
    {
        $q = $this->request->str('q');
        $status = $this->request->str('status');
        $where = ['1=1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(p.name LIKE :q OR p.description LIKE :q2)';
            $params += ['q' => "%$q%", 'q2' => "%$q%"];
        }
        if (in_array($status, ['active', 'inactive'], true)) {
            $where[] = 'p.status = :status';
            $params['status'] = $status;
        }
        $plans = Database::instance()->fetchAll(
            'SELECT p.*, (SELECT COUNT(*) FROM subscriptions s WHERE s.plan_id = p.id AND s.is_current = 1) AS tenant_count,
                    (SELECT GROUP_CONCAT(module_key ORDER BY module_key) FROM plan_modules pm WHERE pm.plan_id = p.id) AS modules
               FROM subscription_plans p WHERE ' . implode(' AND ', $where) . ' ORDER BY p.status, p.monthly_price',
            $params
        );
        return $this->view('platform/plans/index', ['title' => 'Subscription plans', 'plans' => $plans, 'q' => $q, 'status' => $status]);
    }

    public function create(): string
    {
        return $this->view('platform/plans/form', ['title' => 'Create plan', 'plan' => null, 'planModules' => []]);
    }

    public function store(): Response
    {
        $data = $this->validate(self::RULES, 'admin/plans/create', self::LABELS);
        $row = $this->rowFromInput($data);
        $db = Database::instance();
        if ($db->fetchColumn('SELECT id FROM subscription_plans WHERE name = ? OR slug = ?', [$row['name'], $row['slug']])) {
            $this->failValidation(['name' => 'A plan with this name already exists.'], 'admin/plans/create');
        }
        $modules = $this->modulesFromInput();
        $id = $db->transaction(function (Database $db) use ($row, $modules) {
            $row['created_by'] = \App\Core\Auth::id();
            $id = $db->insert('subscription_plans', $row);
            foreach ($modules as $m) {
                $db->insert('plan_modules', ['plan_id' => $id, 'module_key' => $m]);
            }
            AuditLogger::log('plan.created', 'plan', $id, ['name' => $row['name'], 'modules' => $modules] + array_intersect_key($row, array_flip(['monthly_price', 'annual_price', 'max_users', 'max_members', 'max_storage_mb'])), null);
            return $id;
        });
        return $this->redirect('admin/plans/' . $id, 'success', 'Plan created.');
    }

    public function show(): string
    {
        $plan = $this->find();
        $db = Database::instance();
        return $this->view('platform/plans/show', [
            'title' => $plan['name'],
            'plan' => $plan,
            'planModules' => $this->planModules((int) $plan['id']),
            'tenants' => $db->fetchAll(
                'SELECT t.id, t.name, t.reference_code, s.status, s.billing_cycle, s.end_date FROM subscriptions s
                   JOIN tenants t ON t.id = s.tenant_id WHERE s.plan_id = ? AND s.is_current = 1 ORDER BY t.name',
                [(int) $plan['id']]
            ),
        ]);
    }

    public function edit(): string
    {
        $plan = $this->find();
        return $this->view('platform/plans/form', ['title' => 'Edit ' . $plan['name'], 'plan' => $plan, 'planModules' => $this->planModules((int) $plan['id'])]);
    }

    public function update(): Response
    {
        $plan = $this->find();
        $id = (int) $plan['id'];
        $data = $this->validate(self::RULES, 'admin/plans/' . $id . '/edit', self::LABELS);
        $row = $this->rowFromInput($data);
        $db = Database::instance();
        if ($db->fetchColumn('SELECT id FROM subscription_plans WHERE (name = ? OR slug = ?) AND id <> ?', [$row['name'], $row['slug'], $id])) {
            $this->failValidation(['name' => 'A plan with this name already exists.'], 'admin/plans/' . $id . '/edit');
        }
        $modules = $this->modulesFromInput();
        $before = $plan;
        $beforeModules = $this->planModules($id);
        $db->transaction(function (Database $db) use ($id, $row, $modules, $before, $beforeModules) {
            $db->update('subscription_plans', $row, ['id' => $id]);
            $db->execute('DELETE FROM plan_modules WHERE plan_id = ?', [$id]);
            foreach ($modules as $m) {
                $db->insert('plan_modules', ['plan_id' => $id, 'module_key' => $m]);
            }
            $changes = [];
            foreach ($row as $k => $v) {
                if ((string) ($before[$k] ?? '') !== (string) ($v ?? '')) {
                    $changes[$k] = ['from' => $before[$k] ?? null, 'to' => $v];
                }
            }
            if ($modules !== $beforeModules) {
                $changes['modules'] = ['from' => $beforeModules, 'to' => $modules];
            }
            AuditLogger::log('plan.updated', 'plan', $id, ['changes' => $changes], null);
        });
        \App\Services\SubscriptionService::flushCache();
        return $this->redirect('admin/plans/' . $id, 'success', 'Plan updated. Limits apply immediately to NGOs on this plan; existing data is never removed.');
    }

    public function toggle(): Response
    {
        $plan = $this->find();
        $new = $plan['status'] === 'active' ? 'inactive' : 'active';
        Database::instance()->update('subscription_plans', ['status' => $new], ['id' => (int) $plan['id']]);
        AuditLogger::log($new === 'active' ? 'plan.activated' : 'plan.deactivated', 'plan', $plan['id'], ['name' => $plan['name']], null);
        return $this->redirect('admin/plans/' . $plan['id'], 'success', $new === 'active'
            ? 'Plan activated. It can be assigned to NGOs.'
            : 'Plan deactivated. It can no longer be assigned; NGOs already on it keep their subscription.');
    }

    private function find(): array
    {
        return Database::instance()->fetch('SELECT * FROM subscription_plans WHERE id = ?', [(int) $this->request->param('id')]) ?? $this->notFound('plan');
    }

    private function planModules(int $planId): array
    {
        $m = array_column(Database::instance()->fetchAll('SELECT module_key FROM plan_modules WHERE plan_id = ? ORDER BY module_key', [$planId]), 'module_key');
        sort($m);
        return $m;
    }

    private function modulesFromInput(): array
    {
        $known = array_keys(array_filter((array) config('modules'), static fn ($m) => !$m['core']));
        $mods = array_values(array_intersect($known, array_map('strval', $this->request->arr('modules'))));
        sort($mods);
        return $mods;
    }

    private function rowFromInput(array $d): array
    {
        $nullableInt = static fn ($v) => $v === '' ? null : (int) $v;
        $storage = $d['storage_value'] === '' ? null : (int) round((float) $d['storage_value'] * ($d['storage_unit'] === 'GB' ? 1024 : 1));
        return [
            'name' => $d['name'],
            'slug' => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($d['name'])), '-') ?: 'plan',
            'description' => $d['description'] ?: null,
            'monthly_price' => number_format((float) $d['monthly_price'], 2, '.', ''),
            'annual_price' => number_format((float) $d['annual_price'], 2, '.', ''),
            'currency' => (string) config('app.currency', 'INR'),
            'trial_days' => $nullableInt($d['trial_days']),
            'max_users' => $nullableInt($d['max_users']),
            'max_members' => $nullableInt($d['max_members']),
            'max_storage_mb' => $storage,
            'max_certificates' => $nullableInt($d['max_certificates']),
            'status' => $d['status'],
        ];
    }
}
