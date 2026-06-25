<?php
/**
 * AJAX Endpoint: Transfer stock from vehicle (rep) back to main warehouse.
 */
require_once '../config/db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';

// --- GET VEHICLE STOCK FOR A REP ---
if ($action === 'get_stock') {
    $rep_id = (int)($_POST['rep_id'] ?? 0);
    if (!$rep_id) {
        echo json_encode(['success' => false, 'error' => 'Rep ID is required']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT vs.product_id, vs.stock_qty, p.name, p.sku
        FROM vehicle_stock vs
        JOIN products p ON vs.product_id = p.id
        WHERE vs.rep_id = ? AND vs.stock_qty > 0
        ORDER BY p.name ASC
    ");
    $stmt->execute([$rep_id]);
    $items = $stmt->fetchAll();

    echo json_encode(['success' => true, 'items' => $items]);
    exit;
}

// --- GET LATEST ACTIVE ASSIGNMENT ID FOR A REP ---
if ($action === 'get_assignment') {
    $rep_id = (int)($_POST['rep_id'] ?? 0);
    if (!$rep_id) {
        echo json_encode(['success' => false, 'error' => 'Rep ID is required']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM rep_routes WHERE rep_id = ? AND status = 'completed' ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$rep_id]);
    $assignment_id = $stmt->fetchColumn();

    echo json_encode(['success' => true, 'assignment_id' => $assignment_id ? (int)$assignment_id : null]);
    exit;
}

// --- PROCESS TRANSFER ---
if ($action === 'transfer_stock') {
    $rep_id = (int)($_POST['rep_id'] ?? 0);
    $assignment_id = !empty($_POST['assignment_id']) ? (int)$_POST['assignment_id'] : null;
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['qty'] ?? [];

    if (!$rep_id || empty($product_ids) || !is_array($product_ids)) {
        echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        foreach ($product_ids as $index => $prod_id) {
            $prod_id = (int)$prod_id;
            $qty = (int)($quantities[$index] ?? 0);

            if (!$prod_id || $qty <= 0) {
                continue;
            }

            // 1. Check vehicle stock (with row lock)
            $vsStmt = $pdo->prepare("SELECT stock_qty FROM vehicle_stock WHERE rep_id = ? AND product_id = ? FOR UPDATE");
            $vsStmt->execute([$rep_id, $prod_id]);
            $current_vehicle_qty = (int)$vsStmt->fetchColumn();

            if ($current_vehicle_qty < $qty) {
                throw new Exception("Insufficient vehicle stock for Product ID $prod_id. Available: $current_vehicle_qty, requested: $qty.");
            }

            // 2. Get current warehouse stock for logging
            $prodStmt = $pdo->prepare("SELECT stock FROM products WHERE id = ? FOR UPDATE");
            $prodStmt->execute([$prod_id]);
            $current_warehouse_stock = (int)$prodStmt->fetchColumn();

            // 3. Deduct from vehicle_stock
            $new_vehicle_qty = $current_vehicle_qty - $qty;
            if ($new_vehicle_qty <= 0) {
                $pdo->prepare("DELETE FROM vehicle_stock WHERE rep_id = ? AND product_id = ?")->execute([$rep_id, $prod_id]);
            } else {
                $pdo->prepare("UPDATE vehicle_stock SET stock_qty = ? WHERE rep_id = ? AND product_id = ?")->execute([$new_vehicle_qty, $rep_id, $prod_id]);
            }

            // 4. Add back to main warehouse
            $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?")->execute([$qty, $prod_id]);

            // 5. Update route_loads.returned_qty if assignment is provided
            if ($assignment_id) {
                $rlStmt = $pdo->prepare("SELECT id, loaded_qty, returned_qty FROM route_loads WHERE assignment_id = ? AND product_id = ?");
                $rlStmt->execute([$assignment_id, $prod_id]);
                $route_load = $rlStmt->fetch();

                if ($route_load) {
                    $new_returned = (int)$route_load['returned_qty'] + $qty;
                    $pdo->prepare("UPDATE route_loads SET returned_qty = ? WHERE id = ?")->execute([$new_returned, $route_load['id']]);
                }
            }

            // 6. Log the transfer
            $pdo->prepare("INSERT INTO stock_logs (product_id, type, qty_change, previous_stock, new_stock, created_by) 
                VALUES (?, 'transfer_from_rep', ?, ?, ?, ?)")
                ->execute([$prod_id, $qty, $current_warehouse_stock, $current_warehouse_stock + $qty, $_SESSION['user_id']]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Stock transferred from vehicle to warehouse successfully!']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);