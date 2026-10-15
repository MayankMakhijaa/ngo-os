<?php
/**
 * Authenticated application shell shared by all four layouts.
 * @var string $content @var string $title @var array $shell
 *   shell: brand, brand_sub, theme, nav[], role_label, search{action,placeholder}|null, home
 */
$me = auth_user();
$current = \App\Core\Request::current()?->path ?? '';
// Keep only links the user may open (permission and plan module), then drop empty sections.
$nav = [];
foreach ($shell['nav'] as $item) {
    if (isset($item['section'])) {
        $nav[] = $item;
        continue;
    }
    if ((isset($item['perm']) && !can($item['perm'])) || (isset($item['module']) && !in_array($item['module'], $shell['modules'] ?? [], true))) {
        continue;
    }
    $nav[] = $item;
}
$nav = array_values(array_filter($nav, static function ($item, $i) use ($nav) {
    return !isset($item['section']) || (isset($nav[$i + 1]) && !isset($nav[$i + 1]['section']));
}, ARRAY_FILTER_USE_BOTH));
?><!doctype html>
<html lang="en">
<head><?php include __DIR__ . '/head.php'; ?></head>
<body class="app <?= e($shell['theme']) ?>">
<a class="visually-hidden-focusable skip-link" href="#main">Skip to main content</a>
<?php if ($support = \App\Services\SupportAccessService::active()):
  $left = max(0, \App\Services\SupportAccessService::MAX_MINUTES - intdiv(time() - (int) $support['started'], 60)); ?>
<div class="support-banner d-flex flex-wrap align-items-center gap-2" role="status">
  <i class="bi bi-person-badge" aria-hidden="true"></i>
  <span class="me-auto"><strong>Fameweb support session.</strong> <?= e($support['name']) ?> is signed in as <?= e($me['name'] ?? '') ?> (<?= e($shell['brand']) ?>). Everything you do is recorded. Ends in about <?= (int) $left ?> min.</span>
  <form method="post" action="<?= e(url('support-session/end')) ?>"><?= csrf_field() ?>
    <button type="submit" class="btn btn-sm btn-light"><i class="bi bi-arrow-return-left me-1" aria-hidden="true"></i>Return to Fameweb</button>
  </form>
</div>
<?php endif; ?>
<div class="app-wrapper">
  <aside class="sidebar offcanvas-lg offcanvas-start" id="sidebar" tabindex="-1" aria-label="Main navigation">
    <div class="sidebar-brand d-flex align-items-center gap-2">
      <?php if (!empty($shell['logo_url'])): ?>
        <img src="<?= e($shell['logo_url']) ?>" alt="" class="brand-logo">
      <?php else: ?>
        <span class="brand-mark" aria-hidden="true"><i class="bi bi-<?= e($shell['brand_icon'] ?? 'heart-pulse') ?>"></i></span>
      <?php endif; ?>
      <div class="min-w-0 brand-text">
        <div class="brand-name text-truncate"><?= e($shell['brand']) ?></div>
        <div class="brand-sub text-truncate"><?= e($shell['brand_sub']) ?></div>
      </div>
      <button type="button" class="btn-close btn-close-white d-lg-none ms-auto" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close menu"></button>
    </div>
    <nav class="sidebar-nav">
      <ul class="nav flex-column">
        <?php foreach ($nav as $item):
          if (isset($item['section'])): ?>
            <li class="nav-section"><?= e($item['section']) ?></li>
          <?php continue; endif;
          $href = url($item['url']);
          $active = $current === $item['url'] || ($item['url'] !== $shell['home'] && str_starts_with($current, $item['url'] . '/')); ?>
          <li class="nav-item">
            <a class="nav-link<?= $active ? ' active' : '' ?>" href="<?= e($href) ?>"<?= $active ? ' aria-current="page"' : '' ?> title="<?= e($item['label']) ?>">
              <i class="bi bi-<?= e($item['icon']) ?>" aria-hidden="true"></i><span class="nav-text"><?= e($item['label']) ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </nav>
    <div class="sidebar-foot small"><span class="nav-text">Signed in as <?= e($shell['role_label']) ?></span></div>
  </aside>

  <div class="app-main">
    <header class="topbar d-flex align-items-center gap-2">
      <button class="btn btn-icon d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Open menu">
        <i class="bi bi-list fs-4" aria-hidden="true"></i>
      </button>
      <button class="btn btn-icon d-none d-lg-inline-flex" type="button" id="sidebarCollapse" aria-label="Collapse sidebar" aria-pressed="false">
        <i class="bi bi-layout-sidebar-inset fs-5" aria-hidden="true"></i>
      </button>
      <?php if (!empty($shell['search'])): ?>
      <form class="topbar-search d-none d-md-block" role="search" method="get" action="<?= e(url($shell['search']['action'])) ?>">
        <label class="visually-hidden" for="globalSearch"><?= e($shell['search']['placeholder']) ?></label>
        <div class="input-group input-group-sm">
          <span class="input-group-text bg-transparent"><i class="bi bi-search" aria-hidden="true"></i></span>
          <input class="form-control" type="search" id="globalSearch" name="q" placeholder="<?= e($shell['search']['placeholder']) ?>">
        </div>
      </form>
      <?php endif; ?>
      <div class="ms-auto d-flex align-items-center gap-2">
        <span class="badge role-badge d-none d-sm-inline"><?= e($shell['role_label']) ?></span>
        <div class="dropdown">
          <button class="btn btn-user dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($me['name'] ?? '?', 0, 1))) ?></span>
            <span class="d-none d-md-inline text-truncate" style="max-width:160px"><?= e($me['name'] ?? '') ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <li><h2 class="dropdown-header text-truncate" style="max-width:240px"><?= e($me['email'] ?? '') ?></h2></li>
            <li><a class="dropdown-item" href="<?= e(url('account')) ?>"><i class="bi bi-person-gear me-2" aria-hidden="true"></i>My account</a></li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <form method="post" action="<?= e(url('logout')) ?>"><?= csrf_field() ?>
                <button type="submit" class="dropdown-item"><i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Sign out</button>
              </form>
            </li>
          </ul>
        </div>
      </div>
    </header>

    <main id="main" class="app-content" tabindex="-1">
      <?php if (!empty($shell['banner'])): ?>
        <div class="alert alert-<?= e($shell['banner']['tone']) ?> d-flex gap-2 align-items-start" role="status">
          <i class="bi bi-<?= e($shell['banner']['icon']) ?>" aria-hidden="true"></i><div><?= e($shell['banner']['text']) ?></div>
        </div>
      <?php endif; ?>
      <?php include __DIR__ . '/flash.php'; ?>
      <?= $content ?>
    </main>
    <footer class="app-footer small text-body-secondary">
      &copy; <?= date('Y') ?> Fameweb · <?= e(\App\Services\SettingsService::get('platform_name')) ?>
    </footer>
  </div>
</div>
<?php include __DIR__ . '/confirm_modal.php'; ?>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<?php if (!empty($charts)): ?><script src="<?= e(asset('vendor/chartjs/chart.umd.js')) ?>"></script><?php endif; ?>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php if (!empty($builder)): ?><script src="<?= e(asset('js/certificate-builder.js')) ?>"></script><?php endif; ?>
</body>
</html>
