<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); // Restricted to management

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'load_stock') {
        $rep_id = (int)$_POST['rep_id'];

        try {
            $pdo->beginTransaction();

            if (isset($_POST['product_id']) && is_array($_POST['product_id'])) {
                foreach ($_POST['product_id'] as $index => $prod_id) {
                    $qty = (int)$_POST['load_qty'][$index];
                    
                    if ($prod_id && $qty > 0) {
                        // 1. Verify warehouse stock
                        $prodStmt = $pdo->prepare("SELECT stock FROM products WHERE id = ? FOR UPDATE");
                        $prodStmt->execute([$prod_id]);
                        $current_stock = (int)$prodStmt->fetchColumn();

                        if ($current_stock < $qty) {
                            throw new Exception("Insufficient stock in warehouse for Product ID $prod_id.");
                        }

                        // 2. Deduct from main warehouse
                        $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?")->execute([$qty, $prod_id]);
                        
                        // 3. Add to vehicle_stock (Upsert)
                        $pdo->prepare("
                            INSERT INTO vehicle_stock (rep_id, product_id, stock_qty) 
                            VALUES (?, ?, ?) 
                            ON DUPLICATE KEY UPDATE stock_qty = stock_qty + ?
                        ")->execute([$rep_id, $prod_id, $qty, $qty]);
                        
                        // 3.5 Link to route_loads if there is an active assignment for tracking dispatches
                        $assignStmt = $pdo->prepare("SELECT id FROM rep_routes WHERE rep_id = ? AND status IN ('assigned', 'accepted') ORDER BY created_at DESC LIMIT 1");
                        $assignStmt->execute([$rep_id]);
                        $assignment_id = $assignStmt->fetchColumn();
                        
                        if ($assignment_id) {
                            $checkLoadStmt = $pdo->prepare("SELECT id FROM route_loads WHERE assignment_id = ? AND product_id = ?");
                            $checkLoadStmt->execute([$assignment_id, $prod_id]);
                            $route_load_id = $checkLoadStmt->fetchColumn();
                            
                            if ($route_load_id) {
                                $pdo->prepare("UPDATE route_loads SET loaded_qty = loaded_qty + ? WHERE id = ?")
                                    ->execute([$qty, $route_load_id]);
                            } else {
                                $pdo->prepare("INSERT INTO route_loads (assignment_id, product_id, loaded_qty) VALUES (?, ?, ?)")
                                    ->execute([$assignment_id, $prod_id, $qty]);
                            }
                        }
                        
                        // 4. Log the transfer
                        $pdo->prepare("INSERT INTO stock_logs (product_id, type, qty_change, previous_stock, new_stock, created_by) VALUES (?, 'transfer_to_rep', ?, ?, ?, ?)")
                            ->execute([$prod_id, -$qty, $current_stock, $current_stock - $qty, $_SESSION['user_id']]);
                    }
                }
            }

            $pdo->commit();
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-check-circle-fill me-2'></i> Stock loaded to vehicle successfully!</div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error loading vehicle: ".$e->getMessage()."</div>";
        }
    }
}

// Fetch Data
$reps = $pdo->query("SELECT id, name FROM users WHERE role = 'rep' ORDER BY name ASC")->fetchAll();
$products = $pdo->query("SELECT id, name, sku, stock FROM products WHERE status = 'available' AND stock > 0 ORDER BY name ASC")->fetchAll();

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
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Load Vehicle Stock</h1>
        <div class="page-subtitle">Transfer inventory from the main warehouse to a Rep's vehicle.</div>
    </div>
</div>

<?php echo $message; ?>

<div class="row">
    <div class="col-lg-8">
        <div class="dash-card overflow-hidden">
            <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
                <span class="card-title">
                    <span class="card-title-icon" style="background: rgba(0,122,255,0.1); color: #0055CC;">
                        <i class="bi bi-box-arrow-right"></i>
                    </span>
                    Stock Transfer Form
                </span>
            </div>
            
            <div class="p-4" style="background: #fff;">
                <form method="POST">
                    <input type="hidden" name="action" value="load_stock">
                    
                    <div class="mb-4 pb-4 border-bottom border-secondary border-opacity-10">
                        <label class="ios-label-sm">Select Sales Rep <span class="text-danger">*</span></label>
                        <select name="rep_id" class="form-select fw-bold" style="background: #fff; font-size: 1.1rem; padding: 12px 14px;" required>
                            <option value="">-- Choose Rep --</option>
                            <?php foreach($reps as $rep): ?>
                                <option value="<?php echo $rep['id']; ?>"><?php echo htmlspecialchars($rep['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <h6 class="fw-bold mb-3" style="color: var(--ios-label); font-size: 0.95rem;">
                        <i class="bi bi-boxes me-1 text-primary"></i> Items to Load
                    </h6>
                    
                    <div id="loadItemsContainer">
                        <div class="row g-2 mb-2 align-items-end load-row">
                            <div class="col-md-8">
                                <label class="ios-label-sm">Product</label>
                                <select name="product_id[]" class="form-select" style="background: #fff;" required>
                                    <option value="">-- Select Product --</option>
                                    <?php foreach($products as $p): ?>
                                        <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?> (Warehouse: <?php echo $p['stock']; ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="ios-label-sm">Load Qty</label>
                                <input type="number" name="load_qty[]" class="ios-input text-center" style="background: #fff;" min="1" placeholder="Qty" required>
                            </div>
                            <div class="col-md-1 text-end">
                                <button type="button" class="btn btn-light text-danger w-100" style="min-height: 42px;" onclick="if(document.querySelectorAll('.load-row').length > 1) this.closest('.load-row').remove();">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3 mb-4">
                        <button type="button" class="btn btn-outline-primary btn-sm rounded-pill fw-bold" id="addLoadRowBtn">
                            <i class="bi bi-plus-lg"></i> Add Another Product
                        </button>
                    </div>

                    <div class="text-end">
                        <button type="submit" class="btn btn-primary px-4 fw-bold rounded-pill" style="padding: 12px 30px;">
                            <i class="bi bi-check-lg me-2"></i> Confirm Load
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add Row Logic
    const loadItemsContainer = document.getElementById('loadItemsContainer');
    const addLoadRowBtn = document.getElementById('addLoadRowBtn');
    
    if (addLoadRowBtn) {
        addLoadRowBtn.addEventListener('click', function() {
            const firstRow = loadItemsContainer.querySelector('.load-row');
            if (firstRow) {
                const newRow = firstRow.cloneNode(true);
                newRow.querySelector('select').value = '';
                newRow.querySelector('input').value = '';
                loadItemsContainer.appendChild(newRow);
            }
        });
    }
});
</script>

<?php include '../includes/footer.php'; ?>
