<?php
/**
 * API Endpoint: Processes the advanced billing cart, creates or UPDATES the order, handles inventory, 
 * distributes excess payments, and records Automated/Manual FOC promotions.
 */
ini_set('display_errors', 0); 
error_reporting(E_ALL); 

require_once '../config/db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// --- AUTO DB MIGRATION ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS company_finances (
        id INT PRIMARY KEY,
        cash_on_hand DECIMAL(12,2) DEFAULT 0.00,
        bank_balance DECIMAL(12,2) DEFAULT 0.00
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $pdo->exec("INSERT IGNORE INTO company_finances (id, cash_on_hand, bank_balance) VALUES (1, 0.00, 0.00)");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS finance_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('cash_in', 'cash_out', 'bank_in', 'bank_out', 'transfer') NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        description VARCHAR(255),
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    $pdo->exec("ALTER TABLE cheques ADD COLUMN IF NOT EXISTS type ENUM('incoming', 'outgoing') DEFAULT 'incoming'");
    $pdo->exec("ALTER TABLE cheques ADD COLUMN IF NOT EXISTS customer_id INT NULL");
    
    $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS assignment_id INT NULL AFTER rep_id");
    
    $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS paid_cash DECIMAL(12,2) DEFAULT 0.00 AFTER paid_amount");
    $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS paid_bank DECIMAL(12,2) DEFAULT 0.00 AFTER paid_cash");
    $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS paid_cheque DECIMAL(12,2) DEFAULT 0.00 AFTER paid_bank");

    // NEW MIGRATIONS FOR PROMOTIONS / FOC SUPPORT
    $pdo->exec("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS is_foc TINYINT(1) DEFAULT 0 AFTER discount");
    $pdo->exec("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS promo_id INT NULL AFTER is_foc");
} catch(PDOException $e) {}
// ------------------------------------------------

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['cart'])) {
    echo json_encode(['success' => false, 'message' => 'Cart is empty.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $edit_order_id = !empty($input['edit_order_id']) ? (int)$input['edit_order_id'] : null;
    $assignment_id = !empty($input['assignment_id']) ? (int)$input['assignment_id'] : null;
    $customer_id = !empty($input['customer_id']) ? (int)$input['customer_id'] : null;
    $rep_id = !empty($input['rep_id']) ? (int)$input['rep_id'] : $_SESSION['user_id'];
    $is_tiered = !empty($input['tiered_stock']);
    
    $bill_discount = (float)($input['bill_discount'] ?? 0);
    $tax_amount = (float)($input['tax_amount'] ?? 0);
    
    $paid_cash = (float)($input['paid_cash'] ?? 0);
    $paid_bank = (float)($input['paid_bank'] ?? 0);
    $paid_cheque = (float)($input['paid_cheque'] ?? 0);
    $is_general = isset($input['is_general']) && ($input['is_general'] === true || $input['is_general'] === 'true');
    
    $subtotal = 0;

    // --- EDIT MODE ONLY: RESTORE OLD STOCK FIRST ---
    if ($edit_order_id) {
        $oldOrderStmt = $pdo->prepare("SELECT assignment_id, rep_id FROM orders WHERE id = ?");
        $oldOrderStmt->execute([$edit_order_id]);
        $oldOrder = $oldOrderStmt->fetch();
        $was_general = $oldOrder && is_null($oldOrder['assignment_id']);
        $old_rep_id = $oldOrder['rep_id'] ?? $rep_id;

        // Query stock_logs to see if we can restore precisely
        $logQuery = $pdo->prepare("SELECT product_id, type, qty_change FROM stock_logs WHERE reference_id = ? AND type IN ('sale_out', 'sale_out_van')");
        $logQuery->execute([$edit_order_id]);
        $logs = $logQuery->fetchAll();

        if (count($logs) > 0) {
            $restoreWarehouse = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
            $restoreVan = $pdo->prepare("UPDATE vehicle_stock SET stock_qty = stock_qty - ? WHERE rep_id = ? AND product_id = ?");
            foreach ($logs as $log) {
                $qty_to_restore = $log['qty_change']; // negative number, e.g. -5
                if ($log['type'] === 'sale_out') {
                    $restoreWarehouse->execute([$qty_to_restore, $log['product_id']]);
                } elseif ($log['type'] === 'sale_out_van') {
                    $restoreVan->execute([$qty_to_restore, $old_rep_id, $log['product_id']]);
                }
            }
            // Delete the old logs to avoid duplicates when we insert new ones below
            $pdo->prepare("DELETE FROM stock_logs WHERE reference_id = ? AND type IN ('sale_out', 'sale_out_van')")->execute([$edit_order_id]);
        } else {
            // Fallback to original logic if no stock logs exist (for older pre-fix orders)
            $oldItems = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
            $oldItems->execute([$edit_order_id]);
            
            if ($was_general) {
                $restoreStmt = $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                foreach($oldItems->fetchAll() as $old) {
                    $restoreStmt->execute([$old['quantity'], $old['product_id']]);
                }
            } else {
                $restoreStmt = $pdo->prepare("UPDATE vehicle_stock SET stock_qty = stock_qty + ? WHERE rep_id = ? AND product_id = ?");
                foreach($oldItems->fetchAll() as $old) {
                    $restoreStmt->execute([$old['quantity'], $old_rep_id, $old['product_id']]);
                }
            }
        }
        $pdo->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$edit_order_id]);
    }
    // -----------------------------------------------

    // 1. Validate Stock & Calculate True Subtotal Securely
    $stockLogQueue = []; 
    
    foreach ($input['cart'] as $item) {
        $qty = (int)$item['quantity'];
        $sell_price = (float)$item['sell_price'];
        $item_discount = (float)$item['discount'];

        if ($is_tiered) {
            // TIERED: Check Warehouse first, then Van
            $whStmt = $pdo->prepare("SELECT stock, name FROM products WHERE id = ? FOR UPDATE");
            $whStmt->execute([$item['product_id']]);
            $whRow = $whStmt->fetch();
            
            $vanStmt = $pdo->prepare("SELECT stock_qty FROM vehicle_stock WHERE rep_id = ? AND product_id = ? FOR UPDATE");
            $vanStmt->execute([$rep_id, $item['product_id']]);
            $vanRow = $vanStmt->fetch();
            
            $whStock = $whRow ? (int)$whRow['stock'] : 0;
            $vanStock = $vanRow ? (int)$vanRow['stock_qty'] : 0;
            $totalAvail = $whStock + $vanStock;
            
            if ($totalAvail < $qty) {
                throw new Exception("Insufficient stock for '{$whRow['name']}'. Warehouse: {$whStock}, Van: {$vanStock}. Need: {$qty}");
            }
            
            $whTake = min($whStock, $qty);
            $vanTake = $qty - $whTake;
            
            $stockLogQueue[] = [
                'product_id' => $item['product_id'],
                'qty' => $qty,
                'wh_take' => $whTake,
                'van_take' => $vanTake,
                'wh_prev' => $whStock,
                'van_prev' => $vanStock
            ];
        } else {
            if ($is_general) {
                $checkStmt = $pdo->prepare("SELECT stock, name FROM products WHERE id = ? FOR UPDATE");
                $checkStmt->execute([$item['product_id']]);
            } else {
                $checkStmt = $pdo->prepare("
                    SELECT vs.stock_qty as stock, p.name 
                    FROM products p
                    LEFT JOIN vehicle_stock vs ON p.id = vs.product_id AND vs.rep_id = ?
                    WHERE p.id = ? FOR UPDATE
                ");
                $checkStmt->execute([$rep_id, $item['product_id']]);
            }
            
            $productRow = $checkStmt->fetch();
            if (!$productRow) throw new Exception("Product (ID: {$item['product_id']}) not found.");
            
            $actualStock = $productRow['stock'] !== null ? (int)$productRow['stock'] : 0;
            if ($actualStock < $qty) {
                $location = $is_general ? "Warehouse" : "Van";
                throw new Exception("Not enough stock in {$location} for '{$productRow['name']}'. Available: {$actualStock}");
            }
            
            $stockLogQueue[] = [
                'product_id' => $item['product_id'],
                'qty' => $qty,
                'actual_stock' => $actualStock
            ];
        }

        $line_total = ($sell_price * $qty) - $item_discount;
        $subtotal += $line_total;
    }

    if ($bill_discount > $subtotal) {
        $bill_discount = $subtotal;
    }
    
    $grand_total = $subtotal - $bill_discount + $tax_amount;
    
    // --- 1.5 AUTO-APPLY ACTIVE CREDIT NOTES ---
    $credit_applied = 0;
    if ($customer_id && $grand_total > 0) {
        $cnStmt = $pdo->prepare("SELECT id, paid_amount FROM orders WHERE customer_id = ? AND payment_method = 'Credit Note' AND paid_amount > 0 ORDER BY created_at ASC FOR UPDATE");
        $cnStmt->execute([$customer_id]);
        $credit_notes = $cnStmt->fetchAll();
        
        $target_to_pay = $grand_total;
        foreach ($credit_notes as $cn) {
            if ($target_to_pay <= 0) break;
            
            $cn_value = (float)$cn['paid_amount'];
            $apply_cn = min($cn_value, $target_to_pay);
            
            $new_cn_paid = $cn_value - $apply_cn;
            $pdo->prepare("UPDATE orders SET paid_amount = ? WHERE id = ?")->execute([$new_cn_paid, $cn['id']]);
            
            $target_to_pay -= $apply_cn;
            $credit_applied += $apply_cn;
        }
    }

    $remaining_grand_total = $grand_total - $credit_applied;

    // Calculate checkout payments to apply to this invoice
    $curr_cheque = min($paid_cheque, $remaining_grand_total);
    $remaining_grand_total -= $curr_cheque;
    
    $curr_bank = min($paid_bank, $remaining_grand_total);
    $remaining_grand_total -= $curr_bank;
    
    $curr_cash = min($paid_cash, $remaining_grand_total);
    $remaining_grand_total -= $curr_cash;
    
    // Remaining excess pools to distribute to older orders
    $excess_cheque = $paid_cheque - $curr_cheque;
    $excess_bank = $paid_bank - $curr_bank;
    $excess_cash = $paid_cash - $curr_cash;
    $excess_payment = $excess_cheque + $excess_bank + $excess_cash;

    // Total paid on this current invoice
    $current_paid_amount = $curr_cheque + $curr_bank + $curr_cash + $credit_applied;
    
    $applied_cash = $curr_cash;
    $applied_bank = $curr_bank;
    $applied_cheque = $curr_cheque;

    $payment_methods_used = [];
    if ($credit_applied > 0) $payment_methods_used[] = 'Credit Note';
    if ($applied_cash > 0) $payment_methods_used[] = 'Cash';
    if ($applied_bank > 0) $payment_methods_used[] = 'Bank';
    if ($applied_cheque > 0) $payment_methods_used[] = 'Cheque';
    
    if (empty($payment_methods_used)) {
        $payment_method_str = 'Credit';
    } elseif (count($payment_methods_used) > 1) {
        $payment_method_str = 'Split (' . implode('+', $payment_methods_used) . ')';
    } else {
        $payment_method_str = $payment_methods_used[0];
    }

    if ($current_paid_amount >= $grand_total && $grand_total > 0) {
        $payment_status = 'paid';
    } elseif ($grand_total == 0) { // FOC only orders
        $payment_status = 'paid';
    } elseif ($applied_cheque > 0) {
        $payment_status = 'waiting'; 
    } else {
        $payment_status = 'pending'; 
    }

    $latitude = isset($input['latitude']) && $input['latitude'] !== null ? (float)$input['latitude'] : null;
    $longitude = isset($input['longitude']) && $input['longitude'] !== null ? (float)$input['longitude'] : null;

    // 2. Create or Update the Current Order
    if ($edit_order_id) {
        $stmt = $pdo->prepare("UPDATE orders SET customer_id = ?, assignment_id = ?, subtotal = ?, discount_amount = ?, tax_amount = ?, total_amount = ?, payment_method = ?, payment_status = ?, paid_amount = ?, paid_cash = ?, paid_bank = ?, paid_cheque = ?, latitude = ?, longitude = ? WHERE id = ?");
        $stmt->execute([$customer_id, $assignment_id, $subtotal, $bill_discount, $tax_amount, $grand_total, $payment_method_str, $payment_status, $current_paid_amount, $applied_cash, $applied_bank, $applied_cheque, $latitude, $longitude, $edit_order_id]);
        $order_id = $edit_order_id;
        $success_message = 'Invoice updated successfully!';
    } else {
        $stmt = $pdo->prepare("INSERT INTO orders (customer_id, rep_id, assignment_id, subtotal, discount_amount, tax_amount, total_amount, payment_method, payment_status, paid_amount, paid_cash, paid_bank, paid_cheque, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$customer_id, $rep_id, $assignment_id, $subtotal, $bill_discount, $tax_amount, $grand_total, $payment_method_str, $payment_status, $current_paid_amount, $applied_cash, $applied_bank, $applied_cheque, $latitude, $longitude]);
        $order_id = $pdo->lastInsertId();
        $success_message = 'Invoice generated successfully!';
    }

    // Log location for rep_location_logs
    if ($latitude !== null && $longitude !== null) {
        $locStmt = $pdo->prepare("INSERT INTO rep_location_logs (user_id, latitude, longitude, activity_type, timestamp) VALUES (?, ?, ?, 'invoice_created', NOW())");
        $locStmt->execute([$rep_id, $latitude, $longitude]);
    }

    // --- 2.5 DISTRIBUTE EXCESS PAYMENT TO OLDER INVOICES ---
    if ($excess_payment > 0 && $customer_id) {
        $stmtUnpaid = $pdo->prepare("SELECT id, total_amount, paid_amount, paid_cash, paid_bank, paid_cheque FROM orders WHERE customer_id = ? AND total_amount > paid_amount AND id != ? ORDER BY created_at ASC FOR UPDATE");
        $stmtUnpaid->execute([$customer_id, $order_id]);
        $unpaid_orders = $stmtUnpaid->fetchAll();
        
        foreach ($unpaid_orders as $old_order) {
            if ($excess_cheque <= 0 && $excess_bank <= 0 && $excess_cash <= 0) break;
            
            $amount_due = $old_order['total_amount'] - $old_order['paid_amount'];
            
            $allocated_cheque = min($excess_cheque, $amount_due);
            $amount_due -= $allocated_cheque;
            
            $allocated_bank = min($excess_bank, $amount_due);
            $amount_due -= $allocated_bank;
            
            $allocated_cash = min($excess_cash, $amount_due);
            $amount_due -= $allocated_cash;
            
            $applied_to_old = $allocated_cheque + $allocated_bank + $allocated_cash;
            
            if ($applied_to_old > 0) {
                $new_paid_amount = $old_order['paid_amount'] + $applied_to_old;
                $new_paid_cash = $old_order['paid_cash'] + $allocated_cash;
                $new_paid_bank = $old_order['paid_bank'] + $allocated_bank;
                $new_paid_cheque = $old_order['paid_cheque'] + $allocated_cheque;
                
                if ($new_paid_cheque > 0 && $new_paid_amount < $old_order['total_amount']) {
                    $new_status = 'waiting';
                } else {
                    $new_status = ($new_paid_amount >= $old_order['total_amount']) ? 'paid' : 'pending';
                }
                
                $pdo->prepare("UPDATE orders SET paid_amount = ?, paid_cash = ?, paid_bank = ?, paid_cheque = ?, payment_status = ? WHERE id = ?")
                    ->execute([$new_paid_amount, $new_paid_cash, $new_paid_bank, $new_paid_cheque, $new_status, $old_order['id']]);
                
                $excess_cheque -= $allocated_cheque;
                $excess_bank -= $allocated_bank;
                $excess_cash -= $allocated_cash;
            }
        }
        
        // Leftover excess after paying off all older orders is added back to current order
        $leftover_excess = $excess_cheque + $excess_bank + $excess_cash;
        if ($leftover_excess > 0) {
            $applied_cash += $excess_cash;
            $applied_bank += $excess_bank;
            $applied_cheque += $excess_cheque;
            $current_paid_amount += $leftover_excess;
            
            $pdo->prepare("UPDATE orders SET paid_amount = ?, paid_cash = ?, paid_bank = ?, paid_cheque = ? WHERE id = ?")
                ->execute([$current_paid_amount, $applied_cash, $applied_bank, $applied_cheque, $order_id]);
        }
    }
    // -------------------------------------------------------

    // 3. Insert New Order Items (With FOC and Promo support)
    $itemStmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, supplier_id, quantity, price, discount, is_foc, promo_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    $logStmt = $pdo->prepare("INSERT INTO stock_logs (product_id, type, reference_id, qty_change, previous_stock, new_stock, created_by) VALUES (?, 'sale_out', ?, ?, ?, ?, ?)");
    $logStmtVan = $pdo->prepare("INSERT INTO stock_logs (product_id, type, reference_id, qty_change, previous_stock, new_stock, created_by) VALUES (?, 'sale_out_van', ?, ?, ?, ?, ?)");

    foreach ($input['cart'] as $index => $item) {
        $supplier_id = !empty($item['supplier_id']) ? (int)$item['supplier_id'] : null;
        $is_foc = !empty($item['is_foc']) ? 1 : 0;
        $promo_id = !empty($item['promo_id']) ? (int)$item['promo_id'] : null;
        
        $itemStmt->execute([
            $order_id, 
            $item['product_id'], 
            $supplier_id, 
            $item['quantity'], 
            $item['sell_price'], 
            $item['discount'],
            $is_foc,
            $promo_id
        ]);
        
        $logData = $stockLogQueue[$index];

        if ($is_tiered) {
            if ($logData['wh_take'] > 0) {
                $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?")->execute([$logData['wh_take'], $item['product_id']]);
                $logStmt->execute([$item['product_id'], $order_id, -$logData['wh_take'], $logData['wh_prev'], $logData['wh_prev'] - $logData['wh_take'], $_SESSION['user_id']]);
            }
            if ($logData['van_take'] > 0) {
                $pdo->prepare("UPDATE vehicle_stock SET stock_qty = stock_qty - ? WHERE rep_id = ? AND product_id = ?")->execute([$logData['van_take'], $rep_id, $item['product_id']]);
                $logStmtVan->execute([$item['product_id'], $order_id, -$logData['van_take'], $logData['van_prev'], $logData['van_prev'] - $logData['van_take'], $_SESSION['user_id']]);
            }
        } else {
            if ($is_general) {
                $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?")->execute([$item['quantity'], $item['product_id']]);
                $logStmt->execute([$item['product_id'], $order_id, -$item['quantity'], $logData['actual_stock'], $logData['actual_stock'] - $item['quantity'], $_SESSION['user_id']]);
            } else {
                $pdo->prepare("UPDATE vehicle_stock SET stock_qty = stock_qty - ? WHERE rep_id = ? AND product_id = ?")->execute([$item['quantity'], $rep_id, $item['product_id']]);
                $logStmtVan->execute([$item['product_id'], $order_id, -$item['quantity'], $logData['actual_stock'], $logData['actual_stock'] - $item['quantity'], $_SESSION['user_id']]);
            }
        }
    }

    // 4. Finances & Incoming Cheque Logic
    if (!$edit_order_id) {
        if (!$assignment_id) {
            if ($applied_cash > 0) {
                $pdo->prepare("UPDATE company_finances SET cash_on_hand = cash_on_hand + ? WHERE id = 1")->execute([$applied_cash]);
                $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('cash_in', ?, ?, ?)")->execute([$applied_cash, "Cash Sale - Order #$order_id", $_SESSION['user_id']]);
            } 
            if ($applied_bank > 0) {
                $pdo->prepare("UPDATE company_finances SET bank_balance = bank_balance + ? WHERE id = 1")->execute([$applied_bank]);
                $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('bank_in', ?, ?, ?)")->execute([$applied_bank, "Bank Transfer - Order #$order_id", $_SESSION['user_id']]);
            }
        }
        
        if ($applied_cheque > 0) {
            $cheque_bank = trim($input['cheque_bank'] ?? '');
            $cheque_number = trim($input['cheque_number'] ?? '');
            $cheque_date = $input['cheque_date'] ?? date('Y-m-d');
            
            if (!empty($cheque_bank) && !empty($cheque_number)) {
                $chkStmt = $pdo->prepare("INSERT INTO cheques (type, order_id, customer_id, bank_name, cheque_number, banking_date, amount, status) VALUES ('incoming', ?, ?, ?, ?, ?, ?, 'pending')");
                $chkStmt->execute([$order_id, $customer_id, $cheque_bank, $cheque_number, $cheque_date, $applied_cheque]);
            }
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'order_id' => $order_id, 'message' => $success_message]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Fintrix Checkout API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Processing Error: ' . $e->getMessage()
    ]);
}
?>