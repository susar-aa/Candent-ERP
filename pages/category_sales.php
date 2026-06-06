<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); 

// --- FILTERING ---
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-t');
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

$limit = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Subqueries use date range parameters twice (once for sales, once for returns)
$subquery_params = [$date_from, $date_to, $date_from, $date_to];

$outer_where = "";
$outer_params = [];

if ($search_query !== '') {
    $outer_where .= " AND cat.category_name LIKE ?";
    $outer_params[] = "%$search_query%";
}

$queryParams = array_merge($subquery_params, $outer_params);

// --- FETCH ALL CATEGORIES FOR DROPDOWN ---
$allCategoriesList = $pdo->query("SELECT name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);

// --- FETCH TOTALS FOR PAGINATION & GRAND ROW ---
$countQuery = "
    SELECT COUNT(DISTINCT COALESCE(cat.id, 0)) 
    FROM (
        SELECT id, name as category_name FROM categories
        UNION ALL
        SELECT NULL as id, 'Uncategorized' as category_name
    ) cat
    LEFT JOIN (
        SELECT p.category_id, SUM(oi.quantity) as total_qty
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN products p ON oi.product_id = p.id
        WHERE DATE(o.created_at) >= ? AND DATE(o.created_at) <= ? AND o.order_status != 'cancelled'
        GROUP BY p.category_id
    ) sales ON (cat.id = sales.category_id OR (cat.id IS NULL AND sales.category_id IS NULL))
    LEFT JOIN (
        SELECT p.category_id, SUM(sri.quantity) as returned_qty
        FROM sales_return_items sri
        JOIN sales_returns sr ON sri.return_id = sr.id
        JOIN products p ON sri.product_id = p.id
        WHERE DATE(sr.created_at) >= ? AND DATE(sr.created_at) <= ?
        GROUP BY p.category_id
    ) returns ON (cat.id = returns.category_id OR (cat.id IS NULL AND returns.category_id IS NULL))
    WHERE (sales.total_qty IS NOT NULL OR returns.returned_qty IS NOT NULL)
    $outer_where
";
$totalStmt = $pdo->prepare($countQuery);
$totalStmt->execute($queryParams);
$totalRows = $totalStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$grandQuery = "
    SELECT 
        SUM(COALESCE(sales.total_qty, 0)) as grand_qty,
        SUM(COALESCE(returns.returned_qty, 0)) as grand_returned_qty,
        SUM(COALESCE(sales.total_qty, 0) - COALESCE(returns.returned_qty, 0)) as grand_net_qty,
        SUM(COALESCE(sales.gross_revenue, 0)) as grand_gross,
        SUM(COALESCE(sales.total_discount, 0)) as grand_discount,
        SUM(COALESCE(returns.returned_val, 0)) as grand_returned_val,
        SUM(COALESCE(sales.gross_revenue, 0) - COALESCE(sales.total_discount, 0) - COALESCE(returns.returned_val, 0)) as grand_net
    FROM (
        SELECT id, name as category_name FROM categories
        UNION ALL
        SELECT NULL as id, 'Uncategorized' as category_name
    ) cat
    LEFT JOIN (
        SELECT 
            p.category_id,
            SUM(oi.quantity) as total_qty,
            SUM(oi.quantity * oi.price) as gross_revenue,
            SUM(oi.discount) as total_discount
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN products p ON oi.product_id = p.id
        WHERE DATE(o.created_at) >= ? AND DATE(o.created_at) <= ? AND o.order_status != 'cancelled'
        GROUP BY p.category_id
    ) sales ON (cat.id = sales.category_id OR (cat.id IS NULL AND sales.category_id IS NULL))
    LEFT JOIN (
        SELECT 
            p.category_id,
            SUM(sri.quantity) as returned_qty,
            SUM(sri.quantity * sri.unit_price) as returned_val
        FROM sales_return_items sri
        JOIN sales_returns sr ON sri.return_id = sr.id
        JOIN products p ON sri.product_id = p.id
        WHERE DATE(sr.created_at) >= ? AND DATE(sr.created_at) <= ?
        GROUP BY p.category_id
    ) returns ON (cat.id = returns.category_id OR (cat.id IS NULL AND returns.category_id IS NULL))
    WHERE (sales.total_qty IS NOT NULL OR returns.returned_qty IS NOT NULL)
    $outer_where
";
$grandStmt = $pdo->prepare($grandQuery);
$grandStmt->execute($queryParams);
$grandTotals = $grandStmt->fetch();

// --- FETCH PAGINATED DATA ---
$query = "
    SELECT 
        cat.id as category_id,
        cat.category_name,
        COALESCE(sales.total_qty, 0) as total_sold_qty,
        COALESCE(sales.gross_revenue, 0) as gross_revenue,
        COALESCE(sales.total_discount, 0) as total_discount,
        COALESCE(returns.returned_qty, 0) as returned_qty,
        COALESCE(returns.returned_val, 0) as returned_val,
        (COALESCE(sales.total_qty, 0) - COALESCE(returns.returned_qty, 0)) as net_qty,
        (COALESCE(sales.gross_revenue, 0) - COALESCE(sales.total_discount, 0) - COALESCE(returns.returned_val, 0)) as net_revenue
    FROM (
        SELECT id, name as category_name FROM categories
        UNION ALL
        SELECT NULL as id, 'Uncategorized' as category_name
    ) cat
    LEFT JOIN (
        SELECT 
            p.category_id,
            SUM(oi.quantity) as total_qty,
            SUM(oi.quantity * oi.price) as gross_revenue,
            SUM(oi.discount) as total_discount
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN products p ON oi.product_id = p.id
        WHERE DATE(o.created_at) >= ? AND DATE(o.created_at) <= ? AND o.order_status != 'cancelled'
        GROUP BY p.category_id
    ) sales ON (cat.id = sales.category_id OR (cat.id IS NULL AND sales.category_id IS NULL))
    LEFT JOIN (
        SELECT 
            p.category_id,
            SUM(sri.quantity) as returned_qty,
            SUM(sri.quantity * sri.unit_price) as returned_val
        FROM sales_return_items sri
        JOIN sales_returns sr ON sri.return_id = sr.id
        JOIN products p ON sri.product_id = p.id
        WHERE DATE(sr.created_at) >= ? AND DATE(sr.created_at) <= ?
        GROUP BY p.category_id
    ) returns ON (cat.id = returns.category_id OR (cat.id IS NULL AND returns.category_id IS NULL))
    WHERE (sales.total_qty IS NOT NULL OR returns.returned_qty IS NOT NULL)
    $outer_where
    ORDER BY net_revenue DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($query);
$stmt->execute($queryParams);
$reportData = $stmt->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Tom Select CSS for Searchable Dropdowns -->
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">

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
        background: var(--ios-surface) !important;
        border: 1px solid var(--ios-separator) !important;
        border-radius: 10px !important;
        padding: 10px 14px !important;
        font-size: 0.95rem !important;
        color: var(--ios-label) !important;
        transition: all 0.2s ease;
        box-shadow: none !important;
        width: 100%;
        min-height: 42px;
    }
    .ios-input:focus, .form-select:focus {
        background: #fff !important;
        border-color: var(--accent) !important;
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
        font-size: 6rem;
        opacity: 0.15;
        z-index: 1;
    }
    .metrics-content {
        position: relative;
        z-index: 2;
    }

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
    .ios-badge.blue { background: rgba(0,122,255,0.12); color: #0055CC; }
    .ios-badge.gray { background: rgba(60,60,67,0.1); color: var(--ios-label-2); }

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

    /* Print Overrides */
    @media print {
        body { background: #fff !important; }
        .no-print { display: none !important; }
        .dash-card { box-shadow: none !important; border: 1px solid var(--ios-separator); }
        .metrics-card { color: #000 !important; background: #f8f9fa !important; border: 1px solid var(--ios-separator); box-shadow: none !important; }
        .metrics-bg-icon { display: none; }
    }
</style>

<div class="page-header no-print">
    <div>
        <h1 class="page-title">Category Sales Report</h1>
        <div class="page-subtitle">Analyze volume and revenue performance by product category.</div>
    </div>
    <div>
        <button onclick="window.print()" class="quick-btn quick-btn-secondary">
            <i class="bi bi-printer"></i> Print Report
        </button>
    </div>
</div>

<div class="d-none d-print-block text-center mb-4 pb-3 border-bottom">
    <h2 style="font-weight: 800; margin: 0;">Category Wise Sales Report</h2>
    <p style="color: #666; margin: 5px 0 0; font-size: 1.1rem;">Period: <?php echo date('M d, Y', strtotime($date_from)); ?> to <?php echo date('M d, Y', strtotime($date_to)); ?></p>
</div>

<!-- Primary KPIs (Extracted from Grand Totals) -->
<div class="row g-3 mb-4">
    <!-- Gross Revenue -->
    <div class="col-md col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #007AFF, #0055CC);">
            <i class="bi bi-cash-stack metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.8); margin-bottom: 2px;">Total Gross Revenue</div>
                <div style="font-size: 1.5rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($grandTotals['grand_gross'] ?? 0, 2); ?></div>
            </div>
        </div>
    </div>
    <!-- Discounts Given -->
    <div class="col-md col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #5856D6, #4543B0);">
            <i class="bi bi-tags-fill metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.8); margin-bottom: 2px;">Discounts Given</div>
                <div style="font-size: 1.5rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($grandTotals['grand_discount'] ?? 0, 2); ?></div>
            </div>
        </div>
    </div>
    <!-- Returned Value -->
    <div class="col-md col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF3B30, #CC1500);">
            <i class="bi bi-arrow-return-left metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.8); margin-bottom: 2px;">Returned Value</div>
                <div style="font-size: 1.5rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($grandTotals['grand_returned_val'] ?? 0, 2); ?></div>
            </div>
        </div>
    </div>
    <!-- Net Revenue -->
    <div class="col-md col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #34C759, #30D158);">
            <i class="bi bi-check-circle-fill metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.8); margin-bottom: 2px;">Total Net Revenue</div>
                <div style="font-size: 1.5rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($grandTotals['grand_net'] ?? 0, 2); ?></div>
            </div>
        </div>
    </div>
    <!-- Net Qty Sold -->
    <div class="col-md col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF9500, #E07800);">
            <i class="bi bi-box-seam-fill metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.8); margin-bottom: 2px;">Net Qty Sold</div>
                <div style="font-size: 1.5rem; font-weight: 800; letter-spacing: -0.5px;"><?php echo number_format($grandTotals['grand_net_qty'] ?? 0); ?> <span style="font-size: 0.72rem; opacity: 0.85; font-weight: 500;">(<?php echo number_format($grandTotals['grand_qty'] ?? 0); ?> gross, <?php echo number_format($grandTotals['grand_returned_qty'] ?? 0); ?> ret)</span></div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="dash-card mb-4 no-print" style="background: var(--ios-surface-2);">
    <div class="p-3">
        <form method="GET" action="" id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="ios-label-sm">Search Category</label>
                <select name="search" id="categorySearchSelect" class="form-select fw-bold">
                    <option value="">All Categories</option>
                    <option value="Uncategorized" <?php echo $search_query === 'Uncategorized' ? 'selected' : ''; ?>>Uncategorized</option>
                    <?php foreach($allCategoriesList as $cName): ?>
                        <option value="<?php echo htmlspecialchars($cName); ?>" <?php echo $search_query === $cName ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cName); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="ios-label-sm">From Date</label>
                <input type="date" name="date_from" class="ios-input fw-bold" value="<?php echo htmlspecialchars($date_from); ?>" onchange="document.getElementById('filterForm').submit();">
            </div>
            <div class="col-md-3">
                <label class="ios-label-sm">To Date</label>
                <input type="date" name="date_to" class="ios-input fw-bold" value="<?php echo htmlspecialchars($date_to); ?>" onchange="document.getElementById('filterForm').submit();">
            </div>
            <div class="col-md-2">
                <button type="submit" class="quick-btn quick-btn-primary w-100" style="min-height: 42px;">
                    <i class="bi bi-funnel-fill me-1"></i> Apply Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Data Table -->
<div class="dash-card mb-5 overflow-hidden">
    <div class="table-responsive">
        <table class="ios-table">
            <thead>
                <tr class="table-ios-header">
                    <th style="width: 25%;" class="ps-4">Category Name</th>
                    <th class="text-center" style="width: 20%;">Qty (Gross / Ret / Net)</th>
                    <th class="text-end" style="width: 13%;">Gross Revenue</th>
                    <th class="text-end" style="width: 13%; color: #CC2200 !important;">Discounts</th>
                    <th class="text-end" style="width: 13%; color: #CC2200 !important;">Returned Value</th>
                    <th class="text-end pe-4" style="width: 16%; color: #1A9A3A !important;">Net Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($reportData as $row): ?>
                <tr>
                    <td class="ps-4">
                        <div style="font-weight: 700; font-size: 1rem; color: var(--ios-label);">
                            <i class="bi bi-tag-fill text-primary me-2"></i> <?php echo htmlspecialchars($row['category_name']); ?>
                        </div>
                    </td>
                    <td class="text-center">
                        <div class="d-flex flex-column align-items-center gap-1">
                            <div class="d-flex gap-1">
                                <span class="ios-badge blue" style="font-size: 0.7rem; padding: 2px 6px;" title="Gross Sold"><?php echo number_format($row['total_sold_qty']); ?> Sold</span>
                                <?php if ($row['returned_qty'] > 0): ?>
                                    <span class="ios-badge danger" style="font-size: 0.7rem; padding: 2px 6px;" title="Returned"><?php echo number_format($row['returned_qty']); ?> Ret</span>
                                <?php endif; ?>
                            </div>
                            <span class="ios-badge green fw-bold" style="font-size: 0.75rem; padding: 2px 8px;" title="Net Qty"><?php echo number_format($row['net_qty']); ?> Net</span>
                        </div>
                    </td>
                    <td class="text-end">
                        <span style="font-weight: 600; color: var(--ios-label-2);">Rs <?php echo number_format($row['gross_revenue'], 2); ?></span>
                    </td>
                    <td class="text-end">
                        <span style="font-weight: 600; color: #CC2200;">- Rs <?php echo number_format($row['total_discount'], 2); ?></span>
                    </td>
                    <td class="text-end">
                        <span style="font-weight: 600; color: #CC2200;"><?php echo $row['returned_val'] > 0 ? '- Rs ' . number_format($row['returned_val'], 2) : 'Rs 0.00'; ?></span>
                    </td>
                    <td class="text-end pe-4">
                        <span style="font-weight: 800; font-size: 1rem; color: #1A9A3A;">Rs <?php echo number_format($row['net_revenue'], 2); ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($reportData)): ?>
                <tr>
                    <td colspan="6" class="text-center py-5 text-muted">
                        <div class="empty-state">
                            <i class="bi bi-tags" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2" style="font-weight: 500;">No sales data found for the selected period.</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
            
            <?php if(!empty($reportData)): ?>
            <tfoot style="background: var(--ios-surface-2); border-top: 2px solid var(--ios-label);">
                <tr>
                    <td class="text-end text-uppercase fw-bold ps-4" style="color: var(--ios-label-2); font-size: 0.8rem;">Grand Totals:</td>
                    <td class="text-center">
                        <div class="d-flex flex-column align-items-center gap-1 fw-bold">
                            <div class="d-flex gap-1" style="font-size: 0.75rem;">
                                <span style="color: #0055CC;">Gross: <?php echo number_format($grandTotals['grand_qty'] ?? 0); ?></span>
                                <span style="color: #CC2200;">Ret: <?php echo number_format($grandTotals['grand_returned_qty'] ?? 0); ?></span>
                            </div>
                            <span style="color: #1A9A3A; font-size: 0.85rem;">Net: <?php echo number_format($grandTotals['grand_net_qty'] ?? 0); ?></span>
                        </div>
                    </td>
                    <td class="text-end fw-bold" style="color: var(--ios-label);">Rs <?php echo number_format($grandTotals['grand_gross'] ?? 0, 2); ?></td>
                    <td class="text-end fw-bold text-danger">- Rs <?php echo number_format($grandTotals['grand_discount'] ?? 0, 2); ?></td>
                    <td class="text-end fw-bold text-danger">- Rs <?php echo number_format($grandTotals['grand_returned_val'] ?? 0, 2); ?></td>
                    <td class="text-end fw-bold text-success pe-4 fs-5">Rs <?php echo number_format($grandTotals['grand_net'] ?? 0, 2); ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if($totalPages > 1): ?>
<ul class="ios-pagination mb-5 no-print">
    <?php for($i = 1; $i <= $totalPages; $i++): ?>
    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search_query); ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"><?php echo $i; ?></a>
    </li>
    <?php endfor; ?>
</ul>
<?php endif; ?>

<!-- Tom Select JS -->
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    new TomSelect('#categorySearchSelect', {
        create: false,
        sortField: { field: "text", direction: "asc" },
        placeholder: "Type to search category..."
    });
});
</script>

<?php include '../includes/footer.php'; ?>