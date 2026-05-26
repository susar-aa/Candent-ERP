<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); // Restricted to management

// --- AJAX ENDPOINT FOR ROUTE REPORT ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $assignment_id = (int)$_POST['assignment_id'];
    
    if ($_POST['ajax_action'] == 'get_route_report') {
        try {
            $asgStmt = $pdo->prepare("SELECT rr.*, r.name as route_name, u.name as rep_name FROM rep_routes rr JOIN routes r ON rr.route_id = r.id JOIN users u ON rr.rep_id = u.id WHERE rr.id = ?");
            $asgStmt->execute([$assignment_id]);
            $asg = $asgStmt->fetch();

            $ordersStmt = $pdo->prepare("SELECT id, total_amount, payment_method, payment_status, created_at, paid_cash, paid_bank, paid_cheque FROM orders WHERE assignment_id = ?");
            $ordersStmt->execute([$assignment_id]);
            $orders = $ordersStmt->fetchAll();

            $shortagesStmt = $pdo->prepare("SELECT p.name, rl.short_qty FROM route_loads rl JOIN products p ON rl.product_id = p.id WHERE rl.assignment_id = ? AND rl.short_qty > 0");
            $shortagesStmt->execute([$assignment_id]);
            $shortages = $shortagesStmt->fetchAll();
            
            $expStmt = $pdo->prepare("SELECT * FROM route_expenses WHERE assignment_id = ?");
            $expStmt->execute([$assignment_id]);
            $expenses = $expStmt->fetchAll();

            echo json_encode(['success' => true, 'assignment' => $asg, 'orders' => $orders, 'shortages' => $shortages, 'expenses' => $expenses]);
        } catch(Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}
// -------------------------------------

// --- FILTERING & PAGINATION ---
$limit = 15;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$rep_filter = isset($_GET['rep_id']) ? $_GET['rep_id'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Only show routes that have ended the day
$whereClause = "WHERE rr.status IN ('completed', 'unloaded')";
$params = [];

if ($rep_filter !== '') {
    $whereClause .= " AND rr.rep_id = ?";
    $params[] = $rep_filter;
}
if ($date_from !== '') {
    $whereClause .= " AND rr.assign_date >= ?";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $whereClause .= " AND rr.assign_date <= ?";
    $params[] = $date_to;
}

// Get Total Rows
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM rep_routes rr $whereClause");
$totalStmt->execute($params);
$totalRows = $totalStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Fetch Assignments with Financial Summaries
$query = "
    SELECT rr.*, u.name as rep_name, r.name as route_name,
           (SELECT COUNT(id) FROM orders WHERE assignment_id = rr.id) as total_bills,
           (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE assignment_id = rr.id) as gross_sales,
           (SELECT COALESCE(SUM(amount), 0) FROM route_expenses WHERE assignment_id = rr.id) as total_expenses
    FROM rep_routes rr 
    JOIN users u ON rr.rep_id = u.id 
    JOIN routes r ON rr.route_id = r.id 
    $whereClause
    ORDER BY rr.assign_date DESC, u.name ASC
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$assignments = $stmt->fetchAll();

// Calculate display totals for the current view
$view_gross_sales = 0;
$view_total_expenses = 0;
foreach($assignments as $a) {
    $view_gross_sales += $a['gross_sales'];
    $view_total_expenses += $a['total_expenses'];
}

// Fetch Reps for filter
$reps = $pdo->query("SELECT id, name FROM users WHERE role = 'rep' ORDER BY name ASC")->fetchAll();

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

    /* Metrics Card */
    .metrics-card {
        border-radius: 16px;
        padding: 20px 24px;
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
        <h1 class="page-title">Route Sales History</h1>
        <div class="page-subtitle">Review completed route assignments, expenses, and financial summaries.</div>
    </div>
</div>

<!-- Top Metrics -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #34C759, #30D158);">
            <div class="metrics-icon"><i class="bi bi-graph-up-arrow"></i></div>
            <div>
                <div style="font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.9;">Filtered Gross Sales</div>
                <div style="font-size: 1.8rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($view_gross_sales, 2); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF3B30, #CC1500);">
            <div class="metrics-icon"><i class="bi bi-wallet2"></i></div>
            <div>
                <div style="font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.9;">Filtered Route Expenses</div>
                <div style="font-size: 1.8rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($view_total_expenses, 2); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="dash-card mb-4" style="background: var(--ios-surface-2);">
    <div class="p-3">
        <form method="GET" action="" id="filterForm">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="ios-label-sm">Filter by Sales Rep</label>
                    <select name="rep_id" class="form-select" onchange="document.getElementById('filterForm').submit();">
                        <option value="">All Sales Reps</option>
                        <?php foreach($reps as $r): ?>
                            <option value="<?php echo $r['id']; ?>" <?php echo $rep_filter == $r['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($r['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="ios-label-sm">From Date</label>
                    <input type="date" name="date_from" class="ios-input" value="<?php echo htmlspecialchars($date_from); ?>" onchange="document.getElementById('filterForm').submit();">
                </div>
                <div class="col-md-3">
                    <label class="ios-label-sm">To Date</label>
                    <input type="date" name="date_to" class="ios-input" value="<?php echo htmlspecialchars($date_to); ?>" onchange="document.getElementById('filterForm').submit();">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="quick-btn quick-btn-secondary w-100" style="padding: 10px; min-height: 42px;">
                        <i class="bi bi-funnel-fill"></i> Filter
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Route Sales Table -->
<div class="dash-card mb-4 overflow-hidden">
    <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
        <span class="card-title">
            <span class="card-title-icon" style="background: rgba(0,122,255,0.1); color: #0055CC;">
                <i class="bi bi-card-list"></i>
            </span>
            Completed Route Assignments
        </span>
    </div>
    <div class="table-responsive">
        <table class="ios-table">
            <thead>
                <tr class="table-ios-header">
                    <th style="width: 20%;">Date & Rep</th>
                    <th style="width: 25%;">Route Info</th>
                    <th class="text-center" style="width: 15%;">Bills Generated</th>
                    <th class="text-end" style="width: 15%;">Financials</th>
                    <th style="width: 15%; text-align: center;">Status</th>
                    <th class="text-end" style="width: 10%;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($assignments as $a): ?>
                <tr>
                    <td>
                        <div style="font-weight: 700; font-size: 0.95rem; color: <?php echo $a['assign_date'] == date('Y-m-d') ? '#0055CC' : 'var(--ios-label)'; ?>;">
                            <?php echo date('M d, Y', strtotime($a['assign_date'])); ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--ios-label-2); margin-top: 2px;">
                            <i class="bi bi-person-badge"></i> <?php echo htmlspecialchars($a['rep_name']); ?>
                        </div>
                    </td>
                    <td>
                        <div style="font-weight: 600; font-size: 0.9rem; color: var(--ios-label);">
                            <?php echo htmlspecialchars($a['route_name']); ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--ios-label-3); margin-top: 2px;">
                            Distance: <?php echo number_format($a['end_meter'] - $a['start_meter'], 1); ?> km
                        </div>
                    </td>
                    <td class="text-center">
                        <span class="ios-badge blue" style="font-size: 0.8rem; padding: 4px 12px;">
                            <?php echo $a['total_bills']; ?> Bills
                        </span>
                    </td>
                    <td class="text-end">
                        <div style="font-weight: 800; font-size: 0.95rem; color: #1A9A3A;">
                            Rs <?php echo number_format($a['gross_sales'], 2); ?>
                        </div>
                        <div style="font-weight: 700; font-size: 0.75rem; color: #CC2200; margin-top: 2px;">
                            <?php echo $a['total_expenses'] > 0 ? '- Rs ' . number_format($a['total_expenses'], 2) : 'No Exp.'; ?>
                        </div>
                    </td>
                    <td class="text-center">
                        <?php if($a['status'] == 'completed'): ?>
                            <span class="ios-badge orange d-block"><i class="bi bi-hourglass-split"></i> Pending Unload</span>
                        <?php elseif($a['status'] == 'unloaded'): ?>
                            <span class="ios-badge green d-block"><i class="bi bi-check2-all"></i> Verified & Closed</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <div class="d-flex gap-1 justify-content-end">
                            <button class="quick-btn quick-btn-secondary" style="padding: 6px 12px;" onclick="openReportModal(<?php echo $a['id']; ?>)" title="Quick Route Summary">
                                Quick <i class="bi bi-file-earmark-bar-graph"></i>
                            </button>
                            <a href="route_detailed_report.php?id=<?php echo $a['id']; ?>" class="quick-btn quick-btn-primary" style="padding: 6px 12px;" title="View Complete Detail Report">
                                Full <i class="bi bi-box-arrow-up-right"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($assignments)): ?>
                <tr>
                    <td colspan="6">
                        <div class="empty-state">
                            <i class="bi bi-folder2-open" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2" style="font-weight: 500;">No completed route sales found.</p>
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
        <a class="page-link" href="?page=<?php echo $i; ?>&rep_id=<?php echo $rep_filter; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"><?php echo $i; ?></a>
    </li>
    <?php endfor; ?>
</ul>
<?php endif; ?>

<!-- Route Sales Report Modal (Ported from Dispatch) -->
<div class="modal fade" id="routeReportModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px; border: none; box-shadow: 0 20px 40px rgba(0,0,0,0.2); overflow: hidden;">
            <div class="modal-header" style="background: var(--ios-surface); border-bottom: 1px solid var(--ios-separator); padding: 18px 24px;">
                <h5 class="modal-title fw-bold" style="font-size: 1.1rem;"><i class="bi bi-file-earmark-bar-graph text-primary me-2"></i>Route Detailed Report</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="background: var(--ios-bg); padding: 24px;">
                
                <div class="row g-3 mb-4">
                    <div class="col-sm-4">
                        <div class="dash-card h-100" style="padding: 16px 20px; border: 1px solid var(--ios-separator); box-shadow: 0 2px 10px rgba(0,0,0,0.02);">
                            <div style="font-size: 0.7rem; font-weight: 700; color: var(--ios-label-3); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Route Details</div>
                            <h5 class="fw-bold mb-2" style="color: var(--ios-label); font-size: 1.1rem;" id="report_route_name">Route Name</h5>
                            <div style="font-size: 0.85rem; font-weight: 600; color: var(--ios-label);"><i class="bi bi-person me-1 text-muted"></i>Rep: <span id="report_rep_name"></span></div>
                            <div style="font-size: 0.8rem; color: var(--ios-label-2); margin-top: 4px;"><i class="bi bi-calendar me-1 text-muted"></i>Date: <span id="report_date"></span></div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="dash-card h-100" style="padding: 16px 20px; border: 1px solid var(--ios-separator); box-shadow: 0 2px 10px rgba(0,0,0,0.02); display: flex; flex-direction: column; justify-content: center;">
                            <div style="font-size: 0.7rem; font-weight: 700; color: var(--ios-label-3); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px;">Vehicle Mileage</div>
                            <div class="d-flex justify-content-between mb-1">
                                <span style="font-size: 0.85rem; font-weight: 500; color: var(--ios-label-2);">Start Meter:</span>
                                <span style="font-size: 0.85rem; font-weight: 700; color: var(--ios-label);" id="report_start_meter">0.0</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2 pb-2" style="border-bottom: 1px dashed var(--ios-separator);">
                                <span style="font-size: 0.85rem; font-weight: 500; color: var(--ios-label-2);">End Meter:</span>
                                <span style="font-size: 0.85rem; font-weight: 700; color: var(--ios-label);" id="report_end_meter">0.0</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span style="font-size: 0.85rem; font-weight: 700; color: var(--ios-label);">Total Distance:</span>
                                <span style="font-size: 0.95rem; font-weight: 800; color: #0055CC;" id="report_distance">0.0 km</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="dash-card h-100 d-flex flex-column justify-content-center align-items-center text-center" style="background: linear-gradient(145deg, #34C759, #30D158); color: white; border: none; padding: 16px 20px;">
                            <div style="font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Gross Sales</div>
                            <h3 style="font-size: 1.8rem; font-weight: 800; letter-spacing: -0.5px; margin-bottom: 8px;" id="report_total_sales">Rs: 0.00</h3>
                            <span style="background: rgba(0,0,0,0.15); font-size: 0.75rem; font-weight: 700; padding: 4px 12px; border-radius: 50px;" id="report_total_invoices">0 Bills</span>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-lg-6">
                        <h6 class="fw-bold mb-3" style="color: var(--ios-label); font-size: 0.95rem;"><i class="bi bi-receipt me-2 text-primary"></i>Invoices Generated</h6>
                        <div class="table-responsive rounded border" style="background: #fff; max-height: 300px; overflow-y: auto; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
                            <table class="ios-table text-center" style="margin: 0;">
                                <thead style="position: sticky; top: 0; z-index: 5;">
                                    <tr class="table-ios-header">
                                        <th>Invoice #</th>
                                        <th>Time</th>
                                        <th>Payment Breakdown</th>
                                        <th class="text-end">Amount (Rs)</th>
                                    </tr>
                                </thead>
                                <tbody id="report_invoices_tbody"></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="row g-4">
                            <div class="col-12 d-none" id="report_shortages_container">
                                <h6 class="fw-bold mb-3" style="color: #CC2200; font-size: 0.95rem;"><i class="bi bi-exclamation-triangle-fill me-2"></i>Identified Stock Shortages</h6>
                                <div class="table-responsive rounded border" style="background: #fff; border-color: rgba(255,59,48,0.3) !important; max-height: 150px; overflow-y: auto;">
                                    <table class="ios-table text-center" style="margin: 0;">
                                        <thead style="position: sticky; top: 0; z-index: 5;">
                                            <tr class="table-ios-header" style="background: rgba(255,59,48,0.05) !important;">
                                                <th class="text-start">Product Name</th>
                                                <th style="color: #CC2200 !important;">Missing Qty</th>
                                            </tr>
                                        </thead>
                                        <tbody id="report_shortages_tbody"></tbody>
                                    </table>
                                </div>
                            </div>
                            
                            <div class="col-12 d-none" id="report_expenses_container">
                                <h6 class="fw-bold mb-3" style="color: #C07000; font-size: 0.95rem;"><i class="bi bi-wallet2 me-2"></i>Recorded Expenses</h6>
                                <div class="table-responsive rounded border" style="background: #fff; max-height: 150px; overflow-y: auto; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
                                    <table class="ios-table" style="margin: 0;">
                                        <thead style="position: sticky; top: 0; z-index: 5;">
                                            <tr class="table-ios-header">
                                                <th>Type & Description</th>
                                                <th class="text-end">Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody id="report_expenses_tbody"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <div class="modal-footer" style="background: var(--ios-surface); border-top: 1px solid var(--ios-separator); padding: 16px 24px; display: flex; justify-content: space-between; width: 100%;">
                <a id="btn_full_report" href="#" class="quick-btn quick-btn-primary px-4"><i class="bi bi-box-arrow-up-right me-1"></i> Full Detailed Report</a>
                <button type="button" class="quick-btn quick-btn-secondary px-4" data-bs-dismiss="modal">Close Report</button>
            </div>
        </div>
    </div>
</div>

<script>
function openReportModal(assignmentId) {
    new bootstrap.Modal(document.getElementById('routeReportModal')).show();
    document.getElementById('btn_full_report').href = `route_detailed_report.php?id=${assignmentId}`;
    
    document.getElementById('report_route_name').textContent = "Loading...";
    document.getElementById('report_invoices_tbody').innerHTML = '<tr><td colspan="4" class="py-4 text-center"><span class="spinner-border spinner-border-sm me-2 text-primary"></span><span class="fw-bold text-muted">Fetching...</span></td></tr>';
    document.getElementById('report_shortages_container').classList.add('d-none');
    document.getElementById('report_expenses_container').classList.add('d-none');

    // Fetch the report data using the current page's endpoint
    fetch('route_sales.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `ajax_action=get_route_report&assignment_id=${assignmentId}`
    })
    .then(res => res.json())
    .then(result => {
        if(result.success) {
            const asg = result.assignment;
            
            document.getElementById('report_route_name').textContent = asg.route_name;
            document.getElementById('report_rep_name').textContent = asg.rep_name;
            document.getElementById('report_date').textContent = new Date(asg.assign_date).toLocaleDateString();
            
            const startM = parseFloat(asg.start_meter) || 0;
            const endM = parseFloat(asg.end_meter) || 0;
            document.getElementById('report_start_meter').textContent = startM.toFixed(1) + ' km';
            document.getElementById('report_end_meter').textContent = endM.toFixed(1) + ' km';
            document.getElementById('report_distance').textContent = (endM - startM).toFixed(1) + ' km';

            const orders = result.orders;
            document.getElementById('report_total_invoices').textContent = orders.length + ' Bills';
            
            let totalSales = 0;
            let invHtml = '';
            
            if (orders.length > 0) {
                orders.forEach(o => {
                    const amt = parseFloat(o.total_amount);
                    totalSales += amt;
                    
                    invHtml += `
                        <tr>
                            <td class="text-start ps-3">
                                <a href="view_invoice.php?id=${o.id}" target="_blank" style="font-weight: 700; font-size: 0.9rem; color: var(--accent-dark); text-decoration: none;">#${String(o.id).padStart(6, '0')}</a>
                            </td>
                            <td>
                                <div style="font-size: 0.8rem; color: var(--ios-label-2); font-weight: 600;">${new Date(o.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</div>
                            </td>
                            <td>
                                <span class="ios-badge gray" style="font-size: 0.65rem;">C: ${parseFloat(o.paid_cash).toFixed(0)} | B: ${parseFloat(o.paid_bank).toFixed(0)} | Ch: ${parseFloat(o.paid_cheque).toFixed(0)}</span>
                            </td>
                            <td class="text-end pe-3" style="font-weight: 800; font-size: 0.95rem; color: var(--ios-label);">${amt.toFixed(2)}</td>
                        </tr>
                    `;
                });
            } else {
                invHtml = '<tr><td colspan="4" class="py-4 text-center text-muted fw-bold">No invoices generated on this route.</td></tr>';
            }
            
            document.getElementById('report_total_sales').textContent = 'Rs: ' + totalSales.toFixed(2);
            document.getElementById('report_invoices_tbody').innerHTML = invHtml;

            // Render Shortages
            const shortages = result.shortages;
            if (shortages && shortages.length > 0) {
                document.getElementById('report_shortages_container').classList.remove('d-none');
                let shortHtml = '';
                shortages.forEach(s => {
                    shortHtml += `<tr><td class="text-start ps-3" style="font-weight: 600; font-size: 0.85rem; color: var(--ios-label);">${s.name}</td><td style="font-weight: 800; font-size: 0.95rem; color: #CC2200;">${s.short_qty}</td></tr>`;
                });
                document.getElementById('report_shortages_tbody').innerHTML = shortHtml;
            }

            // Render Expenses
            const expenses = result.expenses;
            if (expenses && expenses.length > 0) {
                document.getElementById('report_expenses_container').classList.remove('d-none');
                let expHtml = '';
                expenses.forEach(e => {
                    expHtml += `<tr>
                        <td class="text-start ps-3">
                            <div style="font-weight: 700; font-size: 0.85rem; color: var(--ios-label);">${e.type}</div>
                            <div style="font-size: 0.75rem; color: var(--ios-label-3);">${e.description}</div>
                        </td>
                        <td class="text-end pe-3" style="font-weight: 800; font-size: 0.95rem; color: #C07000;">- Rs ${parseFloat(e.amount).toFixed(2)}</td>
                    </tr>`;
                });
                document.getElementById('report_expenses_tbody').innerHTML = expHtml;
            }
        }
    });
}
</script>

<?php include '../includes/footer.php'; ?>