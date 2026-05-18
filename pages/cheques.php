<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); // Accounts management restricted

$message = '';

// --- AUTO DB MIGRATION ---
try {
    $pdo->exec("ALTER TABLE cheques MODIFY COLUMN order_id INT NULL");
    $pdo->exec("ALTER TABLE cheques ADD COLUMN customer_id INT NULL");
    $pdo->exec("ALTER TABLE cheques ADD COLUMN type ENUM('incoming', 'outgoing') DEFAULT 'incoming'");
    $pdo->exec("ALTER TABLE cheques ADD COLUMN supplier_id INT NULL");
    $pdo->exec("ALTER TABLE cheques ADD COLUMN grn_id INT NULL");
} catch(PDOException $e) {}
// -------------------------

// Determine Tab Type
$type_filter = isset($_GET['type']) && in_array($_GET['type'], ['incoming', 'outgoing']) ? $_GET['type'] : 'incoming';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'pending';

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // Add New Cheque
    if ($_POST['action'] == 'add_cheque') {
        $type = $_POST['cheque_type'];
        $customer_id = ($type == 'incoming' && !empty($_POST['customer_id'])) ? (int)$_POST['customer_id'] : null;
        $supplier_id = ($type == 'outgoing' && !empty($_POST['supplier_id'])) ? (int)$_POST['supplier_id'] : null;
        $bank_name = trim($_POST['bank_name']);
        $cheque_number = trim($_POST['cheque_number']);
        $banking_date = $_POST['banking_date'];
        $amount = (float)$_POST['amount'];

        $stmt = $pdo->prepare("INSERT INTO cheques (type, customer_id, supplier_id, bank_name, cheque_number, banking_date, amount, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
        if ($stmt->execute([$type, $customer_id, $supplier_id, $bank_name, $cheque_number, $banking_date, $amount])) {
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Cheque added successfully!</div>";
        }
    }

    // Edit Cheque
    if ($_POST['action'] == 'edit_cheque') {
        $cheque_id = (int)$_POST['cheque_id'];
        $type = $_POST['cheque_type']; // passed as hidden
        $customer_id = ($type == 'incoming' && !empty($_POST['customer_id'])) ? (int)$_POST['customer_id'] : null;
        $supplier_id = ($type == 'outgoing' && !empty($_POST['supplier_id'])) ? (int)$_POST['supplier_id'] : null;
        
        $bank_name = trim($_POST['bank_name']);
        $cheque_number = trim($_POST['cheque_number']);
        $banking_date = $_POST['banking_date'];
        $amount = (float)$_POST['amount'];

        $stmt = $pdo->prepare("UPDATE cheques SET customer_id = ?, supplier_id = ?, bank_name = ?, cheque_number = ?, banking_date = ?, amount = ? WHERE id = ?");
        if ($stmt->execute([$customer_id, $supplier_id, $bank_name, $cheque_number, $banking_date, $amount, $cheque_id])) {
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Cheque updated successfully!</div>";
        }
    }

    // Link Bill/GRN to Cheque
    if ($_POST['action'] == 'link_bill') {
        $cheque_id = (int)$_POST['cheque_id'];
        $entity_id = !empty($_POST['entity_id']) ? (int)$_POST['entity_id'] : null;
        $cheque_type = $_POST['cheque_type']; // 'incoming' or 'outgoing'

        if ($cheque_id && $entity_id) {
            try {
                $pdo->beginTransaction();
                if ($cheque_type == 'incoming') {
                    $pdo->prepare("UPDATE cheques SET order_id = ? WHERE id = ?")->execute([$entity_id, $cheque_id]);
                    $chkStmt = $pdo->prepare("SELECT amount FROM cheques WHERE id = ?");
                    $chkStmt->execute([$cheque_id]);
                    $amt = $chkStmt->fetchColumn();
                    $pdo->prepare("UPDATE orders SET payment_status = 'waiting', payment_method = 'Cheque', paid_amount = paid_amount + ?, paid_cheque = paid_cheque + ? WHERE id = ?")->execute([$amt, $amt, $entity_id]);
                } else {
                    $pdo->prepare("UPDATE cheques SET grn_id = ? WHERE id = ?")->execute([$entity_id, $cheque_id]);
                    $pdo->prepare("UPDATE grns SET payment_status = 'waiting', payment_method = 'Cheque' WHERE id = ?")->execute([$entity_id]);
                }
                $pdo->commit();
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-link me-2'></i> Successfully linked to Cheque!</div>";
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error linking: ".$e->getMessage()."</div>";
            }
        }
    }

    // Update Status (Pass / Return)
    if ($_POST['action'] == 'update_status') {
        $cheque_id = (int)$_POST['cheque_id'];
        $new_status = $_POST['status']; // 'passed' or 'returned'
        
        try {
            $pdo->beginTransaction();
            $chkStmt = $pdo->prepare("SELECT type, order_id, grn_id, amount, cheque_number, bank_name FROM cheques WHERE id = ? FOR UPDATE");
            $chkStmt->execute([$cheque_id]);
            $chk = $chkStmt->fetch();
            
            $pdo->prepare("UPDATE cheques SET status = ? WHERE id = ?")->execute([$new_status, $cheque_id]);

            if ($chk['type'] === 'incoming') {
                if ($new_status === 'passed') {
                    $pdo->prepare("UPDATE company_finances SET bank_balance = bank_balance + ? WHERE id = 1")->execute([$chk['amount']]);
                    $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('bank_in', ?, ?, ?)")->execute([$chk['amount'], "Incoming Cheque Cleared: {$chk['cheque_number']} ({$chk['bank_name']})", $_SESSION['user_id']]);
                    
                    if ($chk['order_id']) $pdo->prepare("UPDATE orders SET payment_status = 'paid' WHERE id = ?")->execute([$chk['order_id']]);
                } elseif ($new_status === 'returned' && $chk['order_id']) {
                    $pdo->prepare("UPDATE orders SET payment_status = 'pending', paid_amount = GREATEST(0, paid_amount - ?), paid_cheque = GREATEST(0, paid_cheque - ?) WHERE id = ?")->execute([$chk['amount'], $chk['amount'], $chk['order_id']]);
                }
            } else {
                // Outgoing Cheque Logic
                if ($new_status === 'passed') {
                    // Deduct from Bank
                    $pdo->prepare("UPDATE company_finances SET bank_balance = bank_balance - ? WHERE id = 1")->execute([$chk['amount']]);
                    $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('bank_out', ?, ?, ?)")->execute([$chk['amount'], "Outgoing Cheque Cleared: {$chk['cheque_number']} ({$chk['bank_name']})", $_SESSION['user_id']]);
                    
                    if ($chk['grn_id']) $pdo->prepare("UPDATE grns SET payment_status = 'paid', paid_amount = paid_amount + ? WHERE id = ?")->execute([$chk['amount'], $chk['grn_id']]);
                } elseif ($new_status === 'returned' && $chk['grn_id']) {
                    $pdo->prepare("UPDATE grns SET payment_status = 'pending' WHERE id = ?")->execute([$chk['grn_id']]);
                }
            }
            
            $pdo->commit();
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Cheque marked as " . ucfirst($new_status) . " successfully!</div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error updating status: " . $e->getMessage() . "</div>";
        }
    }

    // Delete Cheque
    if ($_POST['action'] == 'delete_cheque') {
        $cheque_id = (int)$_POST['cheque_id'];
        try {
            $pdo->beginTransaction();
            $chkStmt = $pdo->prepare("SELECT type, order_id, grn_id, amount FROM cheques WHERE id = ?");
            $chkStmt->execute([$cheque_id]);
            $chk = $chkStmt->fetch();

            if ($chk['type'] == 'incoming' && $chk['order_id']) {
                $pdo->prepare("UPDATE orders SET payment_status = 'pending', paid_amount = GREATEST(0, paid_amount - ?), paid_cheque = GREATEST(0, paid_cheque - ?) WHERE id = ? AND payment_status = 'waiting'")->execute([$chk['amount'], $chk['amount'], $chk['order_id']]);
            } elseif ($chk['type'] == 'outgoing' && $chk['grn_id']) {
                $pdo->prepare("UPDATE grns SET payment_status = 'pending' WHERE id = ? AND payment_status = 'waiting'")->execute([$chk['grn_id']]);
            }
            
            $pdo->prepare("DELETE FROM cheques WHERE id = ?")->execute([$cheque_id]);
            $pdo->commit();
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-trash3-fill me-2'></i> Cheque deleted successfully.</div>";
        } catch (Exception $e) {}
    }
}

