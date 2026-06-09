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
    <style>
        .modern-wrapper {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f8f9fa;
            min-height: calc(100vh - 100px);
            padding: 2rem 1rem;
        }
        .selector-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.04);
            border: 1px solid rgba(0,0,0,0.05);
            max-width: 550px;
            margin: 2rem auto;
            overflow: hidden;
        }
        .selector-header {
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            padding: 2rem;
            text-align: center;
            color: white;
        }
        .selector-icon {
            width: 64px;
            height: 64px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin-bottom: 1rem;
        }
        .selector-body {
            padding: 2rem;
        }
        .form-label-modern {
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .form-control-modern {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.2s;
            background-color: #f8f9fa;
        }
        .form-control-modern:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.1);
            background-color: #ffffff;
            outline: none;
        }
        .btn-modern {
            background: #0d6efd;
            color: white;
            font-weight: 600;
            padding: 0.85rem 1.5rem;
            border-radius: 10px;
            border: none;
            transition: all 0.2s;
            font-size: 1.05rem;
        }
        .btn-modern:hover {
            background: #0b5ed7;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(13, 110, 253, 0.3);
        }
    </style>
    <div class="modern-wrapper">
        <div class="d-flex justify-content-between align-items-end mb-4 print-hide" style="max-width: 550px; margin: 0 auto;">
            <div>
                <h1 class="h3 mb-1 fw-bold text-dark">Personnel Salary Reports</h1>
                <p class="text-muted mb-0">Generate and audit monthly payroll statements.</p>
            </div>
        </div>
        
        <div class="selector-card print-hide">
            <div class="selector-header">
                <div class="selector-icon">
                    <i class="bi bi-file-earmark-person-fill"></i>
                </div>
                <h3 class="h4 mb-0 fw-bold">Select Employee Report</h3>
            </div>
            <div class="selector-body">
                <form method="GET" class="m-0">
                    <div class="mb-4">
                        <label class="form-label-modern">Select Personnel</label>
                        <select name="employee_id" class="form-select form-control-modern" required>
                            <option value="" disabled selected>-- Choose Personnel --</option>
                            <?php foreach ($employees as $e): ?>
                                <option value="<?php echo $e['id']; ?>">
                                    <?php echo htmlspecialchars($e['name']); ?> (<?php echo htmlspecialchars($e['emp_code']); ?>) - <?php echo htmlspecialchars($e['designation']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label-modern">Salary Month</label>
                        <input type="month" name="month" class="form-control form-control-modern" value="<?php echo htmlspecialchars($selected_month); ?>" required>
                    </div>
                    <button type="submit" class="btn btn-modern w-100 d-flex justify-content-center align-items-center gap-2">
                        Generate Audit Report <i class="bi bi-arrow-right-circle-fill"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php
    include '../includes/footer.php';
    exit;
}

// Fetch employee details
$empStmt = $pdo->prepare('SELECT id, name, emp_code, designation, daily_rate FROM employees WHERE id = ?');
$empStmt->execute([$employee_id]);
$employee = $empStmt->fetch(PDO::FETCH_ASSOC);
if (!$employee) {
    die('<div class="alert alert-danger m-4">Employee not found.</div>');
}

$message = '';

// Calculate dates for processing
$rangeStart = $selected_month . '-01';
$rangeEnd = date('Y-m-t', strtotime($rangeStart));

