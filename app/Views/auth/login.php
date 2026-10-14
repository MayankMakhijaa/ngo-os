<h1 class="h4 mb-1">Sign in</h1>
<p class="text-body-secondary small mb-4">Fameweb administrators and NGO users sign in here.</p>
<form method="post" action="<?= e(url('login')) ?>" class="needs-validation" novalidate data-loading>
  <?= csrf_field() ?>
  <?= form_input('email', 'Email address', '', 'email', ['required' => true, 'autocomplete' => 'username', 'autofocus' => true, 'maxlength' => 190]) ?>
  <div class="mb-3">
    <div class="d-flex justify-content-between">
      <label class="form-label" for="f_password">Password <span class="text-danger" aria-hidden="true">*</span></label>
      <a class="small" href="<?= e(url('forgot-password')) ?>">Forgot password?</a>
    </div>
    <div class="input-group">
      <input type="password" class="form-control<?= invalid_class('password') ?>" id="f_password" name="password" required autocomplete="current-password" maxlength="200">
      <button class="btn btn-outline-secondary" type="button" data-toggle-password="#f_password" aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
      <div class="invalid-feedback"><?= e(field_error('password') ?? 'Please enter your password.') ?></div>
    </div>
  </div>
  <button class="btn btn-primary w-100" type="submit">Sign in</button>
</form>