// --- FETCH DATA ---
// Fetch Metrics for current tab
$metricsStmt = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as total_pending,
        SUM(CASE WHEN status = 'passed' THEN amount ELSE 0 END) as total_passed,
        MIN(CASE WHEN status = 'pending' AND banking_date >= CURDATE() THEN banking_date ELSE NULL END) as nearest_date
    FROM cheques WHERE type = ?
");
$metricsStmt->execute([$type_filter]);
$metrics = $metricsStmt->fetch();

// Fetch Cheques
$query = "
    SELECT ch.*, 
           c.id as cust_id, c.name as customer_name,
           s.id as supp_id, s.company_name as supplier_name,
           o.id as linked_order_id, o.total_amount as order_amount,
           g.id as linked_grn_id, g.total_amount as grn_amount
    FROM cheques ch
    LEFT JOIN orders o ON ch.order_id = o.id
    LEFT JOIN customers c ON c.id = COALESCE(ch.customer_id, o.customer_id)
    LEFT JOIN grns g ON ch.grn_id = g.id
    LEFT JOIN suppliers s ON s.id = COALESCE(ch.supplier_id, g.supplier_id)
    WHERE ch.type = :type
";
$params = ['type' => $type_filter];

if ($status_filter !== 'all') {
    $query .= " AND ch.status = :status";
    $params['status'] = $status_filter;
}
$query .= " ORDER BY ch.banking_date ASC, ch.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);

