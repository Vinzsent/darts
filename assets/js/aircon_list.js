
                // Session message variables
                var sessionMessage = window.PAGE_CFG.sessionMessage;
                var sessionError = window.PAGE_CFG.sessionError;

                $(document).ready(function() {
                    // Show session message alert if there are messages
                    if (sessionMessage || sessionError) {
                        showSessionMessageAlert();
                    }

                    // Enable Enter key search
                    $('#search').on('keypress', function(e) {
                        if (e.which === 13) { // Enter key
                            $(this).closest('form').submit();
                        }
                    });

                    // Real-time search with debounce - NO PAGE REFRESH
                    let searchTimeout;
                    $('#search').on('input', function() {
                        clearTimeout(searchTimeout);
                        const searchValue = $(this).val();

                        searchTimeout = setTimeout(function() {
                            if (searchValue.length === 0 || searchValue.length >= 2) {
                                // Perform AJAX search instead of form submission
                                performSearch(searchValue);
                            }
                        }, 500); // Wait 500ms after user stops typing
                    });


                    // Mark as Received Modal functionality
                    // Mark as Received Modal functionality
                    $('.mark-received-btn').on('click', function() {
                        const button = $(this);
                        const transactionId = button.data('transaction-id');
                        const itemName = button.data('item-name');
                        const quantity = button.data('quantity');
                        const unit = button.data('unit');
                        const supplier = button.data('supplier');
                        const unitPrice = button.data('unit-price');
                        const notes = button.data('notes');
                        const receiver = button.data('receiver');

                        // Populate hidden fields
                        $('#approve-id').val(transactionId);
                        $('#approve-item-name').val(itemName);
                        $('#approve-quantity').val(quantity);
                        $('#approve-unit').val(unit);
                        $('#approve-supplier').val(supplier);
                        $('#approve-price').val(unitPrice);
                        $('#approve-notes').val(notes);
                        $('#approve-receiver').val(receiver);
                        // Display item name in modal
                        $('#display-item-name').text(itemName);

                        console.log('Mark as Received modal populated with:', {
                            id: transactionId,
                            itemName: itemName,
                            quantity: quantity,
                            unit: unit,
                            supplier: supplier,
                            unitPrice: unitPrice,
                            notes: notes,
                            receiver: receiver
                        });
                    });

                    // View Aircon Details button handler
                    $(document).on('click', '.view-aircon-details-btn', function() {
                        const button = $(this);

                        // Get all data attributes
                        const airconId = button.data('aircon-id');
                        const itemNumber = button.data('item-number') || '';
                        const brand = button.data('brand') || '';
                        const model = button.data('model') || '';
                        const type = button.data('type') || '';
                        const horsePower = button.data('horse-power') || '';
                        const serialNumber = button.data('serial-number') || '';
                        const location = button.data('location') || '';
                        const area = button.data('area') || '';
                        const status = button.data('status') || '';
                        const purchaseDate = button.data('purchase-date') || '';
                        const warrantyExpiry = button.data('warranty-expiry') || '';
                        const lastServiceDate = button.data('last-service-date') || '';
                        const maintenanceSchedule = button.data('maintenance-schedule') || '';
                        const supplierInfo = button.data('supplier-info') || '';
                        const installationDate = button.data('installation-date') || '';
                        const energyEfficiency = button.data('energy-efficiency') || '';
                        const powerConsumption = button.data('power-consumption') || '';
                        const notes = button.data('notes') || '';
                        const purchasePrice = button.data('purchase-price') || '0';
                        const depreciatedValue = button.data('depreciated-value') || '0';
                        const lifespan = button.data('lifespan') || '';
                        const receiver = button.data('receiver') || '';
                        const createdBy = button.data('created-by') || '';
                        const dateCreated = button.data('date-created') || '';
                        const picture = button.data('picture') || '';
                        const modalId = button.data('modal-id');

                        // Call the viewAirconDetails function
                        viewAirconDetails(
                            airconId, itemNumber, brand, model, type, horsePower, serialNumber,
                            location, area, status, purchaseDate, warrantyExpiry, lastServiceDate,
                            maintenanceSchedule, supplierInfo, installationDate, energyEfficiency,
                            powerConsumption, notes, purchasePrice, depreciatedValue, lifespan, receiver,
                            createdBy, dateCreated, picture
                        );

                        // Hide the parent modal if modalId is specified
                        if (modalId) {
                            $('#' + modalId).modal('hide');
                        }
                    });
                });

                // Maintenance Records Modal Handler - Global scope
                let currentAirconId = null;
                const canManageAircon = window.PAGE_CFG.canManageAircon;

                $(document).ready(function() {
                    $(document).on('click', '.view-maintenance-btn', function() {
                        const button = $(this);
                        currentAirconId = button.data('aircon-id');
                        const brand = button.data('brand');
                        const model = button.data('model');
                        const serial = button.data('serial');

                        // Set aircon info in modal
                        $('#maintenance-aircon-info').text(`${brand} ${model} - S/N: ${serial}`);

                        // Show modal
                        $('#maintenanceRecordsModal').modal('show');

                        // Load maintenance records
                        loadMaintenanceRecords(currentAirconId);

                        // Wire export links with current aircon id
                        const csvLink = document.getElementById('exportMaintCsv');
                        const xlsLink = document.getElementById('exportMaintXls');
                        if (csvLink) csvLink.href = `../actions/export_aircon_maintenance_csv.php?aircon_id=${encodeURIComponent(currentAirconId)}`;
                        if (xlsLink) xlsLink.href = `../actions/export_aircon_maintenance_excel.php?aircon_id=${encodeURIComponent(currentAirconId)}`;
                    });

                    // Add Maintenance Button
                    $('#addMaintenanceBtn').on('click', function() {
                        $('#maintenanceFormTitle').html('<i class="fas fa-wrench me-2"></i>Add Maintenance Record');
                        $('#maintenanceForm')[0].reset();
                        $('#maint_maintenance_id').val('');
                        $('#maint_aircon_id').val(currentAirconId);
                        $('#maintenanceFormModal').modal('show');
                    });

                    // Submit Maintenance Form
                    $('#maintenanceForm').on('submit', function(e) {
                        e.preventDefault();

                        const formData = new FormData(this);
                        const maintenanceId = $('#maint_maintenance_id').val();
                        const url = maintenanceId ? '../actions/edit_aircon_maintenance.php' : '../actions/add_aircon_maintenance.php';

                        $.ajax({
                            url: url,
                            type: 'POST',
                            data: formData,
                            processData: false,
                            contentType: false,
                            dataType: 'json',
                            success: function(response) {
                                if (response.success) {
                                    alert('✅ ' + response.message);
                                    $('#maintenanceFormModal').modal('hide');
                                    loadMaintenanceRecords(currentAirconId);
                                    // Note: Removed page reload here so the main modal stays open.
                                } else {
                                    alert('❌ ' + response.message);
                                }
                            },
                            error: function() {
                                alert('❌ Error saving maintenance record');
                            }
                        });
                    });
                }); // End of $(document).ready for maintenance handlers

                // Function to show session message alert
                function showSessionMessageAlert() {
                    if (sessionMessage) {
                        // Success message
                        alert('✅ Success!\n\n' + sessionMessage);
                    } else if (sessionError) {
                        // Error message
                        alert('❌ Error!\n\n' + sessionError);
                    }
                }

                // Load Maintenance Records
                function loadMaintenanceRecords(airconId) {
                    $('#maintenanceTableBody').html(`
                        <tr>
                            <td colspan="${canManageAircon ? 7 : 6}" class="text-center">
                                <div class="spinner-border text-info" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                            </td>
                        </tr>
                    `);
                    $('#noMaintenanceMessage').hide();

                    $.ajax({
                        url: '../actions/get_aircon_maintenance.php',
                        type: 'GET',
                        data: {
                            aircon_id: airconId
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                const records = response.maintenance_records;

                                if (records.length === 0) {
                                    $('#maintenanceTableBody').html('');
                                    $('#noMaintenanceMessage').show();
                                } else {
                                    let html = '';
                                    records.forEach(function(record) {
                                        const serviceDate = record.service_date ? formatDate(record.service_date) : 'N/A';
                                        const nextDate = record.next_scheduled_date ? formatDate(record.next_scheduled_date) : 'N/A';
                                        const technician = record.technician || 'N/A';
                                        const remarks = record.remarks ? (record.remarks.length > 50 ? record.remarks.substring(0, 50) + '...' : record.remarks) : 'N/A';

                                        // Escape data for HTML attributes
                                        const escapedTechnician = (record.technician || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                                        const escapedRemarks = (record.remarks || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                                        const escapedServiceType = (record.service_type || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');

                                        html += `
                                            <tr>
                                                <td>${serviceDate}</td>
                                                <td><span class="badge bg-info">${record.service_type}</span></td>
                                                <td>${technician}</td>
                                                <td>${nextDate}</td>
                                                <td>${remarks}</td>
                                                <td>${record.created_by || 'N/A'}</td>
                                                ${canManageAircon ? `
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-secondary view-maint-btn" 
                                                        data-id="${record.maintenance_id}"
                                                        title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-warning edit-maint-btn" 
                                                        data-id="${record.maintenance_id}"
                                                        data-date="${record.service_date || ''}"
                                                        data-type="${escapedServiceType}"
                                                        data-technician="${escapedTechnician}"
                                                        data-next="${record.next_scheduled_date || ''}"
                                                        data-remarks="${escapedRemarks}"
                                                        title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-danger delete-maint-btn" 
                                                        data-id="${record.maintenance_id}"
                                                        title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </td>
                                                ` : ''}
                                            </tr>
                                        `;
                                    });
                                    $('#maintenanceTableBody').html(html);
                                    $('#noMaintenanceMessage').hide();
                                }
                            } else {
                                $('#maintenanceTableBody').html(`
                                    <tr>
                                        <td colspan="${canManageAircon ? 7 : 6}" class="text-center text-danger">
                                            <i class="fas fa-exclamation-triangle"></i> ${response.message}
                                        </td>
                                    </tr>
                                `);
                            }
                        },
                        error: function() {
                            $('#maintenanceTableBody').html(`
                                <tr>
                                    <td colspan="${canManageAircon ? 7 : 6}" class="text-center text-danger">
                                        <i class="fas fa-exclamation-triangle"></i> Error loading maintenance records
                                    </td>
                                </tr>
                            `);
                        }
                    });
                }

                // View Maintenance Record
                $(document).on('click', '.view-maint-btn', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log('View button clicked');

                    const maintenanceId = $(this).data('id');
                    console.log('Maintenance ID:', maintenanceId);

                    $.ajax({
                        url: '../actions/get_aircon_maintenance.php',
                        type: 'GET',
                        data: {
                            aircon_id: currentAirconId
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                const record = response.maintenance_records.find(r => r.maintenance_id == maintenanceId);
                                if (record) {
                                    $('#view_service_date').text(record.service_date ? formatDate(record.service_date) : 'N/A');
                                    $('#view_service_type').text(record.service_type || 'N/A');
                                    $('#view_technician').text(record.technician || 'N/A');
                                    $('#view_next_scheduled_date').text(record.next_scheduled_date ? formatDate(record.next_scheduled_date) : 'N/A');
                                    $('#view_remarks').text(record.remarks || 'N/A');
                                    $('#view_created_by').text(record.created_by || 'N/A');
                                    $('#view_date_created').text(record.date_created ? formatDateTime(record.date_created) : 'N/A');

                                    $('#viewMaintenanceModal').modal('show');
                                }
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Error fetching maintenance details:', error);
                            alert('Error loading maintenance details');
                        }
                    });
                });

                // Edit Maintenance Record
                $(document).on('click', '.edit-maint-btn', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log('Edit button clicked');

                    const button = $(this);
                    const maintenanceId = button.data('id');
                    console.log('Editing maintenance ID:', maintenanceId);

                    $('#maintenanceFormTitle').html('<i class="fas fa-edit me-2"></i>Edit Maintenance Record');
                    $('#maint_maintenance_id').val(maintenanceId);
                    $('#maint_aircon_id').val(currentAirconId);
                    $('#maint_service_date').val(button.data('date'));
                    $('#maint_service_type').val(button.data('type'));

                    // Decode HTML entities for technician
                    const technicianValue = button.data('technician');
                    const decodedTechnician = $('<textarea/>').html(technicianValue).text();
                    $('#maint_technician').val(decodedTechnician !== 'N/A' && decodedTechnician !== '' ? decodedTechnician : '');

                    $('#maint_next_scheduled_date').val(button.data('next'));

                    // Decode HTML entities for remarks
                    const remarksValue = button.data('remarks');
                    const decodedRemarks = $('<textarea/>').html(remarksValue).text();
                    $('#maint_remarks').val(decodedRemarks);

                    $('#maintenanceFormModal').modal('show');
                });

                // Delete Maintenance Record
                $(document).on('click', '.delete-maint-btn', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log('Delete button clicked');

                    const maintenanceId = $(this).data('id');
                    console.log('Deleting maintenance ID:', maintenanceId);

                    if (confirm('Are you sure you want to delete this maintenance record?')) {
                        $.ajax({
                            url: '../actions/delete_aircon_maintenance.php',
                            type: 'POST',
                            data: {
                                maintenance_id: maintenanceId
                            },
                            dataType: 'json',
                            success: function(response) {
                                if (response.success) {
                                    alert('✅ ' + response.message);
                                    loadMaintenanceRecords(currentAirconId);
                                    // Note: Removed page reload here so the main modal stays open.
                                } else {
                                    alert('❌ ' + response.message);
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('Error deleting maintenance record:', error);
                                alert('❌ Error deleting maintenance record');
                            }
                        });
                    }
                });

                // Helper function to format date
                function formatDate(dateString) {
                    if (!dateString || dateString === '0000-00-00') return 'N/A';
                    const date = new Date(dateString);
                    const options = {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric'
                    };
                    return date.toLocaleDateString('en-US', options);
                }

                // Helper function to format datetime
                function formatDateTime(dateString) {
                    if (!dateString) return 'N/A';
                    const date = new Date(dateString);
                    const options = {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    };
                    return date.toLocaleDateString('en-US', options);
                }

                // AJAX search function - no page refresh
                function performSearch(searchTerm) {
                    // Show loading indicator
                    const searchInput = $('#search');
                    const originalValue = searchInput.val();

                    // Add loading class to search input
                    searchInput.addClass('loading');

                    // Get current URL parameters
                    const currentParams = new URLSearchParams(window.location.search);
                    currentParams.set('search', searchTerm);
                    currentParams.set('ajax', '1');
                    currentParams.delete('page'); // Reset to first page for new search

                    // Fetch search results
                    fetch("aircon_list.php?" + currentParams.toString())
                        .then(response => response.text())
                        .then(data => {
                            // Remove loading class
                            searchInput.removeClass('loading');

                            // Extract table content from response
                            const temp = document.createElement('div');
                            temp.innerHTML = data;
                            const newTable = temp.querySelector('#inventoryTable');
                            const newPagination = temp.querySelector('#paginationContainer');

                            if (newTable) {
                                document.getElementById("inventoryTable").innerHTML = newTable.innerHTML;
                            }
                            if (newPagination) {
                                const paginationContainer = document.querySelector("#paginationContainer");
                                if (paginationContainer) {
                                    paginationContainer.innerHTML = newPagination.innerHTML;
                                }
                            }

                            // Update URL without page reload
                            const url = new URL(window.location);
                            url.searchParams.set('search', searchTerm);
                            url.searchParams.delete('page');
                            window.history.pushState({}, '', url);

                            // Keep the Export link in sync with the current search term
                            const exportBtn = document.getElementById('airconExportBtn');
                            if (exportBtn) {
                                const exportParams = new URLSearchParams(window.location.search);
                                exportParams.set('search', searchTerm);
                                exportParams.delete('ajax');
                                exportParams.delete('page');
                                exportBtn.href = '../actions/export_aircons.php?' + exportParams.toString();
                            }

                            // Smooth scroll to top of table
                            const table = document.querySelector('.table-responsive');
                            if (table) {
                                table.scrollIntoView({
                                    behavior: 'smooth',
                                    block: 'start'
                                });
                            }
                        })
                        .catch(error => {
                            console.error("Search error:", error);
                            searchInput.removeClass('loading');

                            // Show error message
                            const errorDiv = document.createElement('div');
                            errorDiv.className = 'alert alert-danger mt-3';
                            errorDiv.innerHTML = `
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Error performing search. Please try again.
                                <button class="btn btn-sm btn-outline-danger ms-2" onclick="performSearch('${searchTerm}')">
                                    <i class="fas fa-sync-alt"></i> Retry
                                </button>
                            `;

                            const tableContainer = document.querySelector('.table-responsive');
                            if (tableContainer) {
                                tableContainer.parentNode.insertBefore(errorDiv, tableContainer.nextSibling);
                            }
                        });
                }

                // Image Preview Handler with Accumulation support
                $(document).ready(function() {
                    // Global file buffers for accumulation
                    window.addFileBuffer = new DataTransfer();
                    window.editFileBuffer = new DataTransfer();

                    function renderPreviews(type, files) {
                        const previewContainer = type === 'add' ? $('#picture_preview_container_add') : $('#picture_preview_container_edit');
                        const previewWrapper = type === 'add' ? $('#picture_previews_add') : $('#picture_previews_edit');
                        const input = type === 'add' ? document.getElementById('aircon_picture_add') : document.getElementById('aircon_picture_edit');
                        const buffer = type === 'add' ? window.addFileBuffer : window.editFileBuffer;

                        previewWrapper.empty();

                        if (buffer.files.length > 0) {
                            Array.from(buffer.files).forEach((file, index) => {
                                const reader = new FileReader();
                                reader.onload = function(event) {
                                    const wrapper = $('<div>').addClass('position-relative d-inline-block');
                                    const img = $('<img>').attr('src', event.target.result)
                                        .css({
                                            'width': '100px',
                                            'height': '100px',
                                            'object-fit': 'cover',
                                            'border-radius': '5px',
                                            'border': '1px solid #ddd'
                                        });

                                    const removeBtn = $('<button>')
                                        .attr('type', 'button')
                                        .addClass('btn btn-danger btn-sm position-absolute top-0 end-0 p-0')
                                        .css({
                                            'width': '20px',
                                            'height': '20px',
                                            'line-height': '15px',
                                            'font-size': '12px'
                                        })
                                        .html('&times;')
                                        .on('click', function() {
                                            const newBuffer = new DataTransfer();
                                            Array.from(buffer.files).forEach((f, i) => {
                                                if (i !== index) newBuffer.items.add(f);
                                            });
                                            if (type === 'add') window.addFileBuffer = newBuffer;
                                            else window.editFileBuffer = newBuffer;
                                            input.files = (type === 'add' ? window.addFileBuffer : window.editFileBuffer).files;
                                            renderPreviews(type);
                                        });

                                    wrapper.append(img).append(removeBtn);
                                    previewWrapper.append(wrapper);
                                };
                                reader.readAsDataURL(file);
                            });
                            previewContainer.show();
                        } else {
                            previewContainer.hide();
                        }
                    }

                    $('#aircon_picture_add').on('change', function(e) {
                        const files = e.target.files;
                        if (files && files.length > 0) {
                            Array.from(files).forEach(file => {
                                window.addFileBuffer.items.add(file);
                            });
                            // Sync input with buffer
                            this.files = window.addFileBuffer.files;
                            renderPreviews('add');
                        }
                    });

                    $('#aircon_picture_edit').on('change', function(e) {
                        const files = e.target.files;
                        if (files && files.length > 0) {
                            Array.from(files).forEach(file => {
                                window.editFileBuffer.items.add(file);
                            });
                            // Sync input with buffer
                            this.files = window.editFileBuffer.files;
                            renderPreviews('edit');
                        }
                    });

                    // Clear buffers when modals are opened
                    $('#addInventoryModal').on('show.bs.modal', function() {
                        clearImagePreview('add');
                    });
                    $('#editAirconModal').on('show.bs.modal', function() {
                        // clearImagePreview('edit'); // Already called in openEditAirconModal
                    });
                });

                function clearImagePreview(type) {
                    if (type === 'add') {
                        window.addFileBuffer = new DataTransfer();
                        const input = document.getElementById('aircon_picture_add');
                        if (input) input.value = '';
                        $('#picture_previews_add').empty();
                        $('#picture_preview_container_add').hide();
                    } else if (type === 'edit') {
                        window.editFileBuffer = new DataTransfer();
                        const input = document.getElementById('aircon_picture_edit');
                        if (input) input.value = '';
                        $('#picture_previews_edit').empty();
                        $('#picture_preview_container_edit').hide();
                    }
                }

                // Function to change status from Pending to Received
                window.changeStatusToReceived = function() {
                    const procurementId = $('#approve-id').val();
                    const itemName = $('#approve-item-name').val();

                    if (!procurementId) {
                        alert('Error: Procurement ID not found');
                        return;
                    }

                    if (confirm(`Are you sure you want to change the status of "${itemName}" from Pending to Received?`)) {
                        // Send AJAX request to change status
                        $.ajax({
                            url: '../actions/change_procurement_status.php',
                            type: 'POST',
                            data: {
                                procurement_id: procurementId,
                                new_status: 'Received'
                            },
                            dataType: 'json',
                            success: function(response) {
                                if (response.success) {
                                    alert('Status changed successfully from Pending to Received!');
                                    // Close the modal
                                    $('#receivedModal').modal('hide');
                                    // Reload the page to reflect changes
                                    location.reload();
                                } else {
                                    alert('Error changing status: ' + response.message);
                                }
                            },
                            error: function() {
                                alert('Error occurred while changing the status');
                            }
                        });
                    }
                };

                function stockIn(inventoryId) {
                    document.getElementById('movement_inventory_id').value = inventoryId;
                    document.getElementById('movement_type').value = 'IN';
                    fetchItemDetails(inventoryId);
                    const smModal = new bootstrap.Modal(document.getElementById('stockMovementModal'));
                    smModal.show();
                    // Set the movement type and highlight the button after modal is shown
                    setTimeout(() => {
                        setMovementType('IN');
                    }, 100);
                }

                function stockOut(inventoryId) {
                    document.getElementById('movement_inventory_id').value = inventoryId;
                    document.getElementById('movement_type').value = 'OUT';
                    fetchItemDetails(inventoryId);
                    const smModal = new bootstrap.Modal(document.getElementById('stockMovementModal'));
                    smModal.show();
                    // Set the movement type and highlight the button after modal is shown
                    setTimeout(() => {
                        setMovementType('OUT');
                    }, 100);
                }

                function fetchItemDetails(inventoryId) {
                    $.ajax({
                        url: '../pages/get_aircon_list.php',
                        type: 'GET',
                        data: {
                            inventory_id: inventoryId
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                $('#movement_item_name').val(response.item.item_name);
                            } else {
                                $('#movement_item_name').val('Item not found');
                            }
                        },
                        error: function() {
                            $('#movement_item_name').val('Error loading item');
                        }
                    });
                }

                function setMovementType(type) {
                    $('#movement_type').val(type);

                    // Remove active class from all movement buttons
                    $('.movement-btn').removeClass('active btn-outline-success btn-outline-warning');

                    if (type === 'IN') {
                        $('#stockInBtn').addClass('active');
                        $('#stockInBtn').addClass('btn-outline-success');
                        $('#stockOutBtn').removeClass('btn-outline-warning');
                    } else {
                        $('#stockOutBtn').addClass('active');
                        $('#stockOutBtn').addClass('btn-outline-warning');
                        $('#stockInBtn').removeClass('btn-outline-success');
                    }
                }

                function viewItem(inventoryId) {
                    // Implement view item details functionality
                    console.log('View item:', inventoryId);
                }

                function viewAllMovements() {
                    // Implement view all movements functionality
                    console.log('View all movements');
                }

                // Add to Inventory from Acquired Supplies Items row
                function addToInventoryFromRow(button) {
                    const row = button.closest('tr');
                    if (!row) {
                        alert('Error: Could not find table row');
                        return;
                    }

                    const procurementId = row.getAttribute('data-transaction-id') || '';
                    const itemName = row.getAttribute('data-description') || '';
                    const category = row.getAttribute('data-category') || '';
                    const quantity = row.getAttribute('data-quantity') || '0';
                    const unit = row.getAttribute('data-unit') || '';
                    const supplierId = row.getAttribute('data-supplier-id') || '';
                    const unitPrice = row.getAttribute('data-unit-price') || '0';
                    const status = row.getAttribute('data-status') || '';
                    const receiver = row.getAttribute('data-receiver') || '';

                    // Debug: Log all retrieved values
                    console.log('Retrieved data from row:', {
                        procurementId: procurementId,
                        itemName: itemName,
                        category: category,
                        unit: unit,
                        quantity: quantity,
                        supplierId: supplierId,
                        unitPrice: unitPrice,
                        status: status,
                        receiver: receiver
                    });

                    // More lenient validation - only check for truly essential fields
                    if (!itemName || itemName === 'N/A') {
                        alert('Error: Item name is missing or invalid.');
                        return;
                    }

                    // Set defaults for missing fields
                    const finalCategory = category && category !== 'N/A' ? category : 'General';
                    const finalUnit = unit && unit !== '' ? unit : 'pc';
                    const finalQuantity = quantity && quantity !== '0' ? quantity : '1';

                    // Fill hidden form
                    document.getElementById('ai_item_name').value = itemName;
                    document.getElementById('ai_category').value = finalCategory;
                    document.getElementById('ai_current_stock').value = finalQuantity;
                    document.getElementById('ai_unit').value = finalUnit;
                    document.getElementById('ai_status').value = status;
                    document.getElementById('ai_supplier_id').value = supplierId;
                    document.getElementById('ai_unit_cost').value = unitPrice;
                    document.getElementById('ai_reorder_level').value = Math.max(1, Math.floor(finalQuantity * 0.2));
                    document.getElementById('ai_description').value = `From invoice ${row.getAttribute('data-invoice') || ''}`;
                    document.getElementById('ai_procurement_id').value = procurementId;
                    document.getElementById('ai_receiver').value = 'Property Custodian';
                    // Debug: Log the final values being submitted
                    console.log('Final values being submitted:', {
                        item_name: itemName,
                        category: finalCategory,
                        current_stock: finalQuantity,
                        unit: finalUnit,
                        supplier_id: supplierId,
                        unit_cost: unitPrice,
                        reorder_level: Math.max(1, Math.floor(finalQuantity * 0.2)),
                        status: status,
                        receiver: receiver
                    });

                    // Submit
                    document.getElementById('addInventoryHiddenForm').submit();
                }

                // Open Edit Inventory modal with data
                function openEditInventoryModal(id, name, category, unit, stock, reorder, location, supplierId, unitCost, description, quantity, receiver, status, receivedNotes, type, brand, size, color) {
                    document.getElementById('ei_inventory_id').value = id;
                    document.getElementById('ei_item_name').value = name;
                    // Ensure Category select reflects the value even if it's not preset
                    (function() {
                        const catSelect = document.getElementById('ei_category');
                        if (catSelect) {
                            let found = false;
                            for (let i = 0; i < catSelect.options.length; i++) {
                                if (String(catSelect.options[i].value) === String(category)) {
                                    found = true;
                                    break;
                                }
                            }
                            if (!found && category) {
                                const opt = document.createElement('option');
                                opt.value = category;
                                opt.textContent = category;
                                catSelect.appendChild(opt);
                            }
                            catSelect.value = category || '';
                        }
                    })();
                    // Ensure Unit select reflects the value even if it's not preset
                    (function() {
                        const unitSelect = document.getElementById('ei_unit');
                        if (unitSelect) {
                            let found = false;
                            for (let i = 0; i < unitSelect.options.length; i++) {
                                if (String(unitSelect.options[i].value) === String(unit)) {
                                    found = true;
                                    break;
                                }
                            }
                            if (!found && unit) {
                                const opt = document.createElement('option');
                                opt.value = unit;
                                opt.textContent = unit;
                                unitSelect.appendChild(opt);
                            }
                            unitSelect.value = unit || '';
                        }
                    })();
                    document.getElementById('ei_current_stock').value = stock;
                    document.getElementById('ei_reorder_level').value = reorder;
                    document.getElementById('ei_location').value = location || '';
                    document.getElementById('ei_supplier_id').value = supplierId || '';
                    document.getElementById('ei_unit_cost').value = unitCost || 0;
                    document.getElementById('ei_description').value = description || '';
                    document.getElementById('ei_brand').value = brand || '';
                    document.getElementById('ei_color').value = color || '';
                    document.getElementById('ei_size').value = size || '';
                    document.getElementById('ei_type').value = type || '';
                    document.getElementById('ei_quantity').value = quantity || 0;
                    document.getElementById('ei_receiver').value = receiver || '';
                    document.getElementById('ei_status').value = status || 'Active';
                    document.getElementById('ei_received_notes').value = receivedNotes || '';
                    const modal = new bootstrap.Modal(document.getElementById('editInventoryModal'));
                    modal.show();
                }

                // ANCHOR: Edit inventory item with modal management
                function editInventoryItem(inventoryId, currentModalId) {
                    console.log('editInventoryItem called with ID:', inventoryId, 'Modal:', currentModalId);

                    // Hide the current modal first if specified
                    if (currentModalId) {
                        $('#' + currentModalId).modal('hide');
                    }

                    // Wait for modal to close, then fetch data and open edit modal
                    setTimeout(function() {
                        // Fetch inventory item data via AJAX
                        $.ajax({
                            url: '../actions/get_aircon_list.php',
                            method: 'GET',
                            data: {
                                inventory_id: inventoryId
                            },
                            dataType: 'json',
                            success: function(data) {
                                console.log('AJAX response:', data);
                                if (data.success) {
                                    openEditInventoryModal(
                                        data.item.inventory_id,
                                        data.item.item_name,
                                        data.item.category,
                                        data.item.unit,
                                        data.item.current_stock,
                                        data.item.reorder_level,
                                        data.item.location || '',
                                        data.item.supplier_id,
                                        data.item.unit_cost,
                                        data.item.description || '',
                                        data.item.quantity || 0,
                                        data.item.receiver || '',
                                        data.item.status || 'Active',
                                        data.item.received_notes || '',
                                        data.item.type || '',
                                        data.item.brand || '',
                                        data.item.size || '',
                                        data.item.color || ''
                                    );
                                } else {
                                    alert('Error loading inventory item: ' + (data.message || 'Unknown error'));
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('AJAX Error:', status, error);
                                alert('Error: Could not load inventory item data. Please check the console for details.');
                            }
                        });
                    }, currentModalId ? 300 : 0);
                }

                function loadInventory(page = 1) {
                    // Update URL without page reload
                    const url = new URL(window.location);
                    url.searchParams.set('page', page);
                    window.history.pushState({}, '', url);

                    // Show loading overlay with animation
                    const tableContainer = document.querySelector('.table-container');
                    const loadingOverlay = document.createElement('div');
                    loadingOverlay.id = 'loadingOverlay';
                    loadingOverlay.style.cssText = `
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1050;
            border-radius: 8px;
        `;

                    // Create loading spinner
                    const spinner = document.createElement('div');
                    spinner.className = 'spinner-border text-primary';
                    spinner.style.width = '3rem';
                    spinner.style.height = '3rem';
                    spinner.role = 'status';

                    const spinnerText = document.createElement('span');
                    spinnerText.className = 'visually-hidden';
                    spinnerText.textContent = 'Loading...';

                    spinner.appendChild(spinnerText);
                    loadingOverlay.appendChild(spinner);

                    // Add loading text
                    const loadingText = document.createElement('div');
                    loadingText.className = 'ms-3';
                    loadingText.style.fontWeight = '600';
                    loadingText.style.color = '#0d6efd';
                    loadingText.textContent = 'Loading inventory data...';
                    loadingOverlay.appendChild(loadingText);

                    // Add to container with relative positioning
                    tableContainer.style.position = 'relative';
                    tableContainer.appendChild(loadingOverlay);

                    // Disable pagination buttons during load
                    const paginationLinks = document.querySelectorAll('.page-link');
                    paginationLinks.forEach(link => {
                        link.style.pointerEvents = 'none';
                        link.style.opacity = '0.6';
                    });

                    // Get current URL parameters to maintain search and filters
                    const currentParams = new URLSearchParams(window.location.search);
                    currentParams.set('ajax', '1');
                    currentParams.set('page', page);

                    // Fetch the page content
                    fetch("aircon_list.php?" + currentParams.toString())
                        .then(response => response.text())
                        .then(data => {
                            // Remove loading overlay
                            if (loadingOverlay.parentNode) {
                                loadingOverlay.parentNode.removeChild(loadingOverlay);
                            }

                            // Re-enable pagination buttons
                            paginationLinks.forEach(link => {
                                link.style.pointerEvents = '';
                                link.style.opacity = '';
                            });

                            // Extract just the table content from the response
                            const temp = document.createElement('div');
                            temp.innerHTML = data;
                            const newTable = temp.querySelector('#inventoryTable');
                            const newPagination = temp.querySelector('#paginationContainer');

                            if (newTable) {
                                document.getElementById("inventoryTable").innerHTML = newTable.innerHTML;
                            }
                            if (newPagination) {
                                document.querySelector("#paginationContainer").innerHTML = newPagination.innerHTML;
                            }

                            // Update active state
                            document.querySelectorAll('#paginationContainer .page-item').forEach(item => {
                                item.classList.remove('active');
                                if (item.querySelector('a')?.textContent.trim() == page) {
                                    item.classList.add('active');
                                }
                            });

                            // Smooth scroll to top of table
                            const table = document.querySelector('.table-responsive');
                            if (table) {
                                table.scrollIntoView({
                                    behavior: 'smooth',
                                    block: 'start'
                                });
                            }
                        })
                        .catch(error => {
                            console.error("Error:", error);
                            // Remove loading overlay on error
                            if (loadingOverlay.parentNode) {
                                loadingOverlay.parentNode.removeChild(loadingOverlay);
                            }

                            // Show error message
                            const errorDiv = document.createElement('div');
                            errorDiv.className = 'alert alert-danger mt-3';
                            errorDiv.innerHTML = `
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Error loading content. Please try again.
                    <button class="btn btn-sm btn-outline-danger ms-2" onclick="loadInventory(${page})">
                        <i class="fas fa-sync-alt"></i> Retry
                    </button>
                `;

                            const tableContainer = document.querySelector('.table-responsive');
                            if (tableContainer) {
                                tableContainer.parentNode.insertBefore(errorDiv, tableContainer.nextSibling);
                            }
                        });
                }

                // Handle browser back/forward buttons
                window.addEventListener('popstate', function() {
                    const urlParams = new URLSearchParams(window.location.search);
                    const page = urlParams.get('page') || 1;
                    loadInventory(parseInt(page));
                });

                // Load initial page
                document.addEventListener("DOMContentLoaded", function() {
                    const urlParams = new URLSearchParams(window.location.search);
                    const page = urlParams.get('page') || 1;
                    loadInventory(parseInt(page));

                    // Auto-submit filters on change
                    const filterForm = document.getElementById('airconFilterForm');
                    const campusSel = document.getElementById('campus');
                    const sySel = document.getElementById('sy_inv');
                    const statusSel = document.getElementById('status');
                    if (filterForm && campusSel) {
                        campusSel.addEventListener('change', function() {
                            filterForm.submit();
                        });
                    }
                    if (filterForm && sySel) {
                        sySel.addEventListener('change', function() {
                            filterForm.submit();
                        });
                    }
                    if (filterForm && statusSel) {
                        statusSel.addEventListener('change', function() {
                            filterForm.submit();
                        });
                    }
                });

                // Function to view aircon details
                function viewAirconDetails(airconId, itemNumber, brand, model, type, horse_power, serialNumber, location, area, status,
                    purchaseDate, warrantyExpiry, lastServiceDate, maintenanceSchedule, supplierInfo, installationDate,
                    energyEfficiency, powerConsumption, notes, purchasePrice, depreciatedValue, lifespan, receiver, createdBy, dateCreated, picture) {

                    // Populate modal fields
                    document.getElementById('view_aircon_id').textContent = airconId || 'N/A';
                    document.getElementById('view_item_number').textContent = itemNumber || 'N/A';
                    document.getElementById('view_brand').textContent = brand || 'N/A';
                    document.getElementById('view_model').textContent = model || 'N/A';
                    document.getElementById('view_type').textContent = type || 'N/A';
                    document.getElementById('view_horse_power').textContent = horse_power || 'N/A';
                    document.getElementById('view_serial_number').textContent = serialNumber || 'N/A';
                    document.getElementById('view_location').textContent = location || 'N/A';
                    document.getElementById('view_area').textContent = area || 'N/A';
                    // Status badge with color coding by condition
                    var statusBadge = document.getElementById('view_status');
                    statusBadge.textContent = status || 'N/A';
                    var statusNorm = (status || '').toLowerCase().trim();
                    var statusColors = {
                        'working': 'success',
                        'operational': 'success',
                        'needs repair': 'warning text-dark',
                        'needs attention': 'warning text-dark',
                        'under maintenance': 'info',
                        'replace': 'danger',
                        'decommissioned': 'danger',
                        'not applicable': 'secondary',
                        'n/a': 'secondary'
                    };
                    statusBadge.className = 'badge aircon-view-status bg-' + (statusColors[statusNorm] || 'danger');

                    // Format dates
                    document.getElementById('view_purchase_date').textContent = purchaseDate ? formatDate(purchaseDate) : 'N/A';
                    document.getElementById('view_warranty_expiry').textContent = warrantyExpiry ? formatDate(warrantyExpiry) : 'N/A';
                    document.getElementById('view_last_service_date').textContent = lastServiceDate ? formatDate(lastServiceDate) : 'N/A';
                    document.getElementById('view_maintenance_schedule').textContent = maintenanceSchedule || 'N/A';
                    document.getElementById('view_supplier_info').textContent = supplierInfo || 'N/A';
                    document.getElementById('view_installation_date').textContent = installationDate ? formatDate(installationDate) : 'N/A';

                    document.getElementById('view_energy_efficiency_rating').textContent = energyEfficiency || 'N/A';
                    document.getElementById('view_power_consumption').textContent = powerConsumption || 'N/A';
                    document.getElementById('view_lifespan').textContent = lifespan || 'N/A';
                    document.getElementById('view_notes').textContent = notes || 'N/A';

                    // Format currency
                    document.getElementById('view_purchase_price').textContent = purchasePrice ? '₱' + parseFloat(purchasePrice).toLocaleString('en-PH', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }) : '₱0.00';
                    document.getElementById('view_depreciated_value').textContent = depreciatedValue ? '₱' + parseFloat(depreciatedValue).toLocaleString('en-PH', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }) : '₱0.00';

                    document.getElementById('view_created_by').textContent = createdBy || 'N/A';
                    document.getElementById('view_date_created').textContent = dateCreated ? formatDateTime(dateCreated) : 'N/A';

                    // Load the QR image from the qr_code blob (via streaming endpoint).
                    (function() {
                        var qrImg = document.getElementById('viewQrImage');
                        var qrSpin = document.getElementById('viewQrSpinner');
                        var qrEmpty = document.getElementById('viewQrEmpty');
                        if (!qrImg) return;
                        qrImg.style.display = 'none';
                        qrImg.removeAttribute('src');
                        if (qrEmpty) qrEmpty.style.display = 'none';
                        if (qrSpin) qrSpin.style.display = 'inline-block';
                        var probe = new Image();
                        probe.onload = function() {
                            if (qrSpin) qrSpin.style.display = 'none';
                            qrImg.src = probe.src;
                            qrImg.style.display = 'inline-block';
                        };
                        probe.onerror = function() {
                            if (qrSpin) qrSpin.style.display = 'none';
                            if (qrEmpty) qrEmpty.style.display = 'block';
                        };
                        probe.src = '../actions/view_aircon_qr.php?id=' + encodeURIComponent(airconId) + '&t=' + Date.now();
                    })();

                    // (Re)generate the QR on demand, then reload the visual.
                    var qrRegenBtn = document.getElementById('viewQrRegenBtn');
                    if (qrRegenBtn) {
                        qrRegenBtn.onclick = function() {
                            var qrImg = document.getElementById('viewQrImage');
                            var qrSpin = document.getElementById('viewQrSpinner');
                            var qrEmpty = document.getElementById('viewQrEmpty');
                            if (qrSpin) qrSpin.style.display = 'inline-block';
                            if (qrEmpty) qrEmpty.style.display = 'none';
                            if (qrImg) qrImg.style.display = 'none';
                            fetch('../actions/view_aircon_qr.php?id=' + encodeURIComponent(airconId) + '&regen=1&t=' + Date.now(), { credentials: 'same-origin' })
                                .then(function(res) {
                                    if (!res.ok) throw new Error('HTTP ' + res.status);
                                    return res.blob();
                                })
                                .then(function(blob) {
                                    if (qrSpin) qrSpin.style.display = 'none';
                                    if (qrImg) {
                                        if (qrImg.src && qrImg.src.indexOf('blob:') === 0) URL.revokeObjectURL(qrImg.src);
                                        qrImg.src = URL.createObjectURL(blob);
                                        qrImg.style.display = 'inline-block';
                                    }
                                })
                                .catch(function() {
                                    if (qrSpin) qrSpin.style.display = 'none';
                                    if (qrEmpty) {
                                        qrEmpty.textContent = 'Could not generate the QR code for this unit.';
                                        qrEmpty.style.display = 'block';
                                    }
                                });
                        };
                    }

                    // Update pictures gallery
                    const gallery = document.getElementById('airconPicturesGallery');
                    gallery.innerHTML = '<div class="spinner-border text-primary spinner-border-sm" role="status"><span class="visually-hidden">Loading images...</span></div>';

                    fetch('../actions/get_aircon_images.php?aircon_id=' + airconId)
                        .then(response => response.json())
                        .then(data => {
                            gallery.innerHTML = '';
                            if (data.success && data.images.length > 0) {
                                data.images.forEach(imgUrl => {
                                    const imgContainer = document.createElement('div');
                                    imgContainer.style.position = 'relative';

                                    const img = document.createElement('img');
                                    img.src = '../' + imgUrl;
                                    img.alt = 'Aircon Picture';
                                    img.className = 'img-thumbnail';
                                    img.style.width = '150px';
                                    img.style.height = '150px';
                                    img.style.objectFit = 'cover';
                                    img.style.cursor = 'pointer';

                                    // Open in new tab on click
                                    img.onclick = () => window.open('../' + imgUrl, '_blank');

                                    imgContainer.appendChild(img);
                                    gallery.appendChild(imgContainer);
                                });
                            } else {
                                gallery.innerHTML =
                                    '<div class="aircon-default-pic" title="No picture uploaded for this unit">' +
                                    '<img src="../uploads/DCC2.png" alt="DCC2 default logo">' +
                                    '<div class="aircon-default-caption">No picture for this unit</div>' +
                                    '</div>';
                            }
                        })
                        .catch(error => {
                            console.error('Error fetching images:', error);
                            gallery.innerHTML = '<p class="text-danger">Error loading images.</p>';
                        });

                    // Show modal
                    const modal = new bootstrap.Modal(document.getElementById('viewAirconModal'));
                    modal.show();
                }

                // Helper function to format date
                function formatDate(dateString) {
                    if (!dateString || dateString === '0000-00-00') return 'N/A';
                    const date = new Date(dateString);
                    const options = {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric'
                    };
                    return date.toLocaleDateString('en-US', options);
                }

                // Helper function to format datetime
                function formatDateTime(dateString) {
                    if (!dateString) return 'N/A';
                    const date = new Date(dateString);
                    const options = {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    };
                    return date.toLocaleDateString('en-US', options);
                }

                // Function to print aircon details
                function printAirconDetails() {
                    const printContent = document.querySelector('#viewAirconModal .modal-body').innerHTML;
                    const printWindow = window.open('', '', 'height=600,width=800');
                    printWindow.document.write('<html><head><title>Aircon Details</title>');
                    printWindow.document.write('<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">');
                    printWindow.document.write('<style>body { padding: 20px; } .card { margin-bottom: 20px; page-break-inside: avoid; }</style>');
                    printWindow.document.write('</head><body>');
                    printWindow.document.write('<h2 class="text-center mb-4">Aircon Unit Details</h2>');
                    printWindow.document.write(printContent);
                    printWindow.document.write('</body></html>');
                    printWindow.document.close();
                    printWindow.print();
                }

                // Function to delete aircon
                function deleteAircon(airconId) {
                    if (confirm('Are you sure you want to delete this aircon unit? This action cannot be undone.')) {
                        // Create a form and submit it
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = '../actions/delete_aircon.php';

                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'aircon_id';
                        input.value = airconId;

                        form.appendChild(input);
                        document.body.appendChild(form);
                        form.submit();
                    }
                }

                // ── QR Code Printing: escape helper ─────────────────────────
                function escapeHtmlQr(text) {
                    if (!text) return '';
                    var div = document.createElement('div');
                    div.textContent = text;
                    return div.innerHTML;
                }

                // Fetch and display QR code for a single aircon below its button
                function printSingleAirconQr(airconId, btn) {
                    var displayDiv = document.getElementById('qr-display-' + airconId);
                    if (!displayDiv) return;
                    if (displayDiv.style.display === 'block') {
                        displayDiv.style.display = 'none';
                        return;
                    }
                    document.querySelectorAll('.qr-print-container').forEach(function(d) { d.style.display = 'none'; });
                    displayDiv.innerHTML = '<div class="text-center"><div class="spinner-border text-success" role="status"><span class="visually-hidden">Loading QR...</span></div></div>';
                    displayDiv.style.display = 'block';
                    fetch('../actions/view_aircon_qr.php?id=' + encodeURIComponent(airconId) + '&t=' + Date.now(), { credentials: 'same-origin' })
                        .then(function(res) { if (!res.ok) throw new Error('HTTP ' + res.status); return res.blob(); })
                        .then(function(blob) {
                            var url = URL.createObjectURL(blob);
                            var itemNum = btn.getAttribute('data-item-number') || '';
                            var serialNum = btn.getAttribute('data-serial-number') || '';
                            var brand = btn.getAttribute('data-brand') || '';
                            var model = btn.getAttribute('data-model') || '';
                            var campus = btn.getAttribute('data-campus') || '';
                            var location = btn.getAttribute('data-location') || '';
                            var displayCode = (itemNum && itemNum !== 'N/A') ? itemNum : (serialNum && serialNum !== 'N/A') ? serialNum : ('AC-' + String(airconId).padStart(4, '0'));
                            displayDiv.innerHTML = '<div class="card border-0 shadow-sm qr-card" style="max-width:260px; margin:0 auto;"><div class="card-body p-3 text-center"><img src="' + url + '" alt="QR Code" style="width:140px;height:140px;object-fit:contain;border:1px solid #dee2e6;border-radius:8px;background:#fff;padding:8px;" onload="URL.revokeObjectURL(\'' + url + '\')"><div class="mt-3"><small class="text-muted d-block">Aircon ID: <strong>#' + airconId + '</strong></small><small class="text-muted d-block">Code: <strong>' + escapeHtmlQr(displayCode) + '</strong></small><small class="text-muted d-block">' + escapeHtmlQr(brand) + ' ' + escapeHtmlQr(model) + '</small><small class="text-muted d-block">' + escapeHtmlQr(campus) + ' - ' + escapeHtmlQr(location) + '</small><button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="window.print()"><i class="fas fa-print me-1"></i> Print This QR</button></div></div></div>';
                        })
                        .catch(function(err) { console.error('QR fetch error:', err); displayDiv.innerHTML = '<div class="alert alert-danger mt-2 text-center small">Could not load QR code for this unit.</div>'; });
                }

                // Print all aircon QR codes with their unique codes
                var printAllQrWindow = null;

                function printAllAirconQr() {
                    // Step 0: Open the print window SYNCHRONOUSLY inside the user's
                    // click gesture. Opening it after the async QR fetches below gets
                    // blocked by popup blockers (window.open returns null), which made
                    // renderAllQrPrint crash with "Cannot read properties of null".
                    printAllQrWindow = window.open('', 'airconQrPrint', 'height=800,width=1200');
                    if (!printAllQrWindow) {
                        alert('The print window was blocked by your browser.\nPlease allow pop-ups for this site and try again.');
                        return;
                    }
                    try {
                        printAllQrWindow.document.write('<html><head><title>Print All Aircon QR Codes</title>');
                        printAllQrWindow.document.write('<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">');
                        printAllQrWindow.document.write('<style>body { padding: 20px; font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; } h1 { text-align: center; color: #073b1d; margin-bottom: 30px; } .qr-print-item { page-break-inside:avoid; margin-bottom:20px; border:1px solid #dee2e6; border-radius:8px; padding:15px; display:inline-block; width:240px; vertical-align:top; text-align:center; background:#fff; } .qr-print-item img { width:120px; height:120px; object-fit:contain; border:1px solid #dee2e6; border-radius:6px; background:#fff; padding:6px; } .print-header { text-align:center; margin-bottom:30px; } @media print { body { padding: 0; } .no-print { display:none; } }</style></head><body>');
                        printAllQrWindow.document.write('<div class="print-header"><h1><i class="fas fa-qrcode me-2"></i>Aircon QR Code Labels</h1><p class="text-muted" id="qr-progress">Preparing… please keep this window open.</p></div>');
                        printAllQrWindow.document.write('<div class="qr-print-grid" id="qr-print-grid"></div>');
                        printAllQrWindow.document.write('</body></html>');
                        printAllQrWindow.document.close();
                    } catch (e) {
                        console.error('Print window write error:', e);
                    }

                    // Step 1: Fetch ALL aircon records (ignoring pagination/filters) via AJAX
                    fetch('aircon_list.php?ajax=1&print_all_qr=1&t=' + Date.now(), { credentials: 'same-origin' })
                        .then(function(response) {
                            if (!response.ok) throw new Error('HTTP ' + response.status);
                            return response.json();
                        })
                        .then(function(aircons) {
                            if (!aircons || aircons.length === 0) {
                                alert('No aircon units found.');
                                return;
                            }
                            var qrItems = [], pending = 0;
                            var totalQr = aircons.length;
                            var updateProgress = function() {
                                try {
                                    if (printAllQrWindow && !printAllQrWindow.closed) {
                                        var el = printAllQrWindow.document.getElementById('qr-progress');
                                        if (el) el.textContent = 'Loaded ' + (totalQr - pending) + ' of ' + totalQr + ' QR codes…';
                                    }
                                } catch (e) { /* window navigated away */ }
                            };
                            aircons.forEach(function(ac) {
                                var airconId = ac.id;
                                var itemNum = ac.item_number || '';
                                var serialNum = ac.serial_number || '';
                                var brand = ac.brand || '';
                                var model = ac.model || '';
                                var campus = ac.campus || '';
                                var location = ac.location || '';
                                var displayCode = (itemNum && itemNum !== 'N/A') ? itemNum : (serialNum && serialNum !== 'N/A') ? serialNum : ('AC-' + String(airconId).padStart(4, '0'));
                                pending++;
                                fetch('../actions/view_aircon_qr.php?id=' + encodeURIComponent(airconId) + '&t=' + Date.now(), { credentials: 'same-origin' })
                                    .then(function(res) { if (!res.ok) throw new Error('HTTP ' + res.status); return res.blob(); })
                                    .then(function(blob) {
                                        qrItems.push({ id: airconId, code: displayCode, brand: brand, model: model, campus: campus, location: location, imgUrl: URL.createObjectURL(blob) });
                                        pending--;
                                        updateProgress();
                                        if (pending === 0) renderAllQrPrint(qrItems);
                                    })
                                    .catch(function(err) { console.error('QR fetch error for ' + airconId + ':', err); pending--; updateProgress(); if (pending === 0) renderAllQrPrint(qrItems); });
                            });
                        })
                        .catch(function(err) {
                            console.error('Fetch all aircons error:', err);
                            alert('Failed to load aircon list: ' + err.message);
                        });
                }

                function renderAllQrPrint(items) {
                    var printWindow = printAllQrWindow;
                    if (!printWindow || printWindow.closed) {
                        alert('The print window was closed before the QR codes were ready. Please try again.');
                        return;
                    }
                    if (items.length === 0) {
                        alert('No QR codes could be loaded.');
                        return;
                    }
                    items.sort(function(a, b) { return parseInt(a.id) - parseInt(b.id); });
                    var printHtml = '';
                    items.forEach(function(item) {
                        printHtml += '<div class="qr-print-item" style="page-break-inside:avoid; margin-bottom:20px; border:1px solid #dee2e6; border-radius:8px; padding:15px; display:inline-block; width:240px; vertical-align:top; text-align:center;"><img src="' + item.imgUrl + '" alt="QR Code" style="width:120px;height:120px;object-fit:contain;border:1px solid #dee2e6;border-radius:6px;background:#fff;padding:6px;"><div class="mt-3"><div><strong style="font-size:0.9rem;">Aircon #</strong> <span style="font-size:1.1rem; color:#073b1d;">' + item.id + '</span></div><div><small class="text-muted d-block">Code:</small> <strong style="font-size:0.85rem; word-break:break-all;">' + escapeHtmlQr(item.code) + '</strong></div><div><small class="text-muted d-block">Brand / Model:</small> <span style="font-size:0.8rem;">' + escapeHtmlQr(item.brand) + ' / ' + escapeHtmlQr(item.model) + '</span></div><div><small class="text-muted d-block">Campus / Location:</small> <span style="font-size:0.8rem;">' + escapeHtmlQr(item.campus) + ' / ' + escapeHtmlQr(item.location) + '</span></div></div></div>';
                    });
                    try {
                        printWindow.document.open();
                        printWindow.document.write('<html><head><title>Print All Aircon QR Codes</title>');
                        printWindow.document.write('<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">');
                        printWindow.document.write('<style>body { padding: 20px; font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; } h1 { text-align: center; color: #073b1d; margin-bottom: 30px; } .qr-print-item { page-break-inside:avoid; margin-bottom:20px; border:1px solid #dee2e6; border-radius:8px; padding:15px; display:inline-block; width:240px; vertical-align:top; text-align:center; background:#fff; } .qr-print-item img { width:120px; height:120px; object-fit:contain; border:1px solid #dee2e6; border-radius:6px; background:#fff; padding:6px; } .print-header { text-align:center; margin-bottom:30px; } @media print { body { padding: 0; } .no-print { display:none; } }</style></head><body>');
                        printWindow.document.write('<div class="print-header"><h1><i class="fas fa-qrcode me-2"></i>Aircon QR Code Labels</h1><p class="text-muted">Total: ' + items.length + ' unit(s) | Printed: ' + new Date().toLocaleString() + '</p></div>');
                        printWindow.document.write('<div class="qr-print-grid">' + printHtml + '</div>');
                        printWindow.document.write('<div class="text-center mt-4 no-print"><button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print me-1"></i> Print</button><button class="btn btn-secondary ms-2" onclick="window.close()"><i class="fas fa-times me-1"></i> Close</button></div>');
                        printWindow.document.write('</body></html>');
                        printWindow.document.close();
                        printWindow.focus();
                        setTimeout(function() { try { printWindow.print(); } catch (e) {} }, 500);
                    } catch (e) {
                        console.error('Print render error:', e);
                        alert('Could not render the print window. Please allow pop-ups for this site and try again.');
                    }
                }
            

            // Add this to your aircon_list.php, preferably in the head section or before the closing body tag
            function openEditAirconModal(
                aircon_id, item_name, category, brand, model, type, horse_power, serial_number, location, area, status, campus,
                purchase_date, warranty_expiry, last_service_date, maintenance_schedule, supplier_id,
                installation_date, energy_efficient, power_consumption, notes, purchase_price, depreciated_value, lifespan, picture
            ) {
                // Format dates for input fields (YYYY-MM-DD)
                const formatDate = (dateString) => {
                    if (!dateString) return '';
                    const date = new Date(dateString);
                    // Check if date is valid
                    if (isNaN(date.getTime())) return '';
                    return date.toISOString().split('T')[0];
                };

                // Set form values
                document.getElementById('edit_aircon_id').value = aircon_id;
                document.getElementById('edit_item_name').value = item_name || '';
                document.getElementById('edit_category').value = category || '';
                document.getElementById('edit_brand').value = brand || '';
                document.getElementById('edit_model').value = model || '';
                document.getElementById('edit_type').value = type || '';
                document.getElementById('edit_horse_power').value = horse_power || '';
                document.getElementById('edit_serial_number').value = serial_number || '';
                document.getElementById('edit_location').value = location || '';
                document.getElementById('edit_area').value = area || '';
                document.getElementById('edit_status').value = status || 'Working';
                // Campus: DB holds BED/TED (case-insensitive); tolerate null/0/whitespace.
                // Also handle legacy values that are not in the dropdown: add a
                // temporary selected option instead of silently resetting to blank.
                (function() {
                    var campusSel = document.getElementById('edit_campus');
                    if (!campusSel) return;
                    var norm = (campus === null || campus === undefined) ? '' : String(campus).trim();
                    if (norm === '0') norm = '';
                    var upper = norm.toUpperCase();
                    var match = '';
                    for (var i = 0; i < campusSel.options.length; i++) {
                        if (campusSel.options[i].value.toUpperCase() === upper && upper !== '') {
                            match = campusSel.options[i].value;
                            break;
                        }
                    }
                    if (match !== '') {
                        campusSel.value = match;
                    } else if (norm !== '') {
                        var tmp = document.createElement('option');
                        tmp.value = norm;
                        tmp.textContent = norm + ' (saved value)';
                        tmp.selected = true;
                        campusSel.appendChild(tmp);
                        campusSel.value = norm;
                    } else {
                        campusSel.value = '';
                    }
                })();
                document.getElementById('edit_purchase_date').value = formatDate(purchase_date);
                document.getElementById('edit_warranty_expiry').value = formatDate(warranty_expiry);
                document.getElementById('edit_last_service').value = formatDate(last_service_date);
                document.getElementById('edit_maintenance_schedule').value = formatDate(maintenance_schedule);
                document.getElementById('edit_supplier_id').value = supplier_id || '';
                document.getElementById('edit_installation_date').value = formatDate(installation_date);
                document.getElementById('edit_energy_efficient').value = energy_efficient || '';
                document.getElementById('edit_power_consumption').value = power_consumption || '';
                document.getElementById('edit_notes').value = notes || '';
                document.getElementById('edit_purchase_price').value = purchase_price || '';
                document.getElementById('edit_depreciated_value').value = depreciated_value || '';
                document.getElementById('edit_lifespan').value = lifespan || '';

                // Reset previews and buffers
                clearImagePreview('edit');

                // Fetch and show existing pictures
                const existingGallery = document.getElementById('existing_pictures_gallery');
                const existingContainer = document.getElementById('existing_pictures_container');
                existingGallery.innerHTML = '<div class="spinner-border text-primary spinner-border-sm" role="status"></div>';
                existingContainer.style.display = 'block';

                fetch('../actions/get_aircon_images.php?aircon_id=' + aircon_id)
                    .then(response => response.json())
                    .then(data => {
                        existingGallery.innerHTML = '';
                        existingContainer.style.display = 'block';

                        if (data.success && data.images.length > 0) {
                            data.images.forEach(imgUrl => {
                                const wrapper = document.createElement('div');
                                wrapper.style.position = 'relative';
                                wrapper.className = 'position-relative';

                                const img = document.createElement('img');
                                img.src = '../' + imgUrl;
                                img.className = 'img-thumbnail';
                                img.style.width = '80px';
                                img.style.height = '80px';
                                img.style.objectFit = 'cover';

                                const delBtn = document.createElement('button');
                                delBtn.type = 'button';
                                delBtn.className = 'btn btn-danger btn-sm position-absolute top-0 end-0 p-0';
                                delBtn.style.width = '20px';
                                delBtn.style.height = '20px';
                                delBtn.style.lineHeight = '18px';
                                delBtn.innerHTML = '&times;';
                                delBtn.title = 'Remove Image';
                                delBtn.onclick = () => {
                                    if (confirm('Are you sure you want to delete this image?')) {
                                        fetch('../actions/delete_aircon_image.php', {
                                                method: 'POST',
                                                headers: {
                                                    'Content-Type': 'application/x-www-form-urlencoded'
                                                },
                                                body: 'image_path=' + encodeURIComponent(imgUrl) + '&aircon_id=' + aircon_id
                                            })
                                            .then(r => r.json())
                                            .then(res => {
                                                if (res.success) {
                                                    wrapper.remove();
                                                    if (existingGallery.children.length === 0) {
                                                        existingContainer.style.display = 'none';
                                                    }
                                                } else {
                                                    alert('Error: ' + res.message);
                                                }
                                            });
                                    }
                                };

                                wrapper.appendChild(img);
                                wrapper.appendChild(delBtn);
                                existingGallery.appendChild(wrapper);
                            });
                        } else {
                            const wrapper = document.createElement('div');
                            wrapper.className = 'aircon-default-pic';
                            wrapper.title = 'No picture uploaded for this unit';
                            const img = document.createElement('img');
                            img.src = '../uploads/DCC2.png';
                            img.alt = 'DCC2 default logo';
                            img.style.width = '80px';
                            img.style.height = '80px';
                            img.style.objectFit = 'cover';
                            const cap = document.createElement('div');
                            cap.className = 'aircon-default-caption';
                            cap.textContent = 'No picture for this unit';
                            wrapper.appendChild(img);
                            wrapper.appendChild(cap);
                            existingGallery.appendChild(wrapper);
                        }
                    });

                // Show the modal
                const modal = new bootstrap.Modal(document.getElementById('editAirconModal'));
                modal.show();
            }


            function deleteAircon(aircon_id, brand) {
                if (confirm(`Are you sure you want to delete "${brand}"? This action cannot be undone.`)) {
                    fetch('../actions/delete_aircon.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                aircon_id: aircon_id
                            })
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert(data.message);
                                location.reload();
                            } else {
                                alert('Error: ' + data.message);
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('An error occurred while deleting the item.');
                        });
                }
            }
        