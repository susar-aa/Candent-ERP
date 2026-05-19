<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); // Restricted to management

$message = '';

// --- AUTO DB MIGRATION FOR GENERAL EXPENSES ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS general_expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category VARCHAR(100) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        payment_method ENUM('Cash', 'Bank') NOT NULL,
        expense_date DATE NOT NULL,
        reference VARCHAR(100),
        description TEXT,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch(PDOException $e) { 
    error_log("Expenses Migration Error: " . $e->getMessage()); 
}
// ----------------------------------------------

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // ADD EXPENSE
    if ($_POST['action'] == 'add_expense') {
        $category = $_POST['category'];
        $amount = (float)$_POST['amount'];
        $payment_method = $_POST['payment_method']; // 'Cash' or 'Bank'
        $expense_date = $_POST['expense_date'];
        $reference = trim($_POST['reference']);
        $description = trim($_POST['description']);
        $user_id = $_SESSION['user_id'];

        if ($amount > 0 && !empty($category)) {
            try {
                $pdo->beginTransaction();
                
                // 1. Lock and Check Company Finances
                $fin = $pdo->query("SELECT * FROM company_finances WHERE id = 1 FOR UPDATE")->fetch();
                if (!$fin) {
                    $pdo->exec("INSERT INTO company_finances (id, cash_on_hand, bank_balance) VALUES (1, 0, 0)");
                    $fin = ['cash_on_hand' => 0, 'bank_balance' => 0];
                }

                if ($payment_method == 'Cash' && $amount > $fin['cash_on_hand']) {
                    throw new Exception("Insufficient Cash on Hand. (Available: Rs " . number_format($fin['cash_on_hand'], 2) . ")");
                }
                if ($payment_method == 'Bank' && $amount > $fin['bank_balance']) {
                    throw new Exception("Insufficient Bank Balance. (Available: Rs " . number_format($fin['bank_balance'], 2) . ")");
                }

                // 2. Deduct Funds and Log to Master Ledger
                if ($payment_method == 'Cash') {
                    $pdo->exec("UPDATE company_finances SET cash_on_hand = cash_on_hand - $amount WHERE id = 1");
                    $logStmt = $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('cash_out', ?, ?, ?)");
                    $logStmt->execute([$amount, "Company Exp ($category): $description", $user_id]);
                } else {
                    $pdo->exec("UPDATE company_finances SET bank_balance = bank_balance - $amount WHERE id = 1");
                    $logStmt = $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('bank_out', ?, ?, ?)");
                    $logStmt->execute([$amount, "Company Exp ($category): $description", $user_id]);
                }

                // 3. Insert Expense Record
                $stmt = $pdo->prepare("INSERT INTO general_expenses (category, amount, payment_method, expense_date, reference, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$category, $amount, $payment_method, $expense_date, $reference, $description, $user_id]);

                $pdo->commit();
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Expense of Rs: " . number_format($amount, 2) . " recorded successfully!</div>";
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        } else {
            $message = "<div class='ios-alert' style='background: rgba(255,149,0,0.1); color: #C07000;'><i class='bi bi-info-circle-fill me-2'></i> Invalid amount or category.</div>";
        }
    }

    // DELETE EXPENSE
    if ($_POST['action'] == 'delete_expense') {
        $expense_id = (int)$_POST['expense_id'];
        $expense_source = $_POST['expense_source'] ?? 'general';
        $user_id = $_SESSION['user_id'];

        try {
            $pdo->beginTransaction();

            if ($expense_source == 'route') {
                // Delete Route Expense Record
                $pdo->prepare("DELETE FROM route_expenses WHERE id = ?")->execute([$expense_id]);
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-trash3-fill me-2'></i> Route expense deleted successfully!</div>";
            } else {
                $expStmt = $pdo->prepare("SELECT * FROM general_expenses WHERE id = ? FOR UPDATE");
                $expStmt->execute([$expense_id]);
                $exp = $expStmt->fetch();

                if (!$exp) throw new Exception("Expense record not found.");

                // 1. Reverse the funds back into Company Finances
                if ($exp['payment_method'] == 'Cash') {
                    $pdo->exec("UPDATE company_finances SET cash_on_hand = cash_on_hand + {$exp['amount']} WHERE id = 1");
                    $logStmt = $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('cash_in', ?, ?, ?)");
                    $logStmt->execute([$exp['amount'], "Reversed Exp ({$exp['category']}): {$exp['description']}", $user_id]);
                } else {
                    $pdo->exec("UPDATE company_finances SET bank_balance = bank_balance + {$exp['amount']} WHERE id = 1");
                    $logStmt = $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('bank_in', ?, ?, ?)");
                    $logStmt->execute([$exp['amount'], "Reversed Exp ({$exp['category']}): {$exp['description']}", $user_id]);
                }

                // 2. Delete Expense Record
                $pdo->prepare("DELETE FROM general_expenses WHERE id = ?")->execute([$expense_id]);
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-trash3-fill me-2'></i> Expense deleted and funds restored successfully!</div>";
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error deleting expense: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// --- FILTERING & PAGINATION ---
$limit = 15;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';
$month_filter = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

$whereClause = "WHERE DATE_FORMAT(e.expense_date, '%Y-%m') = ?";
$params = [$month_filter];

if ($search_query !== '') {
    $whereClause .= " AND (e.description LIKE ? OR e.reference LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}
if ($category_filter !== '') {
    $whereClause .= " AND e.category = ?";
    $params[] = $category_filter;
}

// Fetch Key Metrics for the Selected Month (Union of General and Route Expenses)
$metricsStmt = $pdo->prepare("
    SELECT 
        SUM(amount) as total_amount,
        SUM(CASE WHEN payment_method = 'Cash' THEN amount ELSE 0 END) as total_cash,
        SUM(CASE WHEN payment_method = 'Bank' THEN amount ELSE 0 END) as total_bank
    FROM (
        SELECT 
            'general' as source,
            ge.id,
            ge.category,
            ge.amount,
            ge.payment_method,
            ge.expense_date,
            ge.reference,
            ge.description,
            ge.created_by,
            ge.created_at
        FROM general_expenses ge

        UNION ALL

        SELECT 
            'route' as source,
            re.id,
            re.type as category,
            re.amount,
            'Cash' as payment_method,
            rr.assign_date as expense_date,
            CONCAT('Route: ', r.name) as reference,
            re.description,
            rr.rep_id as created_by,
            re.created_at
        FROM route_expenses re
        JOIN rep_routes rr ON re.assignment_id = rr.id
        JOIN routes r ON rr.route_id = r.id
    ) e
    $whereClause
");
$metricsStmt->execute($params);
$metrics = $metricsStmt->fetch();

// Fetch Total Rows for Pagination (Union of General and Route Expenses)
$totalStmt = $pdo->prepare("
    SELECT COUNT(*) FROM (
        SELECT 
            'general' as source,
            ge.id,
            ge.category,
            ge.amount,
            ge.payment_method,
            ge.expense_date,
            ge.reference,
            ge.description,
            ge.created_by,
            ge.created_at
        FROM general_expenses ge

        UNION ALL

        SELECT 
            'route' as source,
            re.id,
            re.type as category,
            re.amount,
            'Cash' as payment_method,
            rr.assign_date as expense_date,
            CONCAT('Route: ', r.name) as reference,
            re.description,
            rr.rep_id as created_by,
            re.created_at
        FROM route_expenses re
        JOIN rep_routes rr ON re.assignment_id = rr.id
        JOIN routes r ON rr.route_id = r.id
    ) e
    $whereClause
");
$totalStmt->execute($params);
$totalRows = $totalStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Fetch Expenses (Union of General and Route Expenses)
$query = "
    SELECT e.*, u.name as user_name 
    FROM (
        SELECT 
            'general' as source,
            ge.id,
            ge.category,
            ge.amount,
            ge.payment_method,
            ge.expense_date,
            ge.reference,
            ge.description,
            ge.created_by,
            ge.created_at
        FROM general_expenses ge

        UNION ALL

        SELECT 
            'route' as source,
            re.id,
            re.type as category,
            re.amount,
            'Cash' as payment_method,
            rr.assign_date as expense_date,
            CONCAT('Route: ', r.name) as reference,
            re.description,
            rr.rep_id as created_by,
            re.created_at
        FROM route_expenses re
        JOIN rep_routes rr ON re.assignment_id = rr.id
        JOIN routes r ON rr.route_id = r.id
    ) e 
    LEFT JOIN users u ON e.created_by = u.id 
    $whereClause 
    ORDER BY e.expense_date DESC, e.created_at DESC 
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$expenses = $stmt->fetchAll();

// Fetch Category Wise Breakdown for the Selected Month (Union of General and Route Expenses)
$categoryMetricsStmt = $pdo->prepare("
    SELECT category, SUM(amount) as total_amount
    FROM (
        SELECT 
            category,
            amount,
            expense_date
        FROM general_expenses

        UNION ALL

        SELECT 
            type as category,
            amount,
            rr.assign_date as expense_date
        FROM route_expenses re
        JOIN rep_routes rr ON re.assignment_id = rr.id
    ) e
    WHERE DATE_FORMAT(e.expense_date, '%Y-%m') = ?
    GROUP BY category
    ORDER BY total_amount DESC
");
$categoryMetricsStmt->execute([$month_filter]);
$categoryMetrics = $categoryMetricsStmt->fetchAll();

// Predefined Categories (including Route Expense Types)
$categories = ['Office Rent', 'Utilities (Electricity/Water/Internet)', 'Office Supplies', 'Marketing & Ads', 'Software/IT Infrastructure', 'Maintenance & Repairs', 'Legal & Professional', 'Fuel', 'Meals', 'Vehicle Repair', 'Other/Miscellaneous', 'Other'];

// Category Styles lookup for cards
$categoryStyles = [
    'Office Rent' => ['icon' => 'bi-building-fill', 'gradient' => 'linear-gradient(135deg, #A855F7, #9333EA)'],
    'Utilities (Electricity/Water/Internet)' => ['icon' => 'bi-lightning-charge-fill', 'gradient' => 'linear-gradient(135deg, #F59E0B, #D97706)'],
    'Office Supplies' => ['icon' => 'bi-box-seam-fill', 'gradient' => 'linear-gradient(135deg, #6B7280, #4B5563)'],
    'Marketing & Ads' => ['icon' => 'bi-megaphone-fill', 'gradient' => 'linear-gradient(135deg, #EC4899, #DB2777)'],
    'Software/IT Infrastructure' => ['icon' => 'bi-cpu-fill', 'gradient' => 'linear-gradient(135deg, #3B82F6, #1D4ED8)'],
    'Maintenance & Repairs' => ['icon' => 'bi-tools', 'gradient' => 'linear-gradient(135deg, #10B981, #047857)'],
    'Legal & Professional' => ['icon' => 'bi-briefcase-fill', 'gradient' => 'linear-gradient(135deg, #6366F1, #4338CA)'],
    'Fuel' => ['icon' => 'bi-fuel-pump-fill', 'gradient' => 'linear-gradient(135deg, #EF4444, #B91C1C)'],
    'Meals' => ['icon' => 'bi-cup-hot-fill', 'gradient' => 'linear-gradient(135deg, #14B8A6, #0D9488)'],
    'Vehicle Repair' => ['icon' => 'bi-wrench-adjustable-circle-fill', 'gradient' => 'linear-gradient(135deg, #F97316, #EA580C)'],
    'Other/Miscellaneous' => ['icon' => 'bi-tags-fill', 'gradient' => 'linear-gradient(135deg, #6B7280, #4B5563)'],
    'Other' => ['icon' => 'bi-tags-fill', 'gradient' => 'linear-gradient(135deg, #6B7280, #4B5563)'],
];

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    /* Specific Page Styles */
    .metrics-card {
        border-radius: 16px;
        padding: 24px;
        color: #fff;
        box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        position: relative;
        overflow: hidden;
        height: 100%;
        display: flex;
        flex-direction: column;
    }
    .metrics-bg-icon {
        position: absolute;
        right: -20px;
        bottom: -20px;
        font-size: 8rem;
        opacity: 0.15;
        z-index: 1;
    }
    .metrics-content {
        position: relative;
        z-index: 2;
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }
    
    /* Category Card Premium Hover Effects */
    .category-card {
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        border: 1px solid var(--ios-separator);
        background: var(--ios-surface);
        border-radius: 16px;
    }
    .category-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px rgba(0,0,0,0.08) !important;
        border-color: var(--accent) !important;
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
        <h1 class="page-title">Company Expenses</h1>
        <div class="page-subtitle">Track operational costs, overheads, and miscellaneous expenditures.</div>
    </div>
    <div>
        <button class="quick-btn px-3" style="background: #CC2200; color: #fff; box-shadow: 0 4px 14px rgba(255,59,48,0.3);" onclick="openAddModal()">
            <i class="bi bi-plus-lg"></i> Record Expense
        </button>
    </div>
</div>

<?php echo $message; ?>

<!-- Top Metrics -->
<div class="row g-3 mb-4">
    <!-- Total Expenses -->
    <div class="col-md-4">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF3B30, #CC1500);">
            <i class="bi bi-wallet2 metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.9); margin-bottom: 4px;">
                    Total Expenses (<?php echo date('M Y', strtotime($month_filter . '-01')); ?>)
                </div>
                <div style="font-size: 2.2rem; font-weight: 800; letter-spacing: -1px;">
                    Rs <?php echo number_format($metrics['total_amount'] ?: 0, 2); ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Paid via Cash -->
    <div class="col-md-4">
        <div class="metrics-card" style="background: linear-gradient(145deg, #34C759, #30D158);">
            <i class="bi bi-cash-stack metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.9); margin-bottom: 4px;">
                    Paid via Cash
                </div>
                <div style="font-size: 2.2rem; font-weight: 800; letter-spacing: -1px;">
                    Rs <?php echo number_format($metrics['total_cash'] ?: 0, 2); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Paid via Bank -->
    <div class="col-md-4">
        <div class="metrics-card" style="background: linear-gradient(145deg, #007AFF, #0055CC);">
            <i class="bi bi-bank metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.9); margin-bottom: 4px;">
                    Paid via Bank
                </div>
                <div style="font-size: 2.2rem; font-weight: 800; letter-spacing: -1px;">
                    Rs <?php echo number_format($metrics['total_bank'] ?: 0, 2); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Category Wise Breakdown -->
<div class="mb-4">
    <div style="font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--ios-label-2); margin-bottom: 12px; padding-left: 4px;">
        <i class="bi bi-grid-fill me-1"></i> Category-wise Breakdown (<?php echo date('M Y', strtotime($month_filter . '-01')); ?>)
    </div>
    <?php if (!empty($categoryMetrics)): ?>
    <div class="row g-3">
        <?php foreach ($categoryMetrics as $cat): 
            $catName = $cat['category'];
            $catTotal = (float)$cat['total_amount'];
            $style = $categoryStyles[$catName] ?? ['icon' => 'bi-wallet2', 'gradient' => 'linear-gradient(135deg, #64748B, #475569)'];
        ?>
        <div class="col-6 col-md-4 col-lg-3">
            <div class="category-card" style="padding: 16px; border: 1px solid var(--ios-separator); box-shadow: 0 4px 12px rgba(0,0,0,0.03); background: var(--ios-surface);">
                <div class="d-flex align-items-center gap-2">
                    <div style="width: 42px; height: 42px; border-radius: 12px; background: <?php echo $style['gradient']; ?>; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; flex-shrink: 0; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
                        <i class="bi <?php echo $style['icon']; ?>"></i>
                    </div>
                    <div style="min-width: 0; flex-grow: 1;">
                        <div class="text-truncate" style="font-size: 0.72rem; font-weight: 700; color: var(--ios-label-2); text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 2px;" title="<?php echo htmlspecialchars($catName); ?>">
                            <?php echo htmlspecialchars($catName); ?>
                        </div>
                        <div style="font-size: 1.15rem; font-weight: 800; color: var(--ios-label);">
                            Rs <?php echo number_format($catTotal, 2); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="dash-card py-4 text-center" style="background: var(--ios-surface-2); border: 1px dashed var(--ios-separator);">
        <span class="text-muted fw-bold small"><i class="bi bi-info-circle me-1"></i> No expenditures recorded in any category this month.</span>
    </div>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="dash-card mb-4" style="background: var(--ios-surface-2);">
    <div class="p-3">
        <form method="GET" action="" id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="ios-label-sm">Search Description/Ref</label>
                <div class="ios-search-wrapper">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" id="searchInput" class="ios-input" placeholder="Search..." value="<?php echo htmlspecialchars($search_query); ?>" oninput="debounceSearch()">
                </div>
            </div>
            <div class="col-md-3">
                <label class="ios-label-sm">Filter by Category</label>
                <select name="category" class="form-select" onchange="document.getElementById('filterForm').submit();">
                    <option value="">All Categories</option>
                    <?php foreach($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category_filter === $cat ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="ios-label-sm text-primary">View Month</label>
                <input type="month" name="month" class="ios-input fw-bold" style="border-color: #0055CC; color: #0055CC;" value="<?php echo htmlspecialchars($month_filter); ?>" onchange="document.getElementById('filterForm').submit();">
            </div>
            <div class="col-md-2">
                <button type="submit" class="quick-btn quick-btn-secondary w-100" style="min-height: 42px;">
                    <i class="bi bi-funnel-fill"></i> Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Expenses Table -->
<div class="dash-card mb-4 overflow-hidden">
    <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
        <span class="card-title">
            <span class="card-title-icon" style="background: rgba(255,59,48,0.1); color: #CC2200;">
                <i class="bi bi-wallet2"></i>
            </span>
            Expense Ledger
        </span>
    </div>
    <div class="table-responsive">
        <table class="ios-table text-center" style="margin: 0;">
            <thead>
                <tr class="table-ios-header">
                    <th class="text-start ps-4" style="width: 15%;">Date</th>
                    <th class="text-start" style="width: 25%;">Category</th>
                    <th class="text-start" style="width: 30%;">Description & Ref</th>
                    <th style="width: 10%;">Method</th>
                    <th class="text-end" style="width: 10%;">Amount (Rs)</th>
                    <th class="text-end pe-4" style="width: 10%;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($expenses as $e): ?>
                <tr>
                    <td class="text-start ps-4">
                        <div style="font-weight: 700; font-size: 0.95rem; color: var(--ios-label);">
                            <?php echo date('M d, Y', strtotime($e['expense_date'])); ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--ios-label-3); margin-top: 2px;">
                            Added by: <?php echo htmlspecialchars($e['user_name'] ?: 'System'); ?>
                        </div>
                    </td>
                    <td class="text-start">
                        <span class="ios-badge gray px-2 py-1"><?php echo htmlspecialchars($e['category']); ?></span>
                        <?php if ($e['source'] == 'route'): ?>
                            <span class="ios-badge orange outline ms-1" style="border-color: #ff9500; color: #ff9500;"><i class="bi bi-truck"></i> Route</span>
                        <?php else: ?>
                            <span class="ios-badge blue outline ms-1" style="border-color: #007aff; color: #007aff;"><i class="bi bi-building"></i> General</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-start">
                        <div style="font-weight: 600; font-size: 0.9rem; color: var(--ios-label);">
                            <?php echo htmlspecialchars($e['description']); ?>
                        </div>
                        <?php if($e['reference']): ?>
                            <div style="font-size: 0.75rem; color: var(--ios-label-2); margin-top: 4px; font-family: monospace;">
                                <i class="bi bi-upc-scan"></i> Ref: <?php echo htmlspecialchars($e['reference']); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($e['payment_method'] == 'Cash'): ?>
                            <span class="ios-badge green outline" style="border-color: #1A9A3A; color: #1A9A3A;"><i class="bi bi-cash-stack"></i> Cash</span>
                        <?php else: ?>
                            <span class="ios-badge blue outline"><i class="bi bi-bank"></i> Bank</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end" style="font-weight: 800; font-size: 1.05rem; color: #CC2200;">
                        <?php echo number_format($e['amount'], 2); ?>
                    </td>
                    <td class="text-end pe-4">
                        <form method="POST" class="d-inline" onsubmit="return confirm('WARNING: Are you sure you want to delete this expense? <?php echo $e['source'] == 'general' ? 'The funds will be returned to your company accounts.' : ''; ?>');">
                            <input type="hidden" name="action" value="delete_expense">
                            <input type="hidden" name="expense_id" value="<?php echo $e['id']; ?>">
                            <input type="hidden" name="expense_source" value="<?php echo $e['source']; ?>">
                            <button type="submit" class="quick-btn quick-btn-ghost" style="padding: 6px 10px; background: rgba(255,59,48,0.1); color: #CC2200;" title="Delete Expense">
                                <i class="bi bi-trash3-fill"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($expenses)): ?>
                <tr>
                    <td colspan="6">
                        <div class="empty-state py-5">
                            <i class="bi bi-wallet2" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2" style="font-weight: 500;">No expenses recorded for this month.</p>
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
        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search_query); ?>&category=<?php echo urlencode($category_filter); ?>&month=<?php echo $month_filter; ?>"><?php echo $i; ?></a>
    </li>
    <?php endfor; ?>
</ul>
<?php endif; ?>

<!-- Add Expense Modal -->
<div class="modal fade" id="addExpenseModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem; color: #CC2200;"><i class="bi bi-wallet2 me-2"></i>Record Company Expense</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="add_expense">
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Date of Expense <span class="text-danger">*</span></label>
                            <input type="date" name="expense_date" class="ios-input fw-bold" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Category <span class="text-danger">*</span></label>
                            <select name="category" class="form-select fw-bold" required>
                                <option value="">-- Select Category --</option>
                                <?php foreach($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-4" style="background: rgba(0,0,0,0.03); border: 1px solid var(--ios-separator); border-radius: 12px; padding: 16px;">
                        <label class="ios-label-sm" style="color: var(--ios-label);"><i class="bi bi-calculator me-1"></i> Financial Details</label>
                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="ios-label-sm">Deduct From Account <span class="text-danger">*</span></label>
                                <select name="payment_method" class="form-select fw-bold border-dark" required>
                                    <option value="Cash">Cash on Hand</option>
                                    <option value="Bank">Company Bank Account</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="ios-label-sm">Amount (Rs) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" name="amount" class="ios-input fw-bold" style="font-size: 1.2rem; color: #CC2200; height: 48px;" required placeholder="0.00">
                            </div>
                        </div>
                        <div class="small text-muted mt-3" style="font-size: 0.75rem;"><i class="bi bi-info-circle-fill me-1"></i> This amount will be instantly deducted from the selected Master Ledger.</div>
                    </div>

                    <div class="mb-3">
                        <label class="ios-label-sm">Description / Purpose <span class="text-danger">*</span></label>
                        <input type="text" name="description" class="ios-input" required placeholder="e.g. Paid monthly electricity bill">
                    </div>
                    <div class="mb-4">
                        <label class="ios-label-sm">Reference / Receipt Number (Optional)</label>
                        <input type="text" name="reference" class="ios-input" style="font-family: monospace;" placeholder="e.g. INV-2023-11">
                    </div>
                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn px-4" style="background: #CC2200; color: #fff;" onclick="return confirm('Confirm Expense? This will deduct funds from your account.');">Save Expense</button>
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

function openAddModal() {
    new bootstrap.Modal(document.getElementById('addExpenseModal')).show();
}
</script>

<?php include '../includes/footer.php'; ?>