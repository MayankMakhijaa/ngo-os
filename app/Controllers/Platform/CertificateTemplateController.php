<?php
declare(strict_types=1);

namespace App\Controllers\Platform;

use App\Core\Database;
use App\Core\Response;
use App\Exceptions\DomainException;
use App\Services\AuditLogger;
use App\Services\CertificateService;
use App\Services\CertificateTemplateService;

/**
 * Fameweb's certificate builder: templates are designed here (positioned text with
 * {{placeholders}}, logo, lines and boxes) and assigned to specific NGOs, which can then issue
 * certificates from them.
 */
final class CertificateTemplateController extends PlatformController
{
    private function template(): array
    {
        return CertificateTemplateService::find((int) $this->request->param('id')) ?? $this->notFound('template');
    }

    private function tenants(): array
    {
        return Database::instance()->fetchAll("SELECT id, name, reference_code, status FROM tenants ORDER BY name");
    }

    public function index(): string
    {
        return $this->view('platform/certificate_templates/index', [
            'title' => 'Certificate templates',
            'templates' => CertificateTemplateService::all(),
            'presets' => CertificateTemplateService::presets(),
        ]);
    }

    public function create(): string
    {
        $presets = CertificateTemplateService::presets();
        $preset = $presets[$this->request->str('preset')] ?? $presets['appreciation'];
        return $this->view('platform/certificate_templates/form', [
            'title' => 'New certificate template',
            'template' => null,
            'name' => $this->request->str('preset') !== '' ? $preset['name'] : '',
            'orientation' => $preset['orientation'],
            'layout' => CertificateTemplateService::normalize($preset['layout'], $preset['orientation']),
            'tenants' => $this->tenants(),
            'assigned' => [],
            'builder' => true,
        ]);
    }

    public function store(): Response
    {
        try {
            $id = CertificateTemplateService::save($this->user(), null, $this->meta(), CertificateTemplateService::layoutFromForm($this->request->body));
            $template = CertificateTemplateService::find($id);
            CertificateTemplateService::assign($this->user(), $template, $this->request->arr('tenant_ids'));
        } catch (DomainException $e) {
            $this->failValidation([], 'admin/certificate-templates/create', $e->getMessage());
        }
        return $this->redirect('admin/certificate-templates/' . $id . '/edit', 'success', 'Template created.');
    }

    public function edit(): string
    {
        $t = $this->template();
        return $this->view('platform/certificate_templates/form', [
            'title' => 'Edit ' . $t['name'],
            'template' => $t,
            'name' => $t['name'],
            'orientation' => $t['orientation'],
            'layout' => CertificateTemplateService::normalize(json_decode($t['layout'], true) ?: [], $t['orientation']),
            'tenants' => $this->tenants(),
            'assigned' => CertificateTemplateService::assignedTenantIds((int) $t['id']),
            'builder' => true,
        ]);
    }

    public function update(): Response
    {
        $t = $this->template();
        try {
            CertificateTemplateService::save($this->user(), $t, $this->meta(), CertificateTemplateService::layoutFromForm($this->request->body));
            CertificateTemplateService::assign($this->user(), $t, $this->request->arr('tenant_ids'));
        } catch (DomainException $e) {
            $this->failValidation([], 'admin/certificate-templates/' . $t['id'] . '/edit', $e->getMessage());
        }
        return $this->redirect('admin/certificate-templates/' . $t['id'] . '/edit', 'success', 'Template saved. Certificates already issued keep their original design.');
    }

    public function toggle(): Response
    {
        $t = $this->template();
        $to = $t['status'] === 'active' ? 'inactive' : 'active';
        Database::instance()->update('certificate_templates', ['status' => $to], ['id' => (int) $t['id']]);
        AuditLogger::log('certificate_template.' . ($to === 'active' ? 'activated' : 'deactivated'), 'certificate_template', $t['id'], ['name' => $t['name']]);
        return $this->redirect('admin/certificate-templates', 'success', $to === 'active' ? 'Template activated.' : 'Template deactivated. Assigned NGOs can no longer issue from it.');
    }

    public function preview(): Response
    {
        $t = $this->template();
        $tenantId = $this->request->int('tenant_id') ?: null;
        if ($tenantId !== null && !Database::instance()->fetchColumn('SELECT id FROM tenants WHERE id = ?', [$tenantId])) {
            $tenantId = null;
        }
        return Response::pdf('template-' . $t['id'] . '-preview.pdf', CertificateService::preview($t, $tenantId), true);
    }

    private function meta(): array
    {
        return [
            'name' => $this->request->str('name'),
            'description' => $this->request->str('description'),
            'orientation' => $this->request->str('orientation'),
            'status' => $this->request->str('status') ?: 'active',
        ];
    }
}
