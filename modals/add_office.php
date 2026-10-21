<form method="POST" action="../actions/add_office.php">
    <div class="mb-3">
        <label for="office_name" class="form-label fw-semibold text-dark">Office Name <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="office_name" name="office_name" placeholder="e.g., Registrar's Office" required autocomplete="off">
        <div class="form-text text-muted">Enter the title or department name for this office.</div>
    </div>
    
    <div class="d-flex justify-content-end mt-4 pt-3 border-top gap-2">
        <button type="button" class="btn btn-secondary px-3" onclick="document.getElementById('addOfficeModal').style.display='none'">Cancel</button>
        <button type="submit" class="btn btn-success px-4" style="background-color: var(--primary-green); border-color: var(--primary-green);">
            <i class="fas fa-save me-1"></i>Save Office
        </button>
    </div>
</form>
