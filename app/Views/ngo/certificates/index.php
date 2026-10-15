<?php
/** @var \App\Core\Paginator $page @var array $f @var array $templates @var array $usage */
$writable = \App\Services\SubscriptionService::isWritable($subscription);
$heading = 'Certificates';
$subtitle = $usage['limit'] !== null ? $usage['used_display'] . ' of ' . $usage['limit_display'] . ' certificates used on your plan' : $usage['used_display'] . ' certificates issued';
$actions = '';
if (can('certificates.issue') && $writable && $templates) {
    $actions .= '<a class="btn btn-primary" href="' . e(url('ngo/certificates/create')) . '"><i class="bi bi-award me-1" aria-hidden="true"></i>Issue certificate</a>';
}
include __DIR__ . '/../../partials/page_header.php';
?>
<?php if ($usage['state'] === 'reached'): ?><div class="alert alert-warning small">Your plan's certificate allowance is used up. Ask Fameweb to upgrade your plan to issue more.</div><?php endif; ?>
<div class="row g-3">
  <div class="col-lg-9">
    <div class="card">
      <div class="card-body border-bottom">
        <form class="row g-2 align-items-end" method="get">
          <div class="col-md-6"><label class="form-label small" for="q">Search</label><input class="form-control form-control-sm" type="search" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Name, ID, certificate no. or title"></div>
          <div class="col-6 col-md-3"><label class="form-label small" for="status">Status</label>
            <select class="form-select form-select-sm" id="status" name="status"><option value="">All</option><option value="issued"<?= $f['status'] === 'issued' ? ' selected' : '' ?>>Issued</option><option value="revoked"<?= $f['status'] === 'revoked' ? ' selected' : '' ?>>Revoked</option></select></div>
          <div class="col-md-3 d-flex gap-2"><button class="btn btn-sm btn-primary" type="submit">Filter</button><a class="btn btn-sm btn-light" href="<?= e(url('ngo/certificates')) ?>">Reset</a></div>
        </form>
      </div>
      <?php if (!$page->items): ?>
        <?= empty_state('No certificates yet', $templates ? 'Issue a certificate to a member, volunteer or intern.' : 'Fameweb has not assigned a certificate template to your organization yet.', 'award') ?>
      <?php else: ?>
      <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr><th scope="col">Certificate</th><th scope="col">Issued to</th><th scope="col">Date</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody><?php foreach ($page->items as $c): ?>
          <tr><td><div class="fw-medium"><?= e($c['title']) ?></div><div class="small font-monospace text-body-secondary"><?= e($c['certificate_no']) ?></div></td>
            <td><a href="<?= e(url('ngo/people/' . \App\Services\PeopleService::slugForType($c['person_type']) . '/' . $c['person_id'])) ?>"><?= e($c['person_name']) ?></a><div class="small text-body-secondary"><?= e($c['member_code']) ?></div></td>
            <td class="small"><?= e(format_date($c['issued_on'])) ?></td>
            <td><?= status_badge($c['status']) ?></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-light" href="<?= e(url('ngo/certificates/' . $c['id'] . '/pdf')) ?>"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF</a>
              <?php if ($c['status'] === 'issued' && $writable): ?>
                <?php if (can('certificates.issue')): ?>
                <form class="d-inline" method="post" action="<?= e(url('ngo/certificates/' . $c['id'] . '/email')) ?>" data-loading><?= csrf_field() ?><button class="btn btn-sm btn-light" type="submit" title="Email the PDF to the person"><i class="bi bi-envelope" aria-hidden="true"></i><span class="visually-hidden">Email</span></button></form>
                <?php endif; ?>
                <?php if (can('certificates.revoke')): ?>
                <form class="d-inline" method="post" action="<?= e(url('ngo/certificates/' . $c['id'] . '/revoke')) ?>" data-confirm="Revoke certificate <?= e($c['certificate_no']) ?>? The record and number are kept and the PDF is marked REVOKED." data-confirm-reason="reason" data-confirm-button="Revoke">
                  <?= csrf_field() ?><input type="hidden" name="reason" value=""><button class="btn btn-sm btn-outline-danger" type="submit">Revoke</button></form>
                <?php endif; ?>
              <?php endif; ?>
            </td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <?php endif; ?>
      <?php include __DIR__ . '/../../partials/pagination.php'; ?>
    </div>
  </div>
  <div class="col-lg-3">
    <div class="card"><div class="card-body">
      <h2 class="h6">Your templates</h2>
      <p class="small text-body-secondary">Designed by Fameweb for your organization.</p>
      <?php if (!$templates): ?><p class="small mb-0">None assigned yet.</p><?php endif; ?>
      <ul class="list-unstyled mb-0">
        <?php foreach ($templates as $t): ?>
          <li class="mb-2"><div class="fw-medium small"><?= e($t['name']) ?></div>
            <a class="small" href="<?= e(url('ngo/certificates/templates/' . $t['id'] . '/preview')) ?>" target="_blank" rel="noopener">Preview with your logo</a></li>
        <?php endforeach; ?>
      </ul>
    </div></div>
  </div>
</div>
