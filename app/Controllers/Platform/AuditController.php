<?php
declare(strict_types=1);

namespace App\Controllers\Platform;

use App\Core\Database;
use App\Core\Paginator;
use App\Core\Response;
use App\Services\AuditLogger;

/** Read-only audit log viewer. There is intentionally no edit or delete route. */
final class AuditController extends PlatformController
{
    private function filters(): array
    {
        $where = ['1=1'];
        $params = [];
        $f = [
            'q' => $this->request->str('q'),
            'action' => $this->request->str('action'),
            'tenant_id' => $this->request->int('tenant_id'),
            'actor_type' => $this->request->str('actor_type'),
            'from' => $this->request->str('from'),
            'to' => $this->request->str('to'),
        ];
        if ($f['action'] !== '') {
            $where[] = 'a.action LIKE :action';
            $params['action'] = $f['action'] . '%';
        }
        if ($f['tenant_id'] > 0) {
            $where[] = 'a.tenant_id = :tenant_id';
            $params['tenant_id'] = $f['tenant_id'];
        }
        if (in_array($f['actor_type'], ['platform', 'tenant', 'system', 'guest'], true)) {
            $where[] = 'a.actor_type = :actor_type';
            $params['actor_type'] = $f['actor_type'];
        }
        if ($f['q'] !== '') {
            $where[] = '(u.name LIKE :q OR u.email LIKE :q2 OR a.metadata LIKE :q3 OR a.ip LIKE :q4)';
            $params += ['q' => '%' . $f['q'] . '%', 'q2' => '%' . $f['q'] . '%', 'q3' => '%' . $f['q'] . '%', 'q4' => '%' . $f['q'] . '%'];
        }
        if (\App\Core\Validator::isDate($f['from'])) {
            $where[] = 'a.created_at >= :from';
            $params['from'] = $f['from'] . ' 00:00:00';
        }
        if (\App\Core\Validator::isDate($f['to'])) {
            $where[] = 'a.created_at <= :to';
            $params['to'] = $f['to'] . ' 23:59:59';
        }
        $from = ' FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id LEFT JOIN tenants t ON t.id = a.tenant_id WHERE ' . implode(' AND ', $where);
        return [$f, $from, $params];
    }

    public function index(): string
    {
        [$f, $from, $params] = $this->filters();
        $page = new Paginator(
            'SELECT a.*, u.name AS actor_name, u.email AS actor_email, t.name AS tenant_name' . $from . ' ORDER BY a.id DESC',
            'SELECT COUNT(*)' . $from,
            $params,
            $this->request->int('page', 1),
            30
        );
        return $this->view('platform/audit', [
            'title' => 'Audit log',
            'page' => $page,
            'f' => $f,
            'tenants' => Database::instance()->fetchAll('SELECT id, name FROM tenants ORDER BY name'),
            'actionList' => array_column(Database::instance()->fetchAll('SELECT DISTINCT action FROM audit_logs ORDER BY action'), 'action'),
        ]);
    }

    public function export(): Response
    {
        [$f, $from, $params] = $this->filters();
        $rows = Database::instance()->fetchAll(
            'SELECT a.id, a.created_at, a.action, a.actor_type, a.actor_role, u.email AS actor_email, a.tenant_id, t.name AS tenant_name,
                    a.entity_type, a.entity_id, a.ip, a.metadata' . $from . ' ORDER BY a.id DESC LIMIT 50000',
            $params
        );
        AuditLogger::log('audit.exported', 'audit_log', null, ['rows' => count($rows), 'filters' => array_filter($f)], null);
        return Response::csv('audit-log-' . date('Ymd-His') . '.csv',
            ['ID', 'Time', 'Action', 'Actor type', 'Actor role', 'Actor email', 'Tenant ID', 'NGO', 'Entity', 'Entity ID', 'IP', 'Details'],
            array_map('array_values', $rows));
    }

    public function verify(): Response
    {
        $broken = AuditLogger::verify();
        AuditLogger::log('audit.verified', 'audit_log', null, ['intact' => $broken === null, 'first_broken_id' => $broken], null);
        return $broken === null
            ? $this->redirect('admin/audit', 'success', 'Audit log integrity verified: the hash chain is intact.')
            : $this->redirect('admin/audit', 'error', 'Audit log integrity check FAILED at entry #' . $broken . '. Rows were changed or removed outside the application.');
    }
}
