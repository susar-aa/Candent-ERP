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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
    exit;
}

// Get POST variables
$employee_id = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
$date = isset($_POST['date']) ? trim($_POST['date']) : '';
$status = isset($_POST['status']) ? trim($_POST['status']) : '';

if ($employee_id <= 0 || empty($date) || empty($status)) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing required fields'
    ]);
    exit;
}

// Validate date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid date format (expected YYYY-MM-DD)'
    ]);
    exit;
}

// Validate status
$valid_statuses = ['present', 'half_day', 'absent', 'clear'];
if (!in_array($status, $valid_statuses)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid status value'
    ]);
    exit;
}

try {
    if ($status === 'clear') {
        // Delete attendance record
        $stmt = $pdo->prepare("DELETE FROM attendance WHERE employee_id = ? AND work_date = ?");
        $stmt->execute([$employee_id, $date]);
        $message = 'Attendance record cleared.';
    } else {
        // Insert or Update attendance record
        $stmt = $pdo->prepare("
            INSERT INTO attendance (employee_id, work_date, status) 
            VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE status = VALUES(status)
        ");
        $stmt->execute([$employee_id, $date, $status]);
        $message = 'Attendance recorded successfully.';
    }

    echo json_encode([
        'success' => true,
        'message' => $message
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
