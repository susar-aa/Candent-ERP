<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['rep']); // Strictly for Sales Reps

$rep_id = $_SESSION['user_id'];
$auto_select_customer = isset($_GET['customer_id']) ? $_GET['customer_id'] : '';
$is_general = isset($_GET['general']) && $_GET['general'] == 'true';

// --- 1. Fetch Edit Data (If applicable) ---
$edit_mode = false;
$edit_order_data = 'null';
$order_assignment_id = null;

if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt = $pdo->prepare("
        SELECT o.*, c.email as customer_email 
        FROM orders o 
        LEFT JOIN customers c ON o.customer_id = c.id 
        WHERE o.id = ? AND o.rep_id = ?
    ");
    $stmt->execute([$edit_id, $rep_id]);
    $order = $stmt->fetch();
    
    if ($order) {
        $edit_mode = true;
        $order_assignment_id = $order['assignment_id'];
        
        $itemsStmt = $pdo->prepare("
            SELECT oi.*, p.name as product_name, p.sku, p.category_id, c.main_category_id 
            FROM order_items oi 
            JOIN products p ON oi.product_id = p.id 
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE oi.order_id = ?
        ");
        $itemsStmt->execute([$edit_id]);
        $items = $itemsStmt->fetchAll();
        
        // Fetch Cheque details if any exist for this order
        $chkStmt = $pdo->prepare("SELECT * FROM cheques WHERE order_id = ?");
        $chkStmt->execute([$edit_id]);
        $cheque = $chkStmt->fetch();
        
        $cart = [];
        foreach($items as $i) {
            $cart[] = [
                'product_id' => $i['product_id'],
                'supplier_id' => $i['supplier_id'],
                'category_id' => $i['category_id'],
                'main_category_id' => $i['main_category_id'],
                'name' => $i['product_name'],
                'sku' => $i['sku'],
                'sell_price' => (float)$i['price'],
                'quantity' => (int)$i['quantity'],
                'dis_type' => 'Rs',
                'dis_value' => (float)$i['discount'],
                'is_foc' => (bool)$i['is_foc'],
                'promo_id' => $i['promo_id'] ? (int)$i['promo_id'] : null
            ];
        }
        
        $editDataObj = [
            'order_id' => $order['id'],
            'customer_id' => $order['customer_id'],
            'customer_email' => $order['customer_email'] ?? '',
            'payment_method' => $order['payment_method'],
            'paid_amount' => (float)$order['paid_amount'],
            'paid_cash' => (float)$order['paid_cash'],
            'paid_bank' => (float)$order['paid_bank'],
            'paid_cheque' => (float)$order['paid_cheque'],
            'bill_discount' => (float)$order['discount_amount'],
            'tax_amount' => (float)$order['tax_amount'],
            'cheque' => $cheque ? [
                'bank' => $cheque['bank_name'],
                'number' => $cheque['cheque_number'],
                'date' => $cheque['banking_date']
            ] : null,
            'cart' => $cart
        ];
        $edit_order_data = json_encode($editDataObj);
    }
}

// --- 2. Verify Active Route / Edit Eligibility ---
$assignment_id = null;
$route_id = null;
$block_message = "";

if ($edit_mode) {
    if (is_null($order_assignment_id)) {
        // Editing a General Invoice (No route)
        $is_general = true;
    } else {
        // Editing an existing route order - fetch its assignment
        $asgStmt = $pdo->prepare("SELECT route_id, status FROM rep_routes WHERE id = ?");
        $asgStmt->execute([$order_assignment_id]);
        $asgInfo = $asgStmt->fetch();

        if ($asgInfo && $asgInfo['status'] === 'unloaded') {
            $block_message = "This route has already been unloaded and verified by the admin. You can no longer edit this invoice.";
        } else {
            // Safe to edit
            $assignment_id = $order_assignment_id;
            $route_id = $asgInfo['route_id'] ?? null;
        }
    }
} else {
    // New Order - Check if we are intentionally creating a General Invoice
    if (!$is_general) {
        // Check for active route (accepted and day started)
        $routeStmt = $pdo->prepare("
            SELECT id, route_id, status 
            FROM rep_routes 
            WHERE rep_id = ? 
              AND status = 'accepted' 
              AND start_meter IS NOT NULL 
            ORDER BY id DESC LIMIT 1
        ");
        $routeStmt->execute([$rep_id]);
        $routeInfo = $routeStmt->fetch();

        if ($routeInfo) {
            $assignment_id = $routeInfo['id'];
            $route_id = $routeInfo['route_id'];
        } else {
            $block_message = "You must accept today's dispatched route and enter your start meter before you can sell from vehicle stock. (Or select 'General Invoice' to sell from main inventory).";
        }
    }
}

// --- 3. Fetch Customers ---
$my_customers = [];
$whereSql = "1=1";
$params = [];

if (!$is_general) {
    // Fetch the territory_id for the current route
    $territory_id = null;
    if ($route_id) {
        $tStmt = $pdo->prepare("SELECT territory_id FROM routes WHERE id = ?");
        $tStmt->execute([$route_id]);
        $territory_id = $tStmt->fetchColumn();
    }

    if ($territory_id) {
        // Fetch customers in all routes that belong to this territory
        if ($auto_select_customer !== '') {
            $whereSql = "c.route_id IN (SELECT id FROM routes WHERE territory_id = ?) OR c.id = ?";
            $params = [$territory_id, (int)$auto_select_customer];
        } else {
            $whereSql = "c.route_id IN (SELECT id FROM routes WHERE territory_id = ?)";
            $params = [$territory_id];
        }
    } else {
        // Fallback to route_id if no territory is assigned
        if ($route_id && $auto_select_customer !== '') {
            $whereSql = "c.route_id = ? OR c.id = ?";
            $params = [$route_id, (int)$auto_select_customer];
        } elseif ($route_id) {
            $whereSql = "c.route_id = ?";
            $params = [$route_id];
        } elseif ($auto_select_customer !== '') {
            $whereSql = "c.id = ?";
            $params = [(int)$auto_select_customer];
        }
    }
}

$customers = $pdo->prepare("
    SELECT c.id, c.name, c.address, c.phone, c.whatsapp, c.email,
           (SELECT COALESCE(SUM(total_amount - paid_amount), 0) FROM orders WHERE customer_id = c.id) as outstanding
    FROM customers c WHERE $whereSql ORDER BY c.name ASC
");
$customers->execute($params);
$my_customers = $customers->fetchAll();


// --- 4. Fetch Available Stock (Vehicle vs General) ---
$vehicle_stock = [];
if ($assignment_id) {
    // Fetch Vehicle Stock (Van Sales Model)
    $stockQuery = "
        SELECT 
            p.id as product_id, p.name, p.sku, p.selling_price, p.supplier_id, p.category_id,
            c.main_category_id,
            vs.stock_qty as loaded_qty,
            vs.stock_qty as remaining_qty
        FROM vehicle_stock vs
        JOIN products p ON vs.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE vs.rep_id = ? AND vs.stock_qty > 0
        ORDER BY p.name ASC
    ";
    $stmt = $pdo->prepare($stockQuery);
    $stmt->execute([$rep_id]);
    $vehicle_stock = $stmt->fetchAll();
} elseif ($is_general) {
    // Fetch Main Inventory Stock
    $stockQuery = "
        SELECT 
            p.id as product_id, p.name, p.sku, p.selling_price, p.supplier_id, p.category_id,
            c.main_category_id,
            p.stock as loaded_qty,
            p.stock as remaining_qty
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.status = 'available' AND p.stock > 0
        ORDER BY p.name ASC
    ";
    $stmt = $pdo->prepare($stockQuery);
    $stmt->execute();
    $vehicle_stock = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Mobile POS - Rep App</title>
    
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#ffffff">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">

    <!-- Google Fonts: Inter & JetBrains Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    
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
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        body {
            background-color: var(--bg-color);
            font-family: 'Inter', sans-serif;
            color: var(--text-main);
            padding-bottom: 100px;
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
            z-index: 99;
            box-shadow: var(--shadow-sm);
        }
        .header-stack { display: flex; align-items: center; gap: 12px; }
        .back-btn {
            color: var(--text-main); font-size: 20px;
            width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;
            border-radius: 50%; background: var(--bg-color); transition: background 0.2s;
            text-decoration: none; cursor: pointer;
        }
        .back-btn:active { background: var(--border); }
        .header-title { font-size: 18px; font-weight: 700; margin: 0; letter-spacing: -0.01em; }
        .header-sub { font-size: 12px; color: var(--text-muted); font-weight: 500; display: block; }

        /* ── Content Area ── */
        .page-content { padding: 16px; }

        /* ── Customer Selection Cards ── */
        .cust-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); padding: 16px; margin-bottom: 12px;
            box-shadow: var(--shadow-sm); cursor: pointer; transition: transform 0.1s, background 0.1s;
            display: flex; align-items: flex-start; gap: 14px;
        }
        .cust-card:active { transform: scale(0.98); background: var(--bg-color); }
        
        .cust-avatar {
            width: 44px; height: 44px; border-radius: 50%; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; color: var(--primary); background: var(--primary-bg);
        }
        .cust-card.walk-in .cust-avatar { color: var(--text-muted); background: var(--border); }
        
        .cust-info { flex: 1; }
        .cust-name { font-size: 15px; font-weight: 700; color: var(--text-main); margin: 0 0 4px 0; }
        .cust-address { font-size: 12px; color: var(--text-muted); font-weight: 500; margin-bottom: 6px; }
        
        .badge-custom {
            display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600;
            padding: 4px 8px; border-radius: 6px; white-space: nowrap; font-family: 'JetBrains Mono', monospace;
        }
        .badge-custom.danger { background: var(--danger-bg); color: var(--danger); }

        /* ── Inputs ── */
        .search-wrapper { position: relative; margin-bottom: 20px; }
        .search-icon {
            position: absolute; left: 16px; top: 50%; transform: translateY(-50%);
            color: var(--text-muted); font-size: 16px;
        }
        .search-input {
            width: 100%; background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); padding: 12px 16px 12px 44px; font-size: 15px;
            font-family: 'Inter', sans-serif; color: var(--text-main); outline: none;
            transition: border 0.2s; box-shadow: var(--shadow-sm);
        }
        .search-input:focus { border-color: var(--primary); }

        .clean-input {
            width: 100%; background: var(--bg-color); border: 1px solid var(--border);
            border-radius: var(--radius-md); padding: 12px 16px; font-size: 15px;
            font-family: 'Inter', sans-serif; color: var(--text-main); outline: none;
            transition: border 0.2s;
        }
        .clean-input.mono { font-family: 'JetBrains Mono', monospace; font-weight: 600; }
        .clean-input:focus { border-color: var(--primary); background: #fff; }
        
        select.clean-input {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%2364748B%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E");
            background-repeat: no-repeat; background-position: right 14px top 50%; background-size: 10px auto;
            padding-right: 40px; font-weight: 600;
        }

        /* ── Sticky Customer Banner (Order Entry) ── */
        .sticky-customer-banner {
            background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            padding: 16px; border-bottom: 1px solid var(--border); box-shadow: var(--shadow-sm);
            position: sticky; top: 76px; z-index: 98; margin: -16px -16px 20px -16px;
            display: flex; justify-content: space-between; align-items: flex-start;
        }
        .sc-badge { display: inline-block; font-size: 10px; font-weight: 700; text-transform: uppercase; color: var(--primary); background: var(--primary-bg); padding: 4px 8px; border-radius: 6px; margin-bottom: 6px; letter-spacing: 0.05em; }
        .sc-name { font-size: 16px; font-weight: 700; color: var(--text-main); margin: 0; }
        .btn-sm-outline {
            background: var(--surface); border: 1px solid var(--border); color: var(--text-main);
            border-radius: 100px; padding: 6px 14px; font-size: 12px; font-weight: 600;
            transition: background 0.1s; cursor: pointer;
        }
        .btn-sm-outline:active { background: var(--bg-color); }

        /* ── Product List (Order Entry) ── */
        .section-title {
            font-size: 12px; font-weight: 700; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 12px 4px;
        }
        .pos-item-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-md); padding: 12px 16px; margin-bottom: 10px;
            display: flex; justify-content: space-between; align-items: center;
            cursor: pointer; transition: transform 0.1s;
        }
        .pos-item-card:active { transform: scale(0.98); background: var(--bg-color); }
        .pi-name { font-size: 14px; font-weight: 600; color: var(--text-main); margin: 0 0 4px 0; }
        .pi-meta { display: flex; align-items: center; gap: 8px; font-size: 12px; }
        .pi-price { font-family: 'JetBrains Mono', monospace; font-weight: 700; color: var(--success); }
        .pi-stock { color: var(--text-muted); background: var(--bg-color); padding: 2px 6px; border-radius: 4px; font-weight: 500; }
        .pi-add {
            width: 32px; height: 32px; border-radius: 50%; background: var(--primary-bg); color: var(--primary);
            display: flex; align-items: center; justify-content: center; font-size: 16px;
        }

        /* ── Floating Cart Button ── */
        .floating-cart-btn {
            position: fixed; bottom: 24px; left: 16px; right: 16px;
            background: var(--text-main); color: #fff;
            border-radius: var(--radius-lg); padding: 16px 20px;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: var(--shadow-lg); border: none; z-index: 1000;
            transition: transform 0.1s; cursor: pointer;
        }
        .floating-cart-btn:active { transform: scale(0.98); }
        .fc-badge {
            background: var(--primary); color: #fff; width: 28px; height: 28px;
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 700; font-family: 'JetBrains Mono', monospace;
        }
        .fc-total { font-family: 'JetBrains Mono', monospace; font-size: 18px; font-weight: 700; }

        /* ── Modals & Offcanvas ── */
        .modal-content { border: none; border-radius: 24px; box-shadow: var(--shadow-lg); }
        .modal-header { border-bottom: 1px solid var(--border); padding: 20px; }
        .modal-title { font-weight: 700; font-size: 18px; color: var(--text-main); }
        .modal-body { padding: 20px; }
        
        .offcanvas-bottom { border-radius: 24px 24px 0 0 !important; border: none; height: 90vh !important; box-shadow: 0 -8px 40px rgba(0,0,0,0.12); }
        .offcanvas-header { padding: 20px; border-bottom: 1px solid var(--border); background: var(--surface); }
        .offcanvas-title { font-size: 18px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px; }
        .offcanvas-body { background: var(--bg-color); padding: 16px; overflow-y: auto; }

        /* ── Checkout Cards ── */
        .co-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); padding: 16px; margin-bottom: 16px;
            box-shadow: var(--shadow-sm);
        }
        .co-card-title { font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.05em; margin-bottom: 12px; }

        .cart-item { display: flex; justify-content: space-between; align-items: flex-start; padding: 12px 0; border-bottom: 1px dashed var(--border); }
        .cart-item:last-child { border-bottom: none; padding-bottom: 0; }
        .cart-item:first-child { padding-top: 0; }
        .ci-name { font-size: 14px; font-weight: 600; color: var(--text-main); margin-bottom: 4px; }
        .ci-meta { font-size: 12px; color: var(--text-muted); }
        .ci-price { font-family: 'JetBrains Mono', monospace; font-size: 14px; font-weight: 700; color: var(--text-main); }
        
        .co-summary-row { display: flex; justify-content: space-between; align-items: center; font-size: 14px; padding: 6px 0; color: var(--text-muted); font-weight: 500; }
        .co-summary-row .val { font-family: 'JetBrains Mono', monospace; color: var(--text-main); font-weight: 600; }
        .co-summary-row.total { font-size: 18px; font-weight: 700; color: var(--text-main); border-top: 1px solid var(--border); padding-top: 12px; margin-top: 6px; }
        .co-summary-row.total .val { font-size: 24px; color: var(--primary); }

        .pay-input { text-align: right; font-family: 'JetBrains Mono', monospace; font-size: 16px; font-weight: 700; padding: 10px 12px; }

        .btn-full {
            width: 100%; border: none; border-radius: var(--radius-md); padding: 16px;
            font-size: 16px; font-weight: 600; cursor: pointer; transition: transform 0.1s;
            text-align: center; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px;
            background: var(--primary); color: #fff;
        }
        .btn-full:active { transform: scale(0.98); }
        .btn-full.outline { background: var(--surface); border: 1.5px solid var(--primary); color: var(--primary); }

        /* ── Simple Alerts ── */
        .clean-alert {
            background: var(--surface); border-radius: var(--radius-md); padding: 16px;
            display: flex; gap: 12px; align-items: center; border: 1px solid var(--border);
            margin-bottom: 20px;
        }
        .clean-alert.warning-alert { background: var(--warning-bg); border-color: #FDE68A; color: #92400E; }
        .clean-alert.info-alert { background: var(--primary-bg); border-color: #BFDBFE; color: #1E3A8A; }
        .clean-alert.error-alert { background: var(--danger-bg); border-color: #FECACA; color: var(--danger); }
        .clean-alert i { font-size: 24px; margin-top: -2px; }
        .clean-alert h6 { margin: 0 0 6px 0; font-weight: 700; font-size: 16px; }
        .clean-alert p { margin: 0; font-size: 14px; line-height: 1.5; opacity: 0.9; }

        /* iOS Switch override */
        .form-switch .form-check-input { width: 3em; height: 1.5em; margin-top: 0; cursor: pointer; }
    </style>
</head>
<body>

    <header class="app-header">
        <div class="header-stack">
            <a id="navBackBtn" class="back-btn"><i class="bi bi-arrow-left"></i></a>
            <div>
                <h1 class="header-title" id="navTitle">Select Customer</h1>
                <?php if($is_general): ?><span class="header-sub">General Invoice (Main Stock)</span><?php endif; ?>
            </div>
        </div>
    </header>

    <div class="page-content">
        <?php if (!$assignment_id && !$is_general): ?>
            <div class="clean-alert error-alert mt-4">
                <i class="bi bi-x-octagon"></i>
                <div>
                    <h6>Cannot Create/Edit Invoice</h6>
                    <p><?php echo htmlspecialchars($block_message); ?></p>
                    <a href="dashboard.php" class="btn-full mt-3" style="background: var(--danger); color: #fff;">Return Home</a>
                </div>
            </div>
        <?php else: ?>

            <!-- Edit Mode Banner -->
            <?php if($edit_mode): ?>
                <div class="clean-alert warning-alert mb-4 py-3 justify-content-center">
                    <i class="bi bi-pencil-square" style="font-size: 18px;"></i>
                    <h6 class="m-0">EDITING INVOICE #<?php echo str_pad($edit_id, 6, '0', STR_PAD_LEFT); ?></h6>
                </div>
            <?php endif; ?>

            <!-- ================= VIEW 1: CUSTOMER SELECTION ================= -->
            <div id="view-customer-selection">
                <div class="search-wrapper">
                    <i class="bi bi-search search-icon"></i>
                    <input type="text" id="custSearchInput" class="search-input" placeholder="Search customers...">
                </div>

                <div id="customersList">
                    <!-- Walk-in Option -->
                    <div class="cust-card walk-in cust-card-btn" data-id="0" data-name="Walk-in Customer" data-address="" data-outstanding="0" data-email="" data-whatsapp="">
                        <div class="cust-avatar"><i class="bi bi-person-walking"></i></div>
                        <div class="cust-info">
                            <h3 class="cust-name">Walk-in Customer</h3>
                            <div class="cust-address">Unregistered client</div>
                        </div>
                        <i class="bi bi-chevron-right text-muted opacity-50 mt-2"></i>
                    </div>

                    <!-- Customers Loop -->
                    <?php foreach($my_customers as $c): ?>
                    <div class="cust-card cust-card-btn" data-id="<?php echo $c['id']; ?>" data-name="<?php echo htmlspecialchars($c['name'], ENT_QUOTES); ?>" data-address="<?php echo htmlspecialchars($c['address'] ?? '', ENT_QUOTES); ?>" data-outstanding="<?php echo $c['outstanding']; ?>" data-email="<?php echo htmlspecialchars($c['email'] ?? '', ENT_QUOTES); ?>" data-whatsapp="<?php echo htmlspecialchars($c['whatsapp'] ?? '', ENT_QUOTES); ?>">
                        <div class="cust-avatar"><i class="bi bi-shop"></i></div>
                        <div class="cust-info">
                            <h3 class="cust-name"><?php echo htmlspecialchars($c['name']); ?></h3>
                            <div class="cust-address text-truncate" style="max-width: 220px;"><i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($c['address'] ?: 'No Address'); ?></div>
                            <?php if($c['outstanding'] > 0): ?>
                                <div class="badge-custom danger"><i class="bi bi-exclamation-circle-fill"></i> Ows: Rs <?php echo number_format($c['outstanding'], 2); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ================= VIEW 2: ORDER ENTRY ================= -->
            <div id="view-order-entry" class="d-none">
                
                <!-- Sticky Customer Info -->
                <div class="sticky-customer-banner">
                    <div>
                        <span class="sc-badge">Billing To</span>
                        <h2 class="sc-name" id="selectedCustName">Customer Name</h2>
                        <div id="selectedCustOut" class="text-danger fw-bold small mt-1 d-none" style="font-family: 'JetBrains Mono', monospace;"><i class="bi bi-exclamation-circle-fill me-1"></i> Prior Ows: Rs <span id="valCustOut">0.00</span></div>
                    </div>
                    <button class="btn-sm-outline" onclick="showCustomerView()">Change</button>
                </div>

                <!-- Product Search -->
                <div class="search-wrapper">
                    <i class="bi bi-search search-icon"></i>
                    <input type="text" id="prodSearchInput" class="search-input" placeholder="Search available stock...">
                </div>

                <h6 class="section-title">Available Products</h6>

                <!-- Products Grid -->
                <div id="productsList">
                    <?php if(empty($vehicle_stock)): ?>
                        <div class="clean-alert info-alert flex-column text-center py-5">
                            <i class="bi bi-box-seam" style="font-size: 3rem; margin: 0;"></i>
                            <h6 class="m-0 mt-2">No stock available!</h6>
                        </div>
                    <?php else: ?>
                        <?php foreach($vehicle_stock as $p): ?>
                        <div class="pos-item-card prod-card" onclick='openProductModal(<?php echo json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                            <div>
                                <h4 class="pi-name"><?php echo htmlspecialchars($p['name']); ?></h4>
                                <div class="pi-meta">
                                    <span class="pi-price">Rs <?php echo number_format($p['selling_price'], 2); ?></span>
                                    <span class="pi-stock"><?php echo $is_general ? 'WH' : 'Van'; ?>: <?php echo $p['remaining_qty']; ?></span>
                                </div>
                                <div class="d-none prod-sku"><?php echo htmlspecialchars($p['sku']); ?></div>
                            </div>
                            <div class="pi-add"><i class="bi bi-plus-lg"></i></div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <!-- UI Elements Protected by Assignment ID or General Mode -->
    <?php if ($assignment_id || $is_general): ?>
        
        <!-- Floating Checkout Button -->
        <button type="button" class="floating-cart-btn d-none" data-bs-toggle="offcanvas" data-bs-target="#checkoutCanvas" id="mainCheckoutBtn">
            <div class="d-flex align-items-center gap-3">
                <div class="fc-badge" id="cartItemCount">0</div>
                <span class="fw-bold fs-6">Review Cart</span>
            </div>
            <div class="fc-total">Rs <span id="cartTotalBtn">0.00</span></div>
        </button>

        <!-- ================= MODALS & OFFCANVAS ================= -->

        <!-- Product Entry Modal -->
        <div class="modal fade" id="productModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalProdName">Product Name</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="text-muted small fw-bold mb-3">Available Stock: <span id="modalProdStock" class="text-primary font-monospace fs-6 ms-1">0</span></div>
                        
                        <div class="row g-3 mb-4">
                            <div class="col-6">
                                <label class="form-label fw-bold small text-muted text-uppercase" style="letter-spacing: 0.05em;">Quantity</label>
                                <input type="number" id="modalQty" class="clean-input mono text-center fs-4 py-2" value="1" min="1">
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-bold small text-muted text-uppercase" style="letter-spacing: 0.05em;">Price (Rs)</label>
                                <input type="number" id="modalPrice" class="clean-input mono text-center fs-4 py-2 text-success" step="0.01">
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label fw-bold small text-muted text-uppercase" style="letter-spacing: 0.05em;">Item Discount</label>
                            <div class="d-flex gap-2">
                                <select id="modalDisType" class="clean-input flex-shrink-0" style="width: 80px;">
                                    <option value="%">%</option>
                                    <option value="Rs">Rs</option>
                                </select>
                                <input type="number" id="modalDisValue" class="clean-input mono text-danger" value="0" min="0" step="0.01">
                            </div>
                        </div>
                        
                        <!-- MANUAL FOC SWITCH -->
                        <div class="mb-4 p-3 rounded-3" style="background: var(--danger-bg); border: 1px solid #FECACA;">
                            <div class="form-check form-switch d-flex align-items-center gap-3 m-0 p-0">
                                <input class="form-check-input m-0 flex-shrink-0" type="checkbox" role="switch" id="isManualFoc">
                                <label class="form-check-label fw-bold text-danger m-0" for="isManualFoc" style="cursor: pointer;">Issue as Free Item (FOC)</label>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center p-3 rounded-3" style="background: var(--bg-color); border: 1px solid var(--border);">
                            <span class="fw-bold text-muted text-uppercase small">Net Total:</span>
                            <span class="fs-3 fw-bold text-dark font-monospace">Rs <span id="modalNetTotal">0.00</span></span>
                        </div>
                        
                        <button type="button" class="btn-full mt-4" id="btnAddToCart">Add to Bill</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- FULL SCREEN Checkout Offcanvas -->
        <div class="offcanvas offcanvas-bottom" tabindex="-1" id="checkoutCanvas">
            <div class="offcanvas-header">
                <h5 class="offcanvas-title"><i class="bi bi-cart-check-fill text-success fs-4"></i> Checkout Summary</h5>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
            </div>
            <div class="offcanvas-body pb-5">
                
                <!-- Cart Items (The Bill) -->
                <div class="co-card">
                    <div class="co-card-title">Billed Items</div>
                    <div id="cartItemsPreview">
                        <!-- Filled by JS -->
                    </div>
                </div>

                <!-- Discount & Tax Row -->
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <div class="co-card h-100 mb-0">
                            <label class="co-card-title">Bill Discount</label>
                            <div class="d-flex gap-1">
                                <select id="billDisType" class="clean-input flex-shrink-0 px-2 py-1" style="width: 50px; font-size: 13px;">
                                    <option value="%">%</option>
                                    <option value="Rs">Rs</option>
                                </select>
                                <input type="number" id="billDisValue" class="clean-input mono text-danger text-end px-2 py-1" style="font-size: 14px;" value="0" min="0" step="0.01">
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="co-card h-100 mb-0">
                            <label class="co-card-title">Tax / VAT</label>
                            <div class="d-flex gap-1">
                                <select id="taxDisType" class="clean-input flex-shrink-0 px-2 py-1" style="width: 50px; font-size: 13px;">
                                    <option value="%">%</option>
                                    <option value="Rs">Rs</option>
                                </select>
                                <input type="number" id="taxDisValue" class="clean-input mono text-end px-2 py-1" style="font-size: 14px;" value="0" min="0" step="0.01">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Totals -->
                <div class="co-card" style="border-color: var(--primary);">
                    <div class="co-summary-row">
                        <span>Subtotal</span>
                        <span class="val">Rs <span id="sumSubtotal">0.00</span></span>
                    </div>
                    <div id="sumPromoRow" class="co-summary-row text-success d-none">
                        <span>Promo Discount</span>
                        <span class="val">- Rs <span id="sumPromo">0.00</span></span>
                    </div>
                    <div id="sumDisRow" class="co-summary-row text-danger d-none">
                        <span>Bill Discount</span>
                        <span class="val">- Rs <span id="sumDiscount">0.00</span></span>
                    </div>
                    <div class="co-summary-row" style="color: var(--primary); font-weight: 600;">
                        <span>Current Bill</span>
                        <span class="val">Rs <span id="sumNet">0.00</span></span>
                    </div>
                    <div class="co-summary-row text-danger d-none" id="sumOutRow" style="border-top: 1px dashed var(--border); margin-top: 8px; padding-top: 8px;">
                        <span>Prior Outstanding</span>
                        <span class="val">+ Rs <span id="sumOutstanding">0.00</span></span>
                    </div>
                    <div class="co-summary-row total">
                        <span>PAYABLE</span>
                        <span class="val">Rs <span id="sumTotalPayable">0.00</span></span>
                    </div>
                </div>

                <!-- Split Payments Section -->
                <div class="co-card">
                    <div class="co-card-title">Payment Received</div>
                    
                    <div class="row align-items-center mb-3">
                        <div class="col-4 fw-bold text-success" style="font-size: 14px;"><i class="bi bi-cash-stack me-1"></i> Cash</div>
                        <div class="col-8"><input type="number" id="payCash" class="clean-input pay-input text-success" style="border-color: #A7F3D0; background: var(--success-bg);" placeholder="0.00" min="0" step="0.01"></div>
                    </div>
                    
                    <div class="row align-items-center mb-3">
                        <div class="col-4 fw-bold text-info" style="font-size: 14px;"><i class="bi bi-bank me-1"></i> Bank</div>
                        <div class="col-8"><input type="number" id="payBank" class="clean-input pay-input text-info" style="border-color: #BAE6FD; background: var(--info-bg);" placeholder="0.00" min="0" step="0.01"></div>
                    </div>
                    
                    <div class="row align-items-center mb-3">
                        <div class="col-4 fw-bold text-warning" style="font-size: 14px; color: #D97706 !important;"><i class="bi bi-credit-card-2-front me-1"></i> Cheque</div>
                        <div class="col-8"><input type="number" id="payCheque" class="clean-input pay-input" style="color: #D97706; border-color: #FDE68A; background: var(--warning-bg);" placeholder="0.00" min="0" step="0.01"></div>
                    </div>

                    <!-- Hidden Cheque Details -->
                    <div id="chequeFields" class="p-3 mt-3 d-none rounded-3" style="background: var(--warning-bg); border: 1px dashed #F59E0B;">
                        <h6 class="fw-bold mb-3 small text-uppercase" style="color: #D97706; letter-spacing: 0.05em;"><i class="bi bi-info-circle me-1"></i> Cheque Info</h6>
                        <input type="text" id="chkBank" class="clean-input mb-2" placeholder="Bank Name (e.g. Commercial)">
                        <input type="text" id="chkNum" class="clean-input mb-2" placeholder="Cheque Number">
                        <input type="date" id="chkDate" class="clean-input mono">
                    </div>

                    <div class="border-top mt-4 pt-3 d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-muted text-uppercase small" style="letter-spacing: 0.05em;" id="paymentBalanceLabel">Remaining Due</span>
                        <span class="font-monospace fs-4 fw-bold text-danger" id="paymentBalance">0.00</span>
                    </div>
                </div>

                <div id="checkoutMessage"></div>

                <button type="button" id="btnConfirmSale" class="btn-full py-3 fs-5 mt-2 shadow">
                    <?php echo $edit_mode ? 'Update Order <i class="bi bi-check2-circle ms-1"></i>' : 'Complete Order <i class="bi bi-check2-circle ms-1"></i>'; ?>
                </button>
            </div>
        </div>

        <!-- Success Output Modal -->
        <div class="modal fade" id="successModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content text-center p-2">
                    <div class="modal-body">
                        <div class="mb-3">
                            <i class="bi bi-check-circle-fill text-success" style="font-size: 5rem;"></i>
                        </div>
                        <h3 class="fw-bold text-dark mb-2">Order Saved!</h3>
                        <p class="text-muted mb-4">Invoice <strong class="text-primary font-monospace" id="successOrderId">#000000</strong> has been securely generated.</p>

                        <!-- QR Code Container -->
                        <div id="qrContainer" class="d-none bg-light p-4 rounded-4 border mb-4 d-flex justify-content-center">
                            <div id="qrcode"></div>
                        </div>

                        <div class="d-flex flex-column gap-3 mb-4">
                            <button type="button" id="btnShowQR" class="btn-full outline m-0 py-3">
                                <i class="bi bi-qr-code"></i> Show QR Code
                            </button>
                            <button type="button" id="btnEmailReceipt" class="btn-full outline m-0 py-3" style="display: none;">
                                <i class="bi bi-envelope"></i> Email Receipt
                            </button>
                            <button type="button" id="btnWhatsappShare" class="btn-full outline m-0 py-3" style="display: none; border-color: #25D366; color: #128C7E;">
                                <i class="bi bi-whatsapp"></i> Share on WhatsApp
                            </button>
                            <button type="button" id="btnPrintInvoice" class="btn-full m-0 py-3" style="background: var(--success);">
                                <i class="bi bi-printer"></i> Print PDF
                            </button>
                        </div>
                        
                        <a href="dashboard.php" class="text-muted fw-bold text-decoration-none">Back to Dashboard</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- JS specific to active route -->
        <script>
            window.editInvoiceData = <?php echo $edit_order_data; ?>;
        </script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            
            // --- 1. DOM Elements ---
            const viewCustomer = document.getElementById('view-customer-selection');
            
            if (!viewCustomer) { return; }

            let activePromotions = [];
            let productDb = [];
            let promoState = { applied: {}, rejected: [] }; // Object for applied, array for rejected
            let isEvaluatingPromos = false;

            const viewOrder = document.getElementById('view-order-entry');
            const navTitle = document.getElementById('navTitle');
            const navBackBtn = document.getElementById('navBackBtn');

            const cartBtn = document.getElementById('mainCheckoutBtn');
            const countBadge = document.getElementById('cartItemCount');
            const totalBtnText = document.getElementById('cartTotalBtn');
            
            const sumSubtotal = document.getElementById('sumSubtotal');
            const sumNet = document.getElementById('sumNet');
            const sumDiscount = document.getElementById('sumDiscount');
            const sumDisRow = document.getElementById('sumDisRow');
            
            const sumOutstanding = document.getElementById('sumOutstanding');
            const sumOutRow = document.getElementById('sumOutRow');
            const sumTotalPayable = document.getElementById('sumTotalPayable');
            
            const billDisType = document.getElementById('billDisType');
            const billDisValue = document.getElementById('billDisValue');
            const taxDisType = document.getElementById('taxDisType');
            const taxDisValue = document.getElementById('taxDisValue');

            const payCash = document.getElementById('payCash');
            const payBank = document.getElementById('payBank');
            const payCheque = document.getElementById('payCheque');
            const chequeFields = document.getElementById('chequeFields');

            const prodModal = new bootstrap.Modal(document.getElementById('productModal'));
            const modalQty = document.getElementById('modalQty');
            const modalPrice = document.getElementById('modalPrice');
            const modalDisType = document.getElementById('modalDisType');
            const modalDisValue = document.getElementById('modalDisValue');
            const modalNetTotal = document.getElementById('modalNetTotal');
            const isManualFoc = document.getElementById('isManualFoc'); 

            const custSearchInput = document.getElementById('custSearchInput');
            const prodSearchInput = document.getElementById('prodSearchInput');

            // --- 2. State Variables ---
            let selectedCustomerData = { id: null, name: 'Walk-in', address: '', outstanding: 0, email: '', whatsapp: '' };
            let cart = [];
            let activeProductData = null; 
            let finalOrderId = null;

            // --- FETCH PROMOTIONS API ---
            fetch('../ajax/fetch_promotions.php')
                .then(res => res.json())
                .then(data => {
                    if(data.success) {
                        activePromotions = data.promotions;
                        productDb = data.products;
                        console.log("Rep JSON Tiers Promotions Engine Active:", activePromotions.length, "rules loaded.");
                    }
                }).catch(err => console.error("Error loading promotions engine:", err));

            // --- 3. Function Definitions ---
            window.showCustomerView = function() {
                viewOrder.classList.add('d-none');
                viewCustomer.classList.remove('d-none');
                navTitle.textContent = "Select Customer";
                navBackBtn.removeAttribute('onclick');
                navBackBtn.href = "dashboard.php";
                cartBtn.classList.add('d-none');
            };

            function selectCustomer(id, name, address, outstanding, email, whatsapp = '') {
                if (id === 0 || id === '0') id = null;

                const outValue = parseFloat(outstanding) || 0;

                selectedCustomerData = { id: id, name: name, address: address, outstanding: outValue, email: email, whatsapp: whatsapp };
                document.getElementById('selectedCustName').textContent = name;
                
                const outEl = document.getElementById('selectedCustOut');
                if (outValue > 0) {
                    outEl.classList.remove('d-none');
                    document.getElementById('valCustOut').textContent = outValue.toFixed(2);
                } else {
                    outEl.classList.add('d-none');
                }

                viewCustomer.classList.add('d-none');
                viewOrder.classList.remove('d-none');
                navTitle.textContent = "POS Terminal";
                
                navBackBtn.removeAttribute('href');
                navBackBtn.onclick = showCustomerView;
                
                updateCartUI(); 
            };

            window.openProductModal = function(product) {
                activeProductData = product;
                
                document.getElementById('modalProdName').textContent = product.name;
                document.getElementById('modalProdStock').textContent = product.remaining_qty;
                
                isManualFoc.checked = false;
                modalPrice.readOnly = false;
                modalDisValue.readOnly = false;
                
                const existing = cart.find(c => c.product_id == product.product_id && !c.promo_id);
                if (existing) {
                    modalQty.value = existing.quantity;
                    modalPrice.value = parseFloat(existing.sell_price).toFixed(2);
                    modalDisType.value = existing.dis_type;
                    modalDisValue.value = existing.dis_value;
                    if(existing.is_foc) {
                        isManualFoc.checked = true;
                        modalPrice.readOnly = true;
                        modalDisValue.readOnly = true;
                    }
                } else {
                    modalQty.value = 1;
                    modalPrice.value = parseFloat(product.selling_price).toFixed(2);
                    modalDisType.value = '%';
                    modalDisValue.value = 0;
                }
                
                modalQty.max = product.remaining_qty;
                calculateModalNet();
                prodModal.show();
            };

            isManualFoc.addEventListener('change', function() {
                if (this.checked) {
                    modalPrice.value = "0.00";
                    modalDisValue.value = "0";
                    modalPrice.readOnly = true;
                    modalDisValue.readOnly = true;
                } else {
                    if (activeProductData) modalPrice.value = parseFloat(activeProductData.selling_price).toFixed(2);
                    modalPrice.readOnly = false;
                    modalDisValue.readOnly = false;
                }
                calculateModalNet();
            });

            function calculateModalNet() {
                if(!activeProductData) return;
                
                if (isManualFoc.checked) {
                    modalNetTotal.textContent = "0.00";
                    return;
                }

                const qty = parseFloat(modalQty.value) || 0;
                const price = parseFloat(modalPrice.value) || 0;
                const dType = modalDisType.value;
                const dVal = parseFloat(modalDisValue.value) || 0;
                
                const gross = qty * price;
                let disAmt = dType === '%' ? gross * (dVal / 100) : dVal * qty;
                
                modalNetTotal.textContent = (gross - disAmt).toFixed(2);
            }

            window.removeCartItem = function(idx) {
                cart.splice(idx, 1);
                if (window.editInvoiceData === null) {
                    localStorage.setItem('fintrix_rep_pos_cart', JSON.stringify(cart));
                }
                updateCartUI();
            };

            function removePromoFromCart(promoIdNum) {
                cart = cart.filter(item => !(item.is_foc && item.promo_id == promoIdNum));
                cart.forEach(item => {
                    if (item.promo_id == promoIdNum) {
                        item.dis_type = '%'; item.dis_value = 0; item.dis_percent = 0; item.promo_id = null;
                    }
                });
            }

            // =========================================================================
            // SMART PROMOTIONS ENGINE EVALUATOR - UNIFIED PROMPT TIERED
            // =========================================================================
            function evaluatePromotions() {
                if (isEvaluatingPromos || activePromotions.length === 0) return;
                isEvaluatingPromos = true;
                let cartChanged = false;

                let catQty = {}; let catAmt = {};
                let mainCatQty = {}; let mainCatAmt = {};
                let prodQty = {}; let prodAmt = {};

                cart.forEach(item => {
                    if (item.is_foc) return; // Don't trigger rules with FOC items

                    let cid = parseInt(item.category_id);
                    let mcid = parseInt(item.main_category_id);
                    let pid = parseInt(item.product_id);
                    let qty = parseInt(item.quantity);
                    let amt = (item.sell_price * qty) - (item.dis_type === '%' ? (item.sell_price * qty * (item.dis_value/100)) : (item.dis_value * qty));

                    if(cid) { catQty[cid] = (catQty[cid] || 0) + qty; catAmt[cid] = (catAmt[cid] || 0) + amt; }
                    if(mcid) { mainCatQty[mcid] = (mainCatQty[mcid] || 0) + qty; mainCatAmt[mcid] = (mainCatAmt[mcid] || 0) + amt; }
                    prodQty[pid] = (prodQty[pid] || 0) + qty; prodAmt[pid] = (prodAmt[pid] || 0) + amt;
                });

                let newlyTriggered = [];

                for (const promo of activePromotions) {
                    let tiers = [];
                    try { tiers = JSON.parse(promo.tiers_config); } catch(e) {}
                    if (!tiers || tiers.length === 0) continue;

                    let promoIdNum = parseInt(promo.id);
                    let currentAmt = 0; let currentQty = 0;

                    if (promo.target_main_category_id && parseInt(promo.target_main_category_id) > 0) {
                        currentAmt = mainCatAmt[parseInt(promo.target_main_category_id)] || 0;
                        currentQty = mainCatQty[parseInt(promo.target_main_category_id)] || 0;
                    } else if (promo.target_category_id && parseInt(promo.target_category_id) > 0) {
                        currentAmt = catAmt[parseInt(promo.target_category_id)] || 0;
                        currentQty = catQty[parseInt(promo.target_category_id)] || 0;
                    } else if (promo.target_product_id && parseInt(promo.target_product_id) > 0) {
                        currentAmt = prodAmt[parseInt(promo.target_product_id)] || 0;
                        currentQty = prodQty[parseInt(promo.target_product_id)] || 0;
                    }

                    let highestTierIndex = -1;
                    let highestTier = null;

                    // Find Highest Tier
                    for (let i = 0; i < tiers.length; i++) {
                        let t = tiers[i];
                        if (promo.promo_type === 'foc' && currentQty >= parseInt(t.min_qty)) {
                            if (highestTierIndex === -1 || parseInt(t.min_qty) > parseInt(highestTier.min_qty)) {
                                highestTierIndex = i; highestTier = t;
                            }
                        } else if (promo.promo_type === 'percentage' && currentAmt >= parseFloat(t.min_amount)) {
                            if (highestTierIndex === -1 || parseFloat(t.min_amount) > parseFloat(highestTier.min_amount)) {
                                highestTierIndex = i; highestTier = t;
                            }
                        }
                    }

                    if (highestTierIndex !== -1) {
                        let currentAppliedTier = promoState.applied[promoIdNum];
                        
                        if (currentAppliedTier !== highestTierIndex && !promoState.rejected.includes(promoIdNum + '_' + highestTierIndex)) {
                            newlyTriggered.push({
                                promo_id: promoIdNum,
                                promo_type: promo.promo_type,
                                tier_index: highestTierIndex,
                                tier: highestTier,
                                target_main_category_id: promo.target_main_category_id,
                                target_category_id: promo.target_category_id,
                                target_product_id: promo.target_product_id
                            });
                        } else if (currentAppliedTier === highestTierIndex && promo.promo_type === 'percentage') {
                            // MAINTENANCE: If this tier is already active, ensure any NEW items added to cart that match the target also get the discount
                            cart.forEach(item => {
                                if (!item.is_foc && !item.promo_id) {
                                    if ((parseInt(promo.target_main_category_id) > 0 && parseInt(item.main_category_id) === parseInt(promo.target_main_category_id)) || 
                                        (parseInt(promo.target_category_id) > 0 && parseInt(item.category_id) === parseInt(promo.target_category_id)) || 
                                        (parseInt(promo.target_product_id) > 0 && parseInt(item.product_id) === parseInt(promo.target_product_id))) {
                                        item.dis_type = '%';
                                        item.dis_value = parseFloat(highestTier.discount_percent);
                                        item.dis_percent = parseFloat(highestTier.discount_percent);
                                        item.promo_id = promoIdNum;
                                        cartChanged = true;
                                    }
                                }
                            });
                        }
                    } else {
                        // Dropped below all thresholds
                        if (promoState.applied[promoIdNum] !== undefined) {
                            removePromoFromCart(promoIdNum);
                            delete promoState.applied[promoIdNum];
                            cartChanged = true;
                        }
                    }
                }

                // --- ONE Unified Prompt for ALL newly triggered tiers ---
                if (newlyTriggered.length > 0) {
                    let msg = "🔥 PROMOTIONS TRIGGERED!\n\nThis order reached the tier for:\n";
                    let aggregatedFoc = {};
                    let pctMsgs = [];

                    newlyTriggered.forEach(nt => {
                        if (nt.promo_type === 'foc') {
                            let pid = nt.tier.free_product_id;
                            if(!aggregatedFoc[pid]) aggregatedFoc[pid] = 0;
                            aggregatedFoc[pid] += parseInt(nt.tier.free_qty);
                        } else {
                            pctMsgs.push(`${parseFloat(nt.tier.discount_percent)}% Value Discount`);
                        }
                    });

                    let validPrompt = false;
                    for(let pid in aggregatedFoc) {
                        let freeProd = productDb.find(p => p.id == pid);
                        if(freeProd) {
                            msg += `- ${aggregatedFoc[pid]}x ${freeProd.name} for FREE\n`;
                            validPrompt = true;
                        }
                    }
                    if (pctMsgs.length > 0) {
                        pctMsgs.forEach(m => { msg += `- ${m}\n`; validPrompt = true; });
                    }

                    if (validPrompt) {
                        msg += "\nDo you want to apply these to the bill?";
                        
                        if (confirm(msg)) {
                            newlyTriggered.forEach(nt => {
                                // Crucial: Remove the old tier for THIS promo before applying the new one
                                if (promoState.applied[nt.promo_id] !== undefined) {
                                    removePromoFromCart(nt.promo_id);
                                }

                                // Apply new tier
                                if (nt.promo_type === 'foc') {
                                    let freeProd = productDb.find(p => p.id == nt.tier.free_product_id);
                                    if (freeProd) {
                                        cart.push({
                                            product_id: freeProd.id,
                                            supplier_id: null,
                                            name: freeProd.name,
                                            product_name: freeProd.name,
                                            sku: 'FOC-PROMO',
                                            sell_price: 0,
                                            quantity: parseInt(nt.tier.free_qty),
                                            dis_type: '%',
                                            dis_value: 0,
                                            dis_percent: 0,
                                            max_stock: 9999,
                                            is_foc: true,
                                            promo_id: nt.promo_id,
                                            category_id: freeProd.category_id
                                        });
                                    }
                                } else if (nt.promo_type === 'percentage') {
                                    cart.forEach(item => {
                                        if (!item.is_foc) {
                                            if ((parseInt(nt.target_main_category_id) > 0 && parseInt(item.main_category_id) === parseInt(nt.target_main_category_id)) || 
                                                (parseInt(nt.target_category_id) > 0 && parseInt(item.category_id) === parseInt(nt.target_category_id)) || 
                                                (parseInt(nt.target_product_id) > 0 && parseInt(item.product_id) === parseInt(nt.target_product_id))) {
                                                item.dis_type = '%';
                                                item.dis_value = parseFloat(nt.tier.discount_percent);
                                                item.dis_percent = parseFloat(nt.tier.discount_percent);
                                                item.promo_id = nt.promo_id;
                                            }
                                        }
                                    });
                                }
                                promoState.applied[nt.promo_id] = nt.tier_index;
                            });
                            cartChanged = true;
                        } else {
                            newlyTriggered.forEach(nt => {
                                promoState.rejected.push(nt.promo_id + '_' + nt.tier_index);
                            });
                        }
                    }
                }

                isEvaluatingPromos = false;
                
                if (cartChanged) {
                    if (window.editInvoiceData === null) {
                        localStorage.setItem('fintrix_rep_pos_cart', JSON.stringify(cart));
                    }
                    updateCartUI(); 
                    return true;
                }
                return false;
            }

            function updateCartUI() {
                const previewBox = document.getElementById('cartItemsPreview');
                previewBox.innerHTML = '';
                
                let totalGross = 0;
                let totalPromoDiscount = 0;
                let totalItems = 0;

                cart.forEach((item, idx) => {
                    totalItems++;
                    
                    const gross = item.sell_price * item.quantity;
                    let itemDisAmt = item.dis_type === '%' ? (gross * (item.dis_value / 100)) : (item.dis_value * item.quantity);
                    const lineTotal = gross - itemDisAmt;
                    
                    totalGross += gross;
                    totalPromoDiscount += itemDisAmt;

                    previewBox.innerHTML += `
                        <div class="cart-item">
                            <div>
                                <div class="ci-name">
                                    ${item.name || item.product_name}
                                    ${item.is_foc ? '<span class="badge-custom danger ms-1">FOC</span>' : ''}
                                    ${item.promo_id ? '<span class="badge-custom warning ms-1"><i class="bi bi-magic"></i> Tier</span>' : ''}
                                </div>
                                <div class="ci-meta">${item.quantity}x @ Rs ${parseFloat(item.sell_price).toFixed(2)} ${item.dis_value > 0 ? `(-${item.dis_value}${item.dis_type} dis)` : ''}</div>
                            </div>
                            <div class="text-end">
                                <div class="ci-price">Rs ${lineTotal.toFixed(2)}</div>
                                <i class="bi bi-trash3 text-danger mt-1" style="cursor:pointer; font-size: 16px;" onclick="removeCartItem(${idx})"></i>
                            </div>
                        </div>
                    `;
                });

                if (totalItems > 0) {
                    cartBtn.classList.remove('d-none');
                    countBadge.textContent = totalItems;
                    totalBtnText.textContent = (totalGross - totalPromoDiscount).toFixed(2);
                } else {
                    cartBtn.classList.add('d-none');
                    const bsOffcanvas = bootstrap.Offcanvas.getInstance(document.getElementById('checkoutCanvas'));
                    if (bsOffcanvas) bsOffcanvas.hide();
                }

                if (evaluatePromotions()) return;
                calculateFinalTotals(totalGross, totalPromoDiscount);
            }

            function calculateFinalTotals(totalGross, totalPromoDiscount) {
                sumSubtotal.textContent = totalGross.toFixed(2);
                
                if (totalPromoDiscount > 0) {
                    document.getElementById('sumPromoRow').classList.remove('d-none');
                    document.getElementById('sumPromo').textContent = totalPromoDiscount.toFixed(2);
                } else {
                    document.getElementById('sumPromoRow').classList.add('d-none');
                }

                let subtotalAfterPromo = totalGross - totalPromoDiscount;
                
                const bDType = billDisType.value;
                const bDVal = parseFloat(billDisValue.value) || 0;
                const tType = taxDisType.value;
                const tVal = parseFloat(taxDisValue.value) || 0;

                let finalBillDiscount = bDType === '%' ? subtotalAfterPromo * (bDVal / 100) : bDVal;
                if (finalBillDiscount > subtotalAfterPromo) finalBillDiscount = subtotalAfterPromo;
                
                let discountedSubtotal = subtotalAfterPromo - finalBillDiscount;
                let finalTax = tType === '%' ? discountedSubtotal * (tVal / 100) : tVal;

                if (finalBillDiscount > 0) {
                    sumDisRow.classList.remove('d-none');
                    sumDiscount.textContent = finalBillDiscount.toFixed(2);
                } else {
                    sumDisRow.classList.add('d-none');
                }

                const netAmount = discountedSubtotal + finalTax;
                sumNet.textContent = netAmount.toFixed(2);
                
                const outstanding = parseFloat(selectedCustomerData.outstanding) || 0;
                sumOutstanding.textContent = outstanding.toFixed(2);
                if (outstanding > 0) {
                    sumOutRow.classList.remove('d-none');
                } else {
                    sumOutRow.classList.add('d-none');
                }

                const totalPayable = netAmount + outstanding;
                sumTotalPayable.textContent = totalPayable.toFixed(2);
                
                window.absoluteBillDiscount = finalBillDiscount; 
                window.absoluteTaxAmount = finalTax;
                window.totalPayable = totalPayable; 

                calculatePaymentBalance();
            }

            function calculatePaymentBalance() {
                const cash = parseFloat(payCash.value) || 0;
                const bank = parseFloat(payBank.value) || 0;
                const cheque = parseFloat(payCheque.value) || 0;

                const totalPaid = cash + bank + cheque;
                const net = window.totalPayable || 0; 
                const balance = totalPaid - net;

                const balanceEl = document.getElementById('paymentBalance');
                const balanceLabel = document.getElementById('paymentBalanceLabel');

                if (balance >= 0) {
                    balanceLabel.textContent = "Change Due";
                    balanceEl.textContent = balance.toFixed(2);
                    balanceEl.className = "font-monospace fs-4 fw-bold text-dark";
                } else {
                    balanceLabel.textContent = "Remaining Due (Ows)";
                    balanceEl.textContent = Math.abs(balance).toFixed(2);
                    balanceEl.className = "font-monospace fs-4 fw-bold text-danger";
                }

                if (cheque > 0) {
                    chequeFields.classList.remove('d-none');
                } else {
                    chequeFields.classList.add('d-none');
                }
            }

            // --- 4. Event Listeners ---

            [modalQty, modalPrice, modalDisType, modalDisValue].forEach(el => el.addEventListener('input', calculateModalNet));
            
            [billDisType, billDisValue, taxDisType, taxDisValue].forEach(el => el.addEventListener('input', () => {
                const gross = parseFloat(sumSubtotal.textContent) || 0;
                const promo = parseFloat(document.getElementById('sumPromo').textContent) || 0;
                calculateFinalTotals(gross, promo);
            }));

            [payCash, payBank, payCheque].forEach(el => el.addEventListener('input', calculatePaymentBalance));

            // Click listeners for Customer List
            document.querySelectorAll('.cust-card-btn').forEach(card => {
                card.addEventListener('click', function() {
                    const id = this.dataset.id || null;
                    const name = this.dataset.name;
                    const address = this.dataset.address;
                    const outstanding = parseFloat(this.dataset.outstanding) || 0;
                    const email = this.dataset.email || '';
                    const whatsapp = this.dataset.whatsapp || '';
                    selectCustomer(id, name, address, outstanding, email, whatsapp);
                });
            });

            // Live Searches
            if (custSearchInput) {
                custSearchInput.addEventListener('input', function() {
                    const term = this.value.toLowerCase();
                    document.querySelectorAll('.cust-card').forEach(card => {
                        const text = card.innerText.toLowerCase();
                        card.style.display = text.includes(term) ? 'flex' : 'none';
                    });
                });
            }

            if (prodSearchInput) {
                prodSearchInput.addEventListener('input', function() {
                    const term = this.value.toLowerCase();
                    document.querySelectorAll('.prod-card').forEach(card => {
                        const text = card.innerText.toLowerCase();
                        card.style.display = text.includes(term) ? 'flex' : 'none';
                    });
                });
            }

            document.getElementById('btnAddToCart').addEventListener('click', function() {
                const qty = parseInt(modalQty.value) || 0;
                const price = parseFloat(modalPrice.value) || 0;
                const maxStock = parseInt(activeProductData.remaining_qty);
                const isFoc = isManualFoc.checked;

                if (qty <= 0) { alert('Quantity must be greater than 0.'); return; }
                if (qty > maxStock) { alert(`Only ${maxStock} items available in van.`); return; }

                const existingIdx = cart.findIndex(c => c.product_id == activeProductData.product_id && c.is_foc === isFoc && !c.promo_id);
                
                const cartItem = {
                    product_id: activeProductData.product_id,
                    supplier_id: activeProductData.supplier_id,
                    name: activeProductData.name,
                    sku: activeProductData.sku || 'N/A',
                    category_id: activeProductData.category_id,
                    main_category_id: activeProductData.main_category_id,
                    sell_price: price,
                    quantity: qty,
                    dis_type: modalDisType.value,
                    dis_value: parseFloat(modalDisValue.value) || 0,
                    max_stock: maxStock,
                    is_foc: isFoc,
                    promo_id: null
                };

                if (existingIdx > -1) {
                    cart[existingIdx] = cartItem;
                } else {
                    cart.push(cartItem);
                }

                // Save to localStorage immediately so it's kept in sync
                if (window.editInvoiceData === null) {
                    localStorage.setItem('fintrix_rep_pos_cart', JSON.stringify(cart));
                }

                updateCartUI();
                prodModal.hide();
                
                prodSearchInput.value = '';
                prodSearchInput.dispatchEvent(new Event('input'));
            });

            // --- Process Final Sale ---
            document.getElementById('btnConfirmSale').addEventListener('click', function() {
                if (cart.length === 0) return;

                const chequeAmt = parseFloat(payCheque.value) || 0;
                if (chequeAmt > 0) {
                    if(!document.getElementById('chkBank').value || !document.getElementById('chkNum').value || !document.getElementById('chkDate').value) {
                        alert("Please fill in all Cheque details (Bank, Number, Date).");
                        return;
                    }
                }

                const originalBtn = this.innerHTML;
                this.disabled = true;
                this.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Locating & Processing...';
                const msgBox = document.getElementById('checkoutMessage');
                msgBox.innerHTML = '';

                let lat = null, lng = null;

                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(
                        function(position) {
                            lat = position.coords.latitude;
                            lng = position.coords.longitude;
                            sendCheckoutRequest(lat, lng, originalBtn, msgBox);
                        }, 
                        function(error) {
                            console.warn("Location error:", error.message);
                            sendCheckoutRequest(null, null, originalBtn, msgBox);
                        }, 
                        { enableHighAccuracy: true, timeout: 5000 }
                    );
                } else {
                    sendCheckoutRequest(null, null, originalBtn, msgBox);
                }
            });

            async function sendCheckoutRequest(lat, lng, originalBtn, msgBox) {
                const finalCart = cart.map(item => {
                    const gross = item.sell_price * item.quantity;
                    let itemDisAmt = item.dis_type === '%' ? (gross * (item.dis_value / 100)) : (item.dis_value * item.quantity);
                    return {
                        product_id: item.product_id,
                        supplier_id: item.supplier_id,
                        sell_price: item.sell_price,
                        quantity: item.quantity,
                        discount: itemDisAmt,
                        is_foc: item.is_foc ? 1 : 0,
                        promo_id: item.promo_id || null 
                    };
                });

                const payload = {
                    edit_order_id: window.editInvoiceData ? window.editInvoiceData.order_id : null,
                    assignment_id: <?php echo $assignment_id ? $assignment_id : 'null'; ?>,
                    customer_id: selectedCustomerData.id,
                    bill_discount: window.absoluteBillDiscount || 0,
                    tax_amount: window.absoluteTaxAmount || 0,
                    paid_cash: parseFloat(payCash.value) || 0,
                    paid_bank: parseFloat(payBank.value) || 0,
                    paid_cheque: parseFloat(payCheque.value) || 0,
                    cheque_bank: document.getElementById('chkBank').value,
                    cheque_number: document.getElementById('chkNum').value,
                    cheque_date: document.getElementById('chkDate').value,
                    latitude: lat,
                    longitude: lng,
                    is_general: <?php echo $is_general ? 'true' : 'false'; ?>,
                    cart: finalCart
                };

                try {
                    const response = await fetch('../ajax/process_checkout.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    
                    const rawText = await response.text();
                    
                    let result;
                    try {
                        result = JSON.parse(rawText);
                    } catch(e) {
                        console.error("Server Outputted Non-JSON:", rawText);
                        msgBox.innerHTML = `<div class="clean-alert error-alert mt-3"><i class="bi bi-x-octagon"></i><p>Server Error. Check console logs.</p></div>`;
                        document.getElementById('btnConfirmSale').disabled = false;
                        document.getElementById('btnConfirmSale').innerHTML = originalBtn;
                        return;
                    }

                    if (result.success) {
                        const bsOffcanvas = bootstrap.Offcanvas.getInstance(document.getElementById('checkoutCanvas'));
                        if(bsOffcanvas) bsOffcanvas.hide();

                        // Clear Catalog local storage since checkout was successful
                        localStorage.removeItem('fintrix_rep_pos_cart');

                        finalOrderId = result.order_id;
                        document.getElementById('successOrderId').textContent = '#' + String(finalOrderId).padStart(6, '0');
                        
                        // Handle Email Button Visibility
                        const btnEmail = document.getElementById('btnEmailReceipt');
                        const btnWa = document.getElementById('btnWhatsappShare');

                        if (selectedCustomerData.email && selectedCustomerData.email !== '') {
                            btnEmail.style.display = 'flex';
                            btnEmail.innerHTML = '<i class="bi bi-envelope"></i> Email Receipt';
                            btnEmail.disabled = false;
                            btnEmail.className = 'btn-full outline m-0 py-3';
                        } else {
                            btnEmail.style.display = 'none';
                        }

                        if (selectedCustomerData.whatsapp && selectedCustomerData.whatsapp !== '') {
                            btnWa.style.display = 'flex';
                        } else {
                            btnWa.style.display = 'none';
                        }

                        new bootstrap.Modal(document.getElementById('successModal')).show();
                    } else {
                        msgBox.innerHTML = `<div class="clean-alert error-alert mt-3"><i class="bi bi-exclamation-triangle"></i><p>${result.message}</p></div>`;
                        document.getElementById('btnConfirmSale').disabled = false;
                        document.getElementById('btnConfirmSale').innerHTML = originalBtn;
                    }
                } catch (error) {
                    console.error("Fetch Error:", error);
                    msgBox.innerHTML = `<div class="clean-alert error-alert mt-3"><i class="bi bi-wifi-off"></i><p>Network Error. Check connection.</p></div>`;
                    document.getElementById('btnConfirmSale').disabled = false;
                    document.getElementById('btnConfirmSale').innerHTML = originalBtn;
                }
            }

            // Success Modal Logic
            document.getElementById('btnPrintInvoice').addEventListener('click', function() {
                if(finalOrderId) window.open(`../pages/view_invoice.php?id=${finalOrderId}`, '_blank');
            });

            document.getElementById('btnWhatsappShare').addEventListener('click', function() {
                if(!finalOrderId) return;
                
                // 1. Construct Invoice URL
                const invoiceUrl = window.location.origin + window.location.pathname.replace('/rep/create_order.php', '') + `/pages/view_invoice.php?id=${finalOrderId}`;
                
                // 2. Open WhatsApp Chat
                let waNum = selectedCustomerData.whatsapp.replace(/[^0-9]/g, '');
                if (waNum.length === 10 && waNum.startsWith('0')) {
                    waNum = '94' + waNum.substring(1);
                }
                
                const message = encodeURIComponent(`Dear ${selectedCustomerData.name},\n\nPlease find your invoice at the link below:\n${invoiceUrl}\n\nKindly review it and let us know if you have any questions.\n\nThank you.`);
                
                window.open(`https://wa.me/${waNum}?text=${message}`, '_blank');
            });

            document.getElementById('btnShowQR').addEventListener('click', function() {
                if(finalOrderId) {
                    const container = document.getElementById('qrContainer');
                    const qrcodeEl = document.getElementById('qrcode');
                    
                    qrcodeEl.innerHTML = ''; 
                    const invoiceUrl = window.location.origin + window.location.pathname.replace('/rep/create_order.php', '') + `/pages/view_invoice.php?id=${finalOrderId}`;
                    
                    new QRCode(qrcodeEl, {
                        text: invoiceUrl,
                        width: 200,
                        height: 200,
                        colorDark : "#000000",
                        colorLight : "#ffffff",
                        correctLevel : QRCode.CorrectLevel.H
                    });
                    
                    container.classList.remove('d-none');
                    this.classList.add('d-none'); 
                }
            });

            // Email Receipt Logic
            document.getElementById('btnEmailReceipt').addEventListener('click', async function() {
                if(!finalOrderId) return;
                const btn = this;
                const originalHtml = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sending...';

                try {
                    const response = await fetch('../ajax/send_receipt.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `order_id=${finalOrderId}`
                    });
                    const result = await response.json();
                    
                    if(result.success) {
                        btn.innerHTML = '<i class="bi bi-check-circle"></i> Sent!';
                        btn.className = 'btn-full m-0 py-3';
                    } else {
                        alert('Error: ' + result.error);
                        btn.disabled = false;
                        btn.innerHTML = originalHtml;
                    }
                } catch(e) {
                    alert('Network or Server Error.');
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                }
            });

            // --- 5. INITIALIZATION (Edit Mode & Auto-Select) ---
            if (window.editInvoiceData !== null) {
                const data = window.editInvoiceData;
                cart = data.cart;
                billDisType.value = 'Rs';
                billDisValue.value = data.bill_discount;
                taxDisType.value = 'Rs';
                taxDisValue.value = data.tax_amount;

                payCash.value = data.paid_cash > 0 ? data.paid_cash : '';
                payBank.value = data.paid_bank > 0 ? data.paid_bank : '';
                payCheque.value = data.paid_cheque > 0 ? data.paid_cheque : '';

                if (data.paid_cheque > 0 && data.cheque) {
                    document.getElementById('chkBank').value = data.cheque.bank;
                    document.getElementById('chkNum').value = data.cheque.number;
                    document.getElementById('chkDate').value = data.cheque.date;
                    chequeFields.classList.remove('d-none');
                }
                
                const custIdToSelect = data.customer_id ? data.customer_id : 0;
                const custs = <?php echo json_encode($my_customers); ?>;
                const c = custs.find(x => x.id == custIdToSelect);
                if(c) {
                    selectCustomer(c.id, c.name, c.address, c.outstanding, c.email, c.whatsapp || '');
                } else {
                    selectCustomer(null, 'Walk-in Customer', '', 0, '', '');
                }
            } else {
                
                // Normal Mode - Check if there is an existing cart from Catalog
                const savedCart = localStorage.getItem('fintrix_rep_pos_cart');
                if (savedCart) {
                    try {
                        cart = JSON.parse(savedCart);
                    } catch(e) {}
                }

                const autoCustId = '<?php echo $auto_select_customer; ?>';
                if(autoCustId !== '') {
                    if (autoCustId === '0') {
                        selectCustomer(null, 'Walk-in Customer', '', 0, '', '');
                    } else {
                        const custs = <?php echo json_encode($my_customers); ?>;
                        const c = custs.find(x => x.id == autoCustId);
                        if(c) {
                            selectCustomer(c.id, c.name, c.address, c.outstanding, c.email, c.whatsapp || '');
                        } else {
                            navBackBtn.href = "dashboard.php";
                        }
                    }
                } else {
                    navBackBtn.href = "dashboard.php";
                    updateCartUI();
                }
            }
        });
        </script>
    <?php else: ?>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php endif; ?>

</body>
</html>