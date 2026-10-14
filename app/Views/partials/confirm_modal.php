<!-- Shared confirmation dialog. Any form with data-confirm="..." opens it before submitting. -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5" id="confirmTitle">Please confirm</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancel"></button>
      </div>
      <div class="modal-body">
        <p id="confirmText" class="mb-0"></p>
        <div id="confirmReasonWrap" class="mt-3 d-none">
          <label for="confirmReason" class="form-label">Reason <span class="text-danger" aria-hidden="true">*</span></label>
          <textarea id="confirmReason" class="form-control" rows="2" maxlength="255"></textarea>
          <div class="invalid-feedback">Please give a reason.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmOk">Confirm</button>
      </div>
    </div>
  </div>
</div>
