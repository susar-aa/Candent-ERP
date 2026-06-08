<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor', 'rep']);

$isRep = hasRole('rep');
$message = '';

// --- AUTO DB MIGRATION FOR CHEQUES ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cheques (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL UNIQUE,
        bank_name VARCHAR(100) NOT NULL,
        cheque_number VARCHAR(50) NOT NULL,
        banking_date DATE NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        status ENUM('pending', 'passed', 'returned') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch(PDOException $e) { /* Ignore if exists */ }
// --------------------------------------

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // Mark Order as Paid (For non-cheque pending orders)
    if ($_POST['action'] == 'mark_paid') {
        $order_id = (int)$_POST['order_id'];
        
        $canUpdate = true;
        if ($isRep) {
            $checkStmt = $pdo->prepare("SELECT rep_id FROM orders WHERE id = ?");
            $checkStmt->execute([$order_id]);
            if ($checkStmt->fetchColumn() != $_SESSION['user_id']) $canUpdate = false;
        }

        if ($canUpdate) {
            $stmt = $pdo->prepare("UPDATE orders SET payment_status = 'paid', paid_amount = total_amount WHERE id = ?");
            if ($stmt->execute([$order_id])) {
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-check-circle-fill me-2'></i> Order #".str_pad($order_id, 6, '0', STR_PAD_LEFT)." marked as PAID successfully!</div>";
            }
        } else {
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Unauthorized action.</div>";
        }
    }

    // Link/Add Cheque Details
    if ($_POST['action'] == 'link_cheque') {
        $order_id = (int)$_POST['order_id'];
        $bank_name = trim($_POST['bank_name']);
        $cheque_number = trim($_POST['cheque_number']);
        $banking_date = $_POST['banking_date'];
        $amount = (float)$_POST['amount'];

        $stmt = $pdo->prepare("
            INSERT INTO cheques (order_id, bank_name, cheque_number, banking_date, amount, status) 
            VALUES (?, ?, ?, ?, ?, 'pending') 
            ON DUPLICATE KEY UPDATE bank_name = VALUES(bank_name), cheque_number = VALUES(cheque_number), banking_date = VALUES(banking_date), amount = VALUES(amount)
        ");
        if ($stmt->execute([$order_id, $bank_name, $cheque_number, $banking_date, $amount])) {
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-check-circle-fill me-2'></i> Cheque details saved successfully for Order #".str_pad($order_id, 6, '0', STR_PAD_LEFT)."!</div>";
        } else {
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Failed to save cheque details.</div>";
        }
    }

    // Delete Order (Admin/Supervisor Only)
    if ($_POST['action'] == 'delete_order' && hasRole(['admin', 'supervisor'])) {
        $order_id = (int)$_POST['order_id'];
        try {
            $pdo->beginTransaction();
            
            // Restore stock
            $itemsStmt = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
            $itemsStmt->execute([$order_id]);
            $items = $itemsStmt->fetchAll();
            
            $restoreStmt = $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
            foreach($items as $item) {
                $restoreStmt->execute([$item['quantity'], $item['product_id']]);
            }
            
            // Delete Order (Cascades)
            $delStmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
            $delStmt->execute([$order_id]);
            
            $pdo->commit();
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-check-circle-fill me-2'></i> Order deleted and stock restored successfully!</div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error deleting order: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// --- FILTERING & PAGINATION ---
$limit = 15;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$method_filter = isset($_GET['method']) ? $_GET['method'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

$whereClause = "WHERE 1=1";
$params = [];

if ($isRep) {
    $whereClause .= " AND o.rep_id = ?";
    $params[] = $_SESSION['user_id'];
}
if ($search_query !== '') {
    $whereClause .= " AND (o.id LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR c.whatsapp LIKE ? OR c.address LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}
if ($status_filter !== '') {
    $whereClause .= " AND o.payment_status = ?";
    $params[] = $status_filter;
}
if ($method_filter !== '') {
    $whereClause .= " AND o.payment_method = ?";
    $params[] = $method_filter;
}
if ($date_from !== '') {
    $whereClause .= " AND DATE(o.created_at) >= ?";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $whereClause .= " AND DATE(o.created_at) <= ?";
    $params[] = $date_to;
}

// Get Totals for Metrics Cards
$metricsQuery = "
    SELECT 
        SUM(o.total_amount) as total_sales,
        SUM(CASE WHEN o.payment_status = 'pending' THEN o.total_amount ELSE 0 END) as pending_amount
    FROM orders o 
    LEFT JOIN customers c ON o.customer_id = c.id 
    $whereClause
";
$metricsStmt = $pdo->prepare($metricsQuery);
$metricsStmt->execute($params);
$metrics = $metricsStmt->fetch();

// Get Total Rows for Pagination
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN customers c ON o.customer_id = c.id $whereClause");
$totalStmt->execute($params);
$totalRows = $totalStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Fetch Orders with linked Cheque Info
$query = "
    SELECT o.*, c.name as customer_name, u.name as rep_name, o.customer_id,
           ch.id as cheque_id, ch.bank_name, ch.cheque_number, ch.banking_date, ch.status as cheque_status
    FROM orders o 
    LEFT JOIN customers c ON o.customer_id = c.id 
    LEFT JOIN users u ON o.rep_id = u.id 
    LEFT JOIN cheques ch ON o.id = ch.order_id
    $whereClause 
    ORDER BY o.created_at DESC 
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// --- EXPORT TO EXCEL (CSV) ---
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Fetch ALL matching orders matching current filters without pagination limit
    $exportQuery = "
        SELECT o.id, o.created_at, c.name as customer_name, u.name as rep_name, o.total_amount,
               o.payment_method, o.payment_status,
               ch.bank_name, ch.cheque_number, ch.banking_date, ch.status as cheque_status
        FROM orders o 
        LEFT JOIN customers c ON o.customer_id = c.id 
        LEFT JOIN users u ON o.rep_id = u.id 
        LEFT JOIN cheques ch ON o.id = ch.order_id
        $whereClause 
        ORDER BY o.created_at DESC
    ";
    
    $exportStmt = $pdo->prepare($exportQuery);
    $exportStmt->execute($params);
    $allOrders = $exportStmt->fetchAll();
    
    $filename = "sales_history_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write CSV headers
    fputcsv($output, [
        'Order #',
        'Date & Time',
        'Customer Name',
        'Sales Representative',
        'Total Amount (Rs)',
        'Payment Method',
        'Payment Status',
        'Bank Name',
        'Cheque Number',
        'Banking Date',
        'Cheque Status'
    ]);
    
    // Write data rows
    foreach ($allOrders as $row) {
        $orderNum = "#" . str_pad($row['id'], 6, '0', STR_PAD_LEFT);
        $dateFormatted = date('Y-m-d H:i A', strtotime($row['created_at']));
        $customer = $row['customer_name'] ?? 'Walk-in Customer';
        $rep = $row['rep_name'] ?? 'System';
        $amount = number_format($row['total_amount'], 2, '.', '');
        
        $paymentStatus = ucfirst($row['payment_status']);
        $chequeStatus = $row['payment_method'] === 'Cheque' ? ucfirst($row['cheque_status'] ?? 'pending') : 'N/A';
        $bankName = $row['payment_method'] === 'Cheque' ? ($row['bank_name'] ?? '') : 'N/A';
        $chequeNumber = $row['payment_method'] === 'Cheque' ? ($row['cheque_number'] ?? '') : 'N/A';
        $bankingDate = $row['payment_method'] === 'Cheque' ? ($row['banking_date'] ?? '') : 'N/A';
        
        fputcsv($output, [
            $orderNum,
            $dateFormatted,
            $customer,
            $rep,
            $amount,
            $row['payment_method'],
            $paymentStatus,
            $bankName,
            $chequeNumber,
            $bankingDate,
            $chequeStatus
        ]);
    }
    
    fclose($output);
    exit;
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    /* --- Specific Page Styles (Candent Theme) --- */
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        padding: 24px 0 16px;
        border-bottom: 1px solid var(--ios-separator);
        margin-bottom: 24px;
    }
    .page-title {
        font-size: 1.8rem;
        font-weight: 700;
        letter-spacing: -0.8px;
        color: var(--ios-label);
        margin: 0;
    }
    .page-subtitle {
        font-size: 0.85rem;
        color: var(--ios-label-2);
        margin-top: 4px;
    }

    /* iOS Inputs & Labels */
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

    /* Search Bar with Icon */
    .ios-search-wrapper { position: relative; }
    .ios-search-wrapper .bi-search {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--ios-label-3);
    }
    .ios-search-wrapper .ios-input { padding-left: 38px; }

    /* Custom Tables */
    .table-ios-header th {
        background: var(--ios-surface-2) !important;
        color: var(--ios-label-2) !important;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 700;
        border-bottom: 1px solid var(--ios-separator);
        padding: 14px 20px;
    }
    .ios-table { width: 100%; border-collapse: collapse; }
    .ios-table td {
        vertical-align: middle;
        padding: 14px 20px;
        border-bottom: 1px solid var(--ios-separator);
    }
    .ios-table tr:last-child td { border-bottom: none; }
    .ios-table tr:hover td { background: var(--ios-bg); }

    /* iOS Badges */
    .ios-badge {
        font-size: 0.7rem;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 50px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        white-space: nowrap;
        letter-spacing: 0.02em;
    }
    .ios-badge.green   { background: rgba(52,199,89,0.12); color: #1A9A3A; }
    .ios-badge.blue    { background: rgba(0,122,255,0.12); color: #0055CC; }
    .ios-badge.orange  { background: rgba(255,149,0,0.15); color: #C07000; }
    .ios-badge.red     { background: rgba(255,59,48,0.12); color: #CC2200; }
    .ios-badge.gray    { background: rgba(60,60,67,0.1); color: var(--ios-label-2); }
    .ios-badge.outline { background: transparent; border: 1px solid var(--ios-separator); color: var(--ios-label-2); }
    .ios-badge.outline-orange { background: transparent; border: 1px solid rgba(255,149,0,0.5); color: #C07000; }

    /* Pagination */
    .ios-pagination { display: flex; gap: 4px; list-style: none; padding: 0; justify-content: center; margin-top: 20px; }
    .ios-pagination .page-link {
        border: none;
        color: var(--ios-label);
        background: var(--ios-surface);
        border-radius: 8px;
        width: 36px; height: 36px;
        display: flex; align-items: center; justify-content: center;
        font-weight: 600; font-size: 0.9rem;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    .ios-pagination .page-item.active .page-link {
        background: var(--accent); color: #fff; box-shadow: 0 4px 10px rgba(48,200,138,0.3);
    }
    .ios-pagination .page-link:hover:not(.active) { background: var(--ios-surface-2); }

    /* Modals */
    .modal-content { border-radius: 20px; border: none; box-shadow: 0 20px 40px rgba(0,0,0,0.2); overflow: hidden; }
    .modal-header { background: var(--ios-surface); border-bottom: 1px solid var(--ios-separator); padding: 18px 24px; }
    .modal-footer { border-top: 1px solid var(--ios-separator); padding: 16px 24px; background: var(--ios-surface); }
    
    /* Metrics Card */
    .metrics-card {
        border-radius: 16px;
        padding: 24px;
        color: #fff;
        box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        display: flex;
        align-items: center;
        gap: 20px;
        height: 100%;
    }
    .metrics-icon {
        width: 54px; height: 54px;
        border-radius: 14px;
        background: rgba(255,255,255,0.2);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.8rem;
        flex-shrink: 0;
    }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Sales History</h1>
        <div class="page-subtitle">Track, manage, and review all sales orders and payments.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="?export=excel&search=<?php echo urlencode($search_query); ?>&status=<?php echo urlencode($status_filter); ?>&method=<?php echo urlencode($method_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>" class="quick-btn quick-btn-secondary" title="Export Filtered Sales to Excel">
            <i class="bi bi-file-earmark-excel text-success"></i> Export Excel
        </a>
        <?php if(hasRole(['admin', 'rep'])): ?>
        <a href="create_order.php" class="quick-btn quick-btn-primary">
            <i class="bi bi-plus-lg"></i> New Order
        </a>
        <?php endif; ?>
    </div>
</div>

<?php echo $message; ?>

<!-- Summary Metrics -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #30C88A, #25A872);">
            <div class="metrics-icon"><i class="bi bi-cash-stack"></i></div>
            <div>
                <div style="font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.9;">Total Filtered Sales</div>
                <div style="font-size: 1.8rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($metrics['total_sales'] ?: 0, 2); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF9500, #E07800);">
            <div class="metrics-icon"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div style="font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.9;">Pending Receivables</div>
                <div style="font-size: 1.8rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($metrics['pending_amount'] ?: 0, 2); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="dash-card mb-4" style="background: var(--ios-surface-2);">
    <div class="p-3">
        <form method="GET" action="" id="filterForm">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="ios-label-sm">Search</label>
                    <div class="ios-search-wrapper">
                        <i class="bi bi-search"></i>
                        <input type="text" name="search" id="searchInput" class="ios-input" placeholder="ID or Customer..." value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="ios-label-sm">Method</label>
                    <select name="method" class="form-select" onchange="document.getElementById('filterForm').submit();">
                        <option value="">All</option>
                        <option value="Cash" <?php echo $method_filter == 'Cash' ? 'selected' : ''; ?>>Cash</option>
                        <option value="Credit" <?php echo $method_filter == 'Credit' ? 'selected' : ''; ?>>Credit</option>
                        <option value="Cheque" <?php echo $method_filter == 'Cheque' ? 'selected' : ''; ?>>Cheque</option>
                        <option value="Bank Transfer" <?php echo $method_filter == 'Bank Transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                        <option value="Pending" <?php echo $method_filter == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="ios-label-sm">Status</label>
                    <select name="status" class="form-select" onchange="document.getElementById('filterForm').submit();">
                        <option value="">All</option>
                        <option value="paid" <?php echo $status_filter == 'paid' ? 'selected' : ''; ?>>Paid</option>
                        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
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
                <div class="col-md-1">
                    <button type="submit" class="quick-btn quick-btn-secondary w-100" style="padding: 10px; min-height: 42px;" title="Apply Filters">
                        <i class="bi bi-funnel-fill"></i>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Orders Table -->
<div class="dash-card mb-4 overflow-hidden">
    <div class="table-responsive">
        <table class="ios-table">
            <thead>
                <tr class="table-ios-header">
                    <th style="width: 10%;">Order #</th>
                    <th style="width: 25%;">Date & Customer</th>
                    <th style="width: 15%;">Total Amount</th>
                    <th style="width: 15%;">Method</th>
                    <th style="width: 20%;">Status / Cheque Info</th>
                    <th style="width: 15%; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($orders as $o): ?>
                <tr>
                    <td>
                        <span style="font-weight: 800; font-size: 0.95rem; color: var(--accent-dark);">
                            #<?php echo str_pad($o['id'], 6, '0', STR_PAD_LEFT); ?>
                        </span>
                    </td>
                    <td>
                        <div style="font-weight: 700; font-size: 0.95rem; color: var(--ios-label);">
                            <?php if($o['customer_id']): ?>
                                <a href="view_customer.php?id=<?php echo $o['customer_id']; ?>" class="text-decoration-none" style="color: var(--ios-label);" title="View Customer Profile">
                                    <?php echo htmlspecialchars($o['customer_name']); ?> 
                                    <i class="bi bi-box-arrow-up-right ms-1" style="font-size: 0.65rem; color: var(--ios-label-3);"></i>
                                </a>
                            <?php else: ?>
                                Walk-in Customer
                            <?php endif; ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--ios-label-2); margin: 2px 0;">
                            <?php echo date('M d, Y h:i A', strtotime($o['created_at'])); ?>
                        </div>
                        <?php if(!$isRep): ?>
                        <div style="font-size: 0.75rem; color: var(--ios-label-3);">
                            <i class="bi bi-person-badge"></i> <?php echo htmlspecialchars($o['rep_name'] ?? 'System'); ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-weight: 800; font-size: 0.95rem; color: var(--ios-green);">
                            Rs <?php echo number_format($o['total_amount'], 2); ?>
                        </div>
                    </td>
                    <td>
                        <span class="ios-badge gray px-2">
                            <?php echo htmlspecialchars($o['payment_method']); ?>
                        </span>
                    </td>
                    <td>
                        <!-- Main Payment Status -->
                        <?php if($o['payment_status'] == 'paid'): ?>
                            <div class="mb-1"><span class="ios-badge outline" style="border-color: #1A9A3A; color: #1A9A3A;"><i class="bi bi-check-circle-fill"></i> Paid</span></div>
                        <?php else: ?>
                            <div class="mb-1"><span class="ios-badge outline-orange"><i class="bi bi-hourglass-split"></i> Pending</span></div>
                        <?php endif; ?>
                        
                        <!-- Cheque Dynamic Status Logic -->
                        <?php if($o['payment_method'] === 'Cheque'): ?>
                            <div>
                                <?php if(!$o['cheque_id']): ?>
                                    <span class="ios-badge red" style="font-size: 0.65rem;">Missing Cheque Info</span>
                                <?php else: ?>
                                    <?php 
                                        if($o['cheque_status'] === 'passed') {
                                            echo '<span class="ios-badge green" style="font-size: 0.65rem;"><i class="bi bi-check2-all"></i> Cheque Passed</span>';
                                        } elseif($o['cheque_status'] === 'returned') {
                                            echo '<span class="ios-badge red" style="font-size: 0.65rem;"><i class="bi bi-x-circle-fill"></i> Cheque Returned</span>';
                                        } else {
                                            $today = date('Y-m-d');
                                            if($o['banking_date'] > $today) {
                                                echo '<span class="ios-badge blue" style="font-size: 0.65rem;"><i class="bi bi-calendar-event"></i> Bank: '.date('M d', strtotime($o['banking_date'])).'</span>';
                                            } elseif($o['banking_date'] == $today) {
                                                echo '<span class="ios-badge orange" style="font-size: 0.65rem;"><i class="bi bi-bank"></i> Bank Today!</span>';
                                            } else {
                                                echo '<span class="ios-badge red" style="font-size: 0.65rem;"><i class="bi bi-exclamation-triangle-fill"></i> Banking Overdue</span>';
                                            }
                                        }
                                    ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right;">
                        <div class="d-flex justify-content-end gap-1 flex-wrap">
                            
                            <!-- Location -->
                            <?php if(!empty($o['latitude']) && !empty($o['longitude'])): ?>
                            <button class="quick-btn quick-btn-ghost" style="padding: 6px 10px; color: #34C759;" title="View Invoice Location" onclick="openLocationModal(<?php echo $o['latitude']; ?>, <?php echo $o['longitude']; ?>, '<?php echo htmlspecialchars($o['customer_name'] ?? 'Walk-in'); ?>', <?php echo $o['id']; ?>)">
                                <i class="bi bi-geo-alt-fill"></i>
                            </button>
                            <?php endif; ?>

                            <!-- Link/Edit Cheque -->
                            <?php if($o['payment_method'] === 'Cheque'): ?>
                                <button class="quick-btn quick-btn-ghost" style="padding: 6px 10px;" title="<?php echo $o['cheque_id'] ? 'Update Cheque Info' : 'Link Cheque Details'; ?>" 
                                    onclick='openChequeModal(<?php echo json_encode([
                                        "order_id" => $o['id'],
                                        "bank_name" => $o['bank_name'] ?? "",
                                        "cheque_number" => $o['cheque_number'] ?? "",
                                        "banking_date" => $o['banking_date'] ?? "",
                                        "amount" => $o['cheque_id'] ? $o['amount'] : $o['total_amount']
                                    ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                    <i class="bi bi-credit-card-2-front" style="color: #0055CC;"></i>
                                </button>
                            <?php endif; ?>

                            <!-- Open Full Invoice -->
                            <a href="view_invoice.php?id=<?php echo $o['id']; ?>" class="quick-btn quick-btn-secondary" style="padding: 6px 10px;" title="View/Print Invoice" target="_blank">
                                <i class="bi bi-receipt"></i>
                            </a>

                            <!-- Edit Bill -->
                            <?php if(hasRole(['admin', 'supervisor'])): ?>
                            <a href="create_order.php?edit_id=<?php echo $o['id']; ?>" class="quick-btn quick-btn-ghost" style="padding: 6px 10px;" title="Edit Bill">
                                <i class="bi bi-pencil-square"></i>
                            </a>
                            <?php endif; ?>

                            <!-- Mark as Paid -->
                            <?php if($o['payment_status'] == 'pending' && $o['payment_method'] !== 'Cheque'): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Mark this order as paid?');">
                                <input type="hidden" name="action" value="mark_paid">
                                <input type="hidden" name="order_id" value="<?php echo $o['id']; ?>">
                                <button type="submit" class="quick-btn" style="padding: 6px 10px; background: rgba(52,199,89,0.15); color: #1A9A3A;" title="Mark Paid">
                                    <i class="bi bi-check2-all"></i>
                                </button>
                            </form>
                            <?php endif; ?>

                            <!-- Delete Order (Admins Only) -->
                            <?php if(hasRole(['admin', 'supervisor'])): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('WARNING: Are you sure you want to completely delete this order? Associated stock will be automatically restored to inventory.');">
                                <input type="hidden" name="action" value="delete_order">
                                <input type="hidden" name="order_id" value="<?php echo $o['id']; ?>">
                                <button type="submit" class="quick-btn" style="padding: 6px 10px; background: rgba(255,59,48,0.1); color: #CC2200;" title="Delete Order">
                                    <i class="bi bi-trash3-fill"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($orders)): ?>
                <tr>
                    <td colspan="6">
                        <div class="empty-state">
                            <i class="bi bi-inboxes" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2" style="font-weight: 500;">No orders found matching your criteria.</p>
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
<ul class="ios-pagination">
    <?php for($i = 1; $i <= $totalPages; $i++): ?>
    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search_query); ?>&status=<?php echo $status_filter; ?>&method=<?php echo $method_filter; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"><?php echo $i; ?></a>
    </li>
    <?php endfor; ?>
</ul>
<?php endif; ?>

<!-- ==================== LINK CHEQUE MODAL ==================== -->
<div class="modal fade" id="chequeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title" style="font-size: 1.1rem; font-weight: 700;">
                        <i class="bi bi-credit-card-2-front text-primary me-2"></i>Link Cheque Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="background: var(--ios-bg);">
                    <input type="hidden" name="action" value="link_cheque">
                    <input type="hidden" name="order_id" id="cheque_order_id">
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Bank Name <span class="text-danger">*</span></label>
                        <input type="text" name="bank_name" id="cheque_bank" class="ios-input" placeholder="e.g., Commercial Bank" style="background: #fff;" required>
                    </div>
                    <div class="mb-3">
                        <label class="ios-label-sm">Cheque Number <span class="text-danger">*</span></label>
                        <input type="text" name="cheque_number" id="cheque_number" class="ios-input" placeholder="000000" style="background: #fff;" required>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Banking Date <span class="text-danger">*</span></label>
                            <input type="date" name="banking_date" id="cheque_date" class="ios-input" style="background: #fff;" required>
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Amount (Rs) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="amount" id="cheque_amount" class="ios-input fw-bold text-success-ios" style="background: #fff;" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn quick-btn-primary px-4">Save Cheque</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==================== LOCATION MAP MODAL ==================== -->
<div class="modal fade" id="locationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" style="font-size: 1.1rem; font-weight: 700;">
                    <i class="bi bi-geo-alt-fill text-success me-2"></i>Invoice Location: <span id="loc_customer_name"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="invoiceMap" style="height: 400px; width: 100%;"></div>
            </div>
            <div class="modal-footer">
                <a id="btnDirections" href="#" target="_blank" class="quick-btn quick-btn-primary">
                    <i class="bi bi-cursor"></i> Get Directions
                </a>
                <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Live Search Debounce
    const searchInput = document.getElementById('searchInput');
    if (searchInput && searchInput.value !== '') {
        searchInput.focus();
        const val = searchInput.value;
        searchInput.value = '';
        searchInput.value = val;
    }

    let searchTimer;
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => { document.getElementById('filterForm').submit(); }, 800); 
    });
});

// Populate & Open Cheque Modal
function openChequeModal(data) {
    document.getElementById('cheque_order_id').value = data.order_id;
    document.getElementById('cheque_bank').value = data.bank_name;
    document.getElementById('cheque_number').value = data.cheque_number;
    document.getElementById('cheque_date').value = data.banking_date;
    document.getElementById('cheque_amount').value = data.amount;
    
    new bootstrap.Modal(document.getElementById('chequeModal')).show();
}

let invoiceMap, invoiceMarker;

function openLocationModal(lat, lng, customerName, orderId) {
    document.getElementById('loc_customer_name').textContent = customerName + ' (Order #' + String(orderId).padStart(6, '0') + ')';
    document.getElementById('btnDirections').href = `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`;
    
    const myModal = new bootstrap.Modal(document.getElementById('locationModal'));
    myModal.show();

    document.getElementById('locationModal').addEventListener('shown.bs.modal', function () {
        if (!invoiceMap) {
            invoiceMap = L.map('invoiceMap').setView([lat, lng], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(invoiceMap);
            invoiceMarker = L.marker([lat, lng]).addTo(invoiceMap);
        } else {
            invoiceMap.setView([lat, lng], 15);
            invoiceMarker.setLatLng([lat, lng]);
            invoiceMap.invalidateSize();
        }
    }, { once: true });
}
</script>

<?php include '../includes/footer.php'; ?>