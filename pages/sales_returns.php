<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); 

// --- DB MIGRATION FOR RETURNS ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_returns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        rep_id INT NULL,
        assignment_id INT NULL,
        total_amount DECIMAL(12,2) DEFAULT 0.00,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_return_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        return_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(12,2) NOT NULL,
        condition_status ENUM('good', 'damaged', 'expired') DEFAULT 'good',
        FOREIGN KEY (return_id) REFERENCES sales_returns(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {}

// --- SELF-HEALING MIGRATION FOR CREDIT NOTES ---
try {
    $cnStmt = $pdo->query("SELECT id, customer_id, paid_amount FROM orders WHERE payment_method = 'Credit Note' AND paid_amount > 0");
    $credit_notes = $cnStmt->fetchAll();
    
    foreach ($credit_notes as $cn) {
        $cn_id = $cn['id'];
        $cust_id = $cn['customer_id'];
        $credit_val = (float)$cn['paid_amount'];
        
        if ($credit_val > 0) {
            $unpaidStmt = $pdo->prepare("SELECT id, total_amount, paid_amount FROM orders WHERE customer_id = ? AND payment_method != 'Credit Note' AND total_amount > paid_amount ORDER BY created_at ASC");
            $unpaidStmt->execute([$cust_id]);
            $unpaid_orders = $unpaidStmt->fetchAll();
            
            $remaining = $credit_val;
            foreach ($unpaid_orders as $uo) {
                if ($remaining <= 0) break;
                
                $due = $uo['total_amount'] - $uo['paid_amount'];
                $apply = min($due, $remaining);
                
                $new_paid = $uo['paid_amount'] + $apply;
                $new_status = ($new_paid >= $uo['total_amount']) ? 'paid' : 'pending';
                
                $up = $pdo->prepare("UPDATE orders SET paid_amount = ?, payment_status = ? WHERE id = ?");
                $up->execute([$new_paid, $new_status, $uo['id']]);
                
                $remaining -= $apply;
            }
            
            if ($remaining > 0) {
                $upCN = $pdo->prepare("UPDATE orders SET paid_amount = ? WHERE id = ?");
                $upCN->execute([$remaining, $cn_id]);
            } else {
                $delCN = $pdo->prepare("DELETE FROM orders WHERE id = ?");
                $delCN->execute([$cn_id]);
            }
        }
    }
} catch (Exception $e) {}

$message = '';

// --- PROCESS ADMIN DIRECT RETURN ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'process_return') {
    $customer_id = (int)$_POST['customer_id'];
    $notes = trim($_POST['notes']);
    $products = $_POST['product_id'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $prices = $_POST['price'] ?? [];
    $conditions = $_POST['condition'] ?? [];

    if ($customer_id && !empty($products)) {
        try {
            $pdo->beginTransaction();

            $total_return_value = 0;
            foreach ($products as $idx => $pid) {
                if ($pid && (int)$qtys[$idx] > 0) {
                    $total_return_value += ((int)$qtys[$idx] * (float)$prices[$idx]);
                }
            }

            // Insert Return Record
            $stmt = $pdo->prepare("INSERT INTO sales_returns (customer_id, rep_id, total_amount, notes) VALUES (?, ?, ?, ?)");
            $stmt->execute([$customer_id, $_SESSION['user_id'], $total_return_value, $notes]);
            $return_id = $pdo->lastInsertId();

            // Distribute return credit to customer's outstanding invoices, oldest first
            if ($total_return_value > 0) {
                $unpaidStmt = $pdo->prepare("SELECT id, total_amount, paid_amount FROM orders WHERE customer_id = ? AND payment_method != 'Credit Note' AND total_amount > paid_amount ORDER BY created_at ASC FOR UPDATE");
                $unpaidStmt->execute([$customer_id]);
                $unpaid_orders = $unpaidStmt->fetchAll();

                $remaining_credit = $total_return_value;
                foreach ($unpaid_orders as $order) {
                    if ($remaining_credit <= 0) break;
                    
                    $amount_due = $order['total_amount'] - $order['paid_amount'];
                    $amount_to_apply = min($amount_due, $remaining_credit);

                    $new_paid_amount = $order['paid_amount'] + $amount_to_apply;
                    $new_status = ($new_paid_amount >= $order['total_amount']) ? 'paid' : 'pending';

                    $updateStmt = $pdo->prepare("UPDATE orders SET paid_amount = ?, payment_status = ? WHERE id = ?");
                    $updateStmt->execute([$new_paid_amount, $new_status, $order['id']]);

                    $remaining_credit -= $amount_to_apply;
                }

                // Issue Credit Note ONLY for remaining excess credit
                if ($remaining_credit > 0) {
                    $cnStmt = $pdo->prepare("INSERT INTO orders (customer_id, rep_id, subtotal, total_amount, paid_amount, payment_method, payment_status) VALUES (?, ?, 0, 0, ?, 'Credit Note', 'paid')");
                    $cnStmt->execute([$customer_id, $_SESSION['user_id'], $remaining_credit]);
                }
            }

            // Process Items
            $itemStmt = $pdo->prepare("INSERT INTO sales_return_items (return_id, product_id, quantity, unit_price, condition_status) VALUES (?, ?, ?, ?, ?)");
            $restockStmt = $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
            
            foreach ($products as $idx => $pid) {
                $qty = (int)$qtys[$idx];
                $price = (float)$prices[$idx];
                $cond = $conditions[$idx];

                if ($pid && $qty > 0) {
                    $itemStmt->execute([$return_id, $pid, $qty, $price, $cond]);

                    // RESTOCK ONLY IF GOOD
                    if ($cond === 'good') {
                        $restockStmt->execute([$qty, $pid]);
                    }
                }
            }

            $pdo->commit();
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Return processed & Restocked successfully. Credit Note issued.</div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// Fetch History
$query = "
    SELECT sr.*, c.name as customer_name, u.name as rep_name,
           (SELECT COUNT(*) FROM sales_return_items WHERE return_id = sr.id) as items_count
    FROM sales_returns sr
    JOIN customers c ON sr.customer_id = c.id
    LEFT JOIN users u ON sr.rep_id = u.id
    ORDER BY sr.created_at DESC LIMIT 50
";
$returns = $pdo->query($query)->fetchAll();

// Setup UI Data
$customers = $pdo->query("SELECT id, name FROM customers ORDER BY name ASC")->fetchAll();
$products = $pdo->query("SELECT id, name, sku, selling_price FROM products WHERE status = 'available' ORDER BY name ASC")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Tom Select CSS for Searchable Dropdowns -->
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">

<style>
    /* Explicit Modal Inputs Visibility */
    .modal-body .ios-input, .modal-body .form-select {
        background: #FFFFFF !important;
        border: 1px solid #C7C7CC !important;
        border-radius: 10px !important;
        padding: 10px 14px !important;
        font-size: 0.95rem !important;
        color: #000000 !important;
        width: 100%;
        outline: none;
        box-shadow: inset 0 1px 3px rgba(0,0,0,0.03) !important;
        transition: border 0.2s;
    }
    .modal-body .ios-input:focus, .modal-body .form-select:focus { 
        border-color: var(--accent) !important; 
        box-shadow: 0 0 0 3px rgba(48,200,138,0.2) !important;
    }
    
    /* TomSelect Custom Overrides for Modal */
    .ts-control {
        background: #FFFFFF !important;
        border: 1px solid #C7C7CC !important;
        border-radius: 10px !important;
        padding: 10px 14px !important;
        font-size: 0.95rem !important;
        box-shadow: inset 0 1px 3px rgba(0,0,0,0.03) !important;
    }
    .ts-control.focus {
        border-color: var(--accent) !important;
        box-shadow: 0 0 0 3px rgba(48,200,138,0.2) !important;
    }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Sales Returns & Credit Notes</h1>
        <div class="page-subtitle">Process walk-in returns, issue credit notes, and manage restocks.</div>
    </div>
    <div>
        <button class="quick-btn px-3" style="background: #0055CC; color: #fff; box-shadow: 0 4px 14px rgba(0,122,255,0.3);" onclick="openReturnModal()">
            <i class="bi bi-arrow-return-left"></i> Direct Walk-in Return
        </button>
    </div>
</div>

<?php echo $message; ?>

<div class="dash-card mb-4 overflow-hidden">
    <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
        <span class="card-title">
            <span class="card-title-icon" style="background: rgba(255,149,0,0.1); color: #C07000;">
                <i class="bi bi-clock-history"></i>
            </span>
            Return History Ledger
        </span>
    </div>
    <div class="table-responsive">
        <table class="ios-table text-center" style="margin: 0;">
            <thead>
                <tr class="table-ios-header">
                    <th class="text-start ps-4" style="width: 15%;">Return ID</th>
                    <th class="text-start" style="width: 25%;">Date & Customer</th>
                    <th style="width: 15%;">Processed By</th>
                    <th style="width: 10%;">Items</th>
                    <th class="text-end" style="width: 15%;">Credit Value</th>
                    <th class="text-end pe-4" style="width: 10%;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($returns as $r): ?>
                <tr>
                    <td class="text-start ps-4">
                        <div style="font-weight: 800; font-size: 0.95rem; color: var(--accent-dark);">
                            RET-<?php echo str_pad($r['id'], 5, '0', STR_PAD_LEFT); ?>
                        </div>
                    </td>
                    <td class="text-start">
                        <div style="font-weight: 700; font-size: 0.95rem; color: var(--ios-label);">
                            <a href="view_customer.php?id=<?php echo $r['customer_id']; ?>" class="text-decoration-none" style="color: var(--ios-label);">
                                <?php echo htmlspecialchars($r['customer_name']); ?>
                            </a>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--ios-label-3); margin-top: 2px;">
                            <?php echo date('M d, Y h:i A', strtotime($r['created_at'])); ?>
                        </div>
                    </td>
                    <td>
                        <span class="ios-badge gray outline px-2 py-1">
                            <i class="bi bi-person me-1"></i><?php echo htmlspecialchars($r['rep_name'] ?: 'System Admin'); ?>
                        </span>
                        <?php if($r['assignment_id']): ?> 
                            <div style="font-size: 0.7rem; color: #0055CC; font-weight: 600; margin-top: 4px;">
                                <i class="bi bi-truck me-1"></i>Via Route
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="ios-badge blue px-2"><?php echo $r['items_count']; ?> Items</span>
                    </td>
                    <td class="text-end">
                        <div style="font-weight: 800; font-size: 1rem; color: #CC2200;">
                            Rs <?php echo number_format($r['total_amount'], 2); ?>
                        </div>
                    </td>
                    <td class="text-end pe-4">
                        <a href="view_return.php?id=<?php echo $r['id']; ?>" class="quick-btn quick-btn-secondary" style="padding: 6px 12px;" title="View Detailed Note" target="_blank">
                            <i class="bi bi-printer"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($returns)): ?>
                <tr>
                    <td colspan="6">
                        <div class="empty-state py-5">
                            <i class="bi bi-arrow-return-left" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2" style="font-weight: 500;">No returns recorded yet.</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Return Modal -->
<div class="modal fade" id="newReturnModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem; color: #0055CC;"><i class="bi bi-arrow-return-left me-2"></i>Process Customer Return</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="process_return">
                    
                    <div class="dash-card p-3 mb-4" style="background: #fff;">
                        <label class="ios-label-sm">Select Customer <span class="text-danger">*</span></label>
                        <select name="customer_id" id="returnCustomerSelect" class="form-select fw-bold" required>
                            <option value="">-- Choose Customer --</option>
                            <?php foreach($customers as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <h6 class="fw-bold mb-3 text-dark" style="font-size: 0.95rem;"><i class="bi bi-box-seam me-2 text-primary"></i>Returned Items</h6>
                    
                    <div id="itemsContainer">
                        <div class="load-row dash-card p-3 mb-3 shadow-sm border" style="background: #fff;">
                            <div class="row g-2 align-items-end">
                                <div class="col-md-5">
                                    <label class="ios-label-sm">Product</label>
                                    <select name="product_id[]" class="form-select fw-bold border-dark" required>
                                        <option value="">-- Select Product --</option>
                                        <?php foreach($products as $p): ?>
                                            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?> (Rs <?php echo number_format($p['selling_price'], 2); ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="ios-label-sm">Qty</label>
                                    <input type="number" name="qty[]" class="ios-input text-center fw-bold" required min="1">
                                </div>
                                <div class="col-md-2">
                                    <label class="ios-label-sm">Credit Rate</label>
                                    <input type="number" name="price[]" class="ios-input text-end fw-bold text-primary" required step="0.01">
                                </div>
                                <div class="col-md-2">
                                    <label class="ios-label-sm">Condition</label>
                                    <select name="condition[]" class="form-select fw-bold text-center border-dark" required>
                                        <option value="good" style="color: #1A9A3A;">Good</option>
                                        <option value="damaged" style="color: #CC2200;">Damaged</option>
                                        <option value="expired" style="color: #C07000;">Expired</option>
                                    </select>
                                </div>
                                <div class="col-md-1 text-end">
                                    <button type="button" class="quick-btn" style="padding: 10px; min-height: 42px; background: rgba(255,59,48,0.1); color: #CC2200; width: 100%;" onclick="this.closest('.load-row').remove();"><i class="bi bi-x-lg"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" class="quick-btn quick-btn-ghost mb-4" id="addLoadRowBtn"><i class="bi bi-plus-lg"></i> Add Another Item</button>
                    
                    <div class="ios-alert mb-4" style="background: rgba(52,199,89,0.08); color: #1A9A3A; font-size: 0.8rem; padding: 12px; border-radius: 10px;">
                        <i class="bi bi-info-circle-fill me-1"></i> Items marked as <strong>"Good"</strong> condition will be immediately restocked into the warehouse inventory.
                    </div>

                    <div class="mb-4">
                        <label class="ios-label-sm">Notes / Reason (Optional)</label>
                        <input type="text" name="notes" class="ios-input" placeholder="e.g. Expired goods replaced by supplier">
                    </div>

                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn px-4" style="background: #0055CC; color: #fff;" onclick="return confirm('Process return? A Credit Note will be issued to the customer and their outstanding balance will be reduced automatically.')">Confirm Return</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
<script>
function openReturnModal() {
    new bootstrap.Modal(document.getElementById('newReturnModal')).show();
}

document.addEventListener('DOMContentLoaded', function() {
    new TomSelect('#returnCustomerSelect', {
        create: false,
        sortField: { field: "text", direction: "asc" }
    });

    document.getElementById('addLoadRowBtn').addEventListener('click', function() {
        const container = document.getElementById('itemsContainer');
        const existingRow = document.querySelector('.load-row');
        if(!existingRow) return; // Failsafe
        
        const newRow = existingRow.cloneNode(true);
        newRow.querySelectorAll('input').forEach(i => i.value = '');
        newRow.querySelectorAll('select').forEach(s => s.value = s.querySelector('option') ? s.querySelector('option').value : '');
        newRow.querySelector('select[name="condition[]"]').value = 'good'; // default
        container.appendChild(newRow);
    });
});
</script>

<?php include '../includes/footer.php'; ?>