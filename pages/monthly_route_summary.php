<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); // Restricted to management

// --- MONTH & YEAR FILTERING ---
// Default to current month and year
$selected_month = isset($_GET['month_filter']) ? $_GET['month_filter'] : date('Y-m');

try {
    // Fetch route summaries for the selected month
    $query = "
        SELECT 
            rr.id as assignment_id,
            rr.assign_date,
            r.name as route_name,
            u.name as rep_name,
            COALESCE(SUM(o.paid_cash), 0) as cash_sales,
            COALESCE(SUM(o.paid_bank), 0) as bank_sales,
            COALESCE(SUM(o.paid_cheque), 0) as cheque_sales,
            COALESCE(SUM(o.total_amount - o.paid_amount), 0) as credit_sales,
            COALESCE(SUM(o.total_amount), 0) as total_sales,
            (SELECT COALESCE(SUM(amount), 0) FROM route_expenses WHERE assignment_id = rr.id) as total_expenses
        FROM rep_routes rr
        JOIN routes r ON rr.route_id = r.id
        JOIN users u ON rr.rep_id = u.id
        LEFT JOIN orders o ON o.assignment_id = rr.id
        WHERE rr.status IN ('completed', 'unloaded')
          AND DATE_FORMAT(rr.assign_date, '%Y-%m') = ?
        GROUP BY rr.id, rr.assign_date, r.name, u.name
        ORDER BY rr.assign_date ASC, r.name ASC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$selected_month]);
    $routes_summary = $stmt->fetchAll();
    
    // Calculate overall month totals
    $total_cash = 0;
    $total_bank = 0;
    $total_cheque = 0;
    $total_credit = 0;
    $total_sales = 0;
    $total_expenses = 0;
    
    foreach ($routes_summary as $row) {
        $total_cash += (float)$row['cash_sales'];
        $total_bank += (float)$row['bank_sales'];
        $total_cheque += (float)$row['cheque_sales'];
        $total_credit += (float)$row['credit_sales'];
        $total_sales += (float)$row['total_sales'];
        $total_expenses += (float)$row['total_expenses'];
    }
    
    $net_sales_after_expenses = $total_sales - $total_expenses;

} catch (Exception $e) {
    die("Error generating monthly route summary: " . $e->getMessage());
}

include '../includes/header.php';
include '../includes/sidebar.php';

