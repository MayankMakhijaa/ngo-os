<?php
declare(strict_types=1);

namespace App\Controllers\Ngo;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Response;
use App\Core\Validator;
use App\Exceptions\DomainException;
use App\Exceptions\LimitExceededException;
use App\Pdf\Branding;
use App\Pdf\IdCardPdf;
use App\Services\AuditLogger;
use App\Services\CategoryService;
use App\Services\CertificateService;
use App\Services\CertificateTemplateService;
use App\Services\PeopleService;
use App\Services\PermissionService;
use App\Services\PersonDocumentService;
use App\Services\SalarySlipService;
use App\Services\StorageService;
use App\Services\SubscriptionService;
use App\Services\UsageService;

/**
 * Members, volunteers and interns: /ngo/people/{type}/... where type is members, volunteers or
 * interns. Each type is only reachable when its module is on the NGO's plan; every person is
 * loaded through the current tenant, so other NGOs' records answer 404.
 */
final class PeopleController extends NgoController
{
    private const RULES = [
        'name' => 'required|max:120', 'guardian_name' => 'max:120', 'gender' => 'in:female,male,other,undisclosed',
        'date_of_birth' => 'date', 'blood_group' => 'in:A+,A-,B+,B-,AB+,AB-,O+,O-', 'email' => 'email', 'phone' => 'phone|max:30',
        'address' => 'max:1000', 'city' => 'max:80', 'state' => 'max:80', 'pincode' => 'regex:/^\d{6}$/',
        'emergency_contact' => 'max:120', 'category_id' => 'integer', 'designation' => 'max:120', 'department' => 'max:120',
        'joined_on' => 'date', 'ends_on' => 'date', 'monthly_amount' => 'numeric|min_value:0|max_value:9999999',
        'bank_name' => 'max:120', 'bank_account' => 'regex:/^[0-9 ]{6,24}$/', 'bank_ifsc' => 'regex:/^[A-Za-z]{4}0[A-Za-z0-9]{6}$/',
        'aadhaar_number' => 'regex:/^[0-9 \-]{12,14}$/', 'notes' => 'max:2000',
    ];
    private const LABELS = ['guardian_name' => "Father's / guardian's name", 'date_of_birth' => 'Date of birth', 'bank_ifsc' => 'IFSC',
        'aadhaar_number' => 'Aadhaar number', 'monthly_amount' => 'Monthly salary / stipend', 'category_id' => 'Category', 'pincode' => 'PIN code'];

    /** The person type from the URL, refused when unknown or not on the plan. */
    private function type(): array
    {
        $type = PeopleService::typeBySlug($this->request->param('type')) ?? $this->notFound('page');
        if (!in_array($type['module'], SubscriptionService::enabledModules($this->tenantId()), true)) {
            throw new HttpException(403, 'The ' . $type['label'] . ' module is not included in your organization\'s subscription plan.');
        }
        return $type;
    }

    private function person(array $type): array
    {
        $id = (int) $this->request->param('id');
        $p = PeopleService::find($this->tenantId(), $id);
        if ($p === null || $p['person_type'] !== $type['type']) {
            AuditLogger::log('authz.denied', 'person', $id, ['reason' => 'not_in_tenant', 'path' => $this->request->path]);
            $this->notFound('record');
        }
        return $p;
    }

    private function base(array $type, ?array $p = null): string
    {
        return 'ngo/people/' . $type['slug'] . ($p ? '/' . $p['id'] : '');
    }

