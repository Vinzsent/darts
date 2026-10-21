<form method="POST" action="../actions/delete_office.php">
    <input type="hidden" id="delete_office_id" name="id">
    
    <div class="text-center my-3">
        <i class="fas fa-exclamation-triangle fa-3x text-danger mb-3"></i>
        <h5 class="fw-bold text-dark">Are you sure?</h5>
        <p class="text-muted mb-0">Do you really want to delete this office? This action cannot be undone.</p>
    </div>
    
    <div class="d-flex justify-content-center mt-4 pt-3 border-top gap-2">
        <button type="button" class="btn btn-secondary px-4" onclick="document.getElementById('deleteOfficeModal').style.display='none'">Cancel</button>
        <button type="submit" class="btn btn-danger px-4">
            <i class="fas fa-trash me-1"></i>Delete
        </button>
    </div>
</form>
