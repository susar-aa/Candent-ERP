<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: text/html');

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

// Get parameters
$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

if (!$employee_id) {
    // Select employee dashboard
    $employees = $pdo->query("SELECT id, emp_code, name, designation FROM employees WHERE status='active' ORDER BY name ASC")->fetchAll();
    include '../includes/header.php';
    include '../includes/sidebar.php';
    ?>
    <div class="page-header">
        <div>
            <h1 class="h2">Personnel Salary Reports</h1>
            <div class="page-subtitle">Select an employee and a month to generate a detailed audit report.</div>
        </div>
    </div>
    
    <div class="dash-card mb-4 overflow-hidden" style="max-width: 500px; margin: 20px auto; background: var(--ios-surface-2); border-radius: 16px;">
        <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
            <span class="card-title">
                <span class="card-title-icon" style="background: rgba(0,122,255,0.1); color: #0055CC;">
                    <i class="bi bi-person-badge-fill"></i>
                </span>
                Salary Report Generator
            </span>
        </div>
        <div class="p-4 bg-white">
            <form method="GET" class="m-0">
                <div class="mb-3">
                    <label class="ios-label-sm" style="font-weight: 700; color: var(--ios-label-2);">Select Employee</label>
                    <select name="employee_id" class="form-select fw-bold" style="border: 1px solid #C7C7CC; border-radius: 8px; padding: 10px;" required>
                        <option value="" disabled selected>-- Choose Personnel --</option>
                        <?php foreach ($employees as $e): ?>
                            <option value="<?php echo $e['id']; ?>">
                                <?php echo htmlspecialchars($e['name']); ?> (<?php echo htmlspecialchars($e['emp_code']); ?>) - <?php echo htmlspecialchars($e['designation']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="ios-label-sm" style="font-weight: 700; color: var(--ios-label-2);">Salary Month</label>
                    <input type="month" name="month" class="ios-input fw-bold" style="border: 1px solid #C7C7CC; border-radius: 8px; padding: 10px;" value="<?php echo htmlspecialchars($selected_month); ?>" required>
                </div>
                <button type="submit" class="quick-btn w-100 py-2" style="background: var(--accent); color: white; border: none; font-weight: 700;">
                    Generate Report <i class="bi bi-chevron-right ms-1"></i>
                </button>
            </form>
        </div>
    </div>
    <?php
    include '../includes/footer.php';
    exit;
}

// Fetch employee details
$empStmt = $pdo->prepare('SELECT id, name, daily_rate FROM employees WHERE id = ?');
$empStmt->execute([$employee_id]);
$employee = $empStmt->fetch(PDO::FETCH_ASSOC);
if (!$employee) {
    die('<div class="alert alert-danger">Employee not found.</div>');
}

$message = '';

// Calculate dates for processing
$rangeStart = $selected_month . '-01';
$rangeEnd = date('Y-m-t', strtotime($rangeStart));

// POST Actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'generate_single_payroll') {
        try {
            // Route days count (unique dates where employee had a route as driver or rep)
            $routeDaysStmt = $pdo->prepare("SELECT COUNT(DISTINCT rr.assign_date) AS route_days FROM rep_routes rr
                WHERE (rr.driver_id = :emp_id_drv OR rr.rep_id = (SELECT user_id FROM employees WHERE id = :emp_id_rep1) OR rr.rep_id IN (SELECT id FROM users WHERE employee_id = :emp_id_rep2))
                AND rr.assign_date BETWEEN :start AND :end");
            $routeDaysStmt->execute([
                'emp_id_drv' => $employee_id,
                'emp_id_rep1' => $employee_id,
                'emp_id_rep2' => $employee_id,
                'start' => $rangeStart,
                'end' => $rangeEnd
            ]);
            $routeDays = (int)$routeDaysStmt->fetchColumn();

            // Office attendance days (present=1, half_day=0.5) excluding route days to avoid double counting
            $attStmt = $pdo->prepare("SELECT SUM(CASE WHEN status='present' THEN 1 WHEN status='half_day' THEN 0.5 ELSE 0 END) FROM attendance
                WHERE employee_id = :emp_id AND work_date BETWEEN :start AND :end
                AND work_date NOT IN (
                    SELECT DISTINCT rr.assign_date FROM rep_routes rr
                    WHERE (rr.driver_id = :emp_id_drv OR rr.rep_id = (SELECT user_id FROM employees WHERE id = :emp_id_rep1) OR rr.rep_id IN (SELECT id FROM users WHERE employee_id = :emp_id_rep2))
                    AND rr.assign_date BETWEEN :sub_start AND :sub_end
                )");
            $attStmt->execute([
                'emp_id' => $employee_id,
                'start' => $rangeStart,
                'end' => $rangeEnd,
                'emp_id_drv' => $employee_id,
                'emp_id_rep1' => $employee_id,
                'emp_id_rep2' => $employee_id,
                'sub_start' => $rangeStart,
                'sub_end' => $rangeEnd
            ]);
            $officeDays = (float)$attStmt->fetchColumn();

            $totalDays = $routeDays + $officeDays;
            $basic_pay = $totalDays * $employee['daily_rate'];

            $stmt = $pdo->prepare("INSERT INTO payroll (employee_id, month, days_worked, basic_pay, net_pay) VALUES (?, ?, ?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE days_worked = VALUES(days_worked), basic_pay = VALUES(basic_pay), net_pay = basic_pay + bonus - deduction");
            $stmt->execute([$employee_id, $selected_month, $totalDays, $basic_pay, $basic_pay]);
            
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A; border-radius:12px; padding:12px; margin-bottom:15px;'><i class='bi bi-check-circle-fill me-2'></i> Payroll generated successfully!</div>";
        } catch (Exception $e) {
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200; border-radius:12px; padding:12px; margin-bottom:15px;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error generating payroll: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }

    if ($_POST['action'] == 'save_single_payroll') {
        $bonus = (float)$_POST['bonus'];
        $deduction = (float)$_POST['deduction'];
        try {
            $pdo->prepare("UPDATE payroll SET bonus=?, deduction=?, net_pay = (basic_pay + ? - ?) WHERE employee_id=? AND month=?")
                ->execute([$bonus, $deduction, $bonus, $deduction, $employee_id, $selected_month]);
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A; border-radius:12px; padding:12px; margin-bottom:15px;'><i class='bi bi-check-circle-fill me-2'></i> Payroll adjustments updated!</div>";
        } catch (Exception $e) {
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200; border-radius:12px; padding:12px; margin-bottom:15px;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error saving adjustments: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }

    if ($_POST['action'] == 'pay_single_payroll') {
        $method = $_POST['payment_method']; // Cash or Bank Transfer
        try {
            $pdo->beginTransaction();
            $pStmt = $pdo->prepare("SELECT p.id, p.net_pay, p.month, e.name FROM payroll p JOIN employees e ON p.employee_id = e.id WHERE p.employee_id = ? AND p.month = ? FOR UPDATE");
            $pStmt->execute([$employee_id, $selected_month]);
            $pay = $pStmt->fetch();

            if ($pay) {
                // Mark Paid
                $pdo->prepare("UPDATE payroll SET status='paid', payment_method=? WHERE id=?")->execute([$method, $pay['id']]);
                
                // Deduct from Company Finances
                $desc = "Salary: {$pay['name']} ({$pay['month']})";
                if ($method == 'Cash') {
                    $pdo->prepare("UPDATE company_finances SET cash_on_hand = cash_on_hand - ? WHERE id = 1")->execute([$pay['net_pay']]);
                    $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('cash_out', ?, ?, ?)")->execute([$pay['net_pay'], $desc, $_SESSION['user_id']]);
                } else {
                    $pdo->prepare("UPDATE company_finances SET bank_balance = bank_balance - ? WHERE id = 1")->execute([$pay['net_pay']]);
                    $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('bank_out', ?, ?, ?)")->execute([$pay['net_pay'], $desc, $_SESSION['user_id']]);
                }
                $pdo->commit();
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A; border-radius:12px; padding:12px; margin-bottom:15px;'><i class='bi bi-check-circle-fill me-2'></i> Salary paid and deducted from account!</div>";
            } else {
                $pdo->rollBack();
                $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200; border-radius:12px; padding:12px; margin-bottom:15px;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Payroll record not found.</div>";
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200; border-radius:12px; padding:12px; margin-bottom:15px;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error processing payment: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// Build page
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    /* Calendar styles – same as employee history calendar */
    .col-7th { width:14.285%; flex:0 0 14.285%; max-width:14.285%; }
    .calendar-day-cell {
        min-height:110px; border-right:1px solid var(--ios-separator); border-bottom:1px solid var(--ios-separator);
        position:relative; padding:8px; background:#fff; transition:background .15s ease;
        display:flex; flex-direction:column; justify-content:flex-start; align-items:flex-start; cursor:pointer;
    }
    .calendar-day-cell:hover { background:rgba(0,122,255,0.03); }
    .calendar-day-number { font-size:.85rem; font-weight:700; color:var(--ios-label-2); align-self:flex-end; margin-bottom:4px; }
    .calendar-day-cell.other-month { background:#F8F9FA; opacity:0.4; cursor:not-allowed; }
    .calendar-day-cell.today { background:rgba(48,200,138,0.05); }
    .calendar-day-cell.today .calendar-day-number { color:var(--accent); font-weight:800; background:rgba(48,200,138,0.12);
        width:22px; height:22px; border-radius:50%; display:flex; align-items:center; justify-content:center; }
    .calendar-event-badge { font-size:.72rem; font-weight:600; padding:4px 6px; border-radius:6px; margin-top:4px; border:1px solid transparent;
        width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; text-align:left; }
    .calendar-event-badge.route { background:rgba(0,122,255,0.08); border-color:rgba(0,122,255,0.2); color:#0055CC; }
    .calendar-event-badge.present { background:rgba(52,199,89,0.08); border-color:rgba(52,199,89,0.2); color:#1A9A3A; }
    .calendar-event-badge.half-day { background:rgba(255,204,0,0.08); border-color:rgba(255,204,0,0.2); color:#B38600; }
    .calendar-event-badge.absent { background:rgba(255,59,48,0.08); border-color:rgba(255,59,48,0.2); color:#CC2200; }
    .quick-log-btn { opacity:0; transition:opacity .2s ease; margin-top:auto; font-size:.7rem; color:var(--ios-label-2); font-weight:600; width:100%; text-align:center; }
    .calendar-day-cell:not(.other-month):hover .quick-log-btn { opacity:1; }
    
    .print-only { display: none !important; }
    
    @media print {
        body, html, #mainContent, .main-content-wrapper {
            background: #ffffff !important;
            color: #000000 !important;
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
        }
        /* Hide sidebars, top headers, footer, alert messages and action buttons */
        #sidebarMenu, .sidebar, .navbar, .top-bar, .page-header, .print-hide, .btn, .quick-btn, .modal, .modal-backdrop, .ios-alert, .quick-btn-primary, .quick-btn-secondary {
            display: none !important;
        }
        .print-only {
            display: block !important;
        }
        /* Page layout */
        @page {
            size: A4 portrait;
            margin: 12mm 12mm 15mm 12mm;
        }
        /* Fix row/column systems */
        .row {
            display: flex !important;
            flex-wrap: wrap !important;
            width: 100% !important;
            margin: 0 !important;
        }
        .col-md-3, .col-6 {
            width: 25% !important;
            flex: 0 0 25% !important;
            max-width: 25% !important;
            box-sizing: border-box !important;
        }
        .col-md-6 {
            width: 50% !important;
            flex: 0 0 50% !important;
            max-width: 50% !important;
            box-sizing: border-box !important;
        }
        .col-7th {
            width: 14.285% !important;
            flex: 0 0 14.285% !important;
            max-width: 14.285% !important;
            box-sizing: border-box !important;
        }
        .calendar-day-cell {
            min-height: 80px !important;
            border: 1px solid #C7C7CC !important;
            background: #ffffff !important;
            page-break-inside: avoid !important;
        }
        .calendar-day-cell.other-month {
            background: #F2F2F7 !important;
            opacity: 0.4 !important;
        }
        .quick-log-btn {
            display: none !important;
        }
        .calendar-event-badge {
            font-size: .6rem !important;
            padding: 2px 4px !important;
            border: 1px solid #C7C7CC !important;
            background: #ffffff !important;
            color: #000000 !important;
            margin-top: 2px !important;
        }
        .dash-card {
            border: 1px solid #C7C7CC !important;
            box-shadow: none !important;
            page-break-inside: avoid !important;
            background: #ffffff !important;
        }
        .rounded-3 {
            border: 1px solid #C7C7CC !important;
            box-shadow: none !important;
            background: #ffffff !important;
        }
    }
</style>

<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1 class="h2">Salary Report – <?php echo htmlspecialchars($employee['name']); ?></h1>
        <div class="page-subtitle">Detailed salary calculation for the selected month.</div>
    </div>
    <div class="page-header-actions print-hide">
        <button onclick="window.print()" class="quick-btn py-2 px-3 fw-bold" style="background: var(--accent); color: white; border: none; font-size: 0.9rem; display: flex; align-items: center; gap: 8px; border-radius: 8px;">
            <i class="bi bi-printer-fill"></i> Export PDF / Print
        </button>
    </div>
</div>

<?php echo $message; ?>

<?php
// Summary cards
// We'll compute totals client‑side, but for a quick preview we can pre‑calculate route days via SQL
$rangeStart = $selected_month . '-01';
$rangeEnd = date('Y-m-t', strtotime($rangeStart));

// Route days count (unique dates where employee had a route as driver or rep)
$routeDaysStmt = $pdo->prepare("SELECT COUNT(DISTINCT rr.assign_date) AS route_days FROM rep_routes rr
    WHERE (rr.driver_id = :emp_id_drv OR rr.rep_id = (SELECT user_id FROM employees WHERE id = :emp_id_rep1) OR rr.rep_id IN (SELECT id FROM users WHERE employee_id = :emp_id_rep2))
    AND rr.assign_date BETWEEN :start AND :end");
$routeDaysStmt->execute([
    'emp_id_drv' => $employee_id,
    'emp_id_rep1' => $employee_id,
    'emp_id_rep2' => $employee_id,
    'start' => $rangeStart,
    'end' => $rangeEnd
]);
$routeDays = (int)$routeDaysStmt->fetchColumn();

// Office attendance days (present=1, half_day=0.5) excluding route days to avoid double counting
$attStmt = $pdo->prepare("SELECT SUM(CASE WHEN status='present' THEN 1 WHEN status='half_day' THEN 0.5 ELSE 0 END) FROM attendance
    WHERE employee_id = :emp_id AND work_date BETWEEN :start AND :end
    AND work_date NOT IN (
        SELECT DISTINCT rr.assign_date FROM rep_routes rr
        WHERE (rr.driver_id = :emp_id_drv OR rr.rep_id = (SELECT user_id FROM employees WHERE id = :emp_id_rep1) OR rr.rep_id IN (SELECT id FROM users WHERE employee_id = :emp_id_rep2))
        AND rr.assign_date BETWEEN :sub_start AND :sub_end
    )");
$attStmt->execute([
    'emp_id' => $employee_id,
    'start' => $rangeStart,
    'end' => $rangeEnd,
    'emp_id_drv' => $employee_id,
    'emp_id_rep1' => $employee_id,
    'emp_id_rep2' => $employee_id,
    'sub_start' => $rangeStart,
    'sub_end' => $rangeEnd
]);
$officeDays = (float)$attStmt->fetchColumn();

$totalDays = $routeDays + $officeDays;
$totalPay = $totalDays * $employee['daily_rate'];

// Fetch payroll record
$payStmt = $pdo->prepare("SELECT * FROM payroll WHERE employee_id = ? AND month = ?");
$payStmt->execute([$employee_id, $selected_month]);
$payroll = $payStmt->fetch(PDO::FETCH_ASSOC);
?>

<div class="row g-3 mb-4 print-hide">
    <div class="col-md-3 col-6">
        <div class="p-3 rounded-3 border bg-white shadow-sm">
            <div style="font-size:0.72rem; font-weight:700; color:var(--ios-label-2); text-transform:uppercase; margin-bottom:2px;">Route Days</div>
            <div id="statRouteDays" style="font-size:1.4rem; font-weight:800; color:#007AFF;"><?php echo $routeDays; ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="p-3 rounded-3 border bg-white shadow-sm">
            <div style="font-size:0.72rem; font-weight:700; color:var(--ios-label-2); text-transform:uppercase; margin-bottom:2px;">Office Days</div>
            <div id="statOfficeDays" style="font-size:1.4rem; font-weight:800; color:#34C759;"><?php echo $officeDays; ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="p-3 rounded-3 border bg-white shadow-sm">
            <div style="font-size:0.72rem; font-weight:700; color:var(--ios-label-2); text-transform:uppercase; margin-bottom:2px;">Total Days</div>
            <div id="statTotalDays" style="font-size:1.4rem; font-weight:800; color:#AF52DE;"><?php echo $totalDays; ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="p-3 rounded-3 border bg-white shadow-sm">
            <div style="font-size:0.72rem; font-weight:700; color:var(--ios-label-2); text-transform:uppercase; margin-bottom:2px;">Calculated Salary</div>
            <div id="statSalary" style="font-size:1.4rem; font-weight:800; color:#1A9A3A;">Rs <?php echo number_format($totalPay,2); ?></div>
        </div>
    </div>
</div>

<div class="print-only payslip-container" style="border: 2px solid #000; padding: 25px; border-radius: 12px; margin-bottom: 30px; background: #fff; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #000;">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid #1A9A3A; padding-bottom: 15px; margin-bottom: 25px;">
        <div>
            <h1 style="font-size: 1.8rem; font-weight: 800; margin: 0; color: #1A9A3A; letter-spacing: -0.5px;">CANDENT ERP</h1>
            <p style="margin: 3px 0 0 0; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; color: #666;">Official Salary Statement</p>
        </div>
        <div style="text-align: right;">
            <h2 style="font-size: 1.2rem; font-weight: 700; margin: 0; color: #000;">PAY SLIP</h2>
            <p style="margin: 3px 0 0 0; font-size: 0.85rem; color: #333; font-weight: 600;">Statement Month: <?php echo date('F Y', strtotime($rangeStart)); ?></p>
        </div>
    </div>
    
    <div class="row" style="margin-bottom: 25px;">
        <div class="col-6">
            <h3 style="font-size: 0.85rem; font-weight: 700; text-transform: uppercase; color: #666; margin-bottom: 8px;">Employee Details</h3>
            <div style="font-size: 0.95rem; line-height: 1.6;">
                <strong>Name:</strong> <?php echo htmlspecialchars($employee['name']); ?><br>
                <strong>Designation:</strong> <?php echo htmlspecialchars($employee['designation'] ?? 'Personnel'); ?><br>
                <strong>Daily Wage Rate:</strong> Rs <?php echo number_format($employee['daily_rate'], 2); ?>
            </div>
        </div>
        <div class="col-6 text-end" style="text-align: right;">
            <h3 style="font-size: 0.85rem; font-weight: 700; text-transform: uppercase; color: #666; margin-bottom: 8px;">Statement Details</h3>
            <div style="font-size: 0.95rem; line-height: 1.6;">
                <strong>Slip Ref:</strong> #SLIP-<?php echo $employee_id; ?>-<?php echo date('Ym', strtotime($rangeStart)); ?><br>
                <strong>Payment Status:</strong> <span style="font-weight: 700; color: <?php echo ($payroll && $payroll['status'] == 'paid') ? '#1A9A3A' : '#FF9500'; ?>;"><?php echo ($payroll && $payroll['status'] == 'paid') ? 'PAID' : 'PENDING'; ?></span><br>
                <strong>Method:</strong> <?php echo htmlspecialchars(($payroll && $payroll['payment_method']) ? $payroll['payment_method'] : 'N/A'); ?>
            </div>
        </div>
    </div>
    
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px;">
        <thead>
            <tr style="border-bottom: 2px solid #000; font-size: 0.85rem; text-transform: uppercase; color: #444; font-weight: 700;">
                <th style="padding: 10px 0; text-align: left;">Earnings Description</th>
                <th style="padding: 10px 0; text-align: right; width: 150px;">Calculation Details</th>
                <th style="padding: 10px 0; text-align: right; width: 180px;">Amount (Rs)</th>
            </tr>
        </thead>
        <tbody style="font-size: 0.95rem;">
            <tr style="border-bottom: 1px dashed #E5E5EA;">
                <td style="padding: 12px 0;">Route Days worked</td>
                <td style="padding: 12px 0; text-align: right;"><?php echo $routeDays; ?> days</td>
                <td style="padding: 12px 0; text-align: right;">-</td>
            </tr>
            <tr style="border-bottom: 1px dashed #E5E5EA;">
                <td style="padding: 12px 0;">Office Days worked</td>
                <td style="padding: 12px 0; text-align: right;"><?php echo $officeDays; ?> days</td>
                <td style="padding: 12px 0; text-align: right;">-</td>
            </tr>
            <tr style="border-bottom: 1px solid #000; font-weight: 600;">
                <td style="padding: 12px 0;">Basic Salary (Total Days x Daily Wage)</td>
                <td style="padding: 12px 0; text-align: right;"><?php echo $totalDays; ?> days</td>
                <td style="padding: 12px 0; text-align: right;">Rs <?php echo number_format($totalPay, 2); ?></td>
            </tr>
            <tr style="border-bottom: 1px dashed #E5E5EA;">
                <td style="padding: 12px 0; color: #1A9A3A;">Additions / Performance Bonus</td>
                <td style="padding: 12px 0; text-align: right;">-</td>
                <td style="padding: 12px 0; text-align: right; color: #1A9A3A;">+ Rs <?php echo number_format($payroll ? $payroll['bonus'] : 0, 2); ?></td>
            </tr>
            <tr style="border-bottom: 1px solid #000;">
                <td style="padding: 12px 0; color: #FF3B30;">Deductions / Penalties</td>
                <td style="padding: 12px 0; text-align: right;">-</td>
                <td style="padding: 12px 0; text-align: right; color: #FF3B30;">- Rs <?php echo number_format($payroll ? $payroll['deduction'] : 0, 2); ?></td>
            </tr>
            <tr style="font-size: 1.15rem; font-weight: bold; background: #F2F2F7;">
                <td style="padding: 15px 10px; border-radius: 6px 0 0 6px;">Net Payout Amount</td>
                <td style="padding: 15px 10px; text-align: right;">Total Net</td>
                <td style="padding: 15px 10px; text-align: right; color: #1A9A3A; border-radius: 0 6px 6px 0;">Rs <?php echo number_format($payroll ? $payroll['net_pay'] : $totalPay, 2); ?></td>
            </tr>
        </tbody>
    </table>
    
    <div class="row" style="margin-top: 60px;">
        <div class="col-6">
            <div style="border-top: 1.5px solid #000; width: 220px; text-align: center; padding-top: 8px; font-size: 0.85rem; font-weight: 600;">
                Employee Signature
            </div>
        </div>
        <div class="col-6 text-end" style="text-align: right; display: flex; justify-content: flex-end;">
            <div style="border-top: 1.5px solid #000; width: 220px; text-align: center; padding-top: 8px; font-size: 0.85rem; font-weight: 600;">
                Authorized Signature
            </div>
        </div>
    </div>
</div>

<!-- Payroll Finalization Card -->
<div class="dash-card mb-4 overflow-hidden print-hide">
    <div class="dash-card-header" style="background: var(--ios-surface); padding:18px 20px;">
        <span class="card-title">
            <span class="card-title-icon" style="background: rgba(52,199,89,0.1); color:#1A9A3A;">
                <i class="bi bi-cash-coin"></i>
            </span>
            <span>Payroll System Integration</span>
        </span>
    </div>
    <div class="p-3 bg-white">
        <?php if (!$payroll): ?>
            <div class="p-4 text-center border rounded-3 bg-light">
                <i class="bi bi-exclamation-circle text-warning fs-3 mb-2 d-block"></i>
                <h6 class="fw-bold mb-1">Payroll Not Generated</h6>
                <p class="text-muted small mb-3">No payroll record exists for this employee for the selected month.</p>
                <form method="POST" class="m-0">
                    <input type="hidden" name="action" value="generate_single_payroll">
                    <button type="submit" class="quick-btn px-4 py-2" style="background: var(--accent); color: white; border: none;">
                        <i class="bi bi-gear-fill me-1"></i> Generate &amp; Sync Payroll
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="row g-3">
                <div class="col-md-6 border-end">
                    <h6 class="fw-bold mb-3 text-muted" style="font-size:0.8rem; text-transform:uppercase;">Monthly Breakdown</h6>
                    <table class="table table-sm table-borderless m-0">
                        <tr>
                            <td class="text-muted">Daily Wage Rate:</td>
                            <td class="text-end fw-bold">Rs <?php echo number_format($employee['daily_rate'], 2); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Total Days Worked:</td>
                            <td class="text-end fw-bold"><?php echo (float)$payroll['days_worked']; ?> days</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Basic pay:</td>
                            <td class="text-end fw-bold text-dark">Rs <?php echo number_format($payroll['basic_pay'], 2); ?></td>
                        </tr>
                        <tr>
                            <td class="text-success"><i class="bi bi-plus-circle me-1"></i>Bonus / Additions:</td>
                            <td class="text-end fw-bold text-success">Rs <?php echo number_format($payroll['bonus'], 2); ?></td>
                        </tr>
                        <tr>
                            <td class="text-danger"><i class="bi bi-dash-circle me-1"></i>Deductions:</td>
                            <td class="text-end fw-bold text-danger">Rs <?php echo number_format($payroll['deduction'], 2); ?></td>
                        </tr>
                        <tr class="border-top">
                            <td class="fw-bold fs-6 pt-2">Net Salary:</td>
                            <td class="text-end fw-bold fs-6 pt-2 text-primary">Rs <?php echo number_format($payroll['net_pay'], 2); ?></td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6 d-flex flex-column justify-content-between">
                    <div>
                        <h6 class="fw-bold mb-3 text-muted" style="font-size:0.8rem; text-transform:uppercase;">Payment Status &amp; Processing</h6>
                        <div class="mb-3">
                            <span class="text-muted me-2">Status:</span>
                            <?php if ($payroll['status'] == 'paid'): ?>
                                <span class="ios-badge green"><i class="bi bi-check2-all me-1"></i> PAID</span>
                                <span class="ios-badge gray outline ms-1" style="font-size:0.75rem;"><?php echo htmlspecialchars($payroll['payment_method']); ?></span>
                            <?php else: ?>
                                <span class="ios-badge orange"><i class="bi bi-hourglass-split me-1"></i> PENDING PAYMENT</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($payroll['status'] == 'pending'): ?>
                        <!-- Form to adjust bonus/deduction -->
                        <form method="POST" class="m-0">
                            <input type="hidden" name="action" value="save_single_payroll">
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="ios-label-sm">Bonus Amount (Rs)</label>
                                    <input type="number" step="0.01" name="bonus" class="ios-input fw-bold text-success" style="font-size: 0.95rem;" value="<?php echo $payroll['bonus']; ?>">
                                </div>
                                <div class="col-6">
                                    <label class="ios-label-sm">Deduction Amount (Rs)</label>
                                    <input type="number" step="0.01" name="deduction" class="ios-input fw-bold text-danger" style="font-size: 0.95rem;" value="<?php echo $payroll['deduction']; ?>">
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" class="quick-btn quick-btn-secondary flex-grow-1" style="padding: 10px;">
                                    <i class="bi bi-save me-1"></i> Save Adjustments
                                </button>
                                <button type="button" class="quick-btn" style="background: #1A9A3A; color: white; padding: 10px;" onclick="triggerPayoutModal(<?php echo $payroll['net_pay']; ?>)">
                                    Process Payout <i class="bi bi-cash-coin ms-1"></i>
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-success border-0 rounded-3 m-0" style="background: rgba(52,199,89,0.08); color:#1A9A3A; padding: 12px;">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            This salary has been fully processed and paid. The transaction was logged, and funds were deducted from the chosen company account.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="dash-card mb-4 overflow-hidden">
    <div class="dash-card-header d-flex justify-content-between align-items-center" style="background: var(--ios-surface); padding:18px 20px;">
        <span class="card-title">
            <span class="card-title-icon" style="background: rgba(0,122,255,0.1); color:#007AFF;">
                <i class="bi bi-calendar-event"></i>
            </span>
            <span>Month Calendar – <?php echo htmlspecialchars(date('F Y', strtotime($rangeStart))); ?></span>
        </span>
        <a href="payroll.php?month=<?php echo htmlspecialchars($selected_month); ?>" class="quick-btn quick-btn-primary" style="padding:6px 12px;">Go to Payroll</a>
        <button type="button" class="quick-btn quick-btn-secondary" onclick="window.print()" style="padding:6px 12px; margin-left:8px;">
            <i class="bi bi-printer"></i> Print
        </button>
    </div>
    <div class="p-3" style="background: var(--ios-bg);">
        <div class="border rounded-3 bg-white overflow-hidden shadow-sm">
            <div class="row g-0 text-center border-bottom bg-light py-2 fw-semibold text-muted" style="font-size:0.8rem; text-transform:uppercase; letter-spacing:0.05em; width:100%;">
                <div class="col-7th">Sun</div><div class="col-7th">Mon</div><div class="col-7th">Tue</div><div class="col-7th">Wed</div><div class="col-7th">Thu</div><div class="col-7th">Fri</div><div class="col-7th">Sat</div>
            </div>
            <div id="salaryCalendarBody" class="row g-0" style="width:100%;">
                <div class="col-12 py-5 text-center">
                    <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                    <span class="fw-medium text-muted">Loading calendar...</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const employeeId = <?php echo $employee_id; ?>;
    const monthStr = '<?php echo $selected_month; ?>';
    const rangeStart = '<?php echo $rangeStart; ?>';
    const rangeEnd = '<?php echo $rangeEnd; ?>';
    let calendarEvents = {};
    let calendarDate = new Date(rangeStart);
    const calendarBody = document.getElementById('salaryCalendarBody');
    const monthNameEl = document.querySelector('.dash-card-header span.card-title span');
    function loadCalendar(){
        const year = calendarDate.getFullYear();
        const month = String(calendarDate.getMonth()+1).padStart(2,'0');
        const monthKey = `${year}-${month}`;
        monthNameEl.textContent = `Month Calendar – ${calendarDate.toLocaleDateString('en-US',{month:'long',year:'numeric'})}`;
        calendarBody.innerHTML = `<div class="col-12 py-5 text-center"><div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div><span class="fw-medium text-muted">Loading...</span></div>`;
        fetch(`../ajax/get_employee_calendar.php?employee_id=${employeeId}&month=${monthKey}`)
            .then(r=>r.json())
            .then(data=>{
                if(data.success){
                    calendarEvents = data.events;
                    renderCalendar();
                }else{
                    calendarBody.innerHTML = `<div class="col-12 py-4 text-center text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i> ${data.message}</div>`;
                }
            })
            .catch(err=>{
                console.error(err);
                calendarBody.innerHTML = `<div class="col-12 py-4 text-center text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i> Failed to load.</div>`;
            });
    }
    function renderCalendar(){
        const year = calendarDate.getFullYear();
        const month = calendarDate.getMonth();
        const firstDay = new Date(year, month, 1);
        const startDow = firstDay.getDay();
        const totalDays = new Date(year, month+1, 0).getDate();
        const prevMonthDays = new Date(year, month, 0).getDate();
        const today = new Date();
        const todayStr = today.toISOString().split('T')[0];
        let html='';
        // previous month filler
        for(let i=startDow-1;i>=0;i--){
            const d = prevMonthDays - i;
            html+=`<div class="col-7th calendar-day-cell other-month"><div class="calendar-day-number">${d}</div></div>`;
        }
        // current month days
        for(let d=1; d<=totalDays; d++){
            const dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
            const isToday = dateStr===todayStr;
            let eventsHtml='';
            let hasRoute=false;
            let attStatus='';
            if(calendarEvents[dateStr]){
                const ev = calendarEvents[dateStr];
                if(ev.has_route && ev.routes.length){
                    hasRoute=true;
                    ev.routes.forEach(r=>{
                        const url=`route_detailed_report.php?id=${r.assignment_id}`;
                        eventsHtml+=`<a href="${url}" target="_blank" class="calendar-event-badge route" style="text-decoration:none;display:block;" title="Route: ${r.route_name} (${r.status})" onclick="event.stopPropagation();">🚚 ${r.route_name}</a>`;
                    });
                }
                if(ev.attendance_status){
                    attStatus = ev.attendance_status;
                    let badge='present', icon='🏢', label='Present (Office)';
                    if(attStatus==='half_day'){badge='half-day';label='Half Day';}
                    else if(attStatus==='absent'){badge='absent';icon='❌';label='Absent';}
                    eventsHtml+=`<div class="calendar-event-badge ${badge}" title="${label}">${icon} ${label}</div>`;
                }
            }
            let logBtn='';
            if(!hasRoute){
                logBtn=`<div class="quick-log-btn text-primary mt-auto"><i class="bi bi-pencil-square"></i> ${attStatus?'Edit':'Log Office'}</div>`;
            }
            html+=`<div class="col-7th calendar-day-cell ${isToday?'today':''}" onclick="openLogModal('${dateStr}',${hasRoute},'${attStatus}')">
                <div class="calendar-day-number">${d}</div>
                <div class="w-100 d-flex flex-column gap-1">${eventsHtml}</div>
                ${logBtn}
            </div>`;
        }
        // next month filler
        const rendered = startDow + totalDays;
        const remaining = (7 - (rendered % 7)) % 7;
        for(let i=1;i<=remaining;i++){
            html+=`<div class="col-7th calendar-day-cell other-month"><div class="calendar-day-number">${i}</div></div>`;
        }
        calendarBody.innerHTML = html;
    }
    function openLogModal(dateStr, hasRoute, currentStatus){
        if(hasRoute){
            alert('Route assigned – attendance already accounted for.');
            return;
        }
        // Reuse existing attendance modal from employees page via dynamic import? For simplicity we just redirect to attendance page.
        window.location.href = `attendance.php?employee_id=${employeeId}&date=${dateStr}`;
    }
    
    function triggerPayoutModal(amount) {
        document.getElementById('pay_amount_display').textContent = 'Rs ' + parseFloat(amount).toFixed(2);
        new bootstrap.Modal(document.getElementById('payoutModal')).show();
    }

    // Initial load
    loadCalendar();
</script>

<!-- Payout Modal -->
<div class="modal fade" id="payoutModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem; color: #1A9A3A;"><i class="bi bi-cash-coin me-2"></i>Process Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0 text-center">
                    <input type="hidden" name="action" value="pay_single_payroll">
                    
                    <div style="font-size: 0.85rem; font-weight: 600; color: var(--ios-label-2); margin-bottom: 4px;"><?php echo htmlspecialchars($employee['name']); ?></div>
                    <div class="fw-bold mb-4" style="font-size: 1.8rem; color: #1A9A3A; letter-spacing: -0.5px;" id="pay_amount_display">Rs 0.00</div>

                    <div class="text-start mb-4">
                        <label class="ios-label-sm">Deduct From Account</label>
                        <select name="payment_method" class="form-select fw-bold border-dark" required>
                            <option value="Cash">Cash on Hand</option>
                            <option value="Bank Transfer">Company Bank Account</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary w-100 mb-2" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn w-100" style="background: #1A9A3A; color: #fff;" onclick="return confirm('This will instantly deduct funds from your chosen Company Account. Proceed?');">Confirm Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
