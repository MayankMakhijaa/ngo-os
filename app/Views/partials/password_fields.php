<?php $minLen = (int) config('security.password.min_length', 10); ?>
<div class="mb-3">
  <label class="form-label" for="f_password">New password <span class="text-danger" aria-hidden="true">*</span></label>
  <div class="input-group">
    <input type="password" class="form-control<?= invalid_class('password') ?>" id="f_password" name="password" required minlength="<?= $minLen ?>" maxlength="72" autocomplete="new-password" aria-describedby="pwHelp">
    <button class="btn btn-outline-secondary" type="button" data-toggle-password="#f_password" aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
    <div class="invalid-feedback"><?= e(field_error('password') ?? "At least $minLen characters with a letter and a number.") ?></div>
  </div>
  <div id="pwHelp" class="form-text">At least <?= $minLen ?> characters, including a letter and a number.</div>
</div>
<div class="mb-3">
  <label class="form-label" for="f_password_confirmation">Confirm new password <span class="text-danger" aria-hidden="true">*</span></label>
  <input type="password" class="form-control" id="f_password_confirmation" name="password_confirmation" required autocomplete="new-password" data-match="#f_password">
  <div class="invalid-feedback">Passwords do not match.</div>
</div>
