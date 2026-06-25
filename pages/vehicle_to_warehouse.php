<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

$reps = $pdo->query("SELECT id, name FROM users WHERE role = 'rep' ORDER BY name ASC")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        padding: 24px 0 16px;
        margin-bottom: 24px;
    }
    .page-title { font-size: 1.8rem; font-weight: 700; letter-spacing: -0.8px; color: var(--ios-label); margin: 0; }
    .page-subtitle { font-size: 0.85rem; color: var(--ios-label-2); margin-top: 4px; }
    
    .ios-input, .form-select {
        background: var(--ios-surface);
        border: 1px solid var(--ios-separator);
        border-radius: 10px;
        padding: 10px 14px;
        font-size: 0.9rem;
        color: var(--ios-label);
        transition: all 0.2s ease;
        box-shadow: none;
        width: 100%;
        min-height: 42px;
    }
    .ios-input:focus, .form-select:focus {
        background: #fff;
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(48,200,138,0.15) !important;
        outline: none;
    }
    .ios-label-sm {
        display: block;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--ios-label-2);
        margin-bottom: 6px;
        padding-left: 4px;
    }

    .stock-item-row {
        background: #f8f9fa;
        border-radius: 12px;
        padding: 12px 16px;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 12px;
        transition: all 0.2s ease;
    }
    .stock-item-row:hover {
        background: #f0f1f3;
    }
    .stock-item-row .item-info {
        flex: 1;
        min-width: 0;
    }
    .stock-item-row .item-name {
        font-weight: 600;
        font-size: 0.9rem;
        color: #1c1c1e;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .stock-item-row .item-sku {
        font-size: 0.75rem;
        color: #8e8e93;
    }
    .stock-item-row .item-available {
        font-size: 0.8rem;
        font-weight: 600;
        color: #5856D6;
        white-space: nowrap;
    }
    .stock-item-row .qty-input {
        width: 100px;
        text-align: center;
        font-weight: 700;
    }
    .stock-summary {
        background: rgba(48,200,138,0.06);
        border: 1px solid rgba(48,200,138,0.2);
        border-radius: 12px;
        padding: 16px 20px;
    }
    .stock-summary .summary-label {
        font-size: 0.78rem;
        color: #8e8e93;
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.02em;
    }
    .stock-summary .summary-value {
        font-size: 1.2rem;
        font-weight: 700;
        color: #1c1c1e;
    }
    #loadingIndicator {
        display: none;
        text-align: center;
        padding: 40px;
        color: #8e8e93;
    }
    #loadingIndicator .spinner-border {
        width: 2.5rem;
        height: 2.5rem;
    }
    #noStockMessage {
        display: none;
        text-align: center;
        padding: 40px 20px;
        color: #8e8e93;
    }
    #noStockMessage i {
        font-size: 3rem;
        display: block;
        margin-bottom: 12px;
        color: #c7c7cc;
    }
    .success-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: rgba(52,199,89,0.1);
        color: #1A9A3A;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
    }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Vehicle → Warehouse</h1>
        <div class="page-subtitle">Return unsold stock from a Sales Rep's vehicle back to the main inventory.</div>
    </div>
</div>

<div id="messageContainer"></div>

