<?php
// Enable error reporting
ini_set('display_errors', 0); // Disable direct display to prevent corrupting JSON
error_reporting(E_ALL);

header('Content-Type: application/json');

require_once '../config/db.php';
require_once '../includes/auth_check.php';

// Check if user is authenticated and is admin or supervisor
if (!hasRole(['admin', 'supervisor'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access'
    ]);
    exit;
}

if (!isset($_GET['employee_id']) || empty($_GET['employee_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Employee ID is required'
    ]);
    exit;
}

$employee_id = (int)$_GET['employee_id'];

try {
    // 1. Fetch employee details
    $empStmt = $pdo->prepare("SELECT id, emp_code, name, phone, designation, status, daily_rate FROM employees WHERE id = ?");
    $empStmt->execute([$employee_id]);
    $employee = $empStmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        echo json_encode([
            'success' => false,
            'message' => 'Employee not found'
        ]);
        exit;
    }

    // 2. Query routes visited by this employee (either as rep or driver)
    $historyQuery = "
        SELECT 
            rr.id as assignment_id, 
            rr.assign_date, 
            rr.status as assignment_status, 
            rr.start_meter, 
            rr.end_meter, 
            rr.actual_cash, 
            rr.actual_bank,
            r.name as route_name, 
            u_rep.name as rep_name, 
            emp_drv.name as driver_name,
            CASE 
                WHEN rr.driver_id = :emp_id_role THEN 'Driver'
                ELSE 'Representative'
            END as role_in_trip,
            COALESCE((SELECT SUM(total_amount) FROM orders WHERE assignment_id = rr.id), 0) as total_sales,
            COALESCE((SELECT SUM(amount) FROM customer_payments WHERE assignment_id = rr.id), 0) as total_collections
        FROM rep_routes rr
        JOIN routes r ON rr.route_id = r.id
        LEFT JOIN users u_rep ON rr.rep_id = u_rep.id
        LEFT JOIN employees emp_drv ON rr.driver_id = emp_drv.id
        WHERE rr.driver_id = :emp_id_drv 
           OR rr.rep_id = (SELECT user_id FROM employees WHERE id = :emp_id_rep1)
           OR rr.rep_id IN (SELECT id FROM users WHERE employee_id = :emp_id_rep2)
        ORDER BY rr.assign_date DESC
    ";

    $historyStmt = $pdo->prepare($historyQuery);
    $historyStmt->execute([
        'emp_id_role' => $employee_id,
        'emp_id_drv' => $employee_id,
        'emp_id_rep1' => $employee_id,
        'emp_id_rep2' => $employee_id
    ]);
    $historyRaw = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

    $history = [];
    foreach ($historyRaw as $row) {
        // Parse dates
        $date_ts = strtotime($row['assign_date']);
        $row['formatted_date'] = date('M d, Y', $date_ts);
        $row['month_key'] = date('F Y', $date_ts); // e.g. "June 2026"
        $row['month_val'] = date('Y-m', $date_ts);  // e.g. "2026-06"
        
        // Calculate distance if both meters are present
        if ($row['start_meter'] !== null && $row['end_meter'] !== null) {
            $row['distance'] = round((float)$row['end_meter'] - (float)$row['start_meter'], 1);
        } else {
            $row['distance'] = null;
        }

        // Type cast numeric strings to float
        $row['total_sales'] = (float)$row['total_sales'];
        $row['total_collections'] = (float)$row['total_collections'];
        $row['actual_cash'] = $row['actual_cash'] !== null ? (float)$row['actual_cash'] : null;
        $row['actual_bank'] = $row['actual_bank'] !== null ? (float)$row['actual_bank'] : null;

        $history[] = $row;
    }

    echo json_encode([
        'success' => true,
        'employee' => $employee,
        'history' => $history
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