$cheques = $stmt->fetchAll();

// Group Cheques by Date
$groupedCheques = [];
foreach ($cheques as $chk) {
    $date = $chk['banking_date'];
    if (!isset($groupedCheques[$date])) $groupedCheques[$date] = [];
    $groupedCheques[$date][] = $chk;
}

// Fetch Dropdown Data
$customers = $pdo->query("SELECT id, name FROM customers ORDER BY name ASC")->fetchAll();
$suppliers = $pdo->query("SELECT id, company_name FROM suppliers ORDER BY company_name ASC")->fetchAll();
$pendingOrders = $pdo->query("SELECT id, customer_id, total_amount, created_at FROM orders WHERE payment_status = 'pending'")->fetchAll();
$pendingGRNs = $pdo->query("SELECT id, supplier_id, total_amount, grn_date as created_at FROM grns WHERE payment_status = 'pending'")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Tom Select CSS for Searchable Dropdowns -->
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">

<style>
    @keyframes blinker { 50% { opacity: 0; } }
    .blink-animation { animation: blinker 1.5s linear infinite; }

    /* Segmented Control */
    .ios-segmented-control {
        display: inline-flex;
        background: rgba(118, 118, 128, 0.12);
        padding: 4px;
        border-radius: 12px;
        margin-bottom: 24px;
        width: auto;
    }
    .ios-segmented-control .nav-link {
        color: var(--ios-label);
        font-weight: 600;
        font-size: 0.85rem;
        padding: 8px 20px;
        border-radius: 8px;
        transition: all 0.2s;
        border: none;
        background: transparent;
        text-decoration: none;
    }
    .ios-segmented-control .nav-link:hover { opacity: 0.8; }
    .ios-segmented-control .nav-link.active {
        background: #fff;
        color: var(--ios-label);
        box-shadow: 0 3px 8px rgba(0,0,0,0.12), 0 1px 1px rgba(0,0,0,0.04);
    }
    
    /* Metrics Card */
    .metrics-card {
        border-radius: 16px;
        padding: 20px 24px;
        color: #fff;
        box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        display: flex;
        flex-direction: column;
        justify-content: center;
        height: 100%;
        position: relative;
        overflow: hidden;
    }
    .metrics-bg-icon {
        position: absolute;
        right: -15px;
        bottom: -20px;
        font-size: 7rem;
        opacity: 0.15;
        z-index: 1;
    }

    /* Modal Inputs */
    .modal-body .ios-input, .modal-body .form-select {
        background: #FFFFFF !important;
        border: 1px solid #C7C7CC !important;
        border-radius: 10px !important;
        padding: 10px 14px !important;
        font-size: 0.95rem !important;
        color: #000000 !important;
        width: 100%; outline: none; box-shadow: inset 0 1px 3px rgba(0,0,0,0.03) !important;
        transition: border 0.2s;
    }
    .modal-body .ios-input:focus, .modal-body .form-select:focus { 
        border-color: var(--accent) !important; box-shadow: 0 0 0 3px rgba(48,200,138,0.2) !important;
    }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Manage Cheques</h1>
        <div class="page-subtitle">Track, link, and process incoming and outgoing cheques.</div>
    </div>
    <div>
        <button class="quick-btn quick-btn-primary" onclick="openAddModal()">
            <i class="bi bi-plus-lg"></i> Add Cheque
        </button>
    </div>