<div class="row">
    <div class="col-lg-8">
        <div class="dash-card overflow-hidden">
            <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
                <span class="card-title">
                    <span class="card-title-icon" style="background: rgba(255,149,0,0.1); color: #E07800;">
                        <i class="bi bi-box-arrow-in-left"></i>
                    </span>
                    Return Stock to Warehouse
                </span>
            </div>
            
            <div class="p-4" style="background: #fff;">
                <!-- Step 1: Select Rep -->
                <div class="mb-4 pb-4 border-bottom border-secondary border-opacity-10">
                    <label class="ios-label-sm">Select Sales Rep <span class="text-danger">*</span></label>
                    <select id="repSelect" class="form-select fw-bold" style="background: #fff; font-size: 1.1rem; padding: 12px 14px;" required>
                        <option value="">-- Choose Rep --</option>
                        <?php foreach($reps as $rep): ?>
                            <option value="<?php echo $rep['id']; ?>"><?php echo htmlspecialchars($rep['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Step 2: Vehicle Stock Items -->
                <div id="loadingIndicator">
                    <div class="spinner-border text-success mb-3" role="status"></div>
                    <div>Loading vehicle stock...</div>
                </div>

                <div id="noStockMessage">
                    <i class="bi bi-archive"></i>
                    <div class="fw-bold mb-1">No Stock Available</div>
                    <div style="font-size: 0.85rem;">This rep has no stock loaded on their vehicle.</div>
                </div>

                <div id="stockItemsContainer" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold mb-0" style="color: var(--ios-label); font-size: 0.95rem;">
                            <i class="bi bi-boxes me-1 text-warning"></i> Items Currently in Vehicle
                        </h6>
                        <span id="repNameBadge" class="success-badge"><i class="bi bi-person-fill"></i> <span id="repNameDisplay"></span></span>
                    </div>
                    <div id="stockList"></div>

                    <!-- Summary -->
                    <div class="stock-summary mt-4">
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="summary-label">Items Selected</div>
                                <div class="summary-value" id="itemsSelectedCount">0</div>
                            </div>
                            <div class="col-6">
                                <div class="summary-label">Total Qty to Return</div>
                                <div class="summary-value" id="totalQtyToReturn">0</div>
                            </div>
                        </div>
                    </div>

                    <div class="text-end mt-4">
                        <button type="button" class="btn btn-primary px-4 fw-bold rounded-pill" id="confirmTransferBtn" disabled style="padding: 12px 30px;">
                            <i class="bi bi-check-lg me-2"></i> Confirm Return to Warehouse
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="dash-card overflow-hidden">
            <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
                <span class="card-title">
                    <span class="card-title-icon" style="background: rgba(0,122,255,0.1); color: #0055CC;">
                        <i class="bi bi-info-circle"></i>
                    </span>
                    How It Works
                </span>
            </div>
            <div class="p-4" style="background: #fff;">
                <ol class="mb-0" style="font-size: 0.85rem; color: #3c3c43; line-height: 1.8; padding-left: 20px;">
                    <li>Select a Sales Rep to view their current vehicle stock.</li>
                    <li>Enter the quantities of each product you're returning to the warehouse.</li>
                    <li>The system will deduct stock from the vehicle and add it back to the main warehouse.</li>
                    <li>Stock logs are recorded for full traceability.</li>
                </ol>
                <hr>
                <p class="mb-0 text-muted" style="font-size: 0.8rem;">
                    <i class="bi bi-shield-check me-1"></i> All transfers are logged and auditable.
                </p>
            </div>
        </div>

        <div class="dash-card overflow-hidden mt-4">
            <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
                <span class="card-title">
                    <span class="card-title-icon" style="background: rgba(255,59,48,0.1); color: #CC2200;">
                        <i class="bi bi-exclamation-triangle"></i>
                    </span>
                    Important
                </span>
            </div>
            <div class="p-4" style="background: #fff;">
                <p class="mb-0" style="font-size: 0.82rem; color: #3c3c43;">
                    This operation is <strong>irreversible</strong>. Ensure quantities entered are accurate. 
                    If the rep has an uncompleted route, ensure the route is <strong>completed</strong> first 
                    before returning stock.
                </p>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const repSelect = document.getElementById('repSelect');
    const loadingIndicator = document.getElementById('loadingIndicator');
    const noStockMessage = document.getElementById('noStockMessage');
    const stockItemsContainer = document.getElementById('stockItemsContainer');
    const stockList = document.getElementById('stockList');
    const repNameDisplay = document.getElementById('repNameDisplay');
    const repNameBadge = document.getElementById('repNameBadge');
    const itemsSelectedCount = document.getElementById('itemsSelectedCount');
    const totalQtyToReturn = document.getElementById('totalQtyToReturn');
    const confirmTransferBtn = document.getElementById('confirmTransferBtn');
    const messageContainer = document.getElementById('messageContainer');

    let currentRepId = 0;
    let currentAssignmentId = null;
    let stockItems = [];

    // --- Rep Selection Change ---
    repSelect.addEventListener('change', function() {
        const repId = parseInt(this.value);
        if (!repId) {
            stockItemsContainer.style.display = 'none';
            noStockMessage.style.display = 'none';
            return;
        }

        currentRepId = repId;
        currentAssignmentId = null;
        loadVehicleStock(repId);
        loadAssignment(repId);
    });

    // --- Load Vehicle Stock ---
    function loadVehicleStock(repId) {
        stockList.innerHTML = '';
        stockItemsContainer.style.display = 'none';
        noStockMessage.style.display = 'none';
        loadingIndicator.style.display = 'block';

        // Get rep name for badge
        const repName = repSelect.options[repSelect.selectedIndex].text;
        repNameDisplay.textContent = repName;

        const formData = new FormData();
        formData.append('action', 'get_stock');
        formData.append('rep_id', repId);

        fetch('../ajax/transfer_vehicle_stock.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            loadingIndicator.style.display = 'none';

            if (data.success && data.items && data.items.length > 0) {
                stockItems = data.items;
                renderStockItems(data.items);
                stockItemsContainer.style.display = 'block';
                updateSummary();
            } else {
                noStockMessage.style.display = 'block';
            }
        })
        .catch(err => {
            loadingIndicator.style.display = 'none';
            showMessage('danger', 'Failed to load vehicle stock. Please try again.');
        });
    }

    // --- Load Latest Completed Assignment ---
    function loadAssignment(repId) {
        const formData = new FormData();
        formData.append('action', 'get_assignment');
        formData.append('rep_id', repId);

        fetch('../ajax/transfer_vehicle_stock.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.assignment_id) {
                currentAssignmentId = data.assignment_id;
            }
        })
        .catch(err => {});
    }

    // --- Render Stock Items ---
    function renderStockItems(items) {
        stockList.innerHTML = '';

        items.forEach((item, index) => {
            const row = document.createElement('div');
            row.className = 'stock-item-row';
            row.innerHTML = `
                <div class="item-info">
                    <div class="item-name">${escapeHtml(item.name)}</div>
                    <div class="item-sku">SKU: ${escapeHtml(item.sku || 'N/A')}</div>
                </div>
                <div class="item-available">Avail: <strong>${item.stock_qty}</strong></div>
                <input type="number" class="ios-input qty-input" 
                       min="0" max="${item.stock_qty}" value="0" 
                       data-product-id="${item.product_id}" data-max="${item.stock_qty}"
                       placeholder="Qty">
            `;
            
            const qtyInput = row.querySelector('.qty-input');
            qtyInput.addEventListener('input', function() {
                let val = parseInt(this.value) || 0;
                const max = parseInt(this.dataset.max);
                if (val < 0) val = 0;
                if (val > max) val = max;
                this.value = val;
                updateSummary();
            });

            stockList.appendChild(row);
        });
    }

    // --- Update Summary ---
    function updateSummary() {
        const qtyInputs = document.querySelectorAll('.qty-input');
        let selectedCount = 0;
        let totalQty = 0;

        qtyInputs.forEach(input => {
            const val = parseInt(input.value) || 0;
            if (val > 0) {
                selectedCount++;
                totalQty += val;
            }
        });

        itemsSelectedCount.textContent = selectedCount;
        totalQtyToReturn.textContent = totalQty;
        confirmTransferBtn.disabled = (selectedCount === 0);
    }

    // --- Confirm Transfer ---
    confirmTransferBtn.addEventListener('click', function() {
        if (this.disabled) return;

        if (!confirm('Are you sure you want to return the selected stock to the warehouse? This action cannot be undone.')) {
            return;
        }

        const qtyInputs = document.querySelectorAll('.qty-input');
        const productIds = [];
        const quantities = [];
        let totalCheck = 0;

        qtyInputs.forEach(input => {
            const val = parseInt(input.value) || 0;
            if (val > 0) {
                productIds.push(parseInt(input.dataset.productId));
                quantities.push(val);
                totalCheck += val;
            }
        });

        if (productIds.length === 0) {
            showMessage('warning', 'Please select at least one product with a quantity greater than 0.');
            return;
        }

        confirmTransferBtn.disabled = true;
        confirmTransferBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Processing...';

        const formData = new FormData();
        formData.append('action', 'transfer_stock');
        formData.append('rep_id', currentRepId);
        if (currentAssignmentId) {
            formData.append('assignment_id', currentAssignmentId);
        }
        
        productIds.forEach((id, i) => {
            formData.append('product_id[]', id);
            formData.append('qty[]', quantities[i]);
        });

        fetch('../ajax/transfer_vehicle_stock.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            confirmTransferBtn.disabled = false;
            confirmTransferBtn.innerHTML = '<i class="bi bi-check-lg me-2"></i> Confirm Return to Warehouse';

            if (data.success) {
                showMessage('success', '<i class="bi bi-check-circle-fill me-2"></i> ' + data.message);
                // Reload stock
                loadVehicleStock(currentRepId);
            } else {
                showMessage('danger', '<i class="bi bi-exclamation-triangle-fill me-2"></i> ' + (data.error || 'Transfer failed. Please try again.'));
            }
        })
        .catch(err => {
            confirmTransferBtn.disabled = false;
            confirmTransferBtn.innerHTML = '<i class="bi bi-check-lg me-2"></i> Confirm Return to Warehouse';
            showMessage('danger', '<i class="bi bi-exclamation-triangle-fill me-2"></i> Network error. Please try again.');
        });
    });

    // --- Helper: Show Message ---
    function showMessage(type, html) {
        const icons = {
            success: 'bi-check-circle-fill text-success',
            danger: 'bi-exclamation-triangle-fill text-danger',
            warning: 'bi-exclamation-circle-fill text-warning',
            info: 'bi-info-circle-fill text-info'
        };
        const bg = {
            success: 'rgba(52,199,89,0.1)',
            danger: 'rgba(255,59,48,0.1)',
            warning: 'rgba(255,149,0,0.1)',
            info: 'rgba(0,122,255,0.1)'
        };
        const color = {
            success: '#1A9A3A',
            danger: '#CC2200',
            warning: '#B86800',
            info: '#0055CC'
        };

        messageContainer.innerHTML = `
            <div style="background: ${bg[type]}; color: ${color[type]}; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;">
                ${html}
            </div>
        `;

        setTimeout(() => {
            messageContainer.innerHTML = '';
        }, 6000);
    }

    // --- Helper: Escape HTML ---
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});
</script>

<?php include '../includes/footer.php'; ?>