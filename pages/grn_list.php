<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); // Restricted to management

$message = '';

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // 1. UPDATE PAYMENT STATUS
    if ($_POST['action'] == 'update_payment') {
        $grn_id = (int)$_POST['grn_id'];
        $pay_amount = (float)$_POST['pay_amount'];
        $payment_method = $_POST['payment_method']; // 'Cash', 'Bank Transfer', 'Cheque'

        try {
            $pdo->beginTransaction();
            
            // Lock GRN record for update
            $stmt = $pdo->prepare("SELECT total_amount, paid_amount, supplier_id FROM grns WHERE id = ? FOR UPDATE");
            $stmt->execute([$grn_id]);
            $grn = $stmt->fetch();

            if (!$grn) throw new Exception("GRN not found.");
            
            // Calculate pending cheques
            $pendingChkStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cheques WHERE grn_id = ? AND status = 'pending'");
            $pendingChkStmt->execute([$grn_id]);
            $pending_cheques = $pendingChkStmt->fetchColumn();

            // Prevent overpayment
            $max_payable = max(0, $grn['total_amount'] - $grn['paid_amount'] - $pending_cheques);
            if ($pay_amount > $max_payable) {
                $pay_amount = $max_payable; 
            }

            if ($payment_method === 'Cheque') {
                $bank_name = trim($_POST['cheque_bank']);
                $cheque_number = trim($_POST['cheque_number']);
                $banking_date = $_POST['cheque_date'];
                
                if(empty($bank_name) || empty($cheque_number)) {
                    throw new Exception("Cheque details are required.");
                }

                // Insert into cheques table (Outgoing)
                $chkStmt = $pdo->prepare("INSERT INTO cheques (type, supplier_id, grn_id, bank_name, cheque_number, banking_date, amount, status) VALUES ('outgoing', ?, ?, ?, ?, ?, ?, 'pending')");
                $chkStmt->execute([$grn['supplier_id'], $grn_id, $bank_name, $cheque_number, $banking_date, $pay_amount]);
                
                // Update GRN status to waiting
                $pdo->prepare("UPDATE grns SET payment_status = 'waiting', payment_method = 'Cheque' WHERE id = ?")->execute([$grn_id]);
                
                // Log to supplier_payments
                $grn_no = "GRN-" . str_pad($grn_id, 6, '0', STR_PAD_LEFT);
                $reference = "$bank_name - $cheque_number";
                $pdo->prepare("INSERT INTO supplier_payments (supplier_id, amount, method, reference, notes) VALUES (?, ?, 'Cheque', ?, ?)")
                    ->execute([$grn['supplier_id'], $pay_amount, $reference, "Outgoing Cheque recorded on GRN #$grn_id"]);

                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Cheque payment recorded. GRN is now WAITING for cheque clearance.</div>";

            } else {
                // Cash or Bank Transfer
                
                // Verify sufficient company funds
                $fin = $pdo->query("SELECT * FROM company_finances WHERE id = 1 FOR UPDATE")->fetch();
                if ($payment_method === 'Cash' && $pay_amount > $fin['cash_on_hand']) {
                    throw new Exception("Insufficient Cash on Hand to process this payment. (Available: Rs " . number_format($fin['cash_on_hand'], 2) . ")");
                }
                if ($payment_method === 'Bank Transfer' && $pay_amount > $fin['bank_balance']) {
                    throw new Exception("Insufficient Bank Balance to process this payment. (Available: Rs " . number_format($fin['bank_balance'], 2) . ")");
                }

                $new_paid = $grn['paid_amount'] + $pay_amount;
                $status = ($new_paid >= $grn['total_amount']) ? 'paid' : 'pending';
                
                $pdo->prepare("UPDATE grns SET paid_amount = ?, payment_status = ?, payment_method = ? WHERE id = ?")->execute([$new_paid, $status, $payment_method, $grn_id]);
                
                // Deduct from Company Finances
                if ($payment_method === 'Cash') {
                    $pdo->prepare("UPDATE company_finances SET cash_on_hand = cash_on_hand - ? WHERE id = 1")->execute([$pay_amount]);
                    $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('cash_out', ?, ?, ?)")->execute([$pay_amount, "GRN Payment (Cash) - GRN #$grn_id", $_SESSION['user_id']]);
                } elseif ($payment_method === 'Bank Transfer') {
                    $pdo->prepare("UPDATE company_finances SET bank_balance = bank_balance - ? WHERE id = 1")->execute([$pay_amount]);
                    $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('bank_out', ?, ?, ?)")->execute([$pay_amount, "GRN Payment (Bank) - GRN #$grn_id", $_SESSION['user_id']]);
                }

                // Log to supplier_payments
                $grn_no = "GRN-" . str_pad($grn_id, 6, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO supplier_payments (supplier_id, amount, method, reference, notes) VALUES (?, ?, ?, ?, 'Subsequent GRN Payment')")
                    ->execute([$grn['supplier_id'], $pay_amount, $payment_method, $grn_no]);

                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Payment of Rs " . number_format($pay_amount, 2) . " processed via $payment_method!</div>";
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }

    // 2. DELETE GRN
    if ($_POST['action'] == 'delete_grn') {
        $grn_id = (int)$_POST['grn_id'];
        
        try {
            $pdo->beginTransaction();
            
            // Fetch GRN details to reverse finances if necessary
            $grnStmt = $pdo->prepare("SELECT payment_method, payment_status, paid_amount FROM grns WHERE id = ? FOR UPDATE");
            $grnStmt->execute([$grn_id]);
            $grn = $grnStmt->fetch();
            
            if (!$grn) throw new Exception("GRN not found.");

            // Fetch items to reverse stock
            $itemsStmt = $pdo->prepare("SELECT product_id, quantity FROM grn_items WHERE grn_id = ?");
            $itemsStmt->execute([$grn_id]);
            $items = $itemsStmt->fetchAll();
            
            $revertStockStmt = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
            $logStmt = $pdo->prepare("INSERT INTO stock_logs (product_id, type, reference_id, qty_change, previous_stock, new_stock, created_by) VALUES (?, 'returned', ?, ?, (SELECT stock + ? FROM products WHERE id = ?), (SELECT stock FROM products WHERE id = ?), ?)");
            
            foreach($items as $item) {
                // Deduct stock back
                $revertStockStmt->execute([$item['quantity'], $item['product_id']]);
                // Log the reversal
                $logStmt->execute([$item['product_id'], $grn_id, -$item['quantity'], $item['quantity'], $item['product_id'], $item['product_id'], $_SESSION['user_id']]);
            }
            
            // Reverse Finances if it was paid instantly
            if ($grn['payment_status'] === 'paid' && $grn['paid_amount'] > 0) {
                if ($grn['payment_method'] === 'Cash') {
                    $pdo->prepare("UPDATE company_finances SET cash_on_hand = cash_on_hand + ? WHERE id = 1")->execute([$grn['paid_amount']]);
                    $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('cash_in', ?, ?, ?)")->execute([$grn['paid_amount'], "Reversed Cash Purchase - GRN #$grn_id Deleted", $_SESSION['user_id']]);
                } elseif ($grn['payment_method'] === 'Bank Transfer') {
                    $pdo->prepare("UPDATE company_finances SET bank_balance = bank_balance + ? WHERE id = 1")->execute([$grn['paid_amount']]);
                    $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('bank_in', ?, ?, ?)")->execute([$grn['paid_amount'], "Reversed Bank Purchase - GRN #$grn_id Deleted", $_SESSION['user_id']]);
                }
            }

            // Delete the GRN (Cascades to grn_items)
            $pdo->prepare("DELETE FROM cheques WHERE grn_id = ?")->execute([$grn_id]);
            $pdo->prepare("DELETE FROM grns WHERE id = ?")->execute([$grn_id]);
            
            $pdo->commit();
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-trash3-fill me-2'></i> GRN deleted, stock reversed, and finances restored successfully!</div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error deleting GRN: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// --- FILTERING & PAGINATION ---
$limit = 15;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$supplier_filter = isset($_GET['supplier_id']) ? $_GET['supplier_id'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

$whereClause = "WHERE 1=1";
$params = [];

if ($search_query !== '') {
    $whereClause .= " AND (g.id LIKE ? OR g.reference_no LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}
if ($supplier_filter !== '') {
    $whereClause .= " AND g.supplier_id = ?";
    $params[] = $supplier_filter;
}
if ($date_from !== '') {
    $whereClause .= " AND g.grn_date >= ?";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $whereClause .= " AND g.grn_date <= ?";
    $params[] = $date_to;
}

// Get Totals for Top Metrics Cards
$metricsQuery = "
    SELECT 
        SUM(g.total_amount) as total_purchases,
        SUM(CASE WHEN g.payment_status IN ('pending', 'waiting') THEN 
            GREATEST(g.total_amount - g.paid_amount - COALESCE((SELECT SUM(amount) FROM cheques WHERE grn_id = g.id AND status = 'pending'), 0), 0)
        ELSE 0 END) as total_payable
    FROM grns g 
    $whereClause
";
$metricsStmt = $pdo->prepare($metricsQuery);
$metricsStmt->execute($params);
$metrics = $metricsStmt->fetch();

// Get Total Rows for Pagination
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM grns g $whereClause");
$totalStmt->execute($params);
$totalRows = $totalStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Fetch GRNs
$query = "
    SELECT g.*, s.company_name as supplier_name, u.name as receiver_name 
    FROM grns g 
    LEFT JOIN suppliers s ON g.supplier_id = s.id 
    LEFT JOIN users u ON g.created_by = u.id 
    $whereClause 
    ORDER BY g.created_at DESC 
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$grns = $stmt->fetchAll();

// Fetch Suppliers for dropdown
$suppliers = $pdo->query("SELECT id, company_name FROM suppliers ORDER BY company_name ASC")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    /* Specific Page Styles */
    .metrics-card {
        border-radius: 16px;
        padding: 20px 24px;
        color: #fff;
        box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        display: flex;
        align-items: center;
        gap: 20px;
        height: 100%;
        transition: transform 0.2s ease;
    }
    .metrics-card:hover { transform: translateY(-2px); }
    .metrics-icon {
        width: 54px; height: 54px;
        border-radius: 14px;
        background: rgba(255,255,255,0.2);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.8rem;
        flex-shrink: 0;
    }
    
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
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Purchase History (GRN)</h1>
        <div class="page-subtitle">View past receipts, manage supplier payments, and track payables.</div>
    </div>
    <div>
        <a href="create_grn.php" class="quick-btn quick-btn-primary">
            <i class="bi bi-box-arrow-in-down"></i> Receive New Stock
        </a>
    </div>
</div>

<?php echo $message; ?>

<!-- Summary Metrics -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #007AFF, #0055CC);">
            <div class="metrics-icon"><i class="bi bi-cart-check-fill"></i></div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Total Filtered Purchases</div>
                <div style="font-size: 1.6rem; font-weight: 800; line-height: 1;">Rs <?php echo number_format($metrics['total_purchases'] ?: 0, 2); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF3B30, #CC1500);">
            <div class="metrics-icon"><i class="bi bi-clock-history"></i></div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Total Payables (Unpaid)</div>
                <div style="font-size: 1.6rem; font-weight: 800; line-height: 1;">Rs <?php echo number_format($metrics['total_payable'] ?: 0, 2); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="dash-card mb-4" style="background: var(--ios-surface-2);">
    <div class="p-3">
        <form method="GET" action="" id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="ios-label-sm">Search GRN / Ref #</label>
                <div class="ios-search-wrapper">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" id="searchInput" class="ios-input" placeholder="Search..." value="<?php echo htmlspecialchars($search_query); ?>" oninput="debounceSearch()">
                </div>
            </div>
            <div class="col-md-3">
                <label class="ios-label-sm">Supplier</label>
                <select name="supplier_id" class="form-select" onchange="document.getElementById('filterForm').submit();">
                    <option value="">All Suppliers</option>
                    <?php foreach($suppliers as $s): ?>
                        <option value="<?php echo $s['id']; ?>" <?php echo $supplier_filter == $s['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['company_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="ios-label-sm">From Date</label>
                <input type="date" name="date_from" class="ios-input" value="<?php echo htmlspecialchars($date_from); ?>" onchange="document.getElementById('filterForm').submit();">
            </div>
            <div class="col-md-2">
                <label class="ios-label-sm">To Date</label>
                <input type="date" name="date_to" class="ios-input" value="<?php echo htmlspecialchars($date_to); ?>" onchange="document.getElementById('filterForm').submit();">
            </div>
            <div class="col-md-2">
                <button type="submit" class="quick-btn quick-btn-secondary w-100" style="min-height: 42px;">
                    <i class="bi bi-funnel-fill"></i> Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- GRN Table -->
<div class="dash-card mb-4 overflow-hidden">
    <div class="table-responsive">
        <table class="ios-table text-center">
            <thead>
                <tr class="table-ios-header">
                    <th class="text-start ps-4" style="width: 18%;">GRN # & Ref</th>
                    <th class="text-start" style="width: 22%;">Supplier & Receiver</th>
                    <th style="width: 15%;">Date Received</th>
                    <th class="text-end" style="width: 15%;">Amount & Payment</th>
                    <th style="width: 15%;">Status</th>
                    <th class="text-end pe-4" style="width: 15%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($grns as $g): 
                    $balance = $g['total_amount'] - $g['paid_amount'];
                ?>
                <tr>
                    <td class="text-start ps-4">
                        <div style="font-weight: 800; font-size: 0.95rem; color: var(--accent-dark);">
                            GRN #<?php echo str_pad($g['id'], 6, '0', STR_PAD_LEFT); ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--ios-label-3); margin-top: 2px;">
                            <i class="bi bi-upc-scan"></i> Ref: <?php echo htmlspecialchars($g['reference_no'] ?: 'N/A'); ?>
                        </div>
                    </td>
                    <td class="text-start">
                        <div style="font-weight: 700; font-size: 0.95rem; color: var(--ios-label);">
                            <?php echo htmlspecialchars($g['supplier_name'] ?: 'Unknown'); ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--ios-label-2); margin-top: 2px;">
                            <i class="bi bi-person-fill text-muted"></i> Rcvd By: <?php echo htmlspecialchars($g['receiver_name']); ?>
                        </div>
                    </td>
                    <td>
                        <div style="font-weight: 600; font-size: 0.9rem; color: var(--ios-label);">
                            <?php echo date('M d, Y', strtotime($g['grn_date'])); ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--ios-label-3); margin-top: 2px;">
                            <?php echo date('h:i A', strtotime($g['created_at'])); ?>
                        </div>
                    </td>
                    <td class="text-end">
                        <div style="font-weight: 800; font-size: 0.95rem; color: #CC2200;">
                            Rs <?php echo number_format($g['total_amount'], 2); ?>
                        </div>
                        <?php if($g['paid_amount'] > 0 && $g['paid_amount'] < $g['total_amount']): ?>
                            <div style="font-size: 0.75rem; font-weight: 600; color: #1A9A3A; margin-top: 2px;">
                                Paid: Rs <?php echo number_format($g['paid_amount'], 2); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="mb-1"><span class="ios-badge gray outline" style="font-size: 0.6rem;"><?php echo htmlspecialchars($g['payment_method']); ?></span></div>
                        <?php if($g['payment_status'] == 'paid'): ?>
                            <span class="ios-badge green"><i class="bi bi-check-circle-fill"></i> Paid</span>
                        <?php elseif($g['payment_status'] == 'waiting'): ?>
                            <span class="ios-badge blue"><i class="bi bi-hourglass-split"></i> Waiting (Chq)</span>
                        <?php else: ?>
                            <span class="ios-badge orange"><i class="bi bi-exclamation-circle-fill"></i> Pending</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end pe-4">
                        <div class="d-flex justify-content-end gap-1 flex-wrap">
                            <!-- Pay Button -->
                            <?php if($g['payment_status'] != 'paid'): ?>
                            <button class="quick-btn" style="padding: 6px 10px; background: rgba(52,199,89,0.15); color: #1A9A3A;" title="Update Payment" onclick="openPayModal(<?php echo $g['id']; ?>, <?php echo $balance; ?>)">
                                <i class="bi bi-cash-coin"></i> Pay
                            </button>
                            <?php endif; ?>

                            <a href="view_grn.php?id=<?php echo $g['id']; ?>" class="quick-btn quick-btn-secondary" style="padding: 6px 10px;" title="View/Print GRN" target="_blank">
                                <i class="bi bi-printer"></i>
                            </a>

                            <!-- Delete GRN -->
                            <form method="POST" class="d-inline" onsubmit="return confirm('WARNING: Are you sure you want to completely delete this GRN? This will REVERSE the stock added and refund your Company Account if it was paid.');">
                                <input type="hidden" name="action" value="delete_grn">
                                <input type="hidden" name="grn_id" value="<?php echo $g['id']; ?>">
                                <button type="submit" class="quick-btn" style="padding: 6px 10px; background: rgba(255,59,48,0.1); color: #CC2200;" title="Delete GRN">
                                    <i class="bi bi-trash3-fill"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($grns)): ?>
                <tr>
                    <td colspan="6">
                        <div class="empty-state">
                            <i class="bi bi-box-arrow-in-down" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2" style="font-weight: 500;">No GRN records found.</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if($totalPages > 1): ?>
<ul class="ios-pagination mb-4">
    <?php for($i = 1; $i <= $totalPages; $i++): ?>
    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search_query); ?>&supplier_id=<?php echo $supplier_filter; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"><?php echo $i; ?></a>
    </li>
    <?php endfor; ?>
</ul>
<?php endif; ?>

<!-- Payment Modal -->
<div class="modal fade" id="payGrnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem;"><i class="bi bi-cash-coin text-success me-2"></i>Record GRN Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_payment">
                    <input type="hidden" name="grn_id" id="pay_grn_id">

                    <div class="ios-alert text-center mb-4" style="background: rgba(255,59,48,0.1); color: #CC2200; display: block;">
                        <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Remaining Balance</div>
                        <div style="font-size: 1.6rem; font-weight: 800;">Rs <span id="display_balance">0.00</span></div>
                    </div>

                    <div class="mb-3">
                        <label class="ios-label-sm">Payment Method <span class="text-danger">*</span></label>
                        <select name="payment_method" id="paymentMethodSelect" class="form-select fw-bold border-dark" required>
                            <option value="Cash">Cash (Deducts from Cash on Hand)</option>
                            <option value="Bank Transfer">Bank Transfer (Deducts from Bank)</option>
                            <option value="Cheque">Cheque (Post-dated Outgoing)</option>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="ios-label-sm">Amount to Pay (Rs) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="pay_amount" id="pay_amount" class="ios-input fw-bold" style="color: #1A9A3A; font-size: 1.2rem; height: 50px;" required>
                    </div>

                    <!-- Hidden Cheque Fields -->
                    <div id="chequeFields" style="display: none; background: rgba(255,149,0,0.08); border-radius: 12px; padding: 16px; border: 1px solid rgba(255,149,0,0.2);">
                        <h6 class="fw-bold mb-3 pb-2" style="font-size: 0.9rem; color: #C07000; border-bottom: 1px solid rgba(255,149,0,0.2);"><i class="bi bi-credit-card-2-front me-2"></i>Outgoing Cheque Details</h6>
                        <div class="mb-3">
                            <label class="ios-label-sm" style="color: #C07000;">Bank Name <span class="text-danger">*</span></label>
                            <input type="text" name="cheque_bank" id="chkBank" class="ios-input border-warning">
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="ios-label-sm" style="color: #C07000;">Cheque No. <span class="text-danger">*</span></label>
                                <input type="text" name="cheque_number" id="chkNum" class="ios-input border-warning">
                            </div>
                            <div class="col-6">
                                <label class="ios-label-sm" style="color: #C07000;">Banking Date <span class="text-danger">*</span></label>
                                <input type="date" name="cheque_date" id="chkDate" class="ios-input border-warning">
                            </div>
                        </div>
                        <small style="font-size: 0.7rem; color: #C07000; margin-top: 8px; display: block; opacity: 0.9;">Note: This will send the cheque to your outgoing list. The GRN status will update to Paid only when the cheque clears.</small>
                    </div>
                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn" style="background: #1A9A3A; color: #fff; padding: 10px 20px;" onclick="return confirm('Confirm Payment? Cash/Bank will be deducted instantly.');">Confirm Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Search debounce
    let searchTimer;
    function debounceSearch() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            document.getElementById('filterForm').submit();
        }, 800);
    }

    // Handle Modal Population
    function openPayModal(grnId, balance) {
        document.getElementById('pay_grn_id').value = grnId;
        document.getElementById('pay_amount').value = parseFloat(balance).toFixed(2);
        document.getElementById('pay_amount').max = parseFloat(balance).toFixed(2);
        document.getElementById('display_balance').innerText = parseFloat(balance).toFixed(2);
        
        // Reset fields
        document.getElementById('paymentMethodSelect').value = 'Cash';
        document.getElementById('paymentMethodSelect').dispatchEvent(new Event('change'));
        
        new bootstrap.Modal(document.getElementById('payGrnModal')).show();
    }

    // Toggle Cheque Fields
    document.getElementById('paymentMethodSelect').addEventListener('change', function() {
        const chequeFields = document.getElementById('chequeFields');
        const chkBank = document.getElementById('chkBank');
        const chkNum = document.getElementById('chkNum');
        const chkDate = document.getElementById('chkDate');

        if (this.value === 'Cheque') {
            chequeFields.style.display = 'block';
            chkBank.required = true;
            chkNum.required = true;
            chkDate.required = true;
        } else {
            chequeFields.style.display = 'none';
            chkBank.required = false;
            chkNum.required = false;
            chkDate.required = false;
        }
    });
</script>

<?php include '../includes/footer.php'; ?>