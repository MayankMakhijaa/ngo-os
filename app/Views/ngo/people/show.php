<?php
/** @var array $type @var array $p @var array $documents @var array $history @var array $slips @var array $certificates @var array $templates @var array $modules */
use App\Services\PeopleService;
use App\Services\PersonDocumentService;

$writable = \App\Services\SubscriptionService::isWritable($subscription);
$base = 'ngo/people/' . $type['slug'] . '/' . $p['id'];
$heading = $p['name'];
$subtitle = $type['singular'] . ' · ' . $p['member_code'];
$crumbs = [$type['label'] => 'ngo/people/' . $type['slug']];
$actions = '';
if (can('people.id_cards') && in_array($p['status'], ['active', 'completed'], true)) {
    $actions .= '<a class="btn btn-outline-secondary" href="' . e(url($base . '/id-card')) . '"><i class="bi bi-person-badge me-1" aria-hidden="true"></i>ID card (PDF)</a>';
}
if (can('people.edit') && $writable) {
    $actions .= '<a class="btn btn-outline-secondary" href="' . e(url($base . '/edit')) . '"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit</a>';
}
include __DIR__ . '/../../partials/page_header.php';
$hasDocs = in_array('documents', $modules, true) && can('people.documents');
$hasCerts = in_array('certificates', $modules, true) && can('certificates.view');
?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card mb-3"><div class="card-body">
      <div class="d-flex align-items-center gap-3 mb-3">
        <?php if ($p['photo_object_id']): ?>
          <img src="<?= e(url($base . '/photo')) ?>" alt="Photo of <?= e($p['name']) ?>" class="person-photo">
        <?php else: ?>
          <div class="person-photo person-photo-empty" aria-hidden="true"><i class="bi bi-person"></i></div>
        <?php endif; ?>
        <div class="min-w-0">
          <div class="fw-semibold text-truncate"><?= e($p['name']) ?></div>
          <div class="font-monospace small"><?= e($p['member_code']) ?></div>
          <div class="mt-1"><?= status_badge($p['status']) ?> <?= verification_badge($p['verification_status']) ?></div>
        </div>
      </div>
      <?php if (can('people.edit') && $writable): ?>
      <form method="post" action="<?= e(url($base . '/photo')) ?>" enctype="multipart/form-data" class="mb-3" data-loading>
        <?= csrf_field() ?>
        <label class="form-label small" for="photo">Photo for the ID card</label>
        <div class="input-group input-group-sm">
          <input class="form-control" type="file" id="photo" name="photo" accept="image/png,image/jpeg,image/webp" required>
          <button class="btn btn-outline-primary" type="submit">Upload</button>
        </div>
        <div class="form-text">Passport-style photo, PNG/JPG/WebP up to 2 MB.</div>
      </form>
      <?php endif; ?>
      <dl class="row small mb-0">
        <dt class="col-5 text-body-secondary">Category</dt><dd class="col-7"><?= e($p['category_name'] ?? '—') ?></dd>
        <dt class="col-5 text-body-secondary"><?= $type['type'] === 'intern' ? 'Role / project' : 'Designation' ?></dt><dd class="col-7"><?= e($p['designation'] ?: '—') ?></dd>
        <dt class="col-5 text-body-secondary">Department</dt><dd class="col-7"><?= e($p['department'] ?: '—') ?></dd>
        <dt class="col-5 text-body-secondary">Joined</dt><dd class="col-7"><?= e(format_date($p['joined_on'])) ?></dd>
        <dt class="col-5 text-body-secondary"><?= $type['type'] === 'intern' ? 'Ends' : 'Valid until' ?></dt><dd class="col-7"><?= e(format_date($p['ends_on'])) ?></dd>
        <dt class="col-5 text-body-secondary">Phone</dt><dd class="col-7"><?= e($p['phone'] ?: '—') ?></dd>
        <dt class="col-5 text-body-secondary">Email</dt><dd class="col-7 text-break"><?= e($p['email'] ?: '—') ?></dd>
        <dt class="col-5 text-body-secondary">Gender</dt><dd class="col-7"><?= e(PeopleService::GENDERS[$p['gender']] ?? '—') ?></dd>
        <dt class="col-5 text-body-secondary">Date of birth</dt><dd class="col-7"><?= e(format_date($p['date_of_birth'])) ?></dd>
        <dt class="col-5 text-body-secondary">Blood group</dt><dd class="col-7"><?= e($p['blood_group'] ?: '—') ?></dd>
        <dt class="col-5 text-body-secondary">Guardian</dt><dd class="col-7"><?= e($p['guardian_name'] ?: '—') ?></dd>
        <dt class="col-5 text-body-secondary">Address</dt><dd class="col-7"><?= e(implode(', ', array_filter([$p['address'], $p['city'], $p['state'], $p['pincode']])) ?: '—') ?></dd>
        <dt class="col-5 text-body-secondary">Emergency</dt><dd class="col-7"><?= e($p['emergency_contact'] ?: '—') ?></dd>
        <dt class="col-5 text-body-secondary">Aadhaar</dt><dd class="col-7 font-monospace"><?= e($p['aadhaar_last4'] ? 'XXXX XXXX ' . $p['aadhaar_last4'] : '—') ?></dd>
        <dt class="col-5 text-body-secondary">Bank</dt><dd class="col-7"><?= e(implode(' · ', array_filter([$p['bank_name'], $p['bank_account_last4'] ? 'A/c XXXX' . $p['bank_account_last4'] : null, $p['bank_ifsc']])) ?: '—') ?></dd>
        <?php if ($p['monthly_amount'] !== null): ?><dt class="col-5 text-body-secondary">Monthly amount</dt><dd class="col-7"><?= e(format_money($p['monthly_amount'])) ?></dd><?php endif; ?>
      </dl>
      <?php if ($p['notes']): ?><hr><div class="small text-body-secondary">Notes</div><div class="small" style="white-space:pre-line"><?= e($p['notes']) ?></div><?php endif; ?>
    </div></div>

    <?php if (can('people.status') && $writable): ?>
    <form method="post" action="<?= e(url($base . '/status')) ?>" class="card mb-3" data-confirm="Change the status of <?= e($p['name']) ?>?">
      <div class="card-body">
        <?= csrf_field() ?>
        <h2 class="h6">Status</h2>
        <?php $opts = PeopleService::STATUSES; unset($opts[$p['status']]); ?>
        <?= form_select('status', 'Change to', $opts, $p['status'] === 'pending' ? 'active' : '', ['required' => true]) ?>
        <?= form_input('note', 'Note (optional)', '', 'text', ['maxlength' => 200, 'placeholder' => 'e.g. Internship completed']) ?>
        <button class="btn btn-sm btn-primary" type="submit">Update status</button>
      </div>
    </form>
    <?php endif; ?>
  </div>

  <div class="col-lg-8">
    <?php if ($hasDocs): ?>
    <div class="card mb-3" id="documents"><div class="card-body">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div><h2 class="h6 mb-0">Identity documents</h2><div class="small text-body-secondary">Stored encrypted; downloads are logged.</div></div>
        <div class="text-end"><?= verification_badge($p['verification_status']) ?>
          <?php if ($p['verified_at']): ?><div class="small text-body-secondary mt-1"><?= e(($p['verified_by_name'] ?? '') . ' · ' . format_date($p['verified_at'])) ?></div><?php endif; ?></div>
      </div>
      <?php if ($p['verification_status'] === 'rejected' && $p['verification_note']): ?><div class="alert alert-danger small py-2">Rejected: <?= e($p['verification_note']) ?></div><?php endif; ?>
      <?php if ($documents): ?>
      <div class="table-responsive"><table class="table table-sm align-middle">
        <thead><tr><th scope="col">Document</th><th scope="col">Size</th><th scope="col">Uploaded</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody><?php foreach ($documents as $d): ?>
          <tr><td><a href="<?= e(url($base . '/documents/' . $d['id'])) ?>" target="_blank" rel="noopener"><?= e(PersonDocumentService::TYPES[$d['doc_type']] ?? $d['doc_type']) ?></a>
              <?php if ($d['title']): ?><div class="small text-body-secondary"><?= e($d['title']) ?></div><?php endif; ?></td>
            <td class="small"><?= e(format_bytes((int) $d['size_bytes'])) ?></td>
            <td class="small"><?= e(format_date($d['created_at'])) ?><div class="text-body-secondary"><?= e($d['uploaded_by_name'] ?? '') ?></div></td>
            <td class="text-end"><?php if ($writable): ?>
              <form method="post" action="<?= e(url($base . '/documents/' . $d['id'] . '/delete')) ?>" data-confirm="Delete this document? The file is removed and its storage freed.">
                <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Delete document"><i class="bi bi-trash" aria-hidden="true"></i></button></form>
            <?php endif; ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <?php else: ?><p class="small text-body-secondary">No documents uploaded yet.</p><?php endif; ?>
      <?php if ($writable): ?>
      <form method="post" action="<?= e(url($base . '/documents')) ?>" enctype="multipart/form-data" class="row g-2 align-items-end" data-loading>
        <?= csrf_field() ?>
        <div class="col-sm-4"><?= form_select('doc_type', 'Type', PersonDocumentService::TYPES, 'aadhaar', ['wrap' => 'mb-0']) ?></div>
        <div class="col-sm-4"><?= form_input('title', 'Title (optional)', '', 'text', ['maxlength' => 120, 'wrap' => 'mb-0']) ?></div>
        <div class="col-sm-4"><label class="form-label" for="document">File</label><input class="form-control" type="file" id="document" name="document" accept="application/pdf,image/jpeg,image/png,image/webp" required></div>
        <div class="col-12 d-flex justify-content-between align-items-center"><span class="form-text m-0">PDF, JPG, PNG or WebP up to 5 MB. Counts towards storage.</span><button class="btn btn-sm btn-primary" type="submit">Upload</button></div>
      </form>
      <?php endif; ?>
      <?php if (can('people.verify') && $writable && $documents): ?>
      <hr>
      <form method="post" action="<?= e(url($base . '/verify')) ?>" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <div class="col-sm-3"><?= form_select('decision', 'Aadhaar check', ['verified' => 'Verified', 'rejected' => 'Rejected'], 'verified', ['wrap' => 'mb-0']) ?></div>
        <div class="col-sm-6"><?= form_input('note', 'Note (required when rejecting)', '', 'text', ['maxlength' => 250, 'wrap' => 'mb-0']) ?></div>
        <div class="col-sm-3"><button class="btn btn-outline-primary w-100" type="submit">Record check</button></div>
        <div class="col-12 form-text">Compare the uploaded card with the details above. Last 4 digits on file: <?= e($p['aadhaar_last4'] ?: 'none entered') ?>.</div>
      </form>
      <?php endif; ?>
    </div></div>
    <?php endif; ?>

    <?php if (can('salary.view')): ?>
    <div class="card mb-3" id="slips"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0"><?= $type['type'] === 'intern' ? 'Stipend slips' : 'Salary slips' ?></h2>
        <?php if (can('salary.manage') && $writable): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url($base . '/salary-slips/create')) ?>"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New slip</a><?php endif; ?>
      </div>
      <?php if ($slips): ?>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th scope="col">Month</th><th scope="col">Slip no.</th><th scope="col" class="text-end">Gross</th><th scope="col" class="text-end">Net pay</th><th scope="col"><span class="visually-hidden">Download</span></th></tr></thead>
        <tbody><?php foreach ($slips as $s): ?>
          <tr><td><?= e(date('M Y', strtotime($s['period_month'] . '-01'))) ?></td><td class="font-monospace small"><?= e($s['slip_no']) ?></td>
            <td class="text-end"><?= e(format_money($s['gross_amount'])) ?></td><td class="text-end fw-medium"><?= e(format_money($s['net_amount'])) ?></td>
            <td class="text-end"><a class="btn btn-sm btn-light" href="<?= e(url('ngo/salary-slips/' . $s['id'] . '/pdf')) ?>"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF</a></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <?php else: ?><p class="small text-body-secondary mb-0">No slips yet.</p><?php endif; ?>
    </div></div>
    <?php endif; ?>

    <?php if ($hasCerts): ?>
    <div class="card mb-3" id="certificates"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0">Certificates</h2>
        <?php if (can('certificates.issue') && $writable && $templates && $p['status'] !== 'pending'): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('ngo/certificates/create', ['person_id' => $p['id']])) ?>"><i class="bi bi-award me-1" aria-hidden="true"></i>Issue certificate</a><?php endif; ?>
      </div>
      <?php if ($certificates): ?>
      <ul class="list-group list-group-flush">
        <?php foreach ($certificates as $c): ?>
          <li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
            <div><div class="fw-medium"><?= e($c['title']) ?></div><div class="small text-body-secondary"><?= e($c['certificate_no'] . ' · ' . format_date($c['issued_on'])) ?></div></div>
            <div class="d-flex gap-2 align-items-center"><?= status_badge($c['status']) ?><a class="btn btn-sm btn-light" href="<?= e(url('ngo/certificates/' . $c['id'] . '/pdf')) ?>">PDF</a></div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?><p class="small text-body-secondary mb-0"><?= $templates ? 'No certificates issued yet.' : 'No certificate templates are assigned to your organization yet. Ask Fameweb to assign one.' ?></p><?php endif; ?>
    </div></div>
    <?php endif; ?>

    <div class="card" id="history"><div class="card-body">
      <h2 class="h6">History</h2>
      <?php if ($history): ?>
      <ul class="timeline list-unstyled mb-0">
        <?php foreach ($history as $h): ?>
          <li class="mb-2 small"><div><?= e($h['summary']) ?></div><div class="text-body-secondary"><?= e(format_datetime($h['created_at'])) ?><?= $h['actor_name'] ? ' · ' . e($h['actor_name']) : '' ?></div></li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?><p class="small text-body-secondary mb-0">No history yet.</p><?php endif; ?>
    </div></div>
  </div>
</div>