    public function index(): string
    {
        $type = $this->type();
        $tid = $this->tenantId();
        $f = [
            'q' => $this->request->str('q'),
            'status' => $this->request->str('status'),
            'category' => $this->request->int('category'),
            'verification' => $this->request->str('verification'),
        ];
        $where = ['p.tenant_id = :tid', 'p.person_type = :type'];
        $params = ['tid' => $tid, 'type' => $type['type']];
        if ($f['q'] !== '') {
            $where[] = '(p.name LIKE :q OR p.member_code LIKE :q2 OR p.phone LIKE :q3 OR p.email LIKE :q4)';
            $params += ['q' => "%{$f['q']}%", 'q2' => "%{$f['q']}%", 'q3' => "%{$f['q']}%", 'q4' => "%{$f['q']}%"];
        }
        if (isset(PeopleService::STATUSES[$f['status']])) {
            $where[] = 'p.status = :status';
            $params['status'] = $f['status'];
        }
        if ($f['category'] > 0) {
            $where[] = 'p.category_id = :cat';
            $params['cat'] = $f['category'];
        }
        if (in_array($f['verification'], ['not_submitted', 'pending', 'verified', 'rejected'], true)) {
            $where[] = 'p.verification_status = :ver';
            $params['ver'] = $f['verification'];
        }
        $from = ' FROM people p LEFT JOIN person_categories c ON c.id = p.category_id WHERE ' . implode(' AND ', $where);
        $page = new Paginator(
            'SELECT p.id, p.member_code, p.name, p.phone, p.email, p.status, p.verification_status, p.joined_on, c.name AS category_name' . $from . ' ORDER BY p.id DESC',
            'SELECT COUNT(*)' . $from, $params, $this->request->int('page', 1)
        );
        return $this->view('ngo/people/index', [
            'title' => $type['label'],
            'type' => $type,
            'page' => $page,
            'f' => $f,
            'categories' => CategoryService::listForTenant($tid, $type['type']),
            'usage' => UsageService::summary($tid)['members'],
            'counts' => array_column(Database::instance()->fetchAll(
                'SELECT status, COUNT(*) AS n FROM people WHERE tenant_id = ? AND person_type = ? GROUP BY status', [$tid, $type['type']]
            ), 'n', 'status'),
        ]);
    }

    public function create(): string
    {
        $type = $this->type();
        return $this->view('ngo/people/form', [
            'title' => 'Register ' . strtolower($type['singular']),
            'type' => $type,
            'p' => null,
            'categories' => CategoryService::listForTenant($this->tenantId(), $type['type']),
            'usage' => UsageService::summary($this->tenantId())['members'],
            'nextCode' => PeopleService::prefix($this->tenantId(), $type['type']) . '-#####',
        ]);
    }

    public function store(): Response
    {
        $type = $this->type();
        $back = $this->base($type) . '/create';
        $data = $this->validate(self::RULES + ['status' => 'required|in:pending,active'], $back, self::LABELS);
        try {
            $id = PeopleService::create($this->user(), $type['type'], $data);
        } catch (LimitExceededException|DomainException $e) {
            $this->failValidation([], $back, $e->getMessage());
        }
        $p = PeopleService::find($this->tenantId(), $id);
        return $this->redirect($this->base($type) . '/' . $id, 'success', $type['singular'] . ' registered with ID ' . $p['member_code'] . '.');
    }

    public function show(): string
    {
        $type = $this->type();
        $p = $this->person($type);
        $tid = $this->tenantId();
        $canDocs = can('people.documents');
        return $this->view('ngo/people/show', [
            'title' => $p['name'],
            'type' => $type,
            'p' => $p,
            'documents' => $canDocs ? PersonDocumentService::listFor($tid, (int) $p['id']) : [],
            'history' => PeopleService::historyFor($tid, (int) $p['id']),
            'slips' => can('salary.view') ? SalarySlipService::listFor($tid, (int) $p['id']) : [],
            'certificates' => can('certificates.view') ? Database::instance()->fetchAll(
                'SELECT * FROM certificates WHERE tenant_id = ? AND person_id = ? ORDER BY id DESC', [$tid, (int) $p['id']]
            ) : [],
            'templates' => can('certificates.issue') ? CertificateTemplateService::forTenant($tid) : [],
            'modules' => SubscriptionService::enabledModules($tid),
        ]);
    }

    public function edit(): string
    {
        $type = $this->type();
        $p = $this->person($type);
        return $this->view('ngo/people/form', [
            'title' => 'Edit ' . $p['name'],
            'type' => $type,
            'p' => $p,
            'categories' => CategoryService::listForTenant($this->tenantId(), $type['type']),
            'usage' => null,
            'nextCode' => $p['member_code'],
        ]);
    }

    public function update(): Response
    {
        $type = $this->type();
        $p = $this->person($type);
        $back = $this->base($type, $p) . '/edit';
        $data = $this->validate(self::RULES, $back, self::LABELS);
        if ($data['bank_account'] === '') {
            unset($data['bank_account']); // keep the stored last 4 digits
        }
        try {
            PeopleService::update($this->user(), $p, $data);
        } catch (DomainException $e) {
            $this->failValidation([], $back, $e->getMessage());
        }
        return $this->redirect($this->base($type, $p), 'success', 'Details saved.');
    }