// POST Actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'generate_single_payroll') {
        try {
            $routeDaysStmt = $pdo->prepare("SELECT COUNT(DISTINCT rr.assign_date) AS route_days FROM rep_routes rr
                WHERE (rr.driver_id = :emp_id_drv OR rr.rep_id = (SELECT user_id FROM employees WHERE id = :emp_id_rep1) OR rr.rep_id IN (SELECT id FROM users WHERE employee_id = :emp_id_rep2))
                AND rr.assign_date BETWEEN :start AND :end");
            $routeDaysStmt->execute([
                'emp_id_drv' => $employee_id, 'emp_id_rep1' => $employee_id, 'emp_id_rep2' => $employee_id,
                'start' => $rangeStart, 'end' => $rangeEnd
            ]);
            $routeDays = (int)$routeDaysStmt->fetchColumn();

            $attStmt = $pdo->prepare("SELECT SUM(CASE WHEN status='present' THEN 1 WHEN status='half_day' THEN 0.5 ELSE 0 END) FROM attendance
                WHERE employee_id = :emp_id AND work_date BETWEEN :start AND :end
                AND work_date NOT IN (
                    SELECT DISTINCT rr.assign_date FROM rep_routes rr
                    WHERE (rr.driver_id = :emp_id_drv OR rr.rep_id = (SELECT user_id FROM employees WHERE id = :emp_id_rep1) OR rr.rep_id IN (SELECT id FROM users WHERE employee_id = :emp_id_rep2))
                    AND rr.assign_date BETWEEN :sub_start AND :sub_end
                )");
            $attStmt->execute([
                'emp_id' => $employee_id, 'start' => $rangeStart, 'end' => $rangeEnd,
                'emp_id_drv' => $employee_id, 'emp_id_rep1' => $employee_id, 'emp_id_rep2' => $employee_id,
                'sub_start' => $rangeStart, 'sub_end' => $rangeEnd
            ]);
            $officeDays = (float)$attStmt->fetchColumn();

            $totalDays = $routeDays + $officeDays;
            $basic_pay = $totalDays * $employee['daily_rate'];

            $stmt = $pdo->prepare("INSERT INTO payroll (employee_id, month, days_worked, basic_pay, net_pay) VALUES (?, ?, ?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE days_worked = VALUES(days_worked), basic_pay = VALUES(basic_pay), net_pay = basic_pay + bonus - deduction");
            $stmt->execute([$employee_id, $selected_month, $totalDays, $basic_pay, $basic_pay]);
            
            $message = "<div class='modern-alert modern-alert-success'><i class='bi bi-check-circle-fill'></i> Payroll generated successfully!</div>";
        } catch (Exception $e) {
            $message = "<div class='modern-alert modern-alert-danger'><i class='bi bi-exclamation-triangle-fill'></i> Error generating payroll: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }

    if ($_POST['action'] == 'save_single_payroll') {
        $bonus = (float)$_POST['bonus'];
        $deduction = (float)$_POST['deduction'];
        try {
            $pdo->prepare("UPDATE payroll SET bonus=?, deduction=?, net_pay = (basic_pay + ? - ?) WHERE employee_id=? AND month=?")
                ->execute([$bonus, $deduction, $bonus, $deduction, $employee_id, $selected_month]);
            $message = "<div class='modern-alert modern-alert-success'><i class='bi bi-check-circle-fill'></i> Payroll adjustments updated!</div>";
        } catch (Exception $e) {
            $message = "<div class='modern-alert modern-alert-danger'><i class='bi bi-exclamation-triangle-fill'></i> Error saving adjustments: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }

    if ($_POST['action'] == 'pay_single_payroll') {
        $method = $_POST['payment_method'];
        try {
            $pdo->beginTransaction();
            $pStmt = $pdo->prepare("SELECT p.id, p.net_pay, p.month, e.name FROM payroll p JOIN employees e ON p.employee_id = e.id WHERE p.employee_id = ? AND p.month = ? FOR UPDATE");
            $pStmt->execute([$employee_id, $selected_month]);
            $pay = $pStmt->fetch();

            if ($pay) {
                $pdo->prepare("UPDATE payroll SET status='paid', payment_method=? WHERE id=?")->execute([$method, $pay['id']]);
                
                $desc = "Salary: {$pay['name']} ({$pay['month']})";
                if ($method == 'Cash') {
                    $pdo->prepare("UPDATE company_finances SET cash_on_hand = cash_on_hand - ? WHERE id = 1")->execute([$pay['net_pay']]);
                    $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('cash_out', ?, ?, ?)")->execute([$pay['net_pay'], $desc, $_SESSION['user_id']]);
                } else {
                    $pdo->prepare("UPDATE company_finances SET bank_balance = bank_balance - ? WHERE id = 1")->execute([$pay['net_pay']]);
                    $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('bank_out', ?, ?, ?)")->execute([$pay['net_pay'], $desc, $_SESSION['user_id']]);
                }
                $pdo->commit();
                $message = "<div class='modern-alert modern-alert-success'><i class='bi bi-check-circle-fill'></i> Salary paid and deducted from account!</div>";
            } else {
                $pdo->rollBack();
                $message = "<div class='modern-alert modern-alert-danger'><i class='bi bi-exclamation-triangle-fill'></i> Payroll record not found.</div>";
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='modern-alert modern-alert-danger'><i class='bi bi-exclamation-triangle-fill'></i> Error processing payment: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// Summary Calculation
$routeDaysStmt = $pdo->prepare("SELECT COUNT(DISTINCT rr.assign_date) AS route_days FROM rep_routes rr WHERE (rr.driver_id = :emp_id_drv OR rr.rep_id = (SELECT user_id FROM employees WHERE id = :emp_id_rep1) OR rr.rep_id IN (SELECT id FROM users WHERE employee_id = :emp_id_rep2)) AND rr.assign_date BETWEEN :start AND :end");
$routeDaysStmt->execute(['emp_id_drv' => $employee_id, 'emp_id_rep1' => $employee_id, 'emp_id_rep2' => $employee_id, 'start' => $rangeStart, 'end' => $rangeEnd]);
$routeDays = (int)$routeDaysStmt->fetchColumn();

$attStmt = $pdo->prepare("SELECT SUM(CASE WHEN status='present' THEN 1 WHEN status='half_day' THEN 0.5 ELSE 0 END) FROM attendance WHERE employee_id = :emp_id AND work_date BETWEEN :start AND :end AND work_date NOT IN (SELECT DISTINCT rr.assign_date FROM rep_routes rr WHERE (rr.driver_id = :emp_id_drv OR rr.rep_id = (SELECT user_id FROM employees WHERE id = :emp_id_rep1) OR rr.rep_id IN (SELECT id FROM users WHERE employee_id = :emp_id_rep2)) AND rr.assign_date BETWEEN :sub_start AND :sub_end)");
$attStmt->execute(['emp_id' => $employee_id, 'start' => $rangeStart, 'end' => $rangeEnd, 'emp_id_drv' => $employee_id, 'emp_id_rep1' => $employee_id, 'emp_id_rep2' => $employee_id, 'sub_start' => $rangeStart, 'sub_end' => $rangeEnd]);
$officeDays = (float)$attStmt->fetchColumn();

$totalDays = $routeDays + $officeDays;
$totalPay = $totalDays * $employee['daily_rate'];

$payStmt = $pdo->prepare("SELECT * FROM payroll WHERE employee_id = ? AND month = ?");
$payStmt->execute([$employee_id, $selected_month]);
$payroll = $payStmt->fetch(PDO::FETCH_ASSOC);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    /* ==========================================================================
       SCREEN STYLES (Modern Responsive Layout)
       ========================================================================== */
    @media screen {
        .print-only { display: none !important; }
        
        .page-content-wrapper {
            padding: 1.5rem;
            background: #f4f6f9;
            min-height: calc(100vh - 60px);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        /* Modern Alerts */
        .modern-alert {
            padding: 1rem 1.25rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            font-size: 0.95rem;
            animation: slideDown 0.3s ease-out;
        }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .modern-alert-success { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .modern-alert-danger { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }

        /* Typography & Header */
        .header-section {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            gap: 1rem;
        }
        .header-section h1 { font-size: 1.75rem; font-weight: 800; color: #1e293b; margin: 0; letter-spacing: -0.5px; }
        .header-section p { color: #64748b; margin: 0; font-size: 0.95rem; font-weight: 500; }
        
        .btn-print {
            background: white; border: 1px solid #cbd5e1; color: #334155; padding: 0.6rem 1.25rem;
            border-radius: 10px; font-weight: 600; font-size: 0.9rem; transition: all 0.2s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02); display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-print:hover { background: #f8fafc; border-color: #94a3b8; transform: translateY(-1px); box-shadow: 0 4px 6px rgba(0,0,0,0.05); }

        /* Summary Cards Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .stat-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            border: 1px solid rgba(0,0,0,0.04);
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 5px;
        }
        .stat-card.blue::before { background: #3b82f6; }
        .stat-card.green::before { background: #10b981; }
        .stat-card.purple::before { background: #8b5cf6; }
        .stat-card.orange::before { background: #f59e0b; }
        
        .stat-label { font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.25rem; }
        .stat-value { font-size: 1.75rem; font-weight: 800; color: #0f172a; line-height: 1.2; }
        
        /* Modern Cards */
        .modern-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        .modern-card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .modern-card-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }
        .modern-card-icon {
            width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;
        }
        .icon-green { background: #dcfce7; color: #16a34a; }
        .icon-blue { background: #dbeafe; color: #2563eb; }
        
        .modern-card-body { padding: 1.5rem; }

        /* Payroll Table / Actions */
        .payroll-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }
        @media (max-width: 768px) { .payroll-grid { grid-template-columns: 1fr; } }
        
        .payroll-table { width: 100%; border-collapse: separate; border-spacing: 0 0.75rem; margin: -0.75rem 0; }
        .payroll-table td { padding: 0.25rem 0; font-size: 0.95rem; }
        .payroll-table td.label { color: #64748b; font-weight: 500; }
        .payroll-table td.value { text-align: right; font-weight: 700; color: #1e293b; }
        .payroll-table tr.total-row td { border-top: 2px dashed #e2e8f0; padding-top: 1rem; font-size: 1.1rem; color: #0f172a; }
        
        .form-input {
            width: 100%; padding: 0.6rem 1rem; border-radius: 8px; border: 2px solid #e2e8f0; font-weight: 600; transition: border 0.2s;
        }
        .form-input:focus { border-color: #3b82f6; outline: none; }
        .btn-action {
            padding: 0.6rem 1.2rem; border-radius: 8px; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-primary-action { background: #16a34a; color: white; }
        .btn-primary-action:hover { background: #15803d; }
        .btn-secondary-action { background: #f1f5f9; color: #475569; }
        .btn-secondary-action:hover { background: #e2e8f0; }

        /* Status Badges */
        .status-badge {
            display: inline-flex; align-items: center; gap: 6px; padding: 0.4rem 0.8rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .status-paid { background: #dcfce7; color: #16a34a; }
        .status-pending { background: #fef3c7; color: #d97706; }
        
        /* Modern Calendar CSS Grid */
        .calendar-wrapper { border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; background: #fff; }
        .calendar-header-grid {
            display: grid; grid-template-columns: repeat(7, 1fr); background: #f8fafc; border-bottom: 1px solid #e2e8f0;
        }
        .calendar-header-cell {
            padding: 0.75rem; text-align: center; font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; border-right: 1px solid #e2e8f0;
        }
        .calendar-header-cell:last-child { border-right: none; }
        
        .calendar-grid {
            display: grid; grid-template-columns: repeat(7, 1fr); auto-rows: minmax(120px, auto);
        }
        .calendar-day {
            border-right: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; padding: 0.5rem; min-height: 120px; display: flex; flex-direction: column; transition: background 0.2s; cursor: pointer; background: #fff;
        }
        .calendar-day:nth-child(7n) { border-right: none; }
        .calendar-day:hover { background: #f8fafc; }
        .calendar-day.other-month { background: #f1f5f9; cursor: default; }
        .calendar-day.other-month .day-num { color: #cbd5e1; }
        .calendar-day.today { background: #f0fdf4; }
        .calendar-day.today .day-num { background: #16a34a; color: white; border-radius: 50%; width: 26px; height: 26px; display: flex; align-items: center; justify-content: center; }
        
        .day-num { font-size: 0.85rem; font-weight: 700; color: #475569; align-self: flex-end; margin-bottom: 0.5rem; }
        
        .cal-event {
            font-size: 0.7rem; font-weight: 600; padding: 4px 6px; border-radius: 6px; margin-bottom: 4px; line-height: 1.2; text-align: left; display: block; text-decoration: none; word-break: break-word;
        }
        .cal-event:hover { opacity: 0.9; }
        .cal-event-route { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        .cal-event-present { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .cal-event-half { background: #fef08a; color: #854d0e; border: 1px solid #fde047; }
        .cal-event-absent { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        
        .log-btn { font-size: 0.7rem; color: #64748b; font-weight: 600; text-align: center; margin-top: auto; opacity: 0; transition: opacity 0.2s; padding-top: 4px; }
        .calendar-day:hover .log-btn { opacity: 1; }
        @media (max-width: 992px) {
            .calendar-grid, .calendar-header-grid { min-width: 700px; }
            .calendar-wrapper { overflow-x: auto; }
        }
    }

    /* ==========================================================================
       PRINT STYLES (Strict A4 Perfect Alignment)
       ========================================================================== */
    @media print {
        @page {
            size: A4 portrait;
            margin: 15mm;
        }
        /* Reset and hide everything else */
        body, html {
            background: white !important;
            margin: 0 !important;
            padding: 0 !important;
            color: black !important;
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif !important;
        }
        /* Hide all UI containers, navs, sidebars, buttons, etc. */
        .sidebar, .navbar, header, footer, .print-hide, .page-header, .modern-alert, #sidebarMenu, .top-bar {
            display: none !important;
        }
        /* Force main content wrappers to behave */
        #mainContent, .main-content, .container-fluid, .page-content-wrapper {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            display: block !important;
            box-shadow: none !important;
            border: none !important;
            background: transparent !important;
        }

        /* Show and perfectly format the payslip */
        .print-only {
            display: block !important;
        }
        .payslip-container {
            display: block !important;
            width: 100% !important;
            max-width: 180mm !important; /* Allow some breathing room within A4 210mm */
            margin: 0 auto !important;
            background: white !important;
            box-sizing: border-box !important;
            page-break-after: always;
        }

        .payslip-header { border-bottom: 3px solid #16a34a; padding-bottom: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: flex-end; }
        .payslip-header h1 { font-size: 24pt; font-weight: 800; margin: 0; color: #16a34a !important; line-height: 1; }
        .payslip-header .subtitle { font-size: 10pt; text-transform: uppercase; letter-spacing: 1px; color: #555 !important; margin-top: 4px; }
        .payslip-header .title-right { text-align: right; }
        .payslip-header h2 { font-size: 18pt; font-weight: 700; margin: 0; color: #000 !important; line-height: 1; }
        .payslip-header .month { font-size: 11pt; font-weight: 600; margin-top: 4px; color: #333 !important; }

        .payslip-info-row { display: flex; justify-content: space-between; margin-bottom: 25px; }
        .payslip-info-col { width: 48%; }
        .payslip-info-title { font-size: 10pt; font-weight: 700; text-transform: uppercase; color: #666 !important; margin-bottom: 8px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
        .payslip-info-data { font-size: 11pt; line-height: 1.6; }
        .payslip-info-data strong { display: inline-block; width: 120px; }

        .payslip-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; font-size: 11pt; }
        .payslip-table th { border-top: 2px solid #000; border-bottom: 2px solid #000; padding: 10px 5px; text-align: left; font-weight: 700; text-transform: uppercase; font-size: 10pt; }
        .payslip-table th.right, .payslip-table td.right { text-align: right; }
        .payslip-table th.center, .payslip-table td.center { text-align: center; }
        .payslip-table td { padding: 12px 5px; border-bottom: 1px dashed #ccc; }
        .payslip-table tr.total-row td { border-top: 2px solid #000; border-bottom: 2px solid #000; background: #f9f9f9 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; font-weight: bold; font-size: 14pt; padding: 15px 5px; }
        .text-green { color: #16a34a !important; }
        .text-red { color: #dc2626 !important; }

        .payslip-footer { display: flex; justify-content: space-between; margin-top: 60px; page-break-inside: avoid; }
        .sig-box { width: 200px; text-align: center; border-top: 1px solid #000; padding-top: 5px; font-size: 10pt; font-weight: bold; }

        /* Printable Calendar Sheet Styles */
        .print-calendar-card {
            display: block !important;
            border: 2px solid #000 !important;
            border-radius: 12px !important;
            background: #fff !important;
            page-break-before: always;
            margin-top: 30px !important;
            box-shadow: none !important;
            overflow: visible !important;
        }
        .print-calendar-card .modern-card-header {
            background: #fff !important;
            border-bottom: 2px solid #000 !important;
            padding: 10px 0 !important;
        }
        .print-calendar-card .modern-card-title {
            color: #000 !important;
            font-size: 14pt !important;
        }
        .print-calendar-card .modern-card-icon {
            display: none !important;
        }
        .print-calendar-card .modern-card-body {
            padding: 15px 0 !important;
        }
        .calendar-wrapper {
            border: 1px solid #000 !important;
            border-radius: 8px !important;
            overflow: visible !important;
        }
        .calendar-header-grid {
            display: grid !important;
            grid-template-columns: repeat(7, 1fr) !important;
            border-bottom: 1px solid #000 !important;
            background: #f2f2f7 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .calendar-header-cell {
            border-right: 1px solid #000 !important;
            color: #000 !important;
            padding: 6px !important;
            font-size: 9pt !important;
        }
        .calendar-header-cell:last-child {
            border-right: none !important;
        }
        .calendar-grid {
            display: grid !important;
            grid-template-columns: repeat(7, 1fr) !important;
            border: none !important;
        }
        .calendar-day {
            border-right: 1px solid #000 !important;
            border-bottom: 1px solid #000 !important;
            min-height: 90px !important;
            background: #fff !important;
            page-break-inside: avoid !important;
            padding: 6px !important;
        }
        .calendar-day:nth-child(7n) {
            border-right: none !important;
        }
        .calendar-day.other-month {
            background: #f2f2f7 !important;
            opacity: 0.5 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .calendar-day.today {
            background: #fff !important;
        }
        .calendar-day.today .day-num {
            background: #000 !important;
            color: #fff !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .day-num {
            font-size: 9pt !important;
            color: #000 !important;
            margin-bottom: 2px !important;
        }
        .cal-event {
            font-size: 7pt !important;
            padding: 2px 4px !important;
            border: 1px solid #ccc !important;
            margin-bottom: 2px !important;
            border-radius: 4px !important;
            color: #000 !important;
            background: #fff !important;
        }
        .cal-event-route {
            border-color: #0369a1 !important;
            background: #f0f9ff !important;
            color: #0369a1 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .cal-event-present {
            border-color: #15803d !important;
            background: #f0fdf4 !important;
            color: #15803d !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .cal-event-half {
            border-color: #a16207 !important;
            background: #fefce8 !important;
            color: #a16207 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .cal-event-absent {
            border-color: #b91c1c !important;
            background: #fef2f2 !important;
            color: #b91c1c !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .log-btn {
            display: none !important;
        }
    }
</style>

<div class="page-content-wrapper">
    <div class="header-section print-hide">
        <div>
            <h1>Salary Report</h1>
            <p>Statement for <?php echo htmlspecialchars($employee['name']); ?> • <?php echo date('F Y', strtotime($rangeStart)); ?></p>
        </div>
        <button onclick="window.print()" class="btn-print">
            <i class="bi bi-printer-fill text-primary"></i> Export PDF / Print
        </button>
    </div>

    <div class="print-hide">
        <?php echo $message; ?>
    </div>

    <!-- Screen: Summary Cards -->
    <div class="stats-grid print-hide">
        <div class="stat-card blue">
            <div class="stat-label">Route Days</div>
            <div class="stat-value"><?php echo $routeDays; ?></div>
        </div>
        <div class="stat-card green">
            <div class="stat-label">Office Days</div>
            <div class="stat-value"><?php echo $officeDays; ?></div>
        </div>
        <div class="stat-card purple">
            <div class="stat-label">Total Days Worked</div>
            <div class="stat-value"><?php echo $totalDays; ?></div>
        </div>
        <div class="stat-card orange">
            <div class="stat-label">Calculated Base Salary</div>
            <div class="stat-value text-success">Rs <?php echo number_format($totalPay, 2); ?></div>
        </div>
    </div>

    <!-- Print Only: Perfect A4 Payslip -->
    <div class="print-only payslip-container">
        <div class="payslip-header">
            <div>
                <h1>CANDENT ERP</h1>
                <div class="subtitle">Official Salary Statement</div>
            </div>
            <div class="title-right">
                <h2>PAY SLIP</h2>
                <div class="month">Statement Month: <?php echo date('F Y', strtotime($rangeStart)); ?></div>
            </div>
        </div>
        
        <div class="payslip-info-row">
            <div class="payslip-info-col">
                <div class="payslip-info-title">Employee Details</div>
                <div class="payslip-info-data">
                    <strong>Employee ID:</strong> <?php echo htmlspecialchars($employee['emp_code'] ?? $employee['id']); ?><br>
                    <strong>Name:</strong> <?php echo htmlspecialchars($employee['name']); ?><br>
                    <strong>Designation:</strong> <?php echo htmlspecialchars($employee['designation'] ?? 'Personnel'); ?><br>
                    <strong>Daily Wage Rate:</strong> Rs <?php echo number_format($employee['daily_rate'], 2); ?>
                </div>
            </div>
            <div class="payslip-info-col">
                <div class="payslip-info-title">Statement Details</div>
                <div class="payslip-info-data">
                    <strong>Slip Ref:</strong> #SLIP-<?php echo $employee_id; ?>-<?php echo date('Ym', strtotime($rangeStart)); ?><br>
                    <strong>Generated On:</strong> <?php echo date('d M Y, h:i A'); ?><br>
                    <strong>Payment Status:</strong> <?php echo ($payroll && $payroll['status'] == 'paid') ? 'PAID' : 'PENDING'; ?><br>
                    <strong>Method:</strong> <?php echo htmlspecialchars(($payroll && $payroll['payment_method']) ? $payroll['payment_method'] : 'N/A'); ?>
                </div>
            </div>
        </div>
        
        <table class="payslip-table">
            <thead>
                <tr>
                    <th>Earnings Description</th>
                    <th class="center" style="width:120px;">Units</th>
                    <th class="right" style="width:150px;">Amount (Rs)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Route Days Worked</td>
                    <td class="center"><?php echo $routeDays; ?> days</td>
                    <td class="right">-</td>
                </tr>
                <tr>
                    <td>Office Days Worked</td>
                    <td class="center"><?php echo $officeDays; ?> days</td>
                    <td class="right">-</td>
                </tr>
                <tr style="font-weight: bold; border-bottom: 2px solid #000;">
                    <td>Basic Salary (Total Days &times; Daily Wage)</td>
                    <td class="center"><?php echo $totalDays; ?> days</td>
                    <td class="right">Rs <?php echo number_format($totalPay, 2); ?></td>
                </tr>
                <?php if ($payroll && (float)$payroll['bonus'] > 0): ?>
                <tr>
                    <td>Performance Bonus / Additions</td>
                    <td class="center">-</td>
                    <td class="right text-green">+ Rs <?php echo number_format($payroll['bonus'], 2); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($payroll && (float)$payroll['deduction'] > 0): ?>
                <tr>
                    <td>Deductions / Penalties</td>
                    <td class="center">-</td>
                    <td class="right text-red">- Rs <?php echo number_format($payroll['deduction'], 2); ?></td>
                </tr>
                <?php endif; ?>
                <tr class="total-row">
                    <td colspan="2">NET PAYOUT AMOUNT</td>
                    <td class="right text-green">Rs <?php echo number_format($payroll ? $payroll['net_pay'] : $totalPay, 2); ?></td>
                </tr>
            </tbody>
        </table>
        
        <div class="payslip-footer">
            <div class="sig-box">Employee Signature</div>
            <div class="sig-box">Authorized Signature</div>
        </div>
    </div>
    <!-- /End Print Only -->

    <!-- Screen: Payroll System Integration -->
    <div class="modern-card print-hide">
        <div class="modern-card-header">
            <h2 class="modern-card-title">
                <span class="modern-card-icon icon-green"><i class="bi bi-wallet2"></i></span>
                Payroll Management
            </h2>
        </div>
        <div class="modern-card-body">
            <?php if (!$payroll): ?>
                <div class="text-center py-5">
                    <div style="font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem;"><i class="bi bi-folder-x"></i></div>
                    <h3 class="h5 fw-bold mb-2">Payroll Not Yet Generated</h3>
                    <p class="text-muted mb-4">Calculate and lock the payroll record for this month to enable adjustments and payouts.</p>
                    <form method="POST" class="m-0">
                        <input type="hidden" name="action" value="generate_single_payroll">
                        <button type="submit" class="btn-action btn-primary-action px-4 py-2" style="font-size: 1.1rem;">
                            <i class="bi bi-gear-fill"></i> Generate & Sync Payroll
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="payroll-grid">
                    <!-- Left: Financial Breakdown -->
                    <div>
                        <h4 style="font-size: 0.85rem; text-transform: uppercase; color: #64748b; font-weight: 700; margin-bottom: 1rem;">Financial Breakdown</h4>
                        <table class="payroll-table">
                            <tr>
                                <td class="label">Daily Wage Rate</td>
                                <td class="value">Rs <?php echo number_format($employee['daily_rate'], 2); ?></td>
                            </tr>
                            <tr>
                                <td class="label">Total Credited Days</td>
                                <td class="value"><?php echo (float)$payroll['days_worked']; ?> days</td>
                            </tr>
                            <tr>
                                <td class="label">Base Salary</td>
                                <td class="value">Rs <?php echo number_format($payroll['basic_pay'], 2); ?></td>
                            </tr>
                            <tr>
                                <td class="label" style="color: #16a34a;"><i class="bi bi-plus-circle"></i> Additions (Bonus)</td>
                                <td class="value" style="color: #16a34a;">+ Rs <?php echo number_format($payroll['bonus'], 2); ?></td>
                            </tr>
                            <tr>
                                <td class="label" style="color: #dc2626;"><i class="bi bi-dash-circle"></i> Deductions</td>
                                <td class="value" style="color: #dc2626;">- Rs <?php echo number_format($payroll['deduction'], 2); ?></td>
                            </tr>
                            <tr class="total-row">
                                <td class="label" style="color: #0f172a;">Final Net Salary</td>
                                <td class="value text-primary">Rs <?php echo number_format($payroll['net_pay'], 2); ?></td>
                            </tr>
                        </table>
                    </div>

                    <!-- Right: Adjustments & Payout -->
                    <div style="display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h4 style="font-size: 0.85rem; text-transform: uppercase; color: #64748b; font-weight: 700; margin: 0;">Status & Adjustments</h4>
                                <?php if ($payroll['status'] == 'paid'): ?>
                                    <span class="status-badge status-paid"><i class="bi bi-check-circle-fill"></i> Paid Via <?php echo htmlspecialchars($payroll['payment_method']); ?></span>
                                <?php else: ?>
                                    <span class="status-badge status-pending"><i class="bi bi-hourglass-split"></i> Pending</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($payroll['status'] == 'pending'): ?>
                                <form method="POST" class="m-0 mb-4 bg-light p-3 rounded-3 border">
                                    <input type="hidden" name="action" value="save_single_payroll">
                                    <div class="row g-3 mb-3">
                                        <div class="col-6">
                                            <label class="form-label-modern" style="font-size: 0.75rem;">Add Bonus (Rs)</label>
                                            <input type="number" step="0.01" name="bonus" class="form-input" style="color: #16a34a;" value="<?php echo $payroll['bonus']; ?>">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label-modern" style="font-size: 0.75rem;">Deduction (Rs)</label>
                                            <input type="number" step="0.01" name="deduction" class="form-input" style="color: #dc2626;" value="<?php echo $payroll['deduction']; ?>">
                                        </div>
                                    </div>
                                    <button type="submit" class="btn-action btn-secondary-action w-100">
                                        <i class="bi bi-save"></i> Save Adjustments
                                    </button>
                                </form>
                            <?php else: ?>
                                <div class="alert alert-success border-0 mb-0 mt-2" style="background: #f0fdf4; color: #16a34a; border-radius: 12px;">
                                    <h5 class="fw-bold mb-1"><i class="bi bi-shield-check"></i> Payment Completed</h5>
                                    <p class="mb-0 text-sm">This salary has been fully processed and locked. Funds were deducted from the company account and logged to finances.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($payroll['status'] == 'pending'): ?>
                            <button type="button" class="btn-action btn-primary-action w-100 py-3 mt-3" style="font-size: 1.1rem; box-shadow: 0 4px 12px rgba(22, 163, 74, 0.2);" onclick="triggerPayoutModal(<?php echo $payroll['net_pay']; ?>)">
                                Process Payout <i class="bi bi-arrow-right-circle-fill"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Screen: Interactive Calendar & Printable Schedule -->
    <div class="modern-card print-calendar-card">
        <div class="modern-card-header">
            <h2 class="modern-card-title">
                <span class="modern-card-icon icon-blue"><i class="bi bi-calendar-day"></i></span>
                <span id="calendarMonthLabel">Attendance Calendar</span>
            </h2>
            <div class="d-flex gap-2 print-hide">
                <a href="payroll.php?month=<?php echo htmlspecialchars($selected_month); ?>" class="btn-action btn-secondary-action py-1 px-3" style="font-size: 0.85rem;">View All Payroll</a>
            </div>
        </div>
        <div class="modern-card-body p-0">
            <div class="calendar-wrapper">
                <div class="calendar-header-grid">
                    <div class="calendar-header-cell">Sun</div>
                    <div class="calendar-header-cell">Mon</div>
                    <div class="calendar-header-cell">Tue</div>
                    <div class="calendar-header-cell">Wed</div>
                    <div class="calendar-header-cell">Thu</div>
                    <div class="calendar-header-cell">Fri</div>
                    <div class="calendar-header-cell">Sat</div>
                </div>
                <div id="salaryCalendarBody" class="calendar-grid">
                    <div style="grid-column: 1 / -1; padding: 4rem; text-align: center;">
                        <div class="spinner-border text-primary" role="status"></div>
                        <div class="mt-2 text-muted fw-bold">Loading schedule...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Payout Modal -->
<div class="modal fade print-hide" id="payoutModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius: 16px; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.1);">
            <form method="POST">
                <div class="modal-header border-0 pb-0">
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center pt-0 px-4">
                    <input type="hidden" name="action" value="pay_single_payroll">
                    
                    <div style="width: 64px; height: 64px; background: #dcfce7; color: #16a34a; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 2rem; margin-bottom: 1rem;">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                    <h5 class="fw-bold mb-1">Confirm Payment</h5>
                    <p class="text-muted small mb-3">Payee: <?php echo htmlspecialchars($employee['name']); ?></p>
                    
                    <div class="fw-bold mb-4" style="font-size: 2.2rem; color: #16a34a; line-height: 1;" id="pay_amount_display">Rs 0.00</div>

                    <div class="text-start mb-4">
                        <label class="form-label-modern" style="font-size: 0.75rem;">Deduct Funds From</label>
                        <select name="payment_method" class="form-input bg-light" required>
                            <option value="Cash">Cash on Hand</option>
                            <option value="Bank Transfer">Company Bank Account</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn-action btn-primary-action w-100 py-2 mb-2" onclick="return confirm('Instantly deduct funds from Company Accounts and mark as Paid. Proceed?');">
                        Confirm & Pay
                    </button>
                    <button type="button" class="btn-action btn-secondary-action w-100 py-2" data-bs-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const employeeId = <?php echo $employee_id; ?>;
    const monthStr = '<?php echo $selected_month; ?>';
    const rangeStart = '<?php echo $rangeStart; ?>';
    let calendarEvents = {};
    let calendarDate = new Date(rangeStart);
    
    const calendarBody = document.getElementById('salaryCalendarBody');
    const monthLabel = document.getElementById('calendarMonthLabel');

    function loadCalendar() {
        const year = calendarDate.getFullYear();
        const month = String(calendarDate.getMonth() + 1).padStart(2, '0');
        const monthKey = `${year}-${month}`;
        
        monthLabel.textContent = calendarDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' }) + ' Schedule';
        
        fetch(`../ajax/get_employee_calendar.php?employee_id=${employeeId}&month=${monthKey}`)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    calendarEvents = data.events;
                    renderCalendar();
                } else {
                    calendarBody.innerHTML = `<div style="grid-column: 1/-1; padding: 3rem; text-align: center; color: #dc2626;"><i class="bi bi-exclamation-triangle-fill"></i> ${data.message}</div>`;
                }
            })
            .catch(err => {
                console.error(err);
                calendarBody.innerHTML = `<div style="grid-column: 1/-1; padding: 3rem; text-align: center; color: #dc2626;"><i class="bi bi-wifi-off"></i> Failed to load calendar data.</div>`;
            });
    }

    function renderCalendar() {
        const year = calendarDate.getFullYear();
        const month = calendarDate.getMonth();
        const firstDay = new Date(year, month, 1);
        const startDow = firstDay.getDay();
        const totalDays = new Date(year, month + 1, 0).getDate();
        const prevMonthDays = new Date(year, month, 0).getDate();
        const today = new Date().toISOString().split('T')[0];
        
        let html = '';
        
        // Prev month filler
        for (let i = startDow - 1; i >= 0; i--) {
            html += `<div class="calendar-day other-month"><div class="day-num">${prevMonthDays - i}</div></div>`;
        }
        
        // Current month
        for (let d = 1; d <= totalDays; d++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const isToday = dateStr === today;
            let eventsHtml = '';
            let hasRoute = false;
            let attStatus = '';
            
            if (calendarEvents[dateStr]) {
                const ev = calendarEvents[dateStr];
                if (ev.has_route && ev.routes.length) {
                    hasRoute = true;
                    ev.routes.forEach(r => {
                        eventsHtml += `<a href="route_detailed_report.php?id=${r.assignment_id}" target="_blank" class="cal-event cal-event-route" title="${r.route_name}" onclick="event.stopPropagation();">🚚 ${r.route_name}</a>`;
                    });
                }
                if (ev.attendance_status) {
                    attStatus = ev.attendance_status;
                    if (attStatus === 'present') eventsHtml += `<span class="cal-event cal-event-present">🏢 Present (Office)</span>`;
                    else if (attStatus === 'half_day') eventsHtml += `<span class="cal-event cal-event-half">🏢 Half Day</span>`;
                    else if (attStatus === 'absent') eventsHtml += `<span class="cal-event cal-event-absent">❌ Absent</span>`;
                }
            }
            
            let logBtn = !hasRoute ? `<div class="log-btn"><i class="bi bi-pencil-square"></i> ${attStatus ? 'Edit' : 'Log'}</div>` : '';
            
            html += `
                <div class="calendar-day ${isToday ? 'today' : ''}" onclick="openLogModal('${dateStr}', ${hasRoute})">
                    <div class="day-num">${d}</div>
                    <div style="flex-grow: 1; display: flex; flex-direction: column; gap: 2px;">${eventsHtml}</div>
                    ${logBtn}
                </div>
            `;
        }
        
        // Next month filler
        const rendered = startDow + totalDays;
        const remaining = (7 - (rendered % 7)) % 7;
        for (let i = 1; i <= remaining; i++) {
            html += `<div class="calendar-day other-month"><div class="day-num">${i}</div></div>`;
        }
        
        calendarBody.innerHTML = html;
    }

    function openLogModal(dateStr, hasRoute) {
        if (hasRoute) {
            alert('Route assigned on this date. Attendance is accounted for via route system.');
            return;
        }
        window.location.href = `attendance.php?employee_id=${employeeId}&date=${dateStr}`;
    }

    function triggerPayoutModal(amount) {
        document.getElementById('pay_amount_display').textContent = 'Rs ' + parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        new bootstrap.Modal(document.getElementById('payoutModal')).show();
    }

    document.addEventListener('DOMContentLoaded', loadCalendar);
</script>

<?php include '../includes/footer.php'; ?>