// Format selected month for display
$formatted_month_year = date('F Y', strtotime($selected_month . '-01'));
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
    .ios-input {
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
    .ios-input:focus {
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

    /* Metrics Card */
    .metrics-card {
        border-radius: 16px;
        padding: 20px 24px;
        color: #fff;
        box-shadow: 0 8px 24px rgba(0,0,0,0.08);
        display: flex;
        flex-direction: column;
        justify-content: center;
        height: 100%;
        position: relative;
        overflow: hidden;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .metrics-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 30px rgba(0,0,0,0.12);
    }
    .metrics-bg-icon {
        position: absolute;
        right: -15px;
        bottom: -20px;
        font-size: 6rem;
        opacity: 0.15;
        z-index: 1;
    }
    .metrics-content {
        position: relative;
        z-index: 2;
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
        padding: 14px 18px;
    }
    .ios-table { width: 100%; border-collapse: collapse; }
    .ios-table td {
        vertical-align: middle;
        padding: 14px 18px;
        border-bottom: 1px solid var(--ios-separator);
    }
    .ios-table tr:last-child td { border-bottom: none; }
    .ios-table tr:hover td { background: var(--ios-bg); }
    
    .ios-badge {
        font-size: 0.7rem;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 50px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        white-space: nowrap;
    }
    .ios-badge.green   { background: rgba(52,199,89,0.12); color: #1A9A3A; }
    .ios-badge.blue    { background: rgba(0,122,255,0.12); color: #0055CC; }
    .ios-badge.orange  { background: rgba(255,149,0,0.15); color: #C07000; }
    .ios-badge.red     { background: rgba(255,59,48,0.12); color: #CC2200; }
    .ios-badge.gray    { background: rgba(60,60,67,0.08); color: var(--ios-label-2); }

    /* Printable Signature Block */
    .print-signature-section {
        display: none;
        margin-top: 80px;
        border-top: 1px solid #ddd;
        padding-top: 20px;
    }

    @media print {
        body { background: #fff !important; color: #000 !important; font-family: 'Times New Roman', serif; }
        .no-print { display: none !important; }
        #sidebarMenu { display: none !important; }
        #mainContent { margin-left: 0 !important; padding: 0 !important; }
        .dash-card { box-shadow: none !important; border: 1px solid #000 !important; margin-bottom: 20px !important; }
        .metrics-card { display: none !important; }
        .table-ios-header th {
            background: #f0f0f0 !important;
            color: #000 !important;
            border-bottom: 2px solid #000 !important;
            font-size: 0.8rem !important;
            font-weight: bold !important;
        }
        .ios-table td {
            border-bottom: 1px solid #ccc !important;
            color: #000 !important;
            font-size: 0.85rem !important;
        }
        .print-header-block {
            display: block !important;
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 3px double #000;
            padding-bottom: 15px;
        }
        .print-signature-section {
            display: block !important;
        }
        .text-success { color: #000 !important; font-weight: bold; }
        .text-danger { color: #000 !important; font-style: italic; }
        .ios-badge { background: none !important; color: #000 !important; border: 1px solid #000 !important; padding: 2px 6px !important; }
    }
</style>

<!-- Printable Only Header -->
<div class="print-header-block d-none">
    <h1 style="font-size: 2.2rem; font-weight: 800; margin: 0; text-transform: uppercase;">Candent ERP</h1>
    <h3 style="font-size: 1.3rem; font-weight: 700; margin: 5px 0 0; color: #333;">Monthly Route Sales &amp; Expenses Summary</h3>
    <h4 style="font-size: 1.1rem; font-weight: 600; margin: 5px 0 0; color: #666;">Month: <?php echo $formatted_month_year; ?></h4>
    <p style="font-size: 0.8rem; margin: 10px 0 0; color: #888;">Generated on: <?php echo date('Y-m-d H:i:s'); ?></p>
</div>

<!-- Screen Page Header -->
<div class="page-header no-print">
    <div>
        <h1 class="page-title">Monthly Route Summary</h1>
        <div class="page-subtitle">Detailed monthly route assignment analytics, split by sales type and expenses.</div>
    </div>
    <div>
        <button onclick="window.print()" class="quick-btn quick-btn-primary">
            <i class="bi bi-printer-fill"></i> Print Summary
        </button>
    </div>
</div>

<!-- Filters (Screen Only) -->
<div class="dash-card mb-4 no-print" style="background: var(--ios-surface-2);">
    <div class="p-3">
        <form method="GET" action="" id="filterForm">
            <div class="row g-2 align-items-end">
                <div class="col-md-6 col-sm-8">
                    <label class="ios-label-sm">Select Month &amp; Year</label>
                    <input type="month" name="month_filter" class="ios-input fw-bold" value="<?php echo htmlspecialchars($selected_month); ?>" onchange="document.getElementById('filterForm').submit();">
                </div>
                <div class="col-md-6 col-sm-4">
                    <button type="submit" class="quick-btn quick-btn-secondary w-100" style="padding: 10px; min-height: 42px;">
                        <i class="bi bi-arrow-repeat"></i> Update View
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Top Metrics (Screen Only) -->
<div class="row g-3 mb-4 no-print">
    <!-- Liquid / Cash Sales -->
    <div class="col-md-4 col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #34C759, #30D158);">
            <i class="bi bi-cash-coin metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.85); margin-bottom: 2px;">Cash &amp; Bank Sales</div>
                <div style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($total_cash + $total_bank, 2); ?></div>
                <div style="font-size: 0.75rem; opacity: 0.8; margin-top: 4px;">Cash: Rs <?php echo number_format($total_cash, 0); ?> | Bank: Rs <?php echo number_format($total_bank, 0); ?></div>
            </div>
        </div>
    </div>
    
    <!-- Cheque Sales -->
    <div class="col-md-4 col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF9500, #FF8000);">
            <i class="bi bi-file-earmark-spreadsheet metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.85); margin-bottom: 2px;">Cheque Sales</div>
                <div style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($total_cheque, 2); ?></div>
                <div style="font-size: 0.75rem; opacity: 0.8; margin-top: 4px;">Cheques collected for routes</div>
            </div>
        </div>
    </div>
    
    <!-- Credit Sales -->
    <div class="col-md-4 col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF3B30, #CC1500);">
            <i class="bi bi-credit-card metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.85); margin-bottom: 2px;">Credit Sales</div>
                <div style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($total_credit, 2); ?></div>
                <div style="font-size: 0.75rem; opacity: 0.8; margin-top: 4px;">Outstanding balance from deliveries</div>
            </div>
        </div>
    </div>

    <!-- Total Gross Sales -->
    <div class="col-md-4 col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #007AFF, #0055CC);">
            <i class="bi bi-graph-up metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.85); margin-bottom: 2px;">Total Gross Sales</div>
                <div style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($total_sales, 2); ?></div>
                <div style="font-size: 0.75rem; opacity: 0.8; margin-top: 4px;">Sum of all route invoices</div>
            </div>
        </div>
    </div>

    <!-- Route Expenses -->
    <div class="col-md-4 col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #AF52DE, #8B2BAA);">
            <i class="bi bi-wallet2 metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.85); margin-bottom: 2px;">Route Expenses Total</div>
                <div style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($total_expenses, 2); ?></div>
                <div style="font-size: 0.75rem; opacity: 0.8; margin-top: 4px;">Fuel, food, allowances, etc.</div>
            </div>
        </div>
    </div>

    <!-- Net Realized Sales -->
    <div class="col-md-4 col-sm-12">
        <div class="metrics-card" style="background: linear-gradient(145deg, #30B0C7, #1A95AC);">
            <i class="bi bi-shield-check metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.85); margin-bottom: 2px;">Net Value (Sales - Expenses)</div>
                <div style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($net_sales_after_expenses, 2); ?></div>
                <div style="font-size: 0.75rem; opacity: 0.8; margin-top: 4px;">Revenue margins after direct route costs</div>
            </div>
        </div>
    </div>
</div>

<!-- Route Sales Table -->
<div class="dash-card mb-4 overflow-hidden">
    <div class="dash-card-header no-print" style="background: var(--ios-surface); padding: 18px 20px;">
        <span class="card-title">
            <span class="card-title-icon" style="background: rgba(0,122,255,0.1); color: #0055CC;">
                <i class="bi bi-calendar-event-fill"></i>
            </span>
            Route Reports for <?php echo htmlspecialchars($formatted_month_year); ?>
        </span>
    </div>
    
    <div class="table-responsive">
        <table class="ios-table">
            <thead>
                <tr class="table-ios-header">
                    <th style="width: 5%; text-align: center;">No</th>
                    <th style="width: 12%;">Date</th>
                    <th style="width: 18%;">Route &amp; Rep Name</th>
                    <th style="width: 15%; text-align: right;">Cash / Bank Sale</th>
                    <th style="width: 12%; text-align: right;">Cheque Sale</th>
                    <th style="width: 12%; text-align: right;">Credit Sale</th>
                    <th style="width: 13%; text-align: right; background: rgba(0,122,255,0.02) !important;">Total Sale</th>
                    <th style="width: 13%; text-align: right; color: #CC2200 !important;">Expenses Total</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                foreach ($routes_summary as $row): 
                    $liquid_sale = (float)$row['cash_sales'] + (float)$row['bank_sales'];
                    $cheque_sale = (float)$row['cheque_sales'];
                    $credit_sale = (float)$row['credit_sales'];
                    $total_sale = (float)$row['total_sales'];
                    $expenses_total = (float)$row['total_expenses'];
                ?>
                <tr>
                    <td style="text-align: center; font-weight: 600; color: var(--ios-label-2);">
                        <?php echo $counter++; ?>
                    </td>
                    <td>
                        <div style="font-weight: 700; color: var(--ios-label);">
                            <?php echo date('Y-m-d', strtotime($row['assign_date'])); ?>
                        </div>
                        <div style="font-size: 0.7rem; color: var(--ios-label-3);" class="no-print">
                            <?php echo date('l', strtotime($row['assign_date'])); ?>
                        </div>
                    </td>
                    <td>
                        <div style="font-weight: 600; color: var(--ios-label);">
                            <?php echo htmlspecialchars($row['route_name']); ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--ios-label-2);">
                            <i class="bi bi-person-badge text-muted me-1"></i><?php echo htmlspecialchars($row['rep_name']); ?>
                        </div>
                    </td>
                    <td style="text-align: right;">
                        <span style="font-weight: 600;">Rs <?php echo number_format($liquid_sale, 2); ?></span>
                        <?php if ((float)$row['bank_sales'] > 0): ?>
                            <div style="font-size: 0.7rem; color: var(--ios-label-3);" class="no-print">
                                (Cash: <?php echo number_format($row['cash_sales'], 0); ?> | Bank: <?php echo number_format($row['bank_sales'], 0); ?>)
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right;">
                        <?php if ($cheque_sale > 0): ?>
                            <span style="font-weight: 600;" class="text-warning">Rs <?php echo number_format($cheque_sale, 2); ?></span>
                        <?php else: ?>
                            <span style="color: var(--ios-label-3); font-size: 0.85rem;">-</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right;">
                        <?php if ($credit_sale > 0): ?>
                            <span style="font-weight: 600; color: #CC2200;">Rs <?php echo number_format($credit_sale, 2); ?></span>
                        <?php else: ?>
                            <span style="color: var(--ios-label-3); font-size: 0.85rem;">-</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right; background: rgba(0,122,255,0.01); font-weight: 700; color: #0055CC;">
                        Rs <?php echo number_format($total_sale, 2); ?>
                    </td>
                    <td style="text-align: right; font-weight: 700; color: #CC2200;">
                        <?php if ($expenses_total > 0): ?>
                            Rs <?php echo number_format($expenses_total, 2); ?>
                        <?php else: ?>
                            <span style="color: var(--ios-label-3); font-size: 0.85rem; font-weight: normal;">No Exp.</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($routes_summary)): ?>
                <tr>
                    <td colspan="8" class="text-center py-5">
                        <div class="empty-state">
                            <i class="bi bi-calendar-x" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2 fw-bold text-muted">No completed route summaries found for <?php echo htmlspecialchars($formatted_month_year); ?>.</p>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <!-- Table Footer for Column Totals -->
                <tr style="background: var(--ios-surface-2); font-weight: 800; border-top: 2px solid var(--ios-separator);">
                    <td colspan="3" style="text-align: right; padding: 16px 18px; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--ios-label-2);">
                        Month Totals:
                    </td>
                    <td style="text-align: right; padding: 16px 18px;">
                        Rs <?php echo number_format($total_cash + $total_bank, 2); ?>
                    </td>
                    <td style="text-align: right; padding: 16px 18px;">
                        Rs <?php echo number_format($total_cheque, 2); ?>
                    </td>
                    <td style="text-align: right; padding: 16px 18px; color: #CC2200;">
                        Rs <?php echo number_format($total_credit, 2); ?>
                    </td>
                    <td style="text-align: right; padding: 16px 18px; background: rgba(0,122,255,0.03); color: #0055CC; font-size: 1rem;">
                        Rs <?php echo number_format($total_sales, 2); ?>
                    </td>
                    <td style="text-align: right; padding: 16px 18px; color: #CC2200;">
                        Rs <?php echo number_format($total_expenses, 2); ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Print Verification / Signature Block -->
<div class="print-signature-section">
    <div class="row pt-4" style="margin-top: 50px;">
        <div class="col-4 text-center">
            <div style="border-top: 1px solid #000; width: 80%; margin: 0 auto; padding-top: 8px; font-weight: bold; font-size: 0.9rem;">Prepared By (Sales Rep / Clerk)</div>
            <div style="font-size: 0.75rem; color: #555; margin-top: 4px;">Signature &amp; Date</div>
        </div>
        <div class="col-4 text-center">
            <div style="border-top: 1px solid #000; width: 80%; margin: 0 auto; padding-top: 8px; font-weight: bold; font-size: 0.9rem;">Checked By (Supervisor)</div>
            <div style="font-size: 0.75rem; color: #555; margin-top: 4px;">Signature &amp; Date</div>
        </div>
        <div class="col-4 text-center">
            <div style="border-top: 1px solid #000; width: 80%; margin: 0 auto; padding-top: 8px; font-weight: bold; font-size: 0.9rem;">Approved By (Admin / Management)</div>
            <div style="font-size: 0.75rem; color: #555; margin-top: 4px;">Signature &amp; Date</div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
