<?php
/** @var array $templates @var array $people @var int $personId @var int $templateId @var array $usage */
$heading = 'Issue certificate';
$subtitle = $usage['limit'] !== null ? $usage['remaining_display'] . ' certificates left on your plan' : null;
$crumbs = ['Certificates' => 'ngo/certificates'];
include __DIR__ . '/../../partials/page_header.php';
$peopleOpts = [];
foreach ($people as $p) {
    $peopleOpts[$p['id']] = $p['name'] . ' (' . $p['member_code'] . ')';
}
$tplOpts = array_column($templates, 'name', 'id');
?>
<?php if (!$templates): ?>
  <div class="card"><?= empty_state('No templates assigned', 'Fameweb designs certificate templates and assigns them to your organization. Contact Fameweb to get one.', 'award') ?></div>
<?php elseif (!$people): ?>
  <div class="card"><?= empty_state('No one to issue to', 'Certificates can be issued to approved members, volunteers and interns.', 'people') ?></div>
<?php else: ?>
<form method="post" action="<?= e(url('ngo/certificates')) ?>" class="needs-validation" novalidate data-loading>
  <?= csrf_field() ?>
  <div class="row g-4">
    <div class="col-lg-7">
      <div class="card"><div class="card-body">
        <div class="row">
          <div class="col-sm-7"><?= form_select('person_id', 'Issued to', $peopleOpts, $personId ?: '', ['required' => true, 'placeholder' => 'Choose a person']) ?></div>
          <div class="col-sm-5"><?= form_select('template_id', 'Template', $tplOpts, $templateId ?: (count($tplOpts) === 1 ? array_key_first($tplOpts) : ''), ['required' => true, 'placeholder' => 'Choose a template']) ?></div>
        </div>
        <?= form_input('title', 'Certificate title', 'Certificate of Appreciation', 'text', ['required' => true, 'maxlength' => 150, 'help' => 'Fills {{title}} in the template']) ?>
        <?= form_textarea('description', 'Description / reason', '', ['rows' => 2, 'maxlength' => 500, 'help' => 'Fills {{description}}, e.g. "for outstanding contribution to the health camp"']) ?>
        <?= form_input('event', 'Event or programme', '', 'text', ['maxlength' => 150]) ?>
        <div class="row">
          <div class="col-sm-4"><?= form_input('start_date', 'Start date', '', 'date') ?></div>
          <div class="col-sm-4"><?= form_input('end_date', 'End date', '', 'date') ?></div>
          <div class="col-sm-4"><?= form_input('issued_on', 'Date of issue', date('Y-m-d'), 'date', ['required' => true]) ?></div>
        </div>
        <div class="row">
          <div class="col-sm-6"><?= form_input('signatory_name', 'Signatory name', '', 'text', ['maxlength' => 120]) ?></div>
          <div class="col-sm-6"><?= form_input('signatory_designation', 'Signatory designation', '', 'text', ['maxlength' => 120, 'wrap' => 'mb-0']) ?></div>
        </div>
      </div></div>
    </div>
    <div class="col-lg-5">
      <div class="card"><div class="card-body small">
        <h2 class="h6">How it works</h2>
        <p>The person's name, ID, category, designation and dates, and your NGO's name and logo, are filled in automatically. Fields you leave blank are left empty on the certificate.</p>
        <p class="mb-2">Preview the templates with your logo:</p>
        <ul class="mb-0"><?php foreach ($templates as $t): ?><li><a href="<?= e(url('ngo/certificates/templates/' . $t['id'] . '/preview')) ?>" target="_blank" rel="noopener"><?= e($t['name']) ?></a></li><?php endforeach; ?></ul>
      </div></div>
    </div>
  </div>
  <div class="mt-3 d-flex gap-2"><button class="btn btn-primary" type="submit">Issue certificate</button><a class="btn btn-light" href="<?= e(url('ngo/certificates')) ?>">Cancel</a></div>
</form>
<?php endif; ?>