</div>

<?php echo $message; ?>

<!-- Tabs (Segmented Control) -->
<div class="ios-segmented-control mb-4">
    <a class="nav-link <?php echo $type_filter == 'incoming' ? 'active' : ''; ?>" href="?type=incoming&status=<?php echo $status_filter; ?>">
        <i class="bi bi-box-arrow-in-down-left me-1" style="color: #0055CC;"></i> Incoming (From Customers)
    </a>
    <a class="nav-link <?php echo $type_filter == 'outgoing' ? 'active' : ''; ?>" href="?type=outgoing&status=<?php echo $status_filter; ?>">
        <i class="bi bi-box-arrow-up-right me-1" style="color: #CC2200;"></i> Outgoing (To Suppliers)
    </a>
</div>

<!-- Top Metrics -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF9500, #E07800);">
            <i class="bi bi-hourglass-split metrics-bg-icon"></i>
            <div style="position: relative; z-index: 2;">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.8); margin-bottom: 2px;">Total Pending</div>
                <div style="font-size: 1.8rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($metrics['total_pending'] ?: 0, 2); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="metrics-card" style="background: linear-gradient(145deg, #34C759, #30D158);">
            <i class="bi bi-check-circle-fill metrics-bg-icon"></i>
            <div style="position: relative; z-index: 2;">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.8); margin-bottom: 2px;">Total Passed</div>
                <div style="font-size: 1.8rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($metrics['total_passed'] ?: 0, 2); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="metrics-card" style="background: linear-gradient(145deg, #5856D6, #4543B0);">
            <i class="bi bi-calendar-event metrics-bg-icon"></i>
            <div style="position: relative; z-index: 2;">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.8); margin-bottom: 2px;">Nearest Banking Date</div>
                <div style="font-size: 1.4rem; font-weight: 800; letter-spacing: -0.5px;">
                    <?php echo $metrics['nearest_date'] ? date('M d, Y', strtotime($metrics['nearest_date'])) : 'No Pending'; ?>
                </div>
                <?php if($metrics['nearest_date'] == date('Y-m-d')): ?>
                    <span class="ios-badge outline mt-2 blink-animation" style="border-color: #fff; color: #fff;">Bank Today!</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="d-flex mb-4 gap-2 flex-wrap">
    <a href="?type=<?php echo $type_filter; ?>&status=pending" class="quick-btn <?php echo $status_filter == 'pending' ? 'quick-btn-primary' : 'quick-btn-secondary'; ?>" style="<?php echo $status_filter == 'pending' ? 'background: #FF9500; box-shadow: 0 4px 14px rgba(255,149,0,0.3);' : ''; ?>">
        Pending / Waiting
    </a>
    <a href="?type=<?php echo $type_filter; ?>&status=passed" class="quick-btn <?php echo $status_filter == 'passed' ? 'quick-btn-primary' : 'quick-btn-secondary'; ?>">
        Passed
    </a>
    <a href="?type=<?php echo $type_filter; ?>&status=returned" class="quick-btn <?php echo $status_filter == 'returned' ? 'quick-btn-primary' : 'quick-btn-secondary'; ?>" style="<?php echo $status_filter == 'returned' ? 'background: #CC2200; box-shadow: 0 4px 14px rgba(204,34,0,0.3);' : ''; ?>">
        Returned
    </a>
    <a href="?type=<?php echo $type_filter; ?>&status=all" class="quick-btn <?php echo $status_filter == 'all' ? 'quick-btn-primary' : 'quick-btn-secondary'; ?>" style="<?php echo $status_filter == 'all' ? 'background: #1c1c1e;' : ''; ?>">
        View All
    </a>
</div>

