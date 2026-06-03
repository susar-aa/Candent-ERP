<?php

// Enable error reporting to prevent blank 500 errors in the future
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['rep']);

$rep_id = $_SESSION['user_id'];

// --- DB MIGRATION FOR RETURNS ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_returns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        rep_id INT NULL,
        assignment_id INT NULL,
        total_amount DECIMAL(12,2) DEFAULT 0.00,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_return_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        return_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(12,2) NOT NULL,
        condition_status ENUM('good', 'damaged', 'expired') DEFAULT 'good',
        FOREIGN KEY (return_id) REFERENCES sales_returns(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch(PDOException $e) {}

// --- VERIFY ACTIVE ROUTE ---
$routeStmt = $pdo->prepare("SELECT id FROM rep_routes WHERE rep_id = ? AND assign_date = CURDATE() AND status = 'accepted' AND start_meter IS NOT NULL ORDER BY id DESC LIMIT 1");
$routeStmt->execute([$rep_id]);
$assignment_id = $routeStmt->fetchColumn();

// --- HANDLE SUBMISSION ---
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['process_return'])) {
    $customer_id = (int)$_POST['customer_id'];
    $notes = trim($_POST['notes']);
    $products = $_POST['product_id'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $prices = $_POST['price'] ?? [];
    $conditions = $_POST['condition'] ?? [];

    if ($customer_id && !empty($products)) {
        try {
            $pdo->beginTransaction();

            // 1. Calculate Total Return Value
            $total_return_value = 0;
            foreach ($products as $idx => $pid) {
                if ($pid && (int)$qtys[$idx] > 0) {
                    $total_return_value += ((int)$qtys[$idx] * (float)$prices[$idx]);
                }
            }

            // 2. Insert Return Record
            $stmt = $pdo->prepare("INSERT INTO sales_returns (customer_id, rep_id, assignment_id, total_amount, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$customer_id, $rep_id, $assignment_id, $total_return_value, $notes]);
            $return_id = $pdo->lastInsertId();

            // 3. Distribute return credit to customer's outstanding invoices, oldest first
            if ($total_return_value > 0) {
                $unpaidStmt = $pdo->prepare("SELECT id, total_amount, paid_amount FROM orders WHERE customer_id = ? AND payment_method != 'Credit Note' AND total_amount > paid_amount ORDER BY created_at ASC FOR UPDATE");
                $unpaidStmt->execute([$customer_id]);
                $unpaid_orders = $unpaidStmt->fetchAll();

                $remaining_credit = $total_return_value;
                foreach ($unpaid_orders as $order) {
                    if ($remaining_credit <= 0) break;
                    
                    $amount_due = $order['total_amount'] - $order['paid_amount'];
                    $amount_to_apply = min($amount_due, $remaining_credit);

                    $new_paid_amount = $order['paid_amount'] + $amount_to_apply;
                    $new_status = ($new_paid_amount >= $order['total_amount']) ? 'paid' : 'pending';

                    $updateStmt = $pdo->prepare("UPDATE orders SET paid_amount = ?, payment_status = ? WHERE id = ?");
                    $updateStmt->execute([$new_paid_amount, $new_status, $order['id']]);

                    $remaining_credit -= $amount_to_apply;
                }

                // Issue Credit Note ONLY for remaining excess credit
                if ($remaining_credit > 0) {
                    $cnStmt = $pdo->prepare("INSERT INTO orders (customer_id, rep_id, assignment_id, subtotal, total_amount, paid_amount, payment_method, payment_status) VALUES (?, ?, ?, 0, 0, ?, 'Credit Note', 'paid')");
                    $cnStmt->execute([$customer_id, $rep_id, $assignment_id, $remaining_credit]);
                }
            }

            // 4. Process Items & Restock if Good
            $itemStmt = $pdo->prepare("INSERT INTO sales_return_items (return_id, product_id, quantity, unit_price, condition_status) VALUES (?, ?, ?, ?, ?)");
            $restockGoodStmt = $pdo->prepare("INSERT INTO vehicle_stock (rep_id, product_id, stock_qty) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE stock_qty = stock_qty + ?");
            $restockBadStmt = $pdo->prepare("INSERT INTO damaged_stock (product_id, stock_qty) VALUES (?, ?) ON DUPLICATE KEY UPDATE stock_qty = stock_qty + ?");
            $logStmt = $pdo->prepare("INSERT INTO stock_logs (product_id, type, reference_id, qty_change, created_by) VALUES (?, ?, ?, ?, ?)");

            foreach ($products as $idx => $pid) {
                $qty = (int)$qtys[$idx];
                $price = (float)$prices[$idx];
                $cond = $conditions[$idx];

                if ($pid && $qty > 0) {
                    $itemStmt->execute([$return_id, $pid, $qty, $price, $cond]);

                    if ($cond === 'good') {
                        $restockGoodStmt->execute([$rep_id, $pid, $qty, $qty]);
                        $logStmt->execute([$pid, 'returned_good_van', $return_id, $qty, $rep_id]);
                    } else {
                        // damaged or expired
                        $restockBadStmt->execute([$pid, $qty, $qty]);
                        $logStmt->execute([$pid, 'returned_damaged', $return_id, $qty, $rep_id]);
                    }
                }
            }

            $pdo->commit();
            $message = "<div class='clean-alert success-alert mb-4'><i class='bi bi-check-circle-fill'></i><div><h6 class='m-0 fw-bold'>Return Processed</h6><p class='m-0 small'>Rs " . number_format($total_return_value, 2) . " credited to customer account.</p></div></div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='clean-alert error-alert mb-4'><i class='bi bi-exclamation-triangle-fill'></i><div><h6 class='m-0 fw-bold'>Error</h6><p class='m-0 small'>" . htmlspecialchars($e->getMessage()) . "</p></div></div>";
        }
    }
}

// Fetch Data for Dropdowns
$customers = $pdo->query("SELECT id, name FROM customers ORDER BY name ASC")->fetchAll();
$products = $pdo->query("SELECT id, name, selling_price FROM products WHERE status = 'available' ORDER BY name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Customer Return - Rep</title>
    
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#ffffff">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">

    <!-- Google Fonts: Inter & JetBrains Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        :root {
            /* Clean UI Color Palette */
            --bg-color: #F8FAFC;         
            --surface: #FFFFFF;          
            --text-main: #0F172A;        
            --text-muted: #64748B;       
            --border: #E2E8F0;           
            
            --primary: #2563EB;          
            --primary-bg: #EFF6FF;
            --success: #10B981;          
            --success-bg: #ECFDF5;
            --danger: #EF4444;           
            --danger-bg: #FEF2F2;
            --warning: #F59E0B;          
            --warning-bg: #FFFBEB;
            --info: #0EA5E9;
            --info-bg: #E0F2FE;
            
            --radius-lg: 20px;
            --radius-md: 14px;
            --radius-sm: 10px;
            
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            
            --nav-h: 70px;
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        body {
            background-color: var(--bg-color);
            font-family: 'Inter', sans-serif;
            color: var(--text-main);
            padding-bottom: calc(var(--nav-h) + 20px);
            -webkit-font-smoothing: antialiased;
            margin: 0;
        }

        /* ── Modern Header ── */
        .app-header {
            background: var(--surface);
            padding: 20px 20px 16px;
            display: flex;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow-sm);
        }
        .header-stack { display: flex; align-items: center; gap: 12px; }
        .back-btn {
            color: var(--text-main); font-size: 20px;
            width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;
            border-radius: 50%; background: var(--bg-color); transition: background 0.2s;
            text-decoration: none;
        }
        .back-btn:active { background: var(--border); }
        .header-title { font-size: 18px; font-weight: 700; margin: 0; letter-spacing: -0.01em; }
        .header-sub { font-size: 12px; color: var(--text-muted); font-weight: 500; display: block; }

        /* ── Content Area ── */
        .page-content { padding: 20px 16px; }

        /* ── Form Cards ── */
        .return-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); padding: 20px; margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
        }
        .card-label {
            font-size: 11px; font-weight: 700; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px;
            display: block;
        }

        /* ── Inputs ── */
        .clean-input {
            width: 100%; background: var(--bg-color); border: 1px solid var(--border);
            border-radius: var(--radius-md); padding: 14px 16px; font-size: 15px;
            font-family: 'Inter', sans-serif; color: var(--text-main); outline: none;
            transition: border 0.2s;
        }
        .clean-input.mono { font-family: 'JetBrains Mono', monospace; }
        .clean-input:focus { border-color: var(--primary); background: #fff; }
        
        select.clean-input {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%2364748B%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E");
            background-repeat: no-repeat; background-position: right 14px top 50%; background-size: 10px auto;
            padding-right: 40px; font-weight: 500;
        }

        /* ── Dynamic Rows ── */
        .item-row {
            background: var(--bg-color); border: 1px solid var(--border);
            border-radius: var(--radius-md); padding: 16px; margin-bottom: 12px;
            position: relative;
        }
        .item-row .clean-input {
            background: var(--surface);
            padding: 10px 12px; font-size: 14px; border-radius: var(--radius-sm);
        }
        .remove-row {
            position: absolute; top: -8px; right: -8px; width: 24px; height: 24px;
            background: var(--danger); color: #fff; border-radius: 50%;
            border: none; display: flex; align-items: center; justify-content: center;
            font-size: 12px; cursor: pointer; box-shadow: var(--shadow-sm);
        }

        /* ── Buttons ── */
        .btn-full {
            width: 100%; border: none; border-radius: var(--radius-md); padding: 16px;
            font-size: 16px; font-weight: 600; cursor: pointer; transition: transform 0.1s;
            text-align: center; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px;
            background: var(--primary); color: #fff;
        }
        .btn-full:active { transform: scale(0.98); }
        .btn-sm-outline {
            background: var(--surface); border: 1px solid var(--primary); color: var(--primary);
            border-radius: 100px; padding: 6px 14px; font-size: 12px; font-weight: 600;
            display: inline-flex; align-items: center; gap: 4px; transition: background 0.1s;
        }
        .btn-sm-outline:active { background: var(--primary-bg); }

        /* ── Simple Alerts ── */
        .clean-alert {
            background: var(--surface); border-radius: var(--radius-md); padding: 16px;
            display: flex; gap: 12px; align-items: center; border: 1px solid var(--border);
            margin-bottom: 20px;
        }
        .clean-alert.info-alert { background: var(--primary-bg); border-color: #BFDBFE; color: #1E3A8A; }
        .clean-alert.success-alert { background: var(--success-bg); border-color: #A7F3D0; color: var(--success); }
        .clean-alert.error-alert { background: var(--danger-bg); border-color: #FECACA; color: var(--danger); }
        .clean-alert.warning-alert { background: var(--warning-bg); border-color: #FDE68A; color: #92400E; }
        .clean-alert i { font-size: 22px; margin-top: -2px; }
        .clean-alert.warning-alert i { color: var(--warning); }
        .clean-alert h6 { margin: 0 0 4px 0; font-weight: 700; font-size: 15px; }
        .clean-alert p { margin: 0; font-size: 13px; line-height: 1.4; }

        /* ── Bottom Nav (Glassmorphism) ── */
        .bottom-nav {
            position: fixed; bottom: 0; left: 0; right: 0;
            background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            border-top: 1px solid rgba(226, 232, 240, 0.8);
            display: flex; justify-content: space-around; align-items: center;
            height: var(--nav-h); z-index: 1000; padding-bottom: env(safe-area-inset-bottom, 0);
        }
        .nav-tab {
            flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px;
            text-decoration: none; color: var(--text-muted); font-size: 11px; font-weight: 500;
            padding: 8px 0; transition: color 0.2s;
        }
        .nav-tab i { font-size: 22px; }
        .nav-tab.active { color: var(--primary); }
        .nav-fab-wrapper { position: relative; top: -16px; flex: 1; display: flex; flex-direction: column; align-items: center; text-decoration: none;}
        .nav-fab {
            width: 52px; height: 52px; border-radius: 50%;
            background: var(--primary); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; box-shadow: 0 8px 16px rgba(37, 99, 235, 0.25);
            transition: transform 0.1s;
        }
        .nav-fab:active { transform: scale(0.95); }
        .nav-fab-label { font-size: 11px; font-weight: 600; color: var(--text-main); margin-top: 6px; }
    </style>
</head>
<body>

    <header class="app-header">
        <div class="header-stack">
            <a href="dashboard.php" class="back-btn"><i class="bi bi-arrow-left"></i></a>
            <div>
                <h1 class="header-title">Process Return</h1>
                <span class="header-sub">Issue Customer Credit Note</span>
            </div>
        </div>
    </header>

    <div class="page-content">
        <?php if (!$assignment_id): ?>
            <div class="clean-alert warning-alert">
                <i class="bi bi-signpost-split"></i>
                <div>
                    <h6 class="m-0 fw-bold">No Active Route</h6>
                    <p class="m-0 small mt-1">You must start your day and select a route before processing returns.</p>
                </div>
            </div>
        <?php else: ?>

            <?php echo $message; ?>

            <form method="POST">
                <input type="hidden" name="process_return" value="1">
                
                <div class="return-card">
                    <label class="card-label">1. Select Customer</label>
                    <select name="customer_id" class="clean-input" required>
                        <option value="">-- Choose Customer --</option>
                        <?php foreach($customers as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="return-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <label class="card-label mb-0">2. Returned Items</label>
                        <button type="button" class="btn-sm-outline" id="addRowBtn"><i class="bi bi-plus-lg"></i> Add Row</button>
                    </div>

                    <div id="itemsContainer">
                        <div class="item-row">
                            <button type="button" class="remove-row" aria-label="Remove"><i class="bi bi-x"></i></button>
                            <div class="mb-3 pe-2">
                                <select name="product_id[]" class="clean-input" required>
                                    <option value="">- Select Product -</option>
                                    <?php foreach($products as $p): ?>
                                        <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="row g-2 pe-2">
                                <div class="col-4">
                                    <input type="number" name="qty[]" class="clean-input mono text-center px-1" placeholder="Qty" required min="1">
                                </div>
                                <div class="col-4">
                                    <input type="number" name="price[]" class="clean-input mono text-center px-1" placeholder="Rate" required step="0.01">
                                </div>
                                <div class="col-4">
                                    <select name="condition[]" class="clean-input text-center px-1" required style="padding-right: 24px; font-weight: 600;">
                                        <option value="good" style="color: var(--success);">Good</option>
                                        <option value="damaged" style="color: var(--danger);">Damage</option>
                                        <option value="expired" style="color: var(--warning);">Expire</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="clean-alert info-alert mt-3 mb-0" style="padding: 12px; gap: 8px;">
                        <i class="bi bi-info-circle" style="font-size: 16px;"></i>
                        <p style="font-size: 12px;">"Good" condition items are restocked immediately. Others are documented but not restocked.</p>
                    </div>
                </div>

                <div class="return-card">
                    <label class="card-label">3. Optional Notes</label>
                    <input type="text" name="notes" class="clean-input" placeholder="Reason for return...">
                </div>

                <button type="submit" class="btn-full" onclick="return confirm('Process return? The value will be credited to the customer account.');">
                    <i class="bi bi-arrow-return-left"></i> Confirm Return & Credit
                </button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Mobile Bottom Navigation -->
    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-tab">
            <i class="bi bi-house-door-fill"></i> Home
        </a>
        <a href="catalog.php" class="nav-tab">
            <i class="bi bi-grid"></i> Catalog
        </a>
        <div class="nav-fab-wrapper">
            <a href="create_order.php" class="nav-fab">
                <i class="bi bi-plus-lg"></i>
            </a>
            <span class="nav-fab-label">POS</span>
        </div>
        <a href="customers.php" class="nav-tab">
            <i class="bi bi-people-fill"></i> Customers
        </a>
        <a href="analytics.php" class="nav-tab">
            <i class="bi bi-bar-chart-line-fill"></i> Stats
        </a>
    </nav>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.getElementById('addRowBtn').addEventListener('click', function() {
        const container = document.getElementById('itemsContainer');
        const row = container.querySelector('.item-row').cloneNode(true);
        row.querySelectorAll('input').forEach(input => input.value = '');
        row.querySelector('select[name="product_id[]"]').value = '';
        row.querySelector('select[name="condition[]"]').value = 'good';
        container.appendChild(row);
    });

    document.getElementById('itemsContainer').addEventListener('click', function(e) {
        // Find if the clicked element is the button or inside the button (like the icon)
        const removeBtn = e.target.closest('.remove-row');
        if(removeBtn) {
            if (document.querySelectorAll('.item-row').length > 1) {
                removeBtn.closest('.item-row').remove();
            } else {
                alert("You must have at least one return item row.");
            }
        }
    });
    </script>
</body>
</html>