    public function status(): Response
    {
        $type = $this->type();
        $p = $this->person($type);
        try {
            PeopleService::setStatus($this->user(), $p, $this->request->str('status'), mb_substr($this->request->str('note'), 0, 200));
        } catch (DomainException|LimitExceededException $e) {
            return $this->redirect($this->base($type, $p), 'error', $e->getMessage());
        }
        return $this->redirect($this->base($type, $p), 'success', 'Status updated.');
    }

    public function photo(): Response
    {
        $type = $this->type();
        $p = $this->person($type);
        $file = $this->request->file('photo');
        if (!$file) {
            return $this->redirect($this->base($type, $p), 'error', 'Choose a photo to upload.');
        }
        try {
            PeopleService::setPhoto($this->user(), $p, $file);
        } catch (DomainException|LimitExceededException $e) {
            return $this->redirect($this->base($type, $p), 'error', $e->getMessage());
        }
        return $this->redirect($this->base($type, $p), 'success', 'Photo updated.');
    }

    public function showPhoto(): Response
    {
        $type = $this->type();
        $p = $this->person($type);
        $obj = StorageService::find($p['photo_object_id'] ? (int) $p['photo_object_id'] : null, $this->tenantId()) ?? $this->notFound('photo');
        return StorageService::stream($obj);
    }

    public function uploadDocument(): Response
    {
        $type = $this->type();
        $p = $this->person($type);
        $file = $this->request->file('document');
        if (!$file) {
            return $this->redirect($this->base($type, $p) . '#documents', 'error', 'Choose a file to upload.');
        }
        try {
            PersonDocumentService::upload($this->user(), $p, $file, $this->request->str('doc_type'), $this->request->str('title'));
        } catch (DomainException|LimitExceededException $e) {
            return $this->redirect($this->base($type, $p) . '#documents', 'error', $e->getMessage());
        }
        return $this->redirect($this->base($type, $p) . '#documents', 'success', 'Document uploaded and stored encrypted.');
    }

    public function document(): Response
    {
        $type = $this->type();
        $p = $this->person($type);
        $doc = PersonDocumentService::find($this->tenantId(), (int) $p['id'], (int) $this->request->param('doc')) ?? $this->notFound('document');
        AuditLogger::log('person.document_viewed', 'person', $p['id'], ['doc_type' => $doc['doc_type']]);
        return StorageService::stream($doc, false);
    }

    public function deleteDocument(): Response
    {
        $type = $this->type();
        $p = $this->person($type);
        $doc = PersonDocumentService::find($this->tenantId(), (int) $p['id'], (int) $this->request->param('doc')) ?? $this->notFound('document');
        PersonDocumentService::delete($this->user(), $p, $doc);
        return $this->redirect($this->base($type, $p) . '#documents', 'success', 'Document deleted. The storage it used is freed.');
    }

    public function verify(): Response
    {
        $type = $this->type();
        $p = $this->person($type);
        try {
            PeopleService::verify($this->user(), $p, $this->request->str('decision'), mb_substr($this->request->str('note'), 0, 250));
        } catch (DomainException $e) {
            return $this->redirect($this->base($type, $p) . '#documents', 'error', $e->getMessage());
        }
        return $this->redirect($this->base($type, $p) . '#documents', 'success', 'Verification recorded.');
    }

    public function idCard(): Response
    {
        $type = $this->type();
        $p = $this->person($type);
        if (!in_array($p['status'], ['active', 'completed'], true)) {
            return $this->redirect($this->base($type, $p), 'error', 'ID cards can only be generated for active people.');
        }
        $tid = $this->tenantId();
        $ngo = Branding::forTenant($tid);
        $photo = null;
        if ($obj = StorageService::find($p['photo_object_id'] ? (int) $p['photo_object_id'] : null, $tid)) {
            $photo = StorageService::contents($obj);
        }
        $pdf = IdCardPdf::render($ngo, Branding::logoBytes($tid, $ngo['logo_object_id'] ? (int) $ngo['logo_object_id'] : null), $p, $photo);
        PeopleService::history($tid, (int) $p['id'], 'id_card', 'ID card downloaded', (int) $this->user()['id']);
        AuditLogger::log('person.id_card', 'person', $p['id'], []);
        return Response::pdf('id-card-' . $p['member_code'] . '.pdf', $pdf);
    }