<!-- Grouped Cheques List -->
<?php if (empty($groupedCheques)): ?>
    <div class="empty-state py-5" style="background: var(--ios-surface); border-radius: 16px; border: 1px solid var(--ios-separator);">
        <i class="bi bi-wallet2" style="font-size: 3rem; color: var(--ios-label-4);"></i>
        <h5 class="mt-3 fw-bold">No <?php echo $type_filter; ?> cheques found.</h5>
        <p class="text-muted">Try selecting a different status filter above.</p>
    </div>
<?php else: ?>
    <?php foreach($groupedCheques as $date => $dailyCheques): 
        $isPast = ($date < date('Y-m-d') && $status_filter == 'pending');
        $isToday = ($date == date('Y-m-d'));
        
        $headerBg = 'var(--ios-surface-2)';
        $headerText = 'var(--ios-label)';
        if($isPast) { $headerBg = 'rgba(255,59,48,0.1)'; $headerText = '#CC2200'; }
        if($isToday) { $headerBg = 'rgba(255,149,0,0.1)'; $headerText = '#C07000'; }
    ?>
    
    <div class="dash-card mb-4 overflow-hidden">
        <div class="dash-card-header" style="background: <?php echo $headerBg; ?>; padding: 14px 20px;">
            <span class="card-title" style="color: <?php echo $headerText; ?>; font-size: 1rem;">
                <i class="bi bi-calendar-event-fill me-1"></i> 
                <?php echo date('l, F d, Y', strtotime($date)); ?>
                <?php if($isPast): ?><span class="ios-badge red ms-2">Overdue</span><?php endif; ?>
                <?php if($isToday): ?><span class="ios-badge orange ms-2">Today</span><?php endif; ?>
            </span>
        </div>
        <div class="table-responsive">
            <table class="ios-table">
                <thead>
                    <tr class="table-ios-header">
                        <th style="width: 25%;" class="ps-4">Bank & Cheque No</th>
                        <th style="width: 25%;"><?php echo $type_filter == 'incoming' ? 'Customer' : 'Supplier'; ?></th>
                        <th style="width: 15%;">Linked Doc</th>
                        <th style="width: 15%; text-align: right;">Amount</th>
                        <th style="width: 10%; text-align: center;">Status</th>
                        <th class="text-end pe-4" style="width: 10%;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($dailyCheques as $c): ?>
                    <tr>
                        <td class="ps-4">
                            <div style="font-weight: 700; font-size: 0.95rem; color: var(--ios-label);"><?php echo htmlspecialchars($c['bank_name']); ?></div>
                            <div style="font-size: 0.75rem; color: var(--ios-label-3); margin-top: 2px; font-family: monospace;">
                                <i class="bi bi-upc-scan"></i> <?php echo htmlspecialchars($c['cheque_number']); ?>
                            </div>
                            
                            <?php 
                            // Check 7-day Upcoming Warning
                            $days_diff = (strtotime($c['banking_date']) - strtotime(date('Y-m-d'))) / 86400;
                            if ($c['status'] == 'pending' && $days_diff >= 0 && $days_diff <= 7) {
                                echo '<div style="margin-top: 6px;"><span class="ios-badge red outline blink-animation" style="font-size: 0.65rem;"><i class="bi bi-exclamation-triangle-fill"></i> In '.round($days_diff).' Days</span></div>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php if($type_filter == 'incoming'): ?>
                                <?php if($c['cust_id']): ?>
                                    <a href="view_customer.php?id=<?php echo $c['cust_id']; ?>" target="_blank" style="font-weight: 600; color: #0055CC; text-decoration: none;">
                                        <?php echo htmlspecialchars($c['customer_name']); ?> <i class="bi bi-box-arrow-up-right ms-1" style="font-size: 0.7rem;"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted fst-italic" style="font-size: 0.85rem;">Unknown Customer</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="font-weight: 600; color: var(--ios-label);"><?php echo htmlspecialchars($c['supplier_name'] ?? 'Unknown Supplier'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($type_filter == 'incoming'): ?>
                                <?php if($c['linked_order_id']): ?>
                                    <a href="view_invoice.php?id=<?php echo $c['linked_order_id']; ?>" target="_blank" class="ios-badge blue outline text-decoration-none">Order #<?php echo str_pad($c['linked_order_id'], 6, '0', STR_PAD_LEFT); ?></a>
                                <?php else: ?>
                                    <button class="quick-btn quick-btn-ghost" style="padding: 4px 10px; font-size: 0.7rem;" onclick='openLinkModal(<?php echo $c['id']; ?>, <?php echo $c['cust_id'] ?: "null"; ?>, "incoming")'><i class="bi bi-link"></i> Link</button>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if($c['linked_grn_id']): ?>
                                    <a href="view_grn.php?id=<?php echo $c['linked_grn_id']; ?>" target="_blank" class="ios-badge orange outline text-decoration-none">GRN #<?php echo str_pad($c['linked_grn_id'], 6, '0', STR_PAD_LEFT); ?></a>
                                <?php else: ?>
                                    <button class="quick-btn quick-btn-ghost" style="padding: 4px 10px; font-size: 0.7rem; color: #C07000; background: rgba(255,149,0,0.1);" onclick='openLinkModal(<?php echo $c['id']; ?>, <?php echo $c['supp_id'] ?: "null"; ?>, "outgoing")'><i class="bi bi-link"></i> Link</button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-end" style="font-weight: 800; font-size: 1rem; color: <?php echo $type_filter == 'outgoing' ? '#CC2200' : '#1A9A3A'; ?>;">
                            Rs <?php echo number_format($c['amount'], 2); ?>
                        </td>
                        <td class="text-center">
                            <?php 
                                if($c['status'] === 'passed') echo '<span class="ios-badge green">Passed</span>';
                                elseif($c['status'] === 'returned') echo '<span class="ios-badge red">Returned</span>';
                                else echo '<span class="ios-badge orange">Pending</span>';
                            ?>
                        </td>
                        <td class="text-end pe-4">
                            <div class="d-flex justify-content-end gap-1 flex-wrap">
                                <?php if($c['status'] === 'pending'): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Mark cheque as Passed? This updates Bank Balance instantly.');">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="status" value="passed">
                                        <input type="hidden" name="cheque_id" value="<?php echo $c['id']; ?>">
                                        <button type="submit" class="quick-btn" style="padding: 6px 10px; background: rgba(52,199,89,0.15); color: #1A9A3A;" title="Pass"><i class="bi bi-check-lg"></i></button>
                                    </form>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Mark cheque as Returned?');">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="status" value="returned">
                                        <input type="hidden" name="cheque_id" value="<?php echo $c['id']; ?>">
                                        <button type="submit" class="quick-btn" style="padding: 6px 10px; background: rgba(255,59,48,0.1); color: #CC2200;" title="Return"><i class="bi bi-x-lg"></i></button>
                                    </form>

                                    <button class="quick-btn quick-btn-secondary" style="padding: 6px 10px;" title="Edit" onclick='openEditModal(<?php echo htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8'); ?>, "<?php echo $type_filter; ?>")'>
                                        <i class="bi bi-pencil-square" style="color: #FF9500;"></i>
                                    </button>
                                <?php endif; ?>
                                
                                <form method="POST" class="d-inline" onsubmit="return confirm('Permanently delete this cheque?');">
                                    <input type="hidden" name="action" value="delete_cheque">
                                    <input type="hidden" name="cheque_id" value="<?php echo $c['id']; ?>">
                                    <button type="submit" class="quick-btn quick-btn-ghost" style="padding: 6px 10px; background: rgba(60,60,67,0.1); color: var(--ios-label-2);" title="Delete"><i class="bi bi-trash3-fill"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- ================= MODALS ================= -->

