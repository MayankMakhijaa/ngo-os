<?php
declare(strict_types=1);

namespace App\Controllers\Ngo;

use App\Core\Paginator;
use App\Core\Response;
use App\Exceptions\DomainException;
use App\Exceptions\LimitExceededException;
use App\Services\AuditLogger;
use App\Services\CertificateService;
use App\Services\CertificateTemplateService;
use App\Services\PeopleService;
use App\Services\UsageService;

/**
 * Certificates issued from templates that Fameweb assigned to this NGO. Only people of a type
 * whose module is on the plan can receive one; everything is scoped to the current tenant.
 */
final class CertificateController extends NgoController
{
    /** person_type values of the types enabled on the plan. */
    private function enabledTypes(): array
    {
        return array_column(PeopleService::enabledTypes($this->tenantId()), 'type');
    }

    private function cert(): array
    {
        $cert = CertificateService::find($this->tenantId(), (int) $this->request->param('id'));
        if (!$cert || !in_array($cert['person_type'], $this->enabledTypes(), true)) {
            $this->notFound('certificate');
        }
        return $cert;
    }

    public function index(): string
    {
        $tid = $this->tenantId();
        $types = $this->enabledTypes();
        $f = ['q' => $this->request->str('q'), 'status' => $this->request->str('status')];
        $where = ['c.tenant_id = :tid'];
        $params = ['tid' => $tid];
        $in = [];
        foreach ($types ?: ['none'] as $i => $t) {
            $in[] = ':t' . $i;
            $params['t' . $i] = $t;
        }
        $where[] = 'p.person_type IN (' . implode(',', $in) . ')';
        if ($f['q'] !== '') {
            $where[] = '(p.name LIKE :q OR c.certificate_no LIKE :q2 OR c.title LIKE :q3 OR p.member_code LIKE :q4)';
            $params += ['q' => "%{$f['q']}%", 'q2' => "%{$f['q']}%", 'q3' => "%{$f['q']}%", 'q4' => "%{$f['q']}%"];
        }
        if (in_array($f['status'], ['issued', 'revoked'], true)) {
            $where[] = 'c.status = :status';
            $params['status'] = $f['status'];
        }
        $from = ' FROM certificates c JOIN people p ON p.id = c.person_id WHERE ' . implode(' AND ', $where);
        return $this->view('ngo/certificates/index', [
            'title' => 'Certificates',
            'page' => new Paginator(
                'SELECT c.id, c.certificate_no, c.title, c.issued_on, c.status, p.id AS person_id, p.name AS person_name, p.member_code, p.person_type' . $from . ' ORDER BY c.id DESC',
                'SELECT COUNT(*)' . $from, $params, $this->request->int('page', 1)
            ),
            'f' => $f,
            'templates' => CertificateTemplateService::forTenant($tid),
            'usage' => UsageService::summary($tid)['certificates'],
        ]);
    }

    public function create(): string
    {
        $tid = $this->tenantId();
        $types = $this->enabledTypes();
        $people = $types ? \App\Core\Database::instance()->fetchAll(
            "SELECT id, name, member_code, person_type FROM people WHERE tenant_id = ? AND status IN ('active','completed','inactive')
                AND person_type IN (" . implode(',', array_fill(0, count($types), '?')) . ') ORDER BY name LIMIT 2000',
            array_merge([$tid], $types)
        ) : [];
        return $this->view('ngo/certificates/create', [
            'title' => 'Issue certificate',
            'templates' => CertificateTemplateService::forTenant($tid),
            'people' => $people,
            'personId' => $this->request->int('person_id'),
            'templateId' => $this->request->int('template_id'),
            'usage' => UsageService::summary($tid)['certificates'],
        ]);
    }

    public function store(): Response
    {
        $tid = $this->tenantId();
        $back = 'ngo/certificates/create?person_id=' . $this->request->int('person_id') . '&template_id=' . $this->request->int('template_id');
        $person = PeopleService::find($tid, $this->request->int('person_id'));
        if (!$person || !in_array($person['person_type'], $this->enabledTypes(), true)) {
            $this->failValidation(['person_id' => 'Choose a person.'], $back, 'Choose who receives the certificate.');
        }
        $template = CertificateTemplateService::findForTenant($tid, $this->request->int('template_id'));
        if (!$template) {
            $this->failValidation(['template_id' => 'Choose a template.'], $back, 'Choose one of the templates assigned to your organization.');
        }
        try {
            $id = CertificateService::issue($this->user(), $person, $template, $this->request->only(
                ['title', 'description', 'event', 'start_date', 'end_date', 'issued_on', 'signatory_name', 'signatory_designation']
            ));
        } catch (DomainException|LimitExceededException $e) {
            $this->failValidation([], $back, $e->getMessage());
        }
        $cert = CertificateService::find($tid, $id);
        return $this->redirect('ngo/certificates', 'success', 'Certificate ' . $cert['certificate_no'] . ' issued to ' . $person['name'] . '.');
    }

    public function pdf(): Response
    {
        $cert = $this->cert();
        AuditLogger::log('certificate.downloaded', 'certificate', $cert['id'], ['certificate_no' => $cert['certificate_no']]);
        return Response::pdf($cert['certificate_no'] . '.pdf', CertificateService::pdf($this->tenantId(), $cert));
    }

    public function revoke(): Response
    {
        $cert = $this->cert();
        try {
            CertificateService::revoke($this->user(), $cert, mb_substr($this->request->str('reason'), 0, 255));
        } catch (DomainException $e) {
            return $this->redirect('ngo/certificates', 'error', $e->getMessage());
        }
        return $this->redirect('ngo/certificates', 'success', 'Certificate ' . $cert['certificate_no'] . ' revoked. Its PDF now carries a REVOKED mark.');
    }

    public function email(): Response
    {
        $cert = $this->cert();
        try {
            $ok = CertificateService::email($this->user(), $cert);
        } catch (DomainException $e) {
            return $this->redirect('ngo/certificates', 'error', $e->getMessage());
        }
        return $this->redirect('ngo/certificates', $ok ? 'success' : 'warning', $ok
            ? 'Certificate emailed to ' . $cert['person_email'] . '.'
            : 'The email could not be sent right away. It will be retried automatically; see the email log.');
    }

    /** Sample PDF of an assigned template with this NGO's name and logo. */
    public function preview(): Response
    {
        $template = CertificateTemplateService::findForTenant($this->tenantId(), (int) $this->request->param('id')) ?? $this->notFound('template');
        return Response::pdf('template-preview.pdf', CertificateService::preview($template, $this->tenantId()), true);
    }
}
