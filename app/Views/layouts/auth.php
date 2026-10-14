<!doctype html>
<html lang="en">
<head><?php include __DIR__ . '/../partials/head.php'; ?></head>
<body class="auth-body">
<main class="auth-wrap" id="main">
  <div class="auth-card card shadow-sm">
    <div class="card-body p-4 p-md-5">
      <div class="text-center mb-4">
        <span class="brand-mark brand-mark-lg" aria-hidden="true"><i class="bi bi-globe2"></i></span>
        <div class="fw-semibold mt-2"><?= e(\App\Services\Installer::isInstalled() ? \App\Services\SettingsService::get('platform_name') : config('app.name')) ?></div>
        <div class="small text-body-secondary">by Fameweb</div>
      </div>
      <?php include __DIR__ . '/../partials/flash.php'; ?>
      <?= $content ?>
    </div>
  </div>
</main>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
