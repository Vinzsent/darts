
                // Session message variables
                var sessionMessage = window.PAGE_CFG.sessionMessage;
                var sessionError = window.PAGE_CFG.sessionError;

                // ANCHOR: API configuration for stock movements
                var API_BASE_URL = window.PAGE_CFG.apiBaseUrl;
                console.log('Server-generated API base URL:', API_BASE_URL);

                // â”€â”€ QR debug helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
                // Collects every JS error/unhandled rejection so the QR button
                // debug output can show what actually broke.
                window.__qrDebugLog = [];
                function qrDebugLog(entry) {
                    const stamp = new Date().toISOString();
                    const item = '[' + stamp + '] ' + entry;
                    window.__qrDebugLog.push(item);
                    console.warn('[QR DEBUG] ' + entry);
                }
                window.addEventListener('error', function(e) {
                    qrDebugLog('Page error: ' + (e.message || e.type) +
                        ' @ ' + (e.filename || '') + ':' + (e.lineno || '') +
                        (e.error && e.error.stack ? ' | ' + e.error.stack : ''));
                });
                window.addEventListener('unhandledrejection', function(e) {
                    qrDebugLog('Unhandled promise rejection: ' +
                        (e.reason && e.reason.stack ? e.reason.stack : e.reason));
                });

                // Render an error (and its debug details) inside the QR modal.
                function showQrError(message, details) {
                    const spinner = document.getElementById('rowQrModalSpinner');
                    const img = document.getElementById('rowQrModalImage');
                    const errorBox = document.getElementById('rowQrModalError');
                    if (spinner) spinner.style.display = 'none';
                    if (img) img.style.display = 'none';
                    if (errorBox) {
                        errorBox.innerHTML = '<strong>QR Debug Error</strong><br>' +
                            message.replace(/&/g, '&amp;').replace(/</g, '&lt;') +
                            (details ? '<br><small style="word-break:break-all;">' +
                                String(details).replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</small>' : '');
                        errorBox.style.display = 'block';
                    }
                }

                // â”€â”€ Stock row QR codes (property / supply tables) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
                let currentRowQrType = 'supply';
                let currentRowQrId = 0;

                // Show the QR modal for one inventory/property row. The image
                // endpoint streams the stored BLOB (or generates it on demand).
                function viewRowQr(type, id) {
                    qrDebugLog('viewRowQr called: type=' + type + ', id=' + id);

                    try {
                        const img = document.getElementById('rowQrModalImage');
                        const spinner = document.getElementById('rowQrModalSpinner');
                        const errorBox = document.getElementById('rowQrModalError');
                        const modalEl = document.getElementById('rowQrModal');

                        if (!img || !spinner || !errorBox || !modalEl) {
                            qrDebugLog('Missing modal DOM: img=' + !!img + ', spinner=' + !!spinner +
                                ', errorBox=' + !!errorBox + ', modalEl=' + !!modalEl);
                            alert('[QR DEBUG] rowQrModal elements missing from page DOM.');
                            return;
                        }
                        if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
                            qrDebugLog('bootstrap.Modal unavailable (Bootstrap JS not loaded).');
                            alert('[QR DEBUG] Bootstrap JS is not loaded - modal cannot open.');
                            return;
                        }

                        currentRowQrType = type;
                        currentRowQrId = id;

                        // Reset modal state.
                        img.style.display = 'none';
                        img.removeAttribute('src');
                        errorBox.style.display = 'none';
                        errorBox.textContent = '';
                        spinner.style.display = 'block';

                        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                        modal.show();

                        const url = '../actions/view_stock_qr.php?type=' + encodeURIComponent(type) +
                            '&id=' + encodeURIComponent(id) + '&t=' + Date.now();
                        const absoluteUrl = new URL(url, window.location.href).toString();
                        qrDebugLog('Requesting QR image: ' + absoluteUrl);

                        // Probe the endpoint first so HTTP errors are reported
                        // with status code instead of a silently broken image.
                        fetch(url, { credentials: 'same-origin' })
                            .then(function(response) {
                                qrDebugLog('Endpoint responded: status=' + response.status +
                                    ' ' + response.statusText +
                                    ', content-type=' + (response.headers.get('content-type') || 'n/a'));
                                if (!response.ok) {
                                    return response.text().then(function(body) {
                                        showQrError('QR endpoint returned HTTP ' + response.status + '.',
                                            absoluteUrl + ' | body: ' + body.substring(0, 300));
                                    });
                                }
                                return response.blob().then(function(blob) {
                                    qrDebugLog('Blob fetched: size=' + blob.size +
                                        ', type=' + (blob.type || 'n/a'));
                                    if (!blob.size) {
                                        showQrError('QR endpoint returned an empty response (0 bytes).', absoluteUrl);
                                        return;
                                    }
                                    if (img.src && img.src.indexOf('blob:') === 0) {
                                        URL.revokeObjectURL(img.src);
                                    }
                                    img.src = URL.createObjectURL(blob);
                                });
                            })
                            .catch(function(fetchError) {
                                qrDebugLog('Fetch of QR endpoint failed: ' +
                                    (fetchError && fetchError.stack ? fetchError.stack : fetchError));
                                showQrError('Could not reach the QR endpoint: ' + fetchError.message, absoluteUrl);
                            });

                        img.onload = function() {
                            spinner.style.display = 'none';
                            img.style.display = 'block';
                            qrDebugLog('QR image rendered: naturalWidth=' + img.naturalWidth +
                                ', naturalHeight=' + img.naturalHeight);
                        };
                        img.onerror = function() {
                            spinner.style.display = 'none';
                            qrDebugLog('QR <img> onerror fired for: ' + (img.src || '(no src)'));
                            showQrError('Could not load the QR code for this item.',
                                'src=' + (img.src || '(none)'));
                        };
                    } catch (err) {
                        qrDebugLog('Exception in viewRowQr: ' + (err && err.stack ? err.stack : err));
                        showQrError('Unexpected error while opening the QR modal: ' + err.message,
                            err.stack || '');
                    }
                }

                // Open the printable QR label sheet (all rows, or just one when
                // an id is passed by the QR modal's Print button).
                function printStockQr(type, id) {
                    let url = 'print_stock_qr.php?type=' + encodeURIComponent(type);
                    if (id) url += '&id=' + encodeURIComponent(id);
                    window.open(url, '_blank');
                }

                // Wire the modal Print button once (type/id captured on open).
                document.addEventListener('DOMContentLoaded', function() {
                    const printBtn = document.getElementById('rowQrPrintBtn');
                    if (printBtn) {
                        printBtn.addEventListener('click', function() {
                            printStockQr(currentRowQrType, currentRowQrId);
                        });
                    }
                });

                $(document).ready(function() {
                    $('.supplier-select2').each(function() {
                        var modal = $(this).closest('.modal');
                        $(this).select2({
                            theme: 'bootstrap-5',
                            width: '100%',
                            tags: true,
                            placeholder: "Select or type to add new supplier...",
                            allowClear: true,
                            dropdownParent: modal.length ? modal : $(document.body)
                        });
                    });

                    // Show session message alert if there are messages
                    if (sessionMessage || sessionError) {
                        showSessionMessageAlert();
                    }

                    // Enable Enter key search for inventory items
                    $('#search').on('keypress', function(e) {
                        if (e.which === 13) { // Enter key
                            filterInventoryItems();
                        }
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

                    // Prevent stacked backdrops by hiding Edit modal before submit
                    $('#editInventoryForm').on('submit', function() {
                        const editModalEl = document.getElementById('editInventoryModal');
                        if (editModalEl) {
                            const instance = bootstrap.Modal.getInstance(editModalEl) || new bootstrap.Modal(editModalEl);
                            instance.hide();
                        }
                        // Also remove any lingering backdrops just in case
                        setTimeout(function() {
                            document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
                        }, 150);
                    });

                    // ANCHOR: Handle edit stock movement form submission
                    $('#editStockMovementForm').on('submit', function(e) {
                        e.preventDefault();

                        const formData = $(this).serialize();
                        console.log('Submitting edit stock movement form:', formData);

                        // Show loading state
                        const submitBtn = $(this).find('button[type="submit"]');
                        const originalText = submitBtn.html();
                        submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');

                        $.ajax({
                            url: '../actions/edit_stock_movement.php',
                            method: 'POST',
                            data: formData,
                            dataType: 'json',
                            success: function(data) {
                                console.log('Edit stock movement response:', data);
                                if (data.success) {
                                    alert('Stock movement updated successfully!');
                                    // Close modal
                                    const modal = bootstrap.Modal.getInstance(document.getElementById('editStockMovementModal'));
                                    modal.hide();
                                    // Reload the stock movements table
                                    const syValue = document.getElementById('sy_logs').value;
                                    loadStockMovements(1, syValue);
                                } else {
                                    alert('Error updating stock movement: ' + (data.message || 'Unknown error'));
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('AJAX Error:', status, error);
                                alert('Error: Could not update stock movement. Please check the console for details.');
                            },
                            complete: function() {
                                // Restore button state
                                submitBtn.prop('disabled', false).html(originalText);
                            }
                        });
                    });
                });

                // Function to show session message alert
                function showSessionMessageAlert() {
                    if (sessionMessage) {
                        // Success message
                        alert('âœ… Success!\n\n' + sessionMessage);
                    } else if (sessionError) {
                        // Error message
                        alert('âŒ Error!\n\n' + sessionError);
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
                        url: '../actions/get_inventory_item.php',
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

                    const isIn = (type === 'IN');

                    // Highlight the selected button (solid + active) and dim the other one (outline)
                    $('#stockInBtn')
                        .toggleClass('active', isIn)
                        .toggleClass('btn-success', isIn)
                        .toggleClass('btn-outline-success', !isIn);
                    $('#stockOutBtn')
                        .toggleClass('active', !isIn)
                        .toggleClass('btn-warning', !isIn)
                        .toggleClass('btn-outline-warning', isIn);
                }

                function viewItem(inventoryId) {
                    // Implement view item details functionality
                    console.log('View item:', inventoryId);
                }

                // ANCHOR: Edit stock movement functionality
                function exportStockMovements() {
                    const syValue = document.getElementById('sy_logs').value;
                    const searchValue = document.getElementById('item_search').value;

                    let exportUrl = '../actions/export_stock_movements.php?';
                    const params = new URLSearchParams();
                    if (syValue) params.append('sy_logs', syValue);
                    if (searchValue) params.append('search', searchValue);

                    window.location.href = exportUrl + params.toString();
                }

                function editMovement(logId) {
                    console.log('Edit movement called with ID:', logId);

                    // Fetch stock movement data via AJAX
                    $.ajax({
                        url: '../actions/get_stock_movement.php',
                        method: 'GET',
                        data: {
                            log_id: logId
                        },
                        dataType: 'json',
                        success: function(data) {
                            console.log('Stock movement data:', data);
                            if (data.success) {
                                openEditStockMovementModal(data.movement);
                            } else {
                                alert('Error loading stock movement: ' + (data.message || 'Unknown error'));
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('AJAX Error:', status, error);
                            alert('Error: Could not load stock movement data. Please check the console for details.');
                        }
                    });
                }

                // ANCHOR: Open edit stock movement modal with data
                function openEditStockMovementModal(movement) {
                    console.log('Opening edit stock movement modal with:', movement);

                    // Populate form fields
                    document.getElementById('edit_log_id').value = movement.log_id;
                    document.getElementById('edit_item_name').value = movement.item_name;
                    document.getElementById('edit_movement_type').value = movement.movement_type;
                    document.getElementById('edit_quantity').value = movement.quantity;
                    document.getElementById('edit_previous_stock').value = movement.previous_stock;
                    document.getElementById('edit_new_stock').value = movement.new_stock;
                    document.getElementById('edit_receiver').value = movement.receiver || '';
                    document.getElementById('edit_notes').value = movement.notes || '';
                    document.getElementById('edit_date_created').value = movement.formatted_date;

                    // Open modal
                    const modal = new bootstrap.Modal(document.getElementById('editStockMovementModal'));
                    modal.show();
                    console.log('Edit stock movement modal should now be visible');
                }

                // ANCHOR: Delete stock movement functionality - REMOVED
                // NOTE: Delete functionality has been removed from the interface
                // The deleteMovement function is no longer needed since trash icons were removed

                // ANCHOR: AJAX functions for stock movement filtering
                function filterStockMovements() {
                    const syValue = document.getElementById('sy_logs').value;
                    const searchValue = document.getElementById('item_search').value;
                    loadStockMovements(1, syValue, searchValue);
                }

                function clearStockMovementFilter() {
                    document.getElementById('sy_logs').value = '';
                    const searchValue = document.getElementById('item_search').value;
                    loadStockMovements(1, '', searchValue);
                }

                // ANCHOR: Item search functions for stock movements
                let stockSearchTimeout;

                function searchStockMovements() {
                    clearTimeout(stockSearchTimeout);
                    const searchValue = document.getElementById('item_search').value;
                    const syValue = document.getElementById('sy_logs').value;

                    stockSearchTimeout = setTimeout(function() {
                        if (searchValue.length === 0 || searchValue.length >= 2) {
                            loadStockMovements(1, syValue, searchValue);
                        }
                    }, 500); // Wait 500ms after user stops typing
                }

                function clearItemSearch() {
                    document.getElementById('item_search').value = '';
                    const syValue = document.getElementById('sy_logs').value;
                    loadStockMovements(1, syValue, '');
                }

                // ANCHOR: AJAX functions for inventory items filtering
                function filterInventoryItems() {
                    const searchValue = document.getElementById('search').value;
                    const syValue = document.getElementById('sy_inv').value;
                    loadInventoryItems(1, searchValue, syValue);
                }

                function clearInventoryFilters() {
                    document.getElementById('search').value = '';
                    document.getElementById('sy_inv').value = '';
                    loadInventoryItems(1, '', '');
                }

                // Debounced search function
                let searchTimeout;

                function handleInventorySearch() {
                    clearTimeout(searchTimeout);
                    const searchValue = document.getElementById('search').value;
                    const syValue = document.getElementById('sy_inv').value;

                    searchTimeout = setTimeout(function() {
                        if (searchValue.length === 0 || searchValue.length >= 2) {
                            loadInventoryItems(1, searchValue, syValue);
                        }
                    }, 500); // Wait 500ms after user stops typing
                }

                function loadStockMovements(page = 1, syLogs = '', searchValue = '') {
                    // Show loading indicator
                    const tableBody = document.getElementById('stock-movements-tbody');
                    const paginationContainer = document.getElementById('stock-movements-pagination');

                    // Create loading row
                    tableBody.innerHTML = '<tr><td colspan="8" class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><div class="mt-2">Loading stock movements...</div></td></tr>';

                    // Build API URL - use server-generated base URL for reliability
                    let apiUrl;

                    try {
                        // Try using server-generated base URL first
                        apiUrl = new URL('api/get_stock_movements.php', API_BASE_URL);
                    } catch (e) {
                        // Fallback to dynamic path resolution
                        console.warn('Server base URL failed, using fallback:', e);
                        const currentPath = window.location.pathname;
                        let basePath;

                        if (currentPath.includes('/pages/')) {
                            basePath = currentPath.replace('/pages/', '/');
                        } else if (currentPath.endsWith('/')) {
                            basePath = currentPath;
                        } else {
                            basePath = currentPath + '/';
                        }

                        apiUrl = new URL('api/get_stock_movements.php', window.location.origin + basePath);
                    }

                    apiUrl.searchParams.set('logs_page', page);
                    if (syLogs) {
                        apiUrl.searchParams.set('sy_logs', syLogs);
                    }
                    if (searchValue) {
                        apiUrl.searchParams.set('search', searchValue);
                    }

                    // Debug: Log the API URL being used
                    console.log('Fetching stock movements from:', apiUrl.toString());

                    // Make AJAX request
                    fetch(apiUrl.toString())
                        .then(response => {
                            console.log('Response status:', response.status);
                            console.log('Response URL:', response.url);
                            if (!response.ok) {
                                throw new Error(`HTTP error! status: ${response.status} - URL: ${response.url}`);
                            }
                            return response.json();
                        })
                        .then(data => {
                            console.log('API Response:', data);
                            if (data.success) {
                                // Update table body
                                tableBody.innerHTML = data.table_rows;

                                // Update pagination
                                if (paginationContainer) {
                                    paginationContainer.innerHTML = data.pagination;
                                }

                                // Update URL without page reload (optional, for bookmarking)
                                const url = new URL(window.location);
                                if (syLogs) {
                                    url.searchParams.set('sy_logs', syLogs);
                                } else {
                                    url.searchParams.delete('sy_logs');
                                }
                                url.searchParams.set('logs_page', page);
                                window.history.pushState({}, '', url);

                                // Scroll to stock movements section smoothly
                                document.getElementById('stock-movements').scrollIntoView({
                                    behavior: 'smooth',
                                    block: 'start'
                                });
                            } else {
                                // Handle error
                                console.error('API returned error:', data.message);
                                tableBody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-danger"><i class="fas fa-exclamation-triangle fa-2x mb-2"></i><div>Error loading stock movements: ' + data.message + '</div></td></tr>';
                                if (paginationContainer) paginationContainer.innerHTML = '';
                            }
                        })
                        .catch(error => {
                            console.error('Fetch error:', error);
                            const errorMessage = error.message || 'Unknown error occurred';
                            if (tableBody) tableBody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-danger"><i class="fas fa-exclamation-triangle fa-2x mb-2"></i><div>Error loading stock movements: ' + errorMessage + '</div><small class="text-muted">Check console for more details</small></td></tr>';
                            if (paginationContainer) paginationContainer.innerHTML = '';
                        });
                }

                function loadInventoryItems(page = 1, searchTerm = '', syInv = '') {
                    // Show loading indicator
                    const tableBody = document.getElementById('inventory-tbody');
                    const paginationContainer = document.getElementById('inventory-pagination');

                    // Create loading row
                    tableBody.innerHTML = '<tr><td colspan="9" class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><div class="mt-2">Loading inventory items...</div></td></tr>';

                    // Build API URL - use current file with ajax=1 param
                    let apiUrl;

                    try {
                        const currentPath = window.location.pathname;
                        apiUrl = new URL(currentPath, window.location.origin);
                    } catch (e) {
                        console.warn('URL creation failed:', e);
                        apiUrl = new URL(window.location.href);
                    }

                    apiUrl.searchParams.set('ajax', '1');
                    apiUrl.searchParams.set('page', page);
                    if (searchTerm) {
                        apiUrl.searchParams.set('search', searchTerm);
                    }
                    if (syInv) {
                        apiUrl.searchParams.set('sy_inv', syInv);
                    }

                    // Debug: Log the API URL being used
                    console.log('Fetching inventory items from:', apiUrl.toString());

                    // Make AJAX request
                    fetch(apiUrl.toString())
                        .then(response => {
                            console.log('Response status:', response.status);
                            if (!response.ok) {
                                throw new Error(`HTTP error! status: ${response.status}`);
                            }
                            return response.json();
                        })
                        .then(data => {
                            console.log('API Response:', data);
                            if (data.success) {
                                // Update table body
                                tableBody.innerHTML = data.table_rows;

                                // Update pagination
                                if (paginationContainer) {
                                    paginationContainer.innerHTML = data.pagination;
                                }

                                // Update URL without page reload (optional, for bookmarking)
                                const url = new URL(window.location);
                                if (searchTerm) {
                                    url.searchParams.set('search', searchTerm);
                                } else {
                                    url.searchParams.delete('search');
                                }
                                if (syInv) {
                                    url.searchParams.set('sy_inv', syInv);
                                } else {
                                    url.searchParams.delete('sy_inv');
                                }
                                url.searchParams.set('page', page);
                                url.searchParams.delete('logs_page'); // Remove logs page param
                                window.history.pushState({}, '', url);

                                // Scroll to inventory table smoothly
                                document.querySelector('.table-container').scrollIntoView({
                                    behavior: 'smooth',
                                    block: 'start'
                                });
                            } else {
                                // Handle error
                                console.error('API returned error:', data.message);
                                tableBody.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-danger"><i class="fas fa-exclamation-triangle fa-2x mb-2"></i><div>Error loading inventory items: ' + (data.message || 'Unknown error') + '</div></td></tr>';
                                paginationContainer.innerHTML = '';
                            }
                        })
                        .catch(error => {
                            console.error('Fetch error:', error);
                            const errorMessage = error.message || 'Unknown error occurred';
                            tableBody.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-danger"><i class="fas fa-exclamation-triangle fa-2x mb-2"></i><div>Error loading inventory items: ' + errorMessage + '</div><small class="text-muted">Check console for more details</small></td></tr>';
                            paginationContainer.innerHTML = '';
                        });
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
                    document.getElementById('ai_receiver').value = 'Supply In-charge';
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
                            url: '../actions/get_inventory_item.php',
                            method: 'GET',
                            data: {
                                inventory_id: inventoryId
                            },
                            dataType: 'json',
                            success: function(data) {
                                console.log('AJAX response:', data);
                                if (data.success) {
                                    console.log('Item data for modal:', data.item);
                                    openEditInventoryModal(
                                        data.item.inventory_id,
                                        data.item.item_name,
                                        data.item.category,
                                        data.item.brand || '',
                                        data.item.color || '',
                                        data.item.size || '',
                                        data.item.type || '',
                                        data.item.description || '',
                                        data.item.unit,
                                        data.item.current_stock,
                                        data.item.reorder_level,
                                        data.item.supplier_id || '',
                                        data.item.location || '',
                                        data.item.unit_cost
                                    );
                                } else {
                                    alert('Error loading inventory item: ' + (data.message || 'Unknown error'));
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('AJAX Error:', status, error, xhr && xhr.responseText ? xhr.responseText.slice(0, 1000) : '');
                                var detail = xhr && xhr.responseText ? xhr.responseText.trim().replace(/^<[^>]*>/, '').slice(0, 400) : (error || status || 'Unknown error');
                                alert('Error: Could not load inventory item data. Server said: ' + detail);
                            }
                        });
                    }, currentModalId ? 300 : 0);
                }

                // Open Edit Inventory modal with data
                function openEditInventoryModal(id, name, category, brand, color, size, type, description, unit, stock, reorder, supplierId, location, unitCost) {
                    console.log('openEditInventoryModal called with:', {
                        id,
                        name,
                        category,
                        unit,
                        stock,
                        supplierId,
                        location
                    });

                    const eiInvId = document.getElementById('ei_inventory_id');
                    if (eiInvId) eiInvId.value = id;
                    
                    const eiItemName = document.getElementById('ei_item_name');
                    if (eiItemName) eiItemName.value = name;
                    
                    // Populate specifications
                    const eiBrand = document.getElementById('ei_brand');
                    if (eiBrand) eiBrand.value = brand || '';
                    const eiColor = document.getElementById('ei_color');
                    if (eiColor) eiColor.value = color || '';
                    const eiSize = document.getElementById('ei_size');
                    if (eiSize) eiSize.value = size || '';
                    const eiType = document.getElementById('ei_type');
                    if (eiType) eiType.value = type || '';
                    const eiDesc = document.getElementById('ei_description');
                    if (eiDesc) eiDesc.value = description || '';

                    // Set category value - ensure Category select reflects the value even if it's not preset
                    (function() {
                        const catSelect = document.getElementById('categorySelect');
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
                    // Set unit value - normalize common variants and ensure select shows the value
                    (function() {
                        const unitSelect = document.getElementById('ei_unit');
                        if (!unitSelect) return;

                        // Normalize incoming unit to match existing option values
                        const raw = (unit || '').toString().trim();
                        const key = raw.toLowerCase();
                        const map = {
                            'pc': 'pc',
                            'pcs': 'pc',
                            'piece': 'pc',
                            'pieces': 'pc',
                            'box': 'box',
                            'boxes': 'boxes',
                            'kg': 'kg',
                            'kilogram': 'kg',
                            'kilograms': 'kg',
                            'liter': 'liter',
                            'liters': 'liter',
                            'set': 'set',
                            'sets': 'sets',
                            'pack': 'pack',
                            'packs': 'packs',
                            'unit': 'units',
                            'units': 'units',
                            'ream': 'reams',
                            'reams': 'reams'
                        };
                        const normalized = map[key] || key;

                        // Try to select a matching option by value
                        let hasMatch = false;
                        for (let i = 0; i < unitSelect.options.length; i++) {
                            if (String(unitSelect.options[i].value).toLowerCase() === String(normalized)) {
                                hasMatch = true;
                                break;
                            }
                        }

                        if (hasMatch) {
                            unitSelect.value = normalized;
                        } else if (raw) {
                            // Fallback: append the raw unit so it displays exactly as stored
                            const opt = document.createElement('option');
                            opt.value = raw;
                            opt.textContent = raw;
                            unitSelect.appendChild(opt);
                            unitSelect.value = raw;
                        } else {
                            unitSelect.value = '';
                        }
                    })();
                    $('#ei_current_stock').val(stock);
                    $('#ei_reorder_level').val(reorder);
                    $('#ei_supplier_id').val(supplierId || '').trigger('change');
                    $('#ei_location').val(location || '');
                    $('#ei_unit_cost').val(unitCost || 0);

                    console.log('Opening edit inventory modal...');
                    const modal = new bootstrap.Modal(document.getElementById('editInventoryModal'));
                    modal.show();
                    console.log('Edit inventory modal should now be visible');
                }


                // Handle browser back/forward buttons
                window.addEventListener('popstate', function() {
                    const urlParams = new URLSearchParams(window.location.search);
                    const page = urlParams.get('page') || 1;
                    const search = urlParams.get('search') || '';
                    const syInv = urlParams.get('sy_inv') || '';

                    // Only load inventory if we're not on a logs page
                    const logsPage = urlParams.get('logs_page');
                    if (!logsPage) {
                        loadInventoryItems(parseInt(page), search, syInv);
                    }
                });
            