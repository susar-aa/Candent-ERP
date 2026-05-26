<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); // Restricted to management

// Check for assignment ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: route_sales.php");
    exit;
}

$assignment_id = (int)$_GET['id'];

try {
    // 1. Fetch Route Assignment details
    $asgStmt = $pdo->prepare("
        SELECT rr.*, r.name as route_name, u.name as rep_name, e.name as driver_name 
        FROM rep_routes rr 
        JOIN routes r ON rr.route_id = r.id 
        JOIN users u ON rr.rep_id = u.id 
        LEFT JOIN employees e ON rr.driver_id = e.id 
        WHERE rr.id = ?
    ");
    $asgStmt->execute([$assignment_id]);
    $asg = $asgStmt->fetch();

    if (!$asg) {
        die("Route assignment not found.");
    }

    // 2. Fetch Orders / Invoices generated
    $ordersStmt = $pdo->prepare("
        SELECT o.*, c.name as customer_name 
        FROM orders o 
        LEFT JOIN customers c ON o.customer_id = c.id 
        WHERE o.assignment_id = ?
        ORDER BY o.created_at ASC
    ");
    $ordersStmt->execute([$assignment_id]);
    $orders = $ordersStmt->fetchAll();

    // 3. Fetch Route Loads & Reconciliations
    $loadsStmt = $pdo->prepare("
        SELECT rl.*, p.name as product_name, p.sku, p.selling_price, p.cost_price
        FROM route_loads rl 
        JOIN products p ON rl.product_id = p.id 
        WHERE rl.assignment_id = ?
        ORDER BY p.name ASC
    ");
    $loadsStmt->execute([$assignment_id]);
    $loads = $loadsStmt->fetchAll();

    // 4. Fetch Route Expenses
    $expStmt = $pdo->prepare("
        SELECT * FROM route_expenses 
        WHERE assignment_id = ? 
        ORDER BY id ASC
    ");
    $expStmt->execute([$assignment_id]);
    $expenses = $expStmt->fetchAll();

    // 5. Fetch Unproductive Shop Visits
    $unprodStmt = $pdo->prepare("
        SELECT uv.*, c.name as customer_name 
        FROM unproductive_visits uv 
        JOIN customers c ON uv.customer_id = c.id 
        WHERE uv.rep_id = ? AND DATE(uv.created_at) = ?
        ORDER BY uv.created_at ASC
    ");
    $unprodStmt->execute([$asg['rep_id'], $asg['assign_date']]);
    $unproductive_visits = $unprodStmt->fetchAll();

    // 6. Fetch GPS Location Logs for the Day
    $gpsStmt = $pdo->prepare("
        SELECT * FROM rep_location_logs 
        WHERE user_id = ? AND DATE(timestamp) = ? 
        ORDER BY timestamp ASC
    ");
    $gpsStmt->execute([$asg['rep_id'], $asg['assign_date']]);
    $gps_logs = $gpsStmt->fetchAll();

    // Financial Summaries (strictly as of Route operational date, unaffected by later payments)
    $total_gross = 0;
    $total_cash = 0;
    $total_bank = 0;
    $total_cheque = 0;
    $total_paid = 0;
    $total_credit = 0;
    
    foreach ($orders as $o) {
        $o_paid_on_route = (float)$o['paid_cash'] + (float)$o['paid_bank'] + (float)$o['paid_cheque'];
        $o_credit_on_route = (float)$o['total_amount'] - $o_paid_on_route;

        $total_gross += (float)$o['total_amount'];
        $total_cash += (float)$o['paid_cash'];
        $total_bank += (float)$o['paid_bank'];
        $total_cheque += (float)$o['paid_cheque'];
        $total_paid += $o_paid_on_route;
        $total_credit += $o_credit_on_route;
    }

    $total_exp_amt = 0;
    foreach ($expenses as $e) {
        $total_exp_amt += (float)$e['amount'];
    }

    // 5.1 Fetch Customer Credit Outstanding Payments (Collections) on this route
    $colStmt = $pdo->prepare("
        SELECT cp.*, c.name as customer_name 
        FROM customer_payments cp 
        LEFT JOIN customers c ON cp.customer_id = c.id 
        WHERE cp.assignment_id = ?
        ORDER BY cp.created_at ASC
    ");
    $colStmt->execute([$assignment_id]);
    $credit_payments = $colStmt->fetchAll();

    // Calculate Credit Collections Breakdown
    $coll_cash = 0.0;
    $coll_bank = 0.0;
    $coll_cheque = 0.0;
    $coll_other = 0.0;
    $coll_total = 0.0;

    foreach ($credit_payments as $cp) {
        $amt = (float)$cp['amount'];
        $coll_total += $amt;
        if ($cp['method'] == 'Cash') {
            $coll_cash += $amt;
        } elseif ($cp['method'] == 'Bank Transfer') {
            $coll_bank += $amt;
        } elseif ($cp['method'] == 'Cheque') {
            $coll_cheque += $amt;
        } else {
            $coll_other += $amt;
        }
    }
    
    // Live calculated expected cash
    $expected_cash_cal = max(0, $total_cash + $coll_cash - $total_exp_amt);

} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    /* --- Premium Page Styles (Candent iOS-like Layout) --- */
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

    /* iOS Detail Info Cards */
    .detail-card {
        background: var(--ios-surface);
        border: 1px solid var(--ios-separator);
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 24px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.02);
    }
    .detail-card-title {
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--ios-label);
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
        border-bottom: 1px solid var(--ios-separator);
        padding-bottom: 10px;
    }
    .info-row {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        border-bottom: 1px dashed var(--ios-separator);
        font-size: 0.9rem;
    }
    .info-row:last-child {
        border-bottom: none;
    }
    .info-label {
        color: var(--ios-label-2);
        font-weight: 500;
    }
    .info-value {
        color: var(--ios-label);
        font-weight: 700;
    }

    /* Tabs Styling */
    .ios-tabs {
        display: flex;
        gap: 8px;
        border-bottom: 1px solid var(--ios-separator);
        margin-bottom: 24px;
        padding-bottom: 8px;
        overflow-x: auto;
    }
    .ios-tab-btn {
        background: none;
        border: none;
        padding: 8px 16px;
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--ios-label-2);
        border-radius: 8px;
        transition: all 0.2s;
        white-space: nowrap;
    }
    .ios-tab-btn:hover {
        background: var(--ios-surface-2);
        color: var(--ios-label);
    }
    .ios-tab-btn.active {
        background: var(--accent);
        color: white;
        box-shadow: 0 4px 10px rgba(48,200,138,0.25);
    }

    /* iOS Badges */
    .ios-badge {
        font-size: 0.72rem;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 50px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .ios-badge.green   { background: rgba(52,199,89,0.12); color: #1A9A3A; }
    .ios-badge.blue    { background: rgba(0,122,255,0.12); color: #0055CC; }
    .ios-badge.orange  { background: rgba(255,149,0,0.15); color: #C07000; }
    .ios-badge.red     { background: rgba(255,59,48,0.12); color: #CC2200; }
    .ios-badge.gray    { background: rgba(60,60,67,0.08); color: var(--ios-label-2); }

    /* Custom Tables */
    .ios-table { width: 100%; border-collapse: collapse; }
    .ios-table td {
        vertical-align: middle;
        padding: 14px 16px;
        border-bottom: 1px solid var(--ios-separator);
        font-size: 0.9rem;
    }
    .ios-table tr:hover td { background: var(--ios-bg); }
    .table-ios-header th {
        background: var(--ios-surface-2) !important;
        color: var(--ios-label-2) !important;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 700;
        border-bottom: 1px solid var(--ios-separator);
        padding: 14px 16px;
    }

    /* Timeline Styles */
    .activity-timeline {
        position: relative;
        padding-left: 30px;
        margin-left: 10px;
        border-left: 2px solid var(--ios-separator);
    }
    .timeline-item {
        position: relative;
        margin-bottom: 24px;
    }
    .timeline-item:last-child {
        margin-bottom: 0;
    }
    .timeline-badge {
        position: absolute;
        left: -39px;
        top: 2px;
        width: 16px;
        height: 16px;
        border-radius: 50%;
        background: var(--ios-separator);
        border: 3px solid var(--ios-surface);
        box-shadow: 0 0 0 2px var(--ios-separator);
    }
    .timeline-badge.active {
        background: var(--accent);
        box-shadow: 0 0 0 2px var(--accent);
    }
    .timeline-badge.blue {
        background: #007AFF;
        box-shadow: 0 0 0 2px #007AFF;
    }
    .timeline-badge.orange {
        background: #FF9500;
        box-shadow: 0 0 0 2px #FF9500;
    }
    .timeline-badge.red {
        background: #FF3B30;
        box-shadow: 0 0 0 2px #FF3B30;
    }
    .timeline-time {
        font-size: 0.75rem;
        font-weight: 700;
        color: var(--ios-label-3);
        margin-bottom: 4px;
    }
    .timeline-title {
        font-size: 0.9rem;
        font-weight: 700;
        color: var(--ios-label);
    }
    .timeline-desc {
        font-size: 0.82rem;
        color: var(--ios-label-2);
        margin-top: 2px;
    }

    /* Metrics card style */
    .metrics-card {
        border-radius: 16px;
        padding: 16px;
        color: #fff;
        height: 100%;
        box-shadow: 0 6px 15px rgba(0,0,0,0.06);
    }

    /* Print Formatting */
    @media print {
        body { background: #fff !important; color: #000 !important; font-family: 'Times New Roman', serif; }
        .no-print { display: none !important; }
        .candent-topbar { display: none !important; }
        #sidebarMenu { display: none !important; }
        .d-flex { display: block !important; }
        #mainContent { margin-left: 0 !important; padding: 0 !important; width: 100% !important; max-width: 100% !important; }
        .detail-card { box-shadow: none !important; border: 1px solid #000 !important; border-radius: 0 !important; margin-bottom: 15px !important; }
        .tab-content > .tab-pane { display: block !important; opacity: 1 !important; visibility: visible !important; margin-top: 30px !important; }
        .ios-tabs { display: none !important; }
        .print-break-page { page-break-before: always; }
        .table-ios-header th {
            background: #f0f0f0 !important;
            color: #000 !important;
            border-bottom: 2px solid #000 !important;
            font-size: 0.8rem !important;
        }
        .ios-table td {
            border-bottom: 1px solid #ccc !important;
            font-size: 0.8rem !important;
            color: #000 !important;
        }
        .print-header {
            display: block !important;
            text-align: center;
            border-bottom: 3px double #000;
            padding-bottom: 10px;
            margin-bottom: 25px;
        }
        .print-signatures {
            display: flex !important;
            justify-content: space-between;
            margin-top: 60px;
        }
        .sig-box {
            text-align: center;
            width: 30%;
            border-top: 1px solid #000;
            padding-top: 5px;
            font-size: 0.85rem;
            font-weight: bold;
        }
    }
</style>

<!-- Printable Header -->
<div class="print-header d-none">
    <h1 style="font-size: 2.2rem; font-weight: 800; margin: 0; text-transform: uppercase;">Candent ERP</h1>
    <h3 style="font-size: 1.3rem; font-weight: 700; margin: 5px 0 0; color: #333;">Detailed Route Operational Report</h3>
    <h4 style="font-size: 1.1rem; font-weight: 600; margin: 5px 0 0; color: #666;">Route: <?php echo htmlspecialchars($asg['route_name']); ?> | Date: <?php echo date('M d, Y', strtotime($asg['assign_date'])); ?></h4>
    <p style="font-size: 0.8rem; margin: 5px 0 0; color: #888;">Generated on: <?php echo date('Y-m-d H:i:s'); ?></p>
</div>

<!-- Page Header (Screen) -->
<div class="page-header no-print">
    <div>
        <h1 class="page-title">Route Sales Report</h1>
        <div class="page-subtitle">Detailed financial audits, stock reconciliation, customer coverage, and activity logs.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="route_sales.php" class="quick-btn quick-btn-secondary">
            <i class="bi bi-arrow-left"></i> Back to History
        </a>
        <button onclick="window.print()" class="quick-btn quick-btn-primary">
            <i class="bi bi-printer-fill"></i> Print Audit Report
        </button>
    </div>
</div>

<!-- Primary Stats Grid -->
<div class="row g-3 mb-4 no-print">
    <!-- Row 1 -->
    <div class="col-xl-3 col-lg-4 col-sm-6 col-12">
        <div class="metrics-card" style="background: linear-gradient(145deg, #007AFF, #0055CC);">
            <div style="font-size: 0.72rem; font-weight: 700; opacity: 0.9; text-transform: uppercase;">Gross Sales</div>
            <div style="font-size: 1.4rem; font-weight: 800; margin-top: 4px;">Rs <?php echo number_format($total_gross, 2); ?></div>
            <div style="font-size: 0.7rem; opacity: 0.85; margin-top: 2px;"><?php echo count($orders); ?> Bills Issued</div>
        </div>
    </div>
    <div class="col-xl-3 col-lg-4 col-sm-6 col-12">
        <div class="metrics-card" style="background: linear-gradient(145deg, #34C759, #30D158);">
            <div style="font-size: 0.72rem; font-weight: 700; opacity: 0.9; text-transform: uppercase;">Cash &amp; Bank Sales</div>
            <div style="font-size: 1.4rem; font-weight: 800; margin-top: 4px;">Rs <?php echo number_format($total_cash + $total_bank, 2); ?></div>
            <div style="font-size: 0.7rem; opacity: 0.85; margin-top: 2px;">C: <?php echo number_format($total_cash, 2); ?> | B: <?php echo number_format($total_bank, 2); ?></div>
        </div>
    </div>
    <div class="col-xl-3 col-lg-4 col-sm-6 col-12">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF9500, #FF8000);">
            <div style="font-size: 0.72rem; font-weight: 700; opacity: 0.9; text-transform: uppercase;">New Cheque Sales</div>
            <div style="font-size: 1.4rem; font-weight: 800; margin-top: 4px;">Rs <?php echo number_format($total_cheque, 2); ?></div>
            <div style="font-size: 0.7rem; opacity: 0.85; margin-top: 2px;">Received for new invoices</div>
        </div>
    </div>
    <div class="col-xl-3 col-lg-4 col-sm-6 col-12">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF3B30, #CC1500);">
            <div style="font-size: 0.72rem; font-weight: 700; opacity: 0.9; text-transform: uppercase;">New Credit Issued</div>
            <div style="font-size: 1.4rem; font-weight: 800; margin-top: 4px;">Rs <?php echo number_format($total_credit, 2); ?></div>
            <div style="font-size: 0.7rem; opacity: 0.85; margin-top: 2px;">Added to customer balances</div>
        </div>
    </div>
    
    <!-- Row 2 -->
    <div class="col-xl-3 col-lg-4 col-sm-6 col-12">
        <div class="metrics-card" style="background: linear-gradient(145deg, #5856D6, #4A47C6);">
            <div style="font-size: 0.72rem; font-weight: 700; opacity: 0.9; text-transform: uppercase;">Credit Collections</div>
            <div style="font-size: 1.4rem; font-weight: 800; margin-top: 4px;">Rs <?php echo number_format($coll_total, 2); ?></div>
            <div style="font-size: 0.7rem; opacity: 0.85; margin-top: 2px;">C: <?php echo number_format($coll_cash, 0); ?> | Chq: <?php echo number_format($coll_cheque, 0); ?> | B: <?php echo number_format($coll_bank, 0); ?></div>
        </div>
    </div>
    <div class="col-xl-3 col-lg-4 col-sm-6 col-12">
        <div class="metrics-card" style="background: linear-gradient(145deg, #AF52DE, #8B2BAA);">
            <div style="font-size: 0.72rem; font-weight: 700; opacity: 0.9; text-transform: uppercase;">Route Expenses</div>
            <div style="font-size: 1.4rem; font-weight: 800; margin-top: 4px;">Rs <?php echo number_format($total_exp_amt, 2); ?></div>
            <div style="font-size: 0.7rem; opacity: 0.85; margin-top: 2px;"><?php echo count($expenses); ?> claims filed</div>
        </div>
    </div>
    <div class="col-xl-3 col-lg-4 col-sm-6 col-12">
        <div class="metrics-card" style="background: linear-gradient(145deg, #30B0C7, #1A95AC);">
            <div style="font-size: 0.72rem; font-weight: 700; opacity: 0.9; text-transform: uppercase;">Expected Cash Handover</div>
            <div style="font-size: 1.4rem; font-weight: 800; margin-top: 4px;">Rs <?php echo number_format($expected_cash_cal, 2); ?></div>
            <div style="font-size: 0.7rem; opacity: 0.85; margin-top: 2px;">New Cash (<?php echo number_format($total_cash, 0); ?>) + Coll (<?php echo number_format($coll_cash, 0); ?>) - Exp</div>
        </div>
    </div>
    <div class="col-xl-3 col-lg-4 col-sm-6 col-12">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF2D55, #D81B43);">
            <div style="font-size: 0.72rem; font-weight: 700; opacity: 0.9; text-transform: uppercase;">Net Margin Value</div>
            <div style="font-size: 1.4rem; font-weight: 800; margin-top: 4px;">Rs <?php echo number_format($total_gross - $total_exp_amt, 2); ?></div>
            <div style="font-size: 0.7rem; opacity: 0.85; margin-top: 2px;">Gross Sales minus Expenses</div>
        </div>
    </div>
</div>

<!-- Primary Metadata and Operational Details -->
<div class="row">
    <!-- Operational info card -->
    <div class="col-lg-4 col-12">
        <div class="detail-card">
            <div class="detail-card-title">
                <i class="bi bi-info-circle text-primary"></i> Route &amp; Vehicle Metadata
            </div>
            <div class="info-row">
                <span class="info-label">Route Name</span>
                <span class="info-value"><?php echo htmlspecialchars($asg['route_name']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Date Assigned</span>
                <span class="info-value"><?php echo date('M d, Y', strtotime($asg['assign_date'])); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Sales Rep</span>
                <span class="info-value"><?php echo htmlspecialchars($asg['rep_name']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Assigned Driver</span>
                <span class="info-value"><?php echo $asg['driver_name'] ? htmlspecialchars($asg['driver_name']) : '<span class="text-muted fw-normal">Self Driven</span>'; ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Mileage Start</span>
                <span class="info-value"><?php echo number_format($asg['start_meter'] ?? 0, 1); ?> km</span>
            </div>
            <div class="info-row">
                <span class="info-label">Mileage End</span>
                <span class="info-value"><?php echo $asg['end_meter'] ? number_format($asg['end_meter'], 1) . ' km' : '<span class="text-warning fw-bold">Active</span>'; ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Distance Travelled</span>
                <span class="info-value text-primary">
                    <?php 
                    if ($asg['end_meter']) {
                        echo number_format((float)$asg['end_meter'] - (float)$asg['start_meter'], 1) . ' km';
                    } else {
                        echo 'N/A';
                    }
                    ?>
                </span>
            </div>
            <div class="info-row">
                <span class="info-label">Route Status</span>
                <span class="info-value">
                    <?php if ($asg['status'] == 'assigned'): ?>
                        <span class="ios-badge blue">Assigned</span>
                    <?php elseif ($asg['status'] == 'completed'): ?>
                        <span class="ios-badge orange">Pending Unload</span>
                    <?php elseif ($asg['status'] == 'unloaded'): ?>
                        <span class="ios-badge green">Closed &amp; Verified</span>
                    <?php else: ?>
                        <span class="ios-badge gray"><?php echo ucfirst($asg['status']); ?></span>
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <!-- Cash Denomination Audit Card (If closed and has actual cash records) -->
        <?php if ($asg['status'] == 'unloaded' && ($asg['actual_cash'] > 0 || $asg['expected_cash'] > 0)): ?>
        <div class="detail-card">
            <div class="detail-card-title">
                <i class="bi bi-safe2 text-success"></i> Cash Audit &amp; Denominations
            </div>
            <div class="info-row">
                <span class="info-label">System Expected Cash</span>
                <span class="info-value">Rs <?php echo number_format($asg['expected_cash'] ?? 0, 2); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Handover Actual Cash</span>
                <span class="info-value text-success">Rs <?php echo number_format($asg['actual_cash'] ?? 0, 2); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Cash Variance</span>
                <?php 
                $variance = (float)($asg['actual_cash'] ?? 0) - (float)($asg['expected_cash'] ?? 0);
                if ($variance == 0) {
                    echo '<span class="info-value text-success">Perfect Match</span>';
                } elseif ($variance > 0) {
                    echo '<span class="info-value text-success">+ Rs ' . number_format($variance, 2) . ' (Surplus)</span>';
                } else {
                    echo '<span class="info-value text-danger">- Rs ' . number_format(abs($variance), 2) . ' (Shortage)</span>';
                }
                ?>
            </div>
            
            <div class="mt-3">
                <label class="ios-label-sm pb-2 border-bottom">Audit Count Breakdown</label>
                <div class="row g-2 text-center mt-1" style="font-size: 0.8rem;">
                    <div class="col-4 mb-2"><div class="fw-bold">Rs 5000</div><div class="text-muted"><?php echo $asg['cash_5000'] ?? 0; ?> notes</div></div>
                    <div class="col-4 mb-2"><div class="fw-bold">Rs 2000</div><div class="text-muted"><?php echo $asg['cash_2000'] ?? 0; ?> notes</div></div>
                    <div class="col-4 mb-2"><div class="fw-bold">Rs 1000</div><div class="text-muted"><?php echo $asg['cash_1000'] ?? 0; ?> notes</div></div>
                    <div class="col-4 mb-2"><div class="fw-bold">Rs 500</div><div class="text-muted"><?php echo $asg['cash_500'] ?? 0; ?> notes</div></div>
                    <div class="col-4 mb-2"><div class="fw-bold">Rs 100</div><div class="text-muted"><?php echo $asg['cash_100'] ?? 0; ?> notes</div></div>
                    <div class="col-4 mb-2"><div class="fw-bold">Rs 50</div><div class="text-muted"><?php echo $asg['cash_50'] ?? 0; ?> notes</div></div>
                    <div class="col-4 mb-2"><div class="fw-bold">Rs 20</div><div class="text-muted"><?php echo $asg['cash_20'] ?? 0; ?> notes</div></div>
                    <div class="col-8 mb-2"><div class="fw-bold">Coins &amp; Loose</div><div class="text-muted">Rs <?php echo number_format($asg['cash_coins'] ?? 0, 2); ?></div></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Main analytical tabs panel -->
    <div class="col-lg-8 col-12">
        <div class="ios-tabs no-print">
            <button class="ios-tab-btn active" onclick="switchTab(event, 'tab-invoices')"><i class="bi bi-receipt"></i> Bills Issued</button>
            <button class="ios-tab-btn" onclick="switchTab(event, 'tab-collections')"><i class="bi bi-cash-stack"></i> Credit Collections</button>
            <button class="ios-tab-btn" onclick="switchTab(event, 'tab-stock')"><i class="bi bi-box-seam"></i> Stock Reconcile</button>
            <button class="ios-tab-btn" onclick="switchTab(event, 'tab-expenses')"><i class="bi bi-wallet2"></i> Expenses</button>
            <button class="ios-tab-btn" onclick="event.preventDefault(); switchTab(event, 'tab-visits');"><i class="bi bi-shop"></i> Shop Visits</button>
            <button class="ios-tab-btn" onclick="event.preventDefault(); switchTab(event, 'tab-gps');"><i class="bi bi-geo-alt"></i> GPS Path Logs</button>
        </div>

        <div class="tab-content">
            <!-- 1. TAB: Invoices / Bills Generated -->
            <div id="tab-invoices" class="tab-pane active show">
                <div class="dash-card mb-4 overflow-hidden">
                    <div class="dash-card-header no-print" style="background: var(--ios-surface); padding: 14px 18px;">
                        <span class="card-title"><i class="bi bi-receipt-cutoff text-primary"></i> List of Generated Invoices</span>
                    </div>
                    <div class="table-responsive">
                        <table class="ios-table text-center">
                            <thead>
                                <tr class="table-ios-header">
                                    <th>Bill No</th>
                                    <th>Time</th>
                                    <th>Customer / Outlet</th>
                                    <th>Payment Type</th>
                                    <th class="text-end">Paid (Rs)</th>
                                    <th class="text-end">Balance (Rs)</th>
                                    <th class="text-end">Bill Total (Rs)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $o): 
                                    $o_paid_on_route = (float)$o['paid_cash'] + (float)$o['paid_bank'] + (float)$o['paid_cheque'];
                                    $outstanding_on_route = (float)$o['total_amount'] - $o_paid_on_route;
                                ?>
                                <tr>
                                    <td>
                                        <a href="view_invoice.php?id=<?php echo $o['id']; ?>" target="_blank" class="no-print" style="font-weight: 700; color: var(--accent-dark); text-decoration: none;">
                                            #<?php echo str_pad($o['id'], 6, '0', STR_PAD_LEFT); ?>
                                        </a>
                                        <span class="d-none d-print-inline" style="font-weight: bold;">
                                            #<?php echo str_pad($o['id'], 6, '0', STR_PAD_LEFT); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-size: 0.8rem; color: var(--ios-label-2); font-weight: 600;">
                                            <?php echo date('H:i A', strtotime($o['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: var(--ios-label); text-align: left;">
                                            <?php echo htmlspecialchars($o['customer_name'] ?? 'Walk-in Customer'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="ios-badge <?php 
                                            if($o['payment_method'] == 'Cash') echo 'green';
                                            elseif($o['payment_method'] == 'Cheque') echo 'orange';
                                            elseif($o['payment_method'] == 'Credit') echo 'red';
                                            else echo 'blue';
                                        ?>">
                                            <?php echo $o['payment_method']; ?>
                                        </span>
                                    </td>
                                    <td class="text-end" style="font-weight: 600; color: #1A9A3A;">
                                        Rs <?php echo number_format($o_paid_on_route, 2); ?>
                                        <div style="font-size: 0.65rem; color: var(--ios-label-3);" class="no-print">
                                            C:<?php echo (int)$o['paid_cash']; ?>|Ch:<?php echo (int)$o['paid_cheque']; ?>
                                        </div>
                                    </td>
                                    <td class="text-end" style="font-weight: 600; color: <?php echo $outstanding_on_route > 0 ? '#CC2200' : 'var(--ios-label-3)'; ?>;">
                                        <?php echo $outstanding_on_route > 0 ? 'Rs ' . number_format($outstanding_on_route, 2) : '-'; ?>
                                    </td>
                                    <td class="text-end" style="font-weight: 800; color: var(--ios-label);">
                                        Rs <?php echo number_format($o['total_amount'], 2); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <?php if (empty($orders)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No sales orders generated during this route.</td>
                                </tr>
                                <?php else: ?>
                                <tr style="background: var(--ios-surface-2); font-weight: 800;">
                                    <td colspan="4" style="text-align: right;">Total Sales Audited:</td>
                                    <td class="text-end" style="color: #1A9A3A;">Rs <?php echo number_format($total_paid, 2); ?></td>
                                    <td class="text-end" style="color: #CC2200;">Rs <?php echo number_format($total_credit, 2); ?></td>
                                    <td class="text-end" style="color: #0055CC; font-size: 0.95rem;">Rs <?php echo number_format($total_gross, 2); ?></td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB: Credit Collections -->
            <div id="tab-collections" class="tab-pane d-none print-break-page">
                <div class="dash-card mb-4 overflow-hidden">
                    <div class="dash-card-header no-print" style="background: var(--ios-surface); padding: 14px 18px;">
                        <span class="card-title"><i class="bi bi-cash-stack text-success"></i> Customer Credit Collections (Collected Outstanding)</span>
                    </div>
                    <div class="table-responsive">
                        <table class="ios-table text-center">
                            <thead>
                                <tr class="table-ios-header">
                                    <th style="text-align: left;">Customer / Outlet</th>
                                    <th>Collection Time</th>
                                    <th>Payment Method</th>
                                    <th>Ref / Notes</th>
                                    <th class="text-end">Amount Collected (Rs)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($credit_payments as $cp): ?>
                                <tr>
                                    <td style="text-align: left;">
                                        <div style="font-weight: 700; color: var(--ios-label);">
                                            <?php echo htmlspecialchars($cp['customer_name'] ?? 'Unknown Customer'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-size: 0.8rem; color: var(--ios-label-2); font-weight: 600;">
                                            <?php echo date('H:i A', strtotime($cp['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="ios-badge <?php 
                                            if($cp['method'] == 'Cash') echo 'green';
                                            elseif($cp['method'] == 'Cheque') echo 'orange';
                                            elseif($cp['method'] == 'Bank Transfer') echo 'blue';
                                            else echo 'gray';
                                        ?>">
                                            <?php echo htmlspecialchars($cp['method']); ?>
                                        </span>
                                    </td>
                                    <td style="color: var(--ios-label-2); font-weight: 500;">
                                        <?php 
                                            $details = [];
                                            if($cp['reference']) $details[] = 'Ref: '.$cp['reference'];
                                            if($cp['notes']) $details[] = $cp['notes'];
                                            echo htmlspecialchars(implode(' | ', $details) ?: '-');
                                        ?>
                                    </td>
                                    <td class="text-end" style="font-weight: 800; color: #1A9A3A;">
                                        Rs <?php echo number_format($cp['amount'], 2); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <?php if (empty($credit_payments)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">No credit outstanding collections made during this route.</td>
                                </tr>
                                <?php else: ?>
                                <tr style="background: var(--ios-surface-2); font-weight: 800;">
                                    <td colspan="4" style="text-align: right;">Total Collections Audited:</td>
                                    <td class="text-end" style="color: #1A9A3A;">
                                        Rs <?php echo number_format($coll_total, 2); ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 2. TAB: Stock Loading & Reconciliations -->
            <div id="tab-stock" class="tab-pane d-none print-break-page">
                <div class="dash-card mb-4 overflow-hidden">
                    <div class="dash-card-header no-print" style="background: var(--ios-surface); padding: 14px 18px;">
                        <span class="card-title"><i class="bi bi-box-seam text-primary"></i> Vehicle Stock Loading Sheet &amp; Audit</span>
                    </div>
                    <div class="table-responsive">
                        <table class="ios-table text-center">
                            <thead>
                                <tr class="table-ios-header">
                                    <th style="text-align: left;">Product (SKU)</th>
                                    <th>Loaded Qty</th>
                                    <th>Returned Qty</th>
                                    <th class="text-info">Sold Qty</th>
                                    <th class="text-danger">Shortage Qty</th>
                                    <th class="text-end">Selling Price</th>
                                    <th class="text-end">Shortage Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $grand_short_val = 0;
                                foreach ($loads as $l): 
                                    $short_q = (int)($l['short_qty'] ?? 0);
                                    $returned_q = (int)($l['returned_qty'] ?? 0);
                                    $loaded_q = (int)$l['loaded_qty'];
                                    
                                    // Sold = Loaded - Returned - Short
                                    $sold_q = $loaded_q - $returned_q - $short_q;
                                    
                                    $unit_price = (float)$l['selling_price'];
                                    $short_val = $short_q * $unit_price;
                                    $grand_short_val += $short_val;
                                ?>
                                <tr>
                                    <td style="text-align: left;">
                                        <div style="font-weight: 700; color: var(--ios-label);"><?php echo htmlspecialchars($l['product_name']); ?></div>
                                        <div style="font-size: 0.72rem; color: var(--ios-label-3); font-weight: 600;">SKU: <?php echo htmlspecialchars($l['sku'] ?? 'N/A'); ?></div>
                                    </td>
                                    <td style="font-weight: 600;"><?php echo $loaded_q; ?></td>
                                    <td style="font-weight: 600; color: var(--ios-label-2);"><?php echo $returned_q; ?></td>
                                    <td style="font-weight: 700; color: #0055CC;"><?php echo $sold_q; ?></td>
                                    <td style="font-weight: 700; color: <?php echo $short_q > 0 ? '#CC2200' : 'var(--ios-label-3)'; ?>;">
                                        <?php echo $short_q > 0 ? $short_q : '-'; ?>
                                    </td>
                                    <td class="text-end">Rs <?php echo number_format($unit_price, 2); ?></td>
                                    <td class="text-end" style="font-weight: 800; color: <?php echo $short_val > 0 ? '#CC2200' : 'var(--ios-label-3)'; ?>;">
                                        <?php echo $short_val > 0 ? 'Rs ' . number_format($short_val, 2) : '-'; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <?php if (empty($loads)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No stock loading data recorded for this route.</td>
                                </tr>
                                <?php else: ?>
                                <tr style="background: var(--ios-surface-2); font-weight: 800;">
                                    <td colspan="6" style="text-align: right; color: var(--ios-label);">Total Shortage Liability Cost:</td>
                                    <td class="text-end" style="color: #CC2200; font-size: 0.95rem;">Rs <?php echo number_format($grand_short_val, 2); ?></td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 3. TAB: Expenses -->
            <div id="tab-expenses" class="tab-pane d-none print-break-page">
                <div class="dash-card mb-4 overflow-hidden">
                    <div class="dash-card-header no-print" style="background: var(--ios-surface); padding: 14px 18px;">
                        <span class="card-title"><i class="bi bi-wallet2 text-primary"></i> Recorded Route Expenses &amp; Allowances</span>
                    </div>
                    <div class="table-responsive">
                        <table class="ios-table">
                            <thead>
                                <tr class="table-ios-header">
                                    <th style="width: 25%;">Expense Type</th>
                                    <th style="width: 55%;">Description</th>
                                    <th style="width: 20%; text-align: right;">Amount (Rs)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expenses as $e): ?>
                                <tr>
                                    <td style="font-weight: 700; color: var(--ios-label);"><?php echo htmlspecialchars($e['type']); ?></td>
                                    <td style="color: var(--ios-label-2); font-weight: 500;"><?php echo htmlspecialchars($e['description'] ?? 'No description provided'); ?></td>
                                    <td style="text-align: right; font-weight: 700; color: #CC2200;">Rs <?php echo number_format($e['amount'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <?php if (empty($expenses)): ?>
                                <tr>
                                    <td colspan="3" class="text-center py-4 text-muted">No route expenses recorded.</td>
                                </tr>
                                <?php else: ?>
                                <tr style="background: var(--ios-surface-2); font-weight: 800;">
                                    <td colspan="2" style="text-align: right;">Total Route Expenses:</td>
                                    <td style="text-align: right; color: #CC2200; font-size: 0.95rem;">Rs <?php echo number_format($total_exp_amt, 2); ?></td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 4. TAB: Shop Visits / Customer Coverage -->
            <div id="tab-visits" class="tab-pane d-none print-break-page">
                <div class="dash-card mb-4 overflow-hidden">
                    <div class="dash-card-header no-print" style="background: var(--ios-surface); padding: 14px 18px;">
                        <span class="card-title"><i class="bi bi-shop text-primary"></i> Unproductive Shop Visits (No Sales)</span>
                    </div>
                    <div class="table-responsive">
                        <table class="ios-table">
                            <thead>
                                <tr class="table-ios-header">
                                    <th>Outlet / Customer</th>
                                    <th>Visit Time</th>
                                    <th>Reason for No Sale</th>
                                    <th class="no-print" style="text-align: center;">GPS Location</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($unproductive_visits as $uv): ?>
                                <tr>
                                    <td style="font-weight: 700; color: var(--ios-label);"><?php echo htmlspecialchars($uv['customer_name']); ?></td>
                                    <td>
                                        <div style="font-size: 0.8rem; color: var(--ios-label-2); font-weight: 600;">
                                            <?php echo date('H:i A', strtotime($uv['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td style="font-weight: 600; color: #CC2200;"><?php echo htmlspecialchars($uv['reason']); ?></td>
                                    <td class="no-print" style="text-align: center;">
                                        <?php if ($uv['latitude'] && $uv['longitude']): ?>
                                            <a href="https://www.google.com/maps/search/?api=1&query=<?php echo $uv['latitude']; ?>,<?php echo $uv['longitude']; ?>" target="_blank" class="quick-btn quick-btn-secondary" style="padding: 4px 8px; font-size: 0.75rem;">
                                                <i class="bi bi-geo-alt-fill text-danger"></i> View Map
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <?php if (empty($unproductive_visits)): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-muted">No unproductive visits logged on this day.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 5. TAB: GPS Path Logs -->
            <div id="tab-gps" class="tab-pane d-none print-break-page">
                <div class="dash-card mb-4 overflow-hidden">
                    <div class="dash-card-header no-print" style="background: var(--ios-surface); padding: 14px 18px;">
                        <span class="card-title"><i class="bi bi-geo text-primary"></i> Rep GPS Activity Timeline</span>
                    </div>
                    <div class="p-4">
                        <div class="activity-timeline">
                            <?php foreach ($gps_logs as $log): 
                                $badgeClass = 'blue';
                                if($log['activity_type'] == 'start_day') $badgeClass = 'active';
                                elseif($log['activity_type'] == 'end_day') $badgeClass = 'red';
                                elseif($log['activity_type'] == 'invoice_created') $badgeClass = 'orange';
                            ?>
                            <div class="timeline-item">
                                <div class="timeline-badge <?php echo $badgeClass; ?>"></div>
                                <div class="timeline-time"><?php echo date('h:i:s A', strtotime($log['timestamp'])); ?></div>
                                <div class="timeline-title">
                                    <?php 
                                        if($log['activity_type'] == 'start_day') echo '🏁 Started Working Day';
                                        elseif($log['activity_type'] == 'end_day') echo '🛑 Ended Working Day';
                                        elseif($log['activity_type'] == 'invoice_created') echo '📝 Sales Invoice Generated';
                                        elseif($log['activity_type'] == 'customer_created') echo '➕ New Customer Created';
                                        else echo '📡 GPS Location Ping';
                                    ?>
                                </div>
                                <div class="timeline-desc d-flex align-items-center justify-content-between">
                                    <span>Coordinates: <?php echo $log['latitude']; ?>, <?php echo $log['longitude']; ?></span>
                                    <a href="https://www.google.com/maps/search/?api=1&query=<?php echo $log['latitude']; ?>, <?php echo $log['longitude']; ?>" target="_blank" class="no-print quick-btn quick-btn-secondary ms-2" style="padding: 2px 6px; font-size: 0.72rem; min-height: auto;">
                                        <i class="bi bi-box-arrow-up-right"></i> Trace
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if (empty($gps_logs)): ?>
                            <div class="text-center text-muted py-4">No GPS logs captured for this route.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Print Verification / Signature Block -->
<div class="print-signatures d-none">
    <div class="sig-box">Sales Representative (Rep)</div>
    <div class="sig-box">Checked By (Stock Clerk)</div>
    <div class="sig-box">Verified &amp; Approved By</div>
</div>

<script>
function switchTab(event, tabId) {
    // Hide all tabs
    document.querySelectorAll('.tab-pane').forEach(el => {
        el.classList.add('d-none');
        el.classList.remove('show', 'active');
    });
    
    // Remove active state from all tab buttons
    document.querySelectorAll('.ios-tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show active tab
    const activeTab = document.getElementById(tabId);
    activeTab.classList.remove('d-none');
    activeTab.classList.add('show', 'active');
    
    // Set active tab button
    event.currentTarget.classList.add('active');
}
</script>

<?php include '../includes/footer.php'; ?>
