<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Error') ?></title>
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="auth-body"><main class="auth-wrap" id="main"><div class="auth-card card shadow-sm"><div class="card-body p-4 p-md-5"><?= $content ?></div></div></main></body>
</html>
