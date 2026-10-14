<?php
declare(strict_types=1);

namespace App\Controllers\Ngo;

use App\Core\Paginator;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AuditLogger;

/**
 * The NGO's own audit log. Always filtered by the request's tenant; Fameweb staff actions on
 * the NGO are included (actor shown as "Fameweb") so the NGO can see who accessed its data.
 */
final class AuditController extends NgoController
{
    private function query(): array
    {
        $where = ['a.tenant_id = :tid'];
        $params = ['tid' => $this->tenantId()];
        $f = ['action' => $this->request->str('action'), 'user_id' => $this->request->int('user_id'),
              'from' => $this->request->str('from'), 'to' => $this->request->str('to')];
        if ($f['action'] !== '') {
            $where[] = 'a.action LIKE :action';
            $params['action'] = $f['action'] . '%';
        }
        if ($f['user_id'] > 0) {
            $where[] = 'a.actor_user_id = :uid';
            $params['uid'] = $f['user_id'];
        }
        if (Validator::isDate($f['from'])) {
            $where[] = 'a.created_at >= :from';
            $params['from'] = $f['from'] . ' 00:00:00';
        }
        if (Validator::isDate($f['to'])) {
            $where[] = 'a.created_at <= :to';
            $params['to'] = $f['to'] . ' 23:59:59';
        }
        $from = ' FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id WHERE ' . implode(' AND ', $where);
        return [$f, $from, $params];
    }

    public function index(): string
    {
        [$f, $from, $params] = $this->query();
        $page = new Paginator(
            "SELECT a.id, a.action, a.actor_type, a.actor_role, a.entity_type, a.entity_id, a.ip, a.metadata, a.created_at,
                    CASE WHEN a.actor_type = 'platform' THEN 'Fameweb staff' ELSE u.name END AS actor_name" . $from . ' ORDER BY a.id DESC',
            'SELECT COUNT(*)' . $from,
            $params,
            $this->request->int('page', 1),
            30
        );
        return $this->view('ngo/audit', [
            'title' => 'Audit log',
            'page' => $page,
            'f' => $f,
            'users' => \App\Core\Database::instance()->fetchAll('SELECT id, name FROM users WHERE tenant_id = ? ORDER BY name', [$this->tenantId()]),
        ]);
    }

    public function export(): Response
    {
        [$f, $from, $params] = $this->query();
        $rows = \App\Core\Database::instance()->fetchAll(
            "SELECT a.id, a.created_at, a.action, CASE WHEN a.actor_type = 'platform' THEN 'Fameweb staff' ELSE u.email END,
                    a.actor_role, a.entity_type, a.entity_id, a.ip, a.metadata" . $from . ' ORDER BY a.id DESC LIMIT 50000',
            $params
        );
        AuditLogger::log('audit.exported', 'audit_log', null, ['rows' => count($rows)]);
        return Response::csv('audit-log-' . date('Ymd') . '.csv', ['ID', 'Time', 'Action', 'Actor', 'Role', 'Entity', 'Entity ID', 'IP', 'Details'],
            array_map('array_values', $rows));
    }
}
