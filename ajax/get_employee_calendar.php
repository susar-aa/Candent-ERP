<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');

require_once '../config/db.php';
require_once '../includes/auth_check.php';

// Check roles
if (!hasRole(['admin', 'supervisor'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access'
    ]);
    exit;
}

if (!isset($_GET['employee_id']) || empty($_GET['employee_id']) || !isset($_GET['month']) || empty($_GET['month'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Employee ID and Month (YYYY-MM) are required'
    ]);
    exit;
}

$employee_id = (int)$_GET['employee_id'];
$month_str = trim($_GET['month']); // YYYY-MM

if (!preg_match('/^\d{4}-\d{2}$/', $month_str)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid month format (expected YYYY-MM)'
    ]);
    exit;
}

$start_date = $month_str . '-01';
$end_date = date('Y-m-t', strtotime($start_date));

try {
    // Ensure attendance table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        work_date DATE NOT NULL,
        status ENUM('present', 'half_day', 'absent') DEFAULT 'present',
        UNIQUE KEY emp_date (employee_id, work_date),
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 1. Fetch employee details
    $empStmt = $pdo->prepare("SELECT id, name, designation FROM employees WHERE id = ?");
    $empStmt->execute([$employee_id]);
    $employee = $empStmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        echo json_encode([
            'success' => false,
            'message' => 'Employee not found'
        ]);
        exit;
    }

    // 2. Fetch routes in range
    $routeQuery = "
        SELECT rr.id as assignment_id, rr.assign_date, rr.status as assignment_status, r.name as route_name
        FROM rep_routes rr
        JOIN routes r ON rr.route_id = r.id
        WHERE (rr.driver_id = :emp_id_drv 
           OR rr.rep_id = (SELECT user_id FROM employees WHERE id = :emp_id_rep1)
           OR rr.rep_id IN (SELECT id FROM users WHERE employee_id = :emp_id_rep2))
          AND rr.assign_date BETWEEN :start_date AND :end_date
        ORDER BY rr.assign_date ASC
    ";
    
    $routeStmt = $pdo->prepare($routeQuery);
    $routeStmt->execute([
        'emp_id_drv' => $employee_id,
        'emp_id_rep1' => $employee_id,
        'emp_id_rep2' => $employee_id,
        'start_date' => $start_date,
        'end_date' => $end_date
    ]);
    $routes = $routeStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Fetch attendance in range
    $attQuery = "
        SELECT work_date, status
        FROM attendance
        WHERE employee_id = :emp_id
          AND work_date BETWEEN :start_date AND :end_date
    ";
    $attStmt = $pdo->prepare($attQuery);
    $attStmt->execute([
        'emp_id' => $employee_id,
        'start_date' => $start_date,
        'end_date' => $end_date
    ]);
    $attendance = $attStmt->fetchAll(PDO::FETCH_ASSOC);

    // Format data grouped by date for easy lookup
    $events = [];

    // Add routes to events
    foreach ($routes as $r) {
        $date = $r['assign_date'];
        if (!isset($events[$date])) {
            $events[$date] = [
                'has_route' => true,
                'routes' => []
            ];
        }
        $events[$date]['routes'][] = [
            'assignment_id' => $r['assignment_id'],
            'route_name' => $r['route_name'],
            'status' => $r['assignment_status']
        ];
    }

    // Add attendance to events
    foreach ($attendance as $a) {
        $date = $a['work_date'];
        if (!isset($events[$date])) {
            $events[$date] = [
                'has_route' => false,
                'routes' => []
            ];
        }
        $events[$date]['attendance_status'] = $a['status'];
    }

    echo json_encode([
        'success' => true,
        'employee' => $employee,
        'month' => $month_str,
        'events' => $events
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
