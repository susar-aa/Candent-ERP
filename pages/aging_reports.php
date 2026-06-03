<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';

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
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); // Restricted to Management and Finance

// --- 1. ACCOUNTS RECEIVABLE (AR) - Customers owing the company ---
$arQuery = "
    SELECT 
        c.id, c.name, c.phone,
        SUM(o.total_amount - o.paid_amount) as total_outstanding,
        SUM(CASE WHEN DATEDIFF(CURDATE(), o.created_at) <= 14 THEN (o.total_amount - o.paid_amount) ELSE 0 END) as age_0_14,
        SUM(CASE WHEN DATEDIFF(CURDATE(), o.created_at) BETWEEN 15 AND 30 THEN (o.total_amount - o.paid_amount) ELSE 0 END) as age_15_30,
        SUM(CASE WHEN DATEDIFF(CURDATE(), o.created_at) BETWEEN 31 AND 60 THEN (o.total_amount - o.paid_amount) ELSE 0 END) as age_31_60,
        SUM(CASE WHEN DATEDIFF(CURDATE(), o.created_at) BETWEEN 61 AND 90 THEN (o.total_amount - o.paid_amount) ELSE 0 END) as age_61_90,
        SUM(CASE WHEN DATEDIFF(CURDATE(), o.created_at) > 90 THEN (o.total_amount - o.paid_amount) ELSE 0 END) as age_90_plus
    FROM orders o
    JOIN customers c ON o.customer_id = c.id
    WHERE o.payment_status != 'paid' AND o.total_amount > o.paid_amount
    GROUP BY c.id
    HAVING total_outstanding > 0
    ORDER BY total_outstanding DESC
";
$ar_records = $pdo->query($arQuery)->fetchAll();

$ar_totals = [
    'total' => 0, 'age_0_14' => 0, 'age_15_30' => 0, 'age_31_60' => 0, 'age_61_90' => 0, 'age_90_plus' => 0
];
foreach ($ar_records as $ar) {
    $ar_totals['total'] += $ar['total_outstanding'];
    $ar_totals['age_0_14'] += $ar['age_0_14'];
    $ar_totals['age_15_30'] += $ar['age_15_30'];
    $ar_totals['age_31_60'] += $ar['age_31_60'];
    $ar_totals['age_61_90'] += $ar['age_61_90'];
    $ar_totals['age_90_plus'] += $ar['age_90_plus'];
}

// --- 2. ACCOUNTS PAYABLE (AP) - Company owing suppliers ---
$apQuery = "
    SELECT 
        s.id, s.company_name as name, s.phone,
        SUM(g.total_amount - g.paid_amount) as total_outstanding,
        SUM(CASE WHEN DATEDIFF(CURDATE(), g.grn_date) <= 14 THEN (g.total_amount - g.paid_amount) ELSE 0 END) as age_0_14,
        SUM(CASE WHEN DATEDIFF(CURDATE(), g.grn_date) BETWEEN 15 AND 30 THEN (g.total_amount - g.paid_amount) ELSE 0 END) as age_15_30,
        SUM(CASE WHEN DATEDIFF(CURDATE(), g.grn_date) BETWEEN 31 AND 60 THEN (g.total_amount - g.paid_amount) ELSE 0 END) as age_31_60,
        SUM(CASE WHEN DATEDIFF(CURDATE(), g.grn_date) BETWEEN 61 AND 90 THEN (g.total_amount - g.paid_amount) ELSE 0 END) as age_61_90,
        SUM(CASE WHEN DATEDIFF(CURDATE(), g.grn_date) > 90 THEN (g.total_amount - g.paid_amount) ELSE 0 END) as age_90_plus
    FROM grns g
    JOIN suppliers s ON g.supplier_id = s.id
    WHERE g.payment_status != 'paid' AND g.total_amount > g.paid_amount
    GROUP BY s.id
    HAVING total_outstanding > 0
    ORDER BY total_outstanding DESC
";
$ap_records = $pdo->query($apQuery)->fetchAll();

