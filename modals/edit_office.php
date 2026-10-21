<form method="POST" action="../actions/edit_office.php">
    <input type="hidden" id="edit_office_id" name="id">
    
    <div class="mb-3">
        <label for="edit_office_name" class="form-label fw-semibold text-dark">Office Name <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="edit_office_name" name="office_name" required autocomplete="off">
    </div>

    <div class="mb-3">
        <label for="edit_office_status" class="form-label fw-semibold text-dark">Status</label>
        <select class="form-select" id="edit_office_status" name="status">
            <option value="Active">Active</option>
            <option value="Inactive">Inactive</option>
        </select>
    </div>
    
    <div class="d-flex justify-content-end mt-4 pt-3 border-top gap-2">
        <button type="button" class="btn btn-secondary px-3" onclick="document.getElementById('editOfficeModal').style.display='none'">Cancel</button>
        <button type="submit" class="btn btn-primary px-4">
            <i class="fas fa-check me-1"></i>Update Office
        </button>
    </div>
</form>