<!-- Add Cheque Modal -->
<div class="modal fade" id="addChequeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem;"><i class="bi bi-plus-circle-fill text-primary me-2"></i>Add New Cheque</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="add_cheque">
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Cheque Type <span class="text-danger">*</span></label>
                        <select name="cheque_type" id="add_cheque_type" class="form-select fw-bold border-dark">
                            <option value="incoming" <?php echo $type_filter == 'incoming' ? 'selected' : ''; ?>>Incoming (From Customer)</option>
                            <option value="outgoing" <?php echo $type_filter == 'outgoing' ? 'selected' : ''; ?>>Outgoing (To Supplier)</option>
                        </select>
                    </div>

                    <div class="mb-3" id="add_cust_block" style="<?php echo $type_filter == 'outgoing' ? 'display:none;' : ''; ?>">
                        <label class="ios-label-sm">Select Customer</label>
                        <select name="customer_id" id="add_customer_id" class="form-select">
                            <option value="">-- Unlinked / Select Later --</option>
                            <?php foreach($customers as $cust): ?>
                                <option value="<?php echo $cust['id']; ?>"><?php echo htmlspecialchars($cust['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3" id="add_supp_block" style="<?php echo $type_filter == 'incoming' ? 'display:none;' : ''; ?>">
                        <label class="ios-label-sm">Select Supplier</label>
                        <select name="supplier_id" id="add_supplier_id" class="form-select">
                            <option value="">-- Unlinked / Select Later --</option>
                            <?php foreach($suppliers as $supp): ?>
                                <option value="<?php echo $supp['id']; ?>"><?php echo htmlspecialchars($supp['company_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Bank Name <span class="text-danger">*</span></label>
                        <input type="text" name="bank_name" class="ios-input" required placeholder="e.g. Commercial Bank">
                    </div>
                    <div class="mb-3">
                        <label class="ios-label-sm">Cheque Number <span class="text-danger">*</span></label>
                        <input type="text" name="cheque_number" class="ios-input" required style="font-family: monospace;">
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Banking Date <span class="text-danger">*</span></label>
                            <input type="date" name="banking_date" class="ios-input" required>
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Amount (Rs) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="amount" class="ios-input fw-bold text-success" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn quick-btn-primary px-4">Save Cheque</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Cheque Modal -->
<div class="modal fade" id="editChequeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem; color: #C07000;"><i class="bi bi-pencil-square me-2"></i>Edit Cheque</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="edit_cheque">
                    <input type="hidden" name="cheque_id" id="edit_cheque_id">
                    <input type="hidden" name="cheque_type" id="edit_cheque_type_hidden">
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Cheque Type</label>
                        <input type="text" class="ios-input bg-light text-uppercase fw-bold text-muted" id="edit_type_display" disabled>
                    </div>

                    <div class="mb-3" id="edit_cust_block">
                        <label class="ios-label-sm">Customer</label>
                        <select name="customer_id" id="edit_customer_id" class="form-select">
                            <option value="">-- Unknown --</option>
                            <?php foreach($customers as $cust): ?>
                                <option value="<?php echo $cust['id']; ?>"><?php echo htmlspecialchars($cust['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3" id="edit_supp_block">
                        <label class="ios-label-sm">Supplier</label>
                        <select name="supplier_id" id="edit_supplier_id" class="form-select">
                            <option value="">-- Unknown --</option>
                            <?php foreach($suppliers as $supp): ?>
                                <option value="<?php echo $supp['id']; ?>"><?php echo htmlspecialchars($supp['company_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="ios-label-sm">Bank Name <span class="text-danger">*</span></label>
                        <input type="text" name="bank_name" id="edit_bank_name" class="ios-input" required>
                    </div>
                    <div class="mb-3">
                        <label class="ios-label-sm">Cheque Number <span class="text-danger">*</span></label>
                        <input type="text" name="cheque_number" id="edit_cheque_number" class="ios-input" required style="font-family: monospace;">
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Banking Date <span class="text-danger">*</span></label>
                            <input type="date" name="banking_date" id="edit_banking_date" class="ios-input" required>
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Amount (Rs) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="amount" id="edit_amount" class="ios-input fw-bold" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn px-4" style="background: #FF9500; color: #fff;">Update Cheque</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Link Bill/GRN Modal -->
<div class="modal fade" id="linkBillModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem; color: #0055CC;"><i class="bi bi-link me-2"></i>Link Cheque to Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="link_bill">
                    <input type="hidden" name="cheque_id" id="link_cheque_id">
                    <input type="hidden" name="cheque_type" id="link_cheque_type">
                    
                    <div class="ios-alert mb-4" style="background: rgba(0,122,255,0.08); color: #0055CC; font-size: 0.85rem;">
                        <i class="bi bi-info-circle-fill me-2"></i> Select a pending document to assign this cheque to. The document's status will automatically update to <strong>Waiting</strong>.
                    </div>

                    <div class="mb-4">
                        <label class="ios-label-sm" id="link_select_label">Select Pending Document</label>
                        <select name="entity_id" id="link_entity_select" class="form-select fw-bold" style="height: 50px;" required>
                            <option value="">-- Select --</option>
                        </select>
                        <div id="no_bills_msg" class="text-danger small mt-2 d-none fw-bold"><i class="bi bi-exclamation-triangle-fill"></i> No pending documents available to link for this contact.</div>
                    </div>
                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn px-4" style="background: #0055CC; color: #fff;" id="btn_submit_link">Link Now</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Tom Select JS for Searchable Dropdowns -->
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>

<script>
const allPendingOrders = <?php echo json_encode($pendingOrders); ?>;
const allPendingGRNs = <?php echo json_encode($pendingGRNs); ?>;

let addCustSelect, addSuppSelect, editCustSelect, editSuppSelect;

document.addEventListener('DOMContentLoaded', function() {
    addCustSelect = new TomSelect('#add_customer_id', { create: false, sortField: { field: "text", direction: "asc" } });
    addSuppSelect = new TomSelect('#add_supplier_id', { create: false, sortField: { field: "text", direction: "asc" } });
    editCustSelect = new TomSelect('#edit_customer_id', { create: false, sortField: { field: "text", direction: "asc" } });
    editSuppSelect = new TomSelect('#edit_supplier_id', { create: false, sortField: { field: "text", direction: "asc" } });

    document.getElementById('add_cheque_type').addEventListener('change', function() {
        if(this.value === 'incoming') {
            document.getElementById('add_cust_block').style.display = 'block';
            document.getElementById('add_supp_block').style.display = 'none';
        } else {
            document.getElementById('add_cust_block').style.display = 'none';
            document.getElementById('add_supp_block').style.display = 'block';
        }
    });
});

function openAddModal() {
    new bootstrap.Modal(document.getElementById('addChequeModal')).show();
}

function openEditModal(data, type) {
    document.getElementById('edit_cheque_id').value = data.id;
    document.getElementById('edit_cheque_type_hidden').value = type;
    document.getElementById('edit_type_display').value = type;
    document.getElementById('edit_bank_name').value = data.bank_name;
    document.getElementById('edit_cheque_number').value = data.cheque_number;
    document.getElementById('edit_banking_date').value = data.banking_date;
    document.getElementById('edit_amount').value = data.amount;
    
    if (type === 'incoming') {
        document.getElementById('edit_cust_block').style.display = 'block';
        document.getElementById('edit_supp_block').style.display = 'none';
        if (editCustSelect) editCustSelect.setValue(data.cust_id || '');
    } else {
        document.getElementById('edit_cust_block').style.display = 'none';
        document.getElementById('edit_supp_block').style.display = 'block';
        if (editSuppSelect) editSuppSelect.setValue(data.supp_id || '');
    }
    
    new bootstrap.Modal(document.getElementById('editChequeModal')).show();
}

function openLinkModal(chequeId, entityId, type) {
    document.getElementById('link_cheque_id').value = chequeId;
    document.getElementById('link_cheque_type').value = type;
    
    const select = document.getElementById('link_entity_select');
    const msg = document.getElementById('no_bills_msg');
    const btn = document.getElementById('btn_submit_link');
    const label = document.getElementById('link_select_label');
    
    select.innerHTML = '<option value="">-- Select --</option>';
    
    let filteredDocs = [];
    if (type === 'incoming') {
        label.innerText = 'Select Pending Customer Bill';
        filteredDocs = allPendingOrders.filter(o => o.customer_id == entityId);
    } else {
        label.innerText = 'Select Pending GRN';
        filteredDocs = allPendingGRNs.filter(g => g.supplier_id == entityId);
    }
    
    if (filteredDocs.length > 0) {
        select.style.display = 'block';
        msg.classList.add('d-none');
        btn.disabled = false;
        
        filteredDocs.forEach(d => {
            const dateStr = new Date(d.created_at).toLocaleDateString();
            const prefix = type === 'incoming' ? 'Bill' : 'GRN';
            select.innerHTML += `<option value="${d.id}">${prefix} #${String(d.id).padStart(6, '0')} - Rs: ${parseFloat(d.total_amount).toFixed(2)} (${dateStr})</option>`;
        });
    } else {
        select.style.display = 'none';
        msg.classList.remove('d-none');
        btn.disabled = true;
    }
    
    new bootstrap.Modal(document.getElementById('linkBillModal')).show();
}
</script>

<?php include '../includes/footer.php'; ?>