$ap_totals = [
    'total' => 0, 'age_0_14' => 0, 'age_15_30' => 0, 'age_31_60' => 0, 'age_61_90' => 0, 'age_90_plus' => 0
];
foreach ($ap_records as $ap) {
    $ap_totals['total'] += $ap['total_outstanding'];
    $ap_totals['age_0_14'] += $ap['age_0_14'];
    $ap_totals['age_15_30'] += $ap['age_15_30'];
    $ap_totals['age_31_60'] += $ap['age_31_60'];
    $ap_totals['age_61_90'] += $ap['age_61_90'];
    $ap_totals['age_90_plus'] += $ap['age_90_plus'];
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
    <h1 class="h2"><i class="bi bi-calendar3-range text-danger"></i> Aging Reports (AR & AP)</h1>
    <button onclick="window.print()" class="btn btn-outline-secondary shadow-sm fw-bold px-4 rounded-pill no-print">
        <i class="bi bi-printer"></i> Print Report
    </button>
</div>

<!-- Key Performance Indicators -->
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100 bg-success bg-opacity-10 border-start border-success border-4">
            <div class="card-body">
                <div class="text-success small fw-bold text-uppercase mb-1"><i class="bi bi-arrow-down-left-square"></i> Accounts Receivable (AR)</div>
                <h3 class="fw-bold text-dark mb-0">Rs <?php echo number_format($ar_totals['total'], 2); ?></h3>
                <div class="small text-muted mt-1">Total money owed to you by customers</div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100 bg-danger bg-opacity-10 border-start border-danger border-4">
            <div class="card-body">
                <div class="text-danger small fw-bold text-uppercase mb-1"><i class="bi bi-arrow-up-right-square"></i> Accounts Payable (AP)</div>
                <h3 class="fw-bold text-dark mb-0">Rs <?php echo number_format($ap_totals['total'], 2); ?></h3>
                <div class="small text-muted mt-1">Total money you owe to suppliers</div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <?php $net_liquidity = $ar_totals['total'] - $ap_totals['total']; ?>
        <div class="card border-0 shadow-sm h-100 <?php echo $net_liquidity >= 0 ? 'bg-primary bg-opacity-10 border-primary' : 'bg-warning bg-opacity-10 border-warning'; ?> border-start border-4">
            <div class="card-body">
                <div class="<?php echo $net_liquidity >= 0 ? 'text-primary' : 'text-warning text-dark'; ?> small fw-bold text-uppercase mb-1"><i class="bi bi-scales"></i> Net Debt Liquidity</div>
                <h3 class="fw-bold text-dark mb-0">Rs <?php echo number_format($net_liquidity, 2); ?></h3>
                <div class="small text-muted mt-1">Difference between AR and AP</div>
            </div>
        </div>
    </div>
</div>

<!-- Charts Section -->
<div class="row g-4 mb-4 no-print">
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-3 pb-0 px-4">
                <h6 class="fw-bold text-success m-0"><i class="bi bi-pie-chart-fill"></i> AR Aging Distribution</h6>
            </div>
            <div class="card-body d-flex justify-content-center" style="position: relative; height: 280px;">
                <?php if($ar_totals['total'] > 0): ?>
                    <canvas id="arChart"></canvas>
                <?php else: ?>
                    <div class="text-muted d-flex align-items-center"><i class="bi bi-check-circle fs-3 me-2 text-success"></i> No outstanding receivables.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-3 pb-0 px-4">
                <h6 class="fw-bold text-danger m-0"><i class="bi bi-pie-chart-fill"></i> AP Aging Distribution</h6>
            </div>
            <div class="card-body d-flex justify-content-center" style="position: relative; height: 280px;">
                <?php if($ap_totals['total'] > 0): ?>
                    <canvas id="apChart"></canvas>
                <?php else: ?>
                    <div class="text-muted d-flex align-items-center"><i class="bi bi-check-circle fs-3 me-2 text-success"></i> No outstanding payables.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Tabs Navigation -->
<ul class="nav nav-tabs mb-4 fw-bold" id="agingTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active text-success" id="ar-tab" data-bs-toggle="tab" data-bs-target="#ar" type="button" role="tab">
            Accounts Receivable (Customers)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link text-danger" id="ap-tab" data-bs-toggle="tab" data-bs-target="#ap" type="button" role="tab">
            Accounts Payable (Suppliers)
        </button>
    </li>
</ul>

<!-- Tabs Content -->
<div class="tab-content" id="agingTabsContent">
    
    <!-- AR TAB -->
    <div class="tab-pane fade show active" id="ar" role="tabpanel">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="table-responsive bg-white rounded-4 p-2">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 25%;">Customer</th>
                            <th class="text-center" style="width: 13%;">0-14 Days</th>
                            <th class="text-center" style="width: 13%;">15-30 Days</th>
                            <th class="text-center" style="width: 13%;">31-60 Days</th>
                            <th class="text-center text-warning" style="width: 13%;">61-90 Days</th>
                            <th class="text-center text-danger" style="width: 13%;">90+ Days</th>
                            <th class="text-end" style="width: 15%;">Total Ows</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($ar_records as $ar): ?>
                        <tr>
                            <td>
                                <a href="view_customer.php?id=<?php echo $ar['id']; ?>" class="fw-bold text-dark text-decoration-none">
                                    <?php echo htmlspecialchars($ar['name']); ?> <i class="bi bi-box-arrow-up-right text-primary small"></i>
                                </a>
                                <div class="small text-muted"><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($ar['phone'] ?: 'N/A'); ?></div>
                            </td>
                            <td class="text-center fw-medium"><?php echo $ar['age_0_14'] > 0 ? number_format($ar['age_0_14'], 2) : '-'; ?></td>
                            <td class="text-center fw-medium"><?php echo $ar['age_15_30'] > 0 ? number_format($ar['age_15_30'], 2) : '-'; ?></td>
                            <td class="text-center fw-medium"><?php echo $ar['age_31_60'] > 0 ? number_format($ar['age_31_60'], 2) : '-'; ?></td>
                            <td class="text-center fw-bold text-warning text-dark"><?php echo $ar['age_61_90'] > 0 ? number_format($ar['age_61_90'], 2) : '-'; ?></td>
                            <td class="text-center fw-bold text-danger"><?php echo $ar['age_90_plus'] > 0 ? number_format($ar['age_90_plus'], 2) : '-'; ?></td>
                            <td class="text-end fw-bold text-success fs-6">Rs <?php echo number_format($ar['total_outstanding'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if(empty($ar_records)): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted">No outstanding accounts receivable found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if(!empty($ar_records)): ?>
                    <tfoot class="table-light fw-bold fs-6">
                        <tr>
                            <td class="text-end text-uppercase">Grand Totals:</td>
                            <td class="text-center">Rs <?php echo number_format($ar_totals['age_0_14'], 2); ?></td>
                            <td class="text-center">Rs <?php echo number_format($ar_totals['age_15_30'], 2); ?></td>
                            <td class="text-center">Rs <?php echo number_format($ar_totals['age_31_60'], 2); ?></td>
                            <td class="text-center text-warning text-dark">Rs <?php echo number_format($ar_totals['age_61_90'], 2); ?></td>
                            <td class="text-center text-danger">Rs <?php echo number_format($ar_totals['age_90_plus'], 2); ?></td>
                            <td class="text-end text-success">Rs <?php echo number_format($ar_totals['total'], 2); ?></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- AP TAB -->
    <div class="tab-pane fade" id="ap" role="tabpanel">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="table-responsive bg-white rounded-4 p-2">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 25%;">Supplier</th>
                            <th class="text-center" style="width: 13%;">0-14 Days</th>
                            <th class="text-center" style="width: 13%;">15-30 Days</th>
                            <th class="text-center" style="width: 13%;">31-60 Days</th>
                            <th class="text-center text-warning" style="width: 13%;">61-90 Days</th>
                            <th class="text-center text-danger" style="width: 13%;">90+ Days</th>
                            <th class="text-end" style="width: 15%;">Total Payable</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($ap_records as $ap): ?>
                        <tr>
                            <td>
                                <a href="view_supplier.php?id=<?php echo $ap['id']; ?>" class="fw-bold text-dark text-decoration-none">
                                    <?php echo htmlspecialchars($ap['name']); ?> <i class="bi bi-box-arrow-up-right text-primary small"></i>
                                </a>
                                <div class="small text-muted"><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($ap['phone'] ?: 'N/A'); ?></div>
                            </td>
                            <td class="text-center fw-medium"><?php echo $ap['age_0_14'] > 0 ? number_format($ap['age_0_14'], 2) : '-'; ?></td>
                            <td class="text-center fw-medium"><?php echo $ap['age_15_30'] > 0 ? number_format($ap['age_15_30'], 2) : '-'; ?></td>
                            <td class="text-center fw-medium"><?php echo $ap['age_31_60'] > 0 ? number_format($ap['age_31_60'], 2) : '-'; ?></td>
                            <td class="text-center fw-bold text-warning text-dark"><?php echo $ap['age_61_90'] > 0 ? number_format($ap['age_61_90'], 2) : '-'; ?></td>
                            <td class="text-center fw-bold text-danger"><?php echo $ap['age_90_plus'] > 0 ? number_format($ap['age_90_plus'], 2) : '-'; ?></td>
                            <td class="text-end fw-bold text-danger fs-6">Rs <?php echo number_format($ap['total_outstanding'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if(empty($ap_records)): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted">No outstanding accounts payable found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if(!empty($ap_records)): ?>
                    <tfoot class="table-light fw-bold fs-6">
                        <tr>
                            <td class="text-end text-uppercase">Grand Totals:</td>
                            <td class="text-center">Rs <?php echo number_format($ap_totals['age_0_14'], 2); ?></td>
                            <td class="text-center">Rs <?php echo number_format($ap_totals['age_15_30'], 2); ?></td>
                            <td class="text-center">Rs <?php echo number_format($ap_totals['age_31_60'], 2); ?></td>
                            <td class="text-center text-warning text-dark">Rs <?php echo number_format($ap_totals['age_61_90'], 2); ?></td>
                            <td class="text-center text-danger">Rs <?php echo number_format($ap_totals['age_90_plus'], 2); ?></td>
                            <td class="text-end text-danger">Rs <?php echo number_format($ap_totals['total'], 2); ?></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
    
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    
    const chartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '60%',
        plugins: {
            legend: { position: 'right', labels: { padding: 15, usePointStyle: true } },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return ' ' + context.label + ': Rs ' + context.parsed.toLocaleString(undefined, {minimumFractionDigits: 2});
                    }
                }
            }
        }
    };

    const bgColors = ['#198754', '#20c997', '#0dcaf0', '#ffc107', '#dc3545']; // Green, Teal, Info, Warning, Danger

    <?php if($ar_totals['total'] > 0): ?>
    new Chart(document.getElementById('arChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: ['0-14 Days', '15-30 Days', '31-60 Days', '61-90 Days', '90+ Days Critical'],
            datasets: [{
                data: [
                    <?php echo $ar_totals['age_0_14']; ?>,
                    <?php echo $ar_totals['age_15_30']; ?>,
                    <?php echo $ar_totals['age_31_60']; ?>,
                    <?php echo $ar_totals['age_61_90']; ?>,
                    <?php echo $ar_totals['age_90_plus']; ?>
                ],
                backgroundColor: bgColors,
                borderWidth: 2,
                hoverOffset: 5
            }]
        },
        options: chartOptions
    });
    <?php endif; ?>

    <?php if($ap_totals['total'] > 0): ?>
    new Chart(document.getElementById('apChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: ['0-14 Days', '15-30 Days', '31-60 Days', '61-90 Days', '90+ Days Critical'],
            datasets: [{
                data: [
                    <?php echo $ap_totals['age_0_14']; ?>,
                    <?php echo $ap_totals['age_15_30']; ?>,
                    <?php echo $ap_totals['age_31_60']; ?>,
                    <?php echo $ap_totals['age_61_90']; ?>,
                    <?php echo $ap_totals['age_90_plus']; ?>
                ],
                backgroundColor: bgColors,
                borderWidth: 2,
                hoverOffset: 5
            }]
        },
        options: chartOptions
    });
    <?php endif; ?>
});
</script>

<?php include '../includes/footer.php'; ?>