    public function export(): Response
    {
        $type = $this->type();
        $rows = Database::instance()->fetchAll(
            'SELECT p.member_code, p.name, p.guardian_name, p.gender, p.date_of_birth, p.email, p.phone, p.address, p.city, p.state, p.pincode,
                    c.name AS category, p.designation, p.department, p.status, p.joined_on, p.ends_on, p.verification_status,
                    IF(p.aadhaar_last4 IS NULL, \'\', CONCAT(\'XXXX XXXX \', p.aadhaar_last4)) AS aadhaar
               FROM people p LEFT JOIN person_categories c ON c.id = p.category_id
              WHERE p.tenant_id = ? AND p.person_type = ? ORDER BY p.member_code',
            [$this->tenantId(), $type['type']]
        );
        AuditLogger::log('people.exported', 'person', null, ['type' => $type['type'], 'rows' => count($rows)]);
        return Response::csv($type['slug'] . '-' . date('Ymd') . '.csv', ['ID', 'Name', 'Guardian', 'Gender', 'Date of birth', 'Email', 'Phone', 'Address', 'City',
            'State', 'PIN', 'Category', 'Designation', 'Department', 'Status', 'Joined', 'Ends', 'Verification', 'Aadhaar (masked)'], array_map('array_values', $rows));
    }

    // ------------------------------------------------------------------ salary slips

    public function slipForm(): string
    {
        $type = $this->type();
        $p = $this->person($type);
        return $this->view('ngo/people/slip_form', [
            'title' => 'New slip for ' . $p['name'],
            'type' => $type,
            'p' => $p,
            'last' => SalarySlipService::listFor($this->tenantId(), (int) $p['id'])[0] ?? null,
        ]);
    }

    public function storeSlip(): Response
    {
        $type = $this->type();
        $p = $this->person($type);
        $lines = function (string $prefix): array {
            $labels = $this->request->arr($prefix . '_label');
            $amounts = $this->request->arr($prefix . '_amount');
            $out = [];
            foreach ($labels as $i => $label) {
                $out[] = ['label' => is_string($label) ? $label : '', 'amount' => is_string($amounts[$i] ?? null) ? $amounts[$i] : ''];
            }
            return $out;
        };
        $back = $this->base($type, $p) . '/salary-slips/create';
        try {
            $id = SalarySlipService::create($this->user(), $p, [
                'period_month' => $this->request->str('period_month'),
                'pay_type' => $this->request->str('pay_type'),
                'earnings' => $lines('earning'),
                'deductions' => $lines('deduction'),
                'working_days' => $this->request->str('working_days'),
                'paid_days' => $this->request->str('paid_days'),
                'payment_date' => $this->request->str('payment_date'),
                'payment_mode' => $this->request->str('payment_mode'),
                'notes' => $this->request->str('notes'),
            ]);
        } catch (DomainException $e) {
            $this->failValidation([], $back, $e->getMessage());
        }
        return $this->redirect($this->base($type, $p) . '#slips', 'success', 'Slip created. Download it from the list below.');
    }

    public function slipPdf(): Response
    {
        $slip = SalarySlipService::find($this->tenantId(), (int) $this->request->param('id')) ?? $this->notFound('slip');
        $type = PeopleService::typeBySlug(PeopleService::slugForType($slip['person_type']));
        if (!in_array($type['module'], SubscriptionService::enabledModules($this->tenantId()), true)) {
            $this->notFound('slip');
        }
        AuditLogger::log('salary_slip.downloaded', 'salary_slip', $slip['id'], []);
        return Response::pdf($slip['slip_no'] . '.pdf', SalarySlipService::pdf($this->tenantId(), $slip));
    }

    /** All slips of the NGO for a month. */
    public function slips(): string
    {
        $tid = $this->tenantId();
        $month = $this->request->str('month');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $month = date('Y-m');
        }
        $types = array_column(PeopleService::enabledTypes($tid), 'type');
        $rows = $types ? Database::instance()->fetchAll(
            'SELECT s.*, p.name AS person_name, p.member_code, p.person_type FROM salary_slips s JOIN people p ON p.id = s.person_id
              WHERE s.tenant_id = ? AND s.period_month = ? AND p.person_type IN (' . implode(',', array_fill(0, count($types), '?')) . ') ORDER BY p.name',
            array_merge([$tid, $month], $types)
        ) : [];
        return $this->view('ngo/people/slips', ['title' => 'Salary & stipend slips', 'rows' => $rows, 'month' => $month]);
    }
}
