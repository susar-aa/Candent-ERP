<?php
/**
 * API Endpoint: Fetch vehicle stock for a specific representative (rep_id).
 */
require_once '../config/db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (isset($_GET['rep_id'])) {
    $rep_id = (int)$_GET['rep_id'];

    $stmt = $pdo->prepare("
        SELECT product_id, stock_qty
        FROM vehicle_stock
        WHERE rep_id = ?
    ");
    $stmt->execute([$rep_id]);
    $stocks = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    echo json_encode(['success' => true, 'stocks' => $stocks]);
} else {
    echo json_encode(['success' => false, 'error' => 'Representative ID missing']);
}
?>
