<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor', 'rep']);

// --- EDIT MODE LOGIC ---
$edit_mode = false;
$edit_order_data = 'null';

if (isset($_GET['edit_id']) && hasRole(['admin', 'supervisor'])) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$edit_id]);
    $order = $stmt->fetch();

    if ($order) {
        $edit_mode = true;
        $itemsStmt = $pdo->prepare("
            SELECT oi.*, p.name as product_name, p.sku, p.stock as current_stock 
            FROM order_items oi 
            JOIN products p ON oi.product_id = p.id 
            WHERE oi.order_id = ?
        ");
        $itemsStmt->execute([$edit_id]);
        $items = $itemsStmt->fetchAll();

        $chkStmt = $pdo->prepare("SELECT * FROM cheques WHERE order_id = ?");
        $chkStmt->execute([$edit_id]);
        $cheque = $chkStmt->fetch();

        $cart = [];
        foreach ($items as $i) {
            $cart[] = [
                'product_id'   => $i['product_id'],
                'product_name' => $i['product_name'],
                'sku'          => $i['sku'],
                'supplier_id'  => $i['supplier_id'],
                'sell_price'   => (float)$i['price'],
                'quantity'     => (int)$i['quantity'],
                'discount'     => (float)$i['discount'],
                'dis_percent'  => ($i['price'] * $i['quantity'] > 0) ? round(($i['discount'] / ($i['price'] * $i['quantity'])) * 100, 2) : 0,
                'max_stock'    => (int)$i['current_stock'] + (int)$i['quantity'],
                'is_foc'       => (bool)$i['is_foc'],
                'promo_id'     => $i['promo_id'] ? (int)$i['promo_id'] : null
            ];
        }

        $logTypeStmt = $pdo->prepare("SELECT type FROM stock_logs WHERE reference_id = ? AND type IN ('sale_out', 'sale_out_van') LIMIT 1");
        $logTypeStmt->execute([$edit_id]);
        $logTypeRow = $logTypeStmt->fetch();
        $stock_source = 'warehouse';
        if ($logTypeRow) {
            $stock_source = ($logTypeRow['type'] === 'sale_out_van') ? 'vehicle' : 'warehouse';
        } else {
            $stock_source = is_null($order['assignment_id']) ? 'warehouse' : 'vehicle';
        }

        $editDataObj = [
            'order_id'        => $order['id'],
            'customer_id'     => $order['customer_id'],
            'payment_method'  => $order['payment_method'],
            'paid_amount'     => (float)$order['paid_amount'],
            'paid_cash'       => (float)$order['paid_cash'],
            'paid_bank'       => (float)$order['paid_bank'],
            'paid_cheque'     => (float)$order['paid_cheque'],
            'discount_amount' => (float)$order['discount_amount'],
            'tax_amount'      => (float)$order['tax_amount'],
            'cheque'          => $cheque ? [
                'bank'   => $cheque['bank_name'],
                'number' => $cheque['cheque_number'],
                'date'   => $cheque['banking_date']
            ] : null,
            'stock_source'    => $stock_source,
            'cart'            => $cart
        ];
        $edit_order_data = json_encode($editDataObj);
    }
}

// Fetch data with route details mapping
$custQuery = "
    SELECT c.id, c.name, c.address, c.phone, r.name as route_name,
           (SELECT COALESCE(SUM(total_amount - paid_amount), 0) FROM orders WHERE customer_id = c.id) as outstanding
    FROM customers c
    LEFT JOIN routes r ON c.route_id = r.id
";
if (hasRole('rep')) {
    $custQuery .= " WHERE c.rep_id = " . (int)$_SESSION['user_id'];
}
$custQuery .= " ORDER BY c.name ASC";
$customers = $pdo->query($custQuery)->fetchAll();

$products = $pdo->query("
    SELECT p.id, p.name, p.sku, p.selling_price, p.stock, p.supplier_id, p.category_id, c.main_category_id 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.status = 'available' 
    ORDER BY p.name ASC
")->fetchAll();

$reps = [];
if (hasRole(['admin', 'supervisor'])) {
    $reps = $pdo->query("SELECT id, name FROM users ORDER BY name ASC")->fetchAll();
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">

<style>
/* ═══════════════════════════════════════════════
   POS LAYOUT — TRUE NO-SCROLL VIEWPORT FIT
   The entire POS lives inside .pos-shell which is
   a fixed-height flex column. Nothing overflows.
   ═══════════════════════════════════════════════ */

/* Override any body/wrapper padding added by the theme */
body, html { overflow: hidden !important; }

.pos-shell {
    display: flex;
    flex-direction: column;
    height: calc(100dvh - 56px); /* subtract sidebar/header height — adjust if needed */
    padding: 8px 12px;
    gap: 6px;
    box-sizing: border-box;
    overflow: hidden;
}

/* ── ROW 1 : Topbar ───────────────────────────── */
.pos-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
    padding: 4px 0;
    border-bottom: 1px solid var(--ios-separator);
}
.pos-title {
    font-size: 1.2rem;
    font-weight: 800;
    letter-spacing: -0.5px;
    color: var(--ios-label);
    margin: 0;
}
.pos-edit-banner {
    background: rgba(255,149,0,0.12);
    color: #C07000;
    border-radius: 8px;
    padding: 4px 12px;
    font-weight: 700;
    font-size: 0.78rem;
    border: 1px solid rgba(255,149,0,0.25);
}

/* ── ROW 2 : Details strip (invoice + customer + totals) ── */
.pos-details {
    display: grid;
    grid-template-columns: 1fr 1fr 300px;
    gap: 6px;
    flex-shrink: 0;
}
.pos-card {
    background: var(--ios-surface);
    border-radius: 10px;
    box-shadow: var(--shadow-card);
    padding: 10px 12px;
}

/* ── ROW 3 : Product entry bar ────────────────── */
.pos-entry {
    flex-shrink: 0;
    background: var(--ios-surface-2);
    border-radius: 10px;
    padding: 8px 12px;
    position: relative;
    z-index: 50;
    overflow: visible;
}
.pos-entry .row { align-items: flex-end; }

/* ── ROW 4 : Cart — grows to fill remaining space ── */
.pos-cart-wrap {
    flex: 1 1 0;
    min-height: 0;           /* critical: allows flex child to shrink below content */
    background: var(--ios-surface);
    border-radius: 10px;
    box-shadow: var(--shadow-card);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.pos-cart-scroll {
    flex: 1 1 0;
    overflow-y: auto;
    overflow-x: hidden;
}

/* ── ROW 5 : Payment strip ────────────────────── */
.pos-payment {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 6px;
    flex-shrink: 0;
}
.pos-pay-card {
    background: var(--ios-surface);
    border-radius: 10px;
    box-shadow: var(--shadow-card);
    padding: 10px 14px;
    border-top: 3px solid var(--accent);
}
.pos-balance-card {
    background: var(--ios-surface-2);
    border-radius: 10px;
    padding: 10px 20px;
    min-width: 180px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
}

/* ── Shared input / label styles ──────────────── */
.lbl {
    display: block;
    font-size: 0.6rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--ios-label-3);
    margin-bottom: 3px;
}
.fi {
    background: var(--ios-bg);
    border: 1px solid transparent;
    border-radius: 7px;
    padding: 5px 10px;
    font-size: 0.82rem;
    color: var(--ios-label);
    width: 100%;
    min-height: 32px;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.fi:focus {
    background: #fff;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(48,200,138,0.15);
    outline: none;
}
.fi[readonly], .fi:disabled {
    opacity: 0.85;
    color: var(--ios-label-2);
    font-weight: 600;
}
.fi.text-end { text-align: right; }
.fi.text-center { text-align: center; }

/* Totals mini grid */
.totals-grid { display: flex; flex-direction: column; gap: 5px; }
.tot-row { display: flex; justify-content: space-between; align-items: center; }
.tot-row span { font-size: 0.78rem; font-weight: 600; color: var(--ios-label-2); }
.tot-row .tot-val { width: 110px; text-align: right; font-weight: 700; font-size: 0.88rem; }
.tot-divider { border: none; border-top: 1px solid var(--ios-separator); margin: 4px 0; }
.tot-grand { font-size: 1rem !important; color: var(--ios-label) !important; }
.tot-grand-val { font-size: 1.2rem !important; color: var(--accent-dark) !important; font-weight: 800 !important; }

/* Cart table */
.cart-table-wrap th {
    background: var(--ios-surface-2) !important;
    color: var(--ios-label-3) !important;
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-weight: 800;
    padding: 6px 10px;
    border-bottom: 1px solid var(--ios-separator);
    position: sticky;
    top: 0;
    z-index: 5;
}
.cart-table-wrap td {
    vertical-align: middle;
    padding: 6px 10px;
    font-size: 0.82rem;
    color: var(--ios-label);
    border-bottom: 1px solid var(--ios-separator);
}
.cart-table-wrap tr:hover td { background: var(--ios-bg); }

/* Payment rows */
.pay-line {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 5px;
}
.pay-line:last-child { margin-bottom: 0; }
.pay-lbl { width: 72px; font-size: 0.75rem; font-weight: 700; flex-shrink: 0; }
.pay-line .fi { flex: 1; min-height: 30px; font-weight: 700; background: #fff; }

/* Cheque fields */
#chequeFields { margin-top: 6px; padding: 8px; background: rgba(255,149,0,0.07); border-radius: 8px; border: 1px solid rgba(255,149,0,0.2); }

/* Action buttons */
.act-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 13px;
    border-radius: 8px;
    font-size: 0.78rem;
    font-weight: 700;
    border: none;
    cursor: pointer;
    white-space: nowrap;
    text-decoration: none;
    transition: opacity 0.15s, transform 0.1s;
    min-height: 32px;
}
.act-btn:active { transform: scale(0.97); }
.btn-primary-pos  { background: var(--accent); color: #fff; }
.btn-ghost-pos    { background: var(--ios-surface-2); color: var(--ios-label); }
.btn-secondary-pos { background: var(--ios-surface-2); color: var(--ios-label-2); }

/* TomSelect overrides */
.ts-control {
    background: var(--ios-bg) !important;
    border: 1px solid transparent !important;
    border-radius: 7px !important;
    padding: 4px 10px !important;
    min-height: 32px !important;
    font-size: 0.82rem !important;
    box-shadow: none !important;
}
.ts-control.focus {
    background: #fff !important;
    border-color: var(--accent) !important;
    box-shadow: 0 0 0 3px rgba(48,200,138,0.15) !important;
}
.ts-dropdown { border-radius: 10px; box-shadow: var(--shadow-elevated); border: 1px solid var(--ios-separator); overflow: hidden; z-index: 9999; }
.ts-dropdown .ts-dropdown-content { max-height: 240px; }
.custom-product-dropdown .option { padding: 0 !important; }
.custom-customer-dropdown .option { padding: 0 !important; }

/* Switch */
.form-switch .form-check-input { height: 1.1rem; width: 2rem; cursor: pointer; }
.form-switch .form-check-input:checked { background-color: var(--accent); border-color: var(--accent); }

/* Color helpers */
.c-green  { color: #25A872 !important; }
.c-blue   { color: #30B0C7 !important; }
.c-orange { color: #FF9500 !important; }
.c-red    { color: #FF3B30 !important; }

/* ═══════════════════════════════════════════════
   SMART SUGGESTIONS - FLOATING POP-UP SYSTEM
   ═══════════════════════════════════════════════ */
#promoSuggestionBar {
    position: fixed;
    top: 70px;
    right: 20px;
    width: 380px;
    max-height: 420px;
    z-index: 2000;
    background: rgba(255, 255, 255, 0.96) !important;
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1.5px solid rgba(0, 122, 255, 0.35) !important;
    border-radius: 14px !important;
    box-shadow: 0 12px 36px rgba(0, 0, 0, 0.16) !important;
    padding: 14px !important;
    display: flex;
    flex-direction: column;
    gap: 8px;
    transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.2s;
    animation: slideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-10px) scale(0.96);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

#promoSuggestionBar.d-none {
    display: none !important;
}

#promoSuggestionsList {
    overflow-y: auto;
    max-height: 330px;
    padding-right: 4px;
}

#promoSuggestionsList::-webkit-scrollbar {
    width: 4px;
}
#promoSuggestionsList::-webkit-scrollbar-thumb {
    background-color: var(--ios-separator);
    border-radius: 10px;
}

.promo-close-btn {
    background: var(--ios-bg);
    border: none;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--ios-label-3);
    cursor: pointer;
    font-size: 0.75rem;
    transition: all 0.15s;
}
.promo-close-btn:hover {
    background: var(--ios-red);
    color: #fff;
}
</style>

<!-- ═══════════════════ POS SHELL ═══════════════════ -->
<div class="pos-shell">

    <!-- ── ROW 1 : Topbar ── -->
    <div class="pos-topbar">
        <div class="d-flex align-items-center gap-3">
            <h1 class="pos-title"><i class="bi bi-bag-check-fill me-1" style="color:var(--accent);font-size:1rem;"></i> Point of Sale</h1>
            <?php if ($edit_mode): ?>
                <div class="pos-edit-banner"><i class="bi bi-pencil-square me-1"></i>EDIT MODE — Order #<?php echo str_pad($edit_id, 6, '0', STR_PAD_LEFT); ?></div>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2">
            <a href="create_order.php" class="act-btn btn-secondary-pos"><i class="bi bi-arrow-counterclockwise"></i> Clear</a>
            <a href="orders_list.php" class="act-btn btn-secondary-pos"><i class="bi bi-x-lg"></i> Cancel</a>
            <button class="act-btn btn-ghost-pos" id="btnSaveTop"><i class="bi bi-save"></i> <?php echo $edit_mode ? 'Update' : 'Save Only'; ?></button>
            <button class="act-btn btn-primary-pos" id="btnSavePrintTop"><i class="bi bi-printer"></i> <?php echo $edit_mode ? 'Update & Print' : 'Checkout & Print'; ?></button>
        </div>
    </div>

    <!-- ── ROW 2 : Details strip ── -->
    <div class="pos-details">

        <!-- Invoice Info -->
        <div class="pos-card">
            <div class="lbl" style="color:var(--accent-dark);margin-bottom:6px;">Invoice Details</div>
            <div class="d-flex gap-2 mb-2">
                <div style="flex:1;">
                    <label class="lbl">Invoice No.</label>
                    <input type="text" class="fi fw-bold" value="<?php echo $edit_mode ? '#'.str_pad($edit_id, 6, '0', STR_PAD_LEFT) : '(Auto)'; ?>" readonly>
                </div>
                <div style="flex:1;">
                    <label class="lbl">Billing Date</label>
                    <input type="date" id="invoiceDate" class="fi" value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>
            <label class="lbl">Handled By (Rep)</label>
            <?php if (hasRole(['admin', 'supervisor'])): ?>
                <select id="repSelect" class="fi" style="background:var(--ios-bg);">
                    <?php foreach ($reps as $rep): ?>
                        <option value="<?php echo $rep['id']; ?>" <?php echo ($rep['id'] == $_SESSION['user_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($rep['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input type="text" class="fi" value="<?php echo htmlspecialchars($_SESSION['user_name']); ?>" readonly>
                <input type="hidden" id="repSelect" value="<?php echo $_SESSION['user_id']; ?>">
            <?php endif; ?>
            <div class="mt-2">
                <label class="lbl">Stock Source</label>
                <select id="stockSourceSelect" class="fi" style="background:var(--ios-bg);">
                    <option value="warehouse" selected>Warehouse Stock</option>
                    <option value="vehicle">Vehicle Stock (selected Rep)</option>
                </select>
            </div>
        </div>

        <!-- Customer Info -->
        <div class="pos-card">
            <div class="lbl" style="color:var(--accent-dark);margin-bottom:6px;">Customer Details</div>
            <label class="lbl">Select Customer</label>
            <div class="d-flex gap-2 mb-2">
                <div style="flex:1;">
                    <select id="customerSelect" class="fi" style="background:var(--ios-bg);" placeholder="Walk-in Customer...">
                        <option value="">Walk-in Customer...</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?php echo $c['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($c['name']); ?>"
                                    data-address="<?php echo htmlspecialchars($c['address']); ?>"
                                    data-phone="<?php echo htmlspecialchars($c['phone']); ?>"
                                    data-route="<?php echo htmlspecialchars($c['route_name'] ?? 'No Route Assigned'); ?>"
                                    data-outstanding="<?php echo $c['outstanding']; ?>">
                                <?php echo htmlspecialchars($c['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <a href="customers.php?add=true" class="act-btn btn-ghost-pos" style="padding:5px 9px;" title="Add Customer"><i class="bi bi-person-plus-fill"></i></a>
                <button type="button" id="btnCustomerProfile" class="act-btn btn-secondary-pos" style="padding:5px 9px;display:none;" title="View Profile" data-bs-toggle="modal" data-bs-target="#customerProfileModal">
                    <i class="bi bi-person-vcard"></i>
                </button>
            </div>
            <label class="lbl">Billing Address</label>
            <input type="text" id="customerAddress" class="fi" placeholder="N/A" readonly>
        </div>

        <!-- Totals Summary -->
        <div class="pos-card" style="padding:10px 14px;">
            <div class="totals-grid">
                <div class="tot-row">
                    <span>Sub Total</span>
                    <input type="text" id="summarySubTotal" class="fi tot-val border-0" style="background:transparent;" value="0.00" readonly>
                </div>
                <div class="tot-row">
                    <span>Discount (Rs)</span>
                    <input type="number" id="summaryDiscount" class="fi tot-val" value="0.00" min="0" step="0.01">
                </div>
                <div class="tot-row text-success d-none" id="summaryPromoRow">
                    <span class="fw-bold">Promo Disc</span>
                    <input type="text" id="summaryPromo" class="fi tot-val border-0 text-success fw-bold" style="background:transparent;" value="0.00" readonly>
                </div>
                <div class="tot-row">
                    <span>VAT / Tax (Rs)</span>
                    <input type="number" id="summaryTax" class="fi tot-val" value="0.00" min="0" step="0.01">
                </div>
                <hr class="tot-divider">
                <div class="tot-row">
                    <span class="c-blue" style="font-size:0.82rem;">Current Bill</span>
                    <input type="text" id="summaryNetAmount" class="fi tot-val border-0 c-blue" style="background:transparent;" value="0.00" readonly>
                </div>
                <div class="tot-row d-none" id="summaryOutRow">
                    <span class="c-red" style="font-size:0.82rem;">Outstanding</span>
                    <input type="text" id="summaryOutstanding" class="fi tot-val border-0 c-red" style="background:transparent;" value="+ 0.00" readonly>
                </div>
                <hr class="tot-divider" style="border-top-width:2px;border-color:var(--ios-label);">
                <div class="tot-row">
                    <span class="tot-grand">Total Due</span>
                    <input type="text" id="summaryTotalPayable" class="fi tot-val border-0 tot-grand-val" style="background:transparent;" value="0.00" readonly>
                </div>
            </div>
        </div>
    </div>

    <!-- ── FLOATING SMART SUGGESTIONS POP-UP ── -->
    <div id="promoSuggestionBar" class="d-none">
        <div class="d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-stars text-primary fs-5" style="animation: pulse 1.5s infinite;"></i>
                <span class="fw-bold text-primary" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.04em;">Smart Suggestions</span>
            </div>
            <button type="button" class="promo-close-btn" onclick="document.getElementById('promoSuggestionBar').classList.add('d-none')" title="Dismiss Suggestions">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <hr class="my-1" style="border-top: 1px solid var(--ios-separator);">
        <div id="promoSuggestionsList" class="d-flex flex-column gap-2"></div>
    </div>

    <!-- ── ROW 3 : Product Entry Bar ── -->
    <div class="pos-entry">
        <div class="row g-2">
            <div class="col">
                <label class="lbl">Search Product</label>
                <select id="productSelect" class="fi" style="background:var(--ios-bg);" placeholder="Type name or SKU...">
                    <option value="">Type name or SKU...</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?php echo $p['id']; ?>"
                                data-name="<?php echo htmlspecialchars($p['name']); ?>"
                                data-sku="<?php echo htmlspecialchars($p['sku']); ?>"
                                data-price="<?php echo $p['selling_price']; ?>"
                                data-stock="<?php echo $p['stock']; ?>"
                                data-warehouse-stock="<?php echo $p['stock']; ?>"
                                data-supplier="<?php echo $p['supplier_id']; ?>"
                                data-category="<?php echo $p['category_id']; ?>"
                                data-main-category="<?php echo $p['main_category_id']; ?>">
                            <?php echo htmlspecialchars($p['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto" style="width:90px;">
                <div class="d-flex justify-content-between align-items-center" style="margin-bottom:3px;">
                    <span class="lbl mb-0">Qty</span>
                    <span id="stockDisplayBadge" class="badge" style="display:none;font-size:0.5rem;padding:1px 4px;"></span>
                </div>
                <input type="number" id="entryQty" class="fi text-center" value="1" min="1">
            </div>
            <div class="col-auto" style="width:130px;">
                <label class="lbl">Rate (Rs)</label>
                <input type="number" id="entryRate" class="fi text-end" step="0.01">
            </div>
            <div class="col-auto" style="width:90px;">
                <label class="lbl">Dis (%)</label>
                <input type="number" id="entryDis" class="fi text-center" value="0" min="0" max="100" step="0.1">
            </div>
            <div class="col-auto" style="width:70px;text-align:center;">
                <label class="lbl">FOC</label>
                <div class="form-check form-switch d-inline-block mt-1">
                    <input class="form-check-input" type="checkbox" id="isManualFoc">
                </div>
            </div>
            <div class="col-auto" style="width:130px;">
                <label class="lbl">Net Total</label>
                <input type="text" id="entryNet" class="fi text-end" readonly>
            </div>
            <div class="col-auto d-flex align-items-end">
                <button type="button" id="btnAddItem" class="act-btn btn-primary-pos" style="padding:6px 14px;min-height:32px;">
                    <i class="bi bi-plus-lg"></i> Add
                </button>
            </div>
        </div>
    </div>

    <!-- ── ROW 4 : Cart Table (grows) ── -->
    <div class="pos-cart-wrap">
        <div class="pos-cart-scroll cart-table-wrap">
            <table style="width:100%;border-collapse:collapse;" id="cartTable">
                <thead>
                    <tr>
                        <th style="width:4%;" class="ps-2">#</th>
                        <th style="width:13%;">SKU</th>
                        <th style="width:32%;">Product Description</th>
                        <th style="width:9%;text-align:center;">Qty</th>
                        <th style="width:12%;text-align:right;">Rate</th>
                        <th style="width:9%;text-align:center;">Dis %</th>
                        <th style="width:14%;text-align:right;">Net Total</th>
                        <th style="width:7%;text-align:center;" class="pe-2"><i class="bi bi-trash"></i></th>
                    </tr>
                </thead>
                <tbody id="cartBody">
                    <tr>
                        <td colspan="8">
                            <div style="text-align:center;padding:20px 0;color:var(--ios-label-3);">
                                <i class="bi bi-cart2" style="font-size:1.8rem;display:block;margin-bottom:4px;"></i>
                                <span style="font-size:0.78rem;">No items in cart. Search above to add.</span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── ROW 5 : Payment ── -->
    <div class="pos-payment">

        <!-- Payment allocation -->
        <div class="pos-pay-card">
            <div class="lbl" style="font-size:0.65rem;margin-bottom:8px;"><i class="bi bi-wallet2 me-1"></i>Payment Allocation</div>
            <div class="d-flex gap-3 flex-wrap">
                <div class="pay-line" style="flex:1;min-width:160px;">
                    <span class="pay-lbl c-green"><i class="bi bi-cash-stack me-1"></i>Cash</span>
                    <input type="number" id="payCash" class="fi c-green" placeholder="0.00" min="0" step="0.01">
                </div>
                <div class="pay-line" style="flex:1;min-width:160px;">
                    <span class="pay-lbl c-blue"><i class="bi bi-bank me-1"></i>Bank</span>
                    <input type="number" id="payBank" class="fi c-blue" placeholder="0.00" min="0" step="0.01">
                </div>
                <div class="pay-line" style="flex:1;min-width:160px;">
                    <span class="pay-lbl c-orange"><i class="bi bi-envelope-paper me-1"></i>Cheque</span>
                    <input type="number" id="payCheque" class="fi c-orange" placeholder="0.00" min="0" step="0.01">
                </div>
            </div>
            <div id="chequeFields" class="d-none mt-2">
                <div class="d-flex gap-2">
                    <input type="text" id="chkBank" class="fi" placeholder="Bank Name" style="flex:1;">
                    <input type="text" id="chkNum" class="fi" placeholder="Cheque No." style="flex:1;">
                    <input type="date" id="chkDate" class="fi" style="flex:1;">
                </div>
            </div>
        </div>

        <!-- Balance display -->
        <div class="pos-balance-card">
            <div class="lbl" id="paymentBalanceLabel" style="font-size:0.65rem;margin-bottom:4px;">Balance / Credit</div>
            <div style="font-size:2rem;font-weight:800;letter-spacing:-1px;color:var(--ios-red);" id="paymentBalance">0.00</div>
            <div id="checkoutMessage" class="fw-bold mt-1" style="font-size:0.78rem;"></div>
        </div>
    </div>

</div><!-- /.pos-shell -->

<!-- Customer Profile Modal -->
<div class="modal fade" id="customerProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;border:none;box-shadow:0 20px 40px rgba(0,0,0,0.2);">
            <div class="modal-header" style="background:var(--ios-surface);border-bottom:1px solid var(--ios-separator);padding:14px 20px;">
                <h5 class="modal-title fw-bold" style="font-size:1rem;"><i class="bi bi-person-vcard text-primary me-2"></i>Customer Overview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" style="background:var(--ios-bg);">
                <iframe id="customerProfileIframe" src="" style="width:100%;height:80vh;border:none;"></iframe>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
<script>window.editInvoiceData = <?php echo $edit_order_data; ?>;</script>
<script src="../assets/js/orders.js?v=<?php echo time(); ?>"></script>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const prodSelect  = document.getElementById('productSelect');
    const stockBadge  = document.getElementById('stockDisplayBadge');

    // Stock badge update on native change
    prodSelect.addEventListener('change', function () {
        const opt = this.options[this.selectedIndex];
        if (opt && opt.value) {
            const stock = parseInt(opt.getAttribute('data-stock') || 0);
            stockBadge.innerHTML  = stock + ' left';
            stockBadge.className  = 'badge ' + (stock > 0 ? 'bg-success' : 'bg-danger');
            stockBadge.style.display = 'inline-block';
        } else {
            stockBadge.style.display = 'none';
        }
    });

    // TomSelect — product search dropdown
    setTimeout(() => {
        if (prodSelect.tomselect) prodSelect.tomselect.destroy();
        new TomSelect('#productSelect', {
            dropdownClass: 'ts-dropdown custom-product-dropdown',
            searchField: ['text'],
            render: {
                option: function (data, escape) {
                    const opt = prodSelect.querySelector('option[value="' + escape(data.value) + '"]');
                    if (!opt || !data.value) return `<div class="p-2 text-muted">${escape(data.text)}</div>`;
                    const name  = opt.getAttribute('data-name')  || data.text;
                    const sku   = opt.getAttribute('data-sku')   || '';
                    const price = opt.getAttribute('data-price')  || '0.00';
                    const stock = parseInt(opt.getAttribute('data-stock') || '0');
                    const sCol  = stock > 10 ? 'var(--accent-dark)' : (stock > 0 ? '#C07000' : '#CC2200');
                    return `
                        <div class="d-flex justify-content-between px-3 py-2" style="border-bottom:1px solid var(--ios-separator);cursor:pointer;">
                            <div style="flex:1;min-width:0;padding-right:12px;">
                                <div style="font-weight:700;font-size:0.88rem;color:#1c1c1e;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escape(name)}</div>
                                <div style="font-size:0.74rem;color:#8e8e93;margin-top:2px;">SKU: ${escape(sku)}</div>
                            </div>
                            <div style="text-align:right;white-space:nowrap;flex-shrink:0;">
                                <div style="font-weight:700;font-size:0.88rem;color:var(--accent-dark);">Rs ${escape(price)}</div>
                                <div style="font-size:0.74rem;font-weight:600;color:${sCol};margin-top:2px;">${stock} Available</div>
                            </div>
                        </div>`;
                },
                item: function (data, escape) {
                    const opt = prodSelect.querySelector('option[value="' + escape(data.value) + '"]');
                    if (!opt || !data.value) return `<div>${escape(data.text)}</div>`;
                    const name = opt.getAttribute('data-name') || data.text;
                    const sku  = opt.getAttribute('data-sku')  || '';
                    return `<div style="font-weight:700;font-size:0.82rem;color:#1c1c1e;">${escape(name)} <span style="font-weight:500;color:#8e8e93;">(${escape(sku)})</span></div>`;
                }
            }
        });
    }, 150);

    // TomSelect — customer select with multi-line cards (Displays Route, Address, Phone & Outstanding)
    setTimeout(() => {
        if (document.getElementById('customerSelect')) {
            const custSelect = document.getElementById('customerSelect');
            
            // Destroys the pre-existing instance loaded by assets/js/orders.js so we can safely override templates
            if (custSelect.tomselect) {
                custSelect.tomselect.destroy();
            }
            
            new TomSelect('#customerSelect', {
                maxItems: 1,
                searchField: ['text', 'phone', 'address', 'route'],
                dropdownClass: 'ts-dropdown custom-customer-dropdown',
                render: {
                    option: function (data, escape) {
                        const opt = Array.from(custSelect.options).find(o => String(o.value).trim() === String(data.value).trim());
                        if (!opt || !data.value) return `<div class="p-2 text-muted">${escape(data.text)}</div>`;
                        
                        const name    = opt.getAttribute('data-name') || data.text;
                        const phone   = opt.getAttribute('data-phone') || 'No Phone';
                        const address = opt.getAttribute('data-address') || 'No Address';
                        const route   = opt.getAttribute('data-route') || 'No Route Assigned';
                        const outstanding = parseFloat(opt.getAttribute('data-outstanding') || '0');
                        const outText = outstanding > 0 ? `<span class="badge bg-danger text-white ms-2" style="font-size:0.65rem; font-weight:700;">Rs ${outstanding.toFixed(2)} Out</span>` : '';

                        return `
                            <div class="px-3 py-2" style="border-bottom:1px solid var(--ios-separator); cursor:pointer;">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div style="font-weight:700; font-size:0.88rem; color:#1c1c1e;">
                                        <i class="bi bi-person-fill text-secondary me-1"></i>${escape(name)} ${outText}
                                    </div>
                                    <span class="badge bg-secondary" style="font-size:0.65rem; padding:3px 6px; border-radius:5px; background-color:#007aff !important;">
                                        <i class="bi bi-truck me-1"></i>${escape(route)}
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between text-muted mt-1" style="font-size:0.74rem;">
                                    <div style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:240px;" title="${escape(address)}">
                                        <i class="bi bi-geo-alt-fill me-1" style="color:#007aff;"></i>${escape(address)}
                                    </div>
                                    <div style="flex-shrink:0; font-weight:600;">
                                        <i class="bi bi-telephone-fill me-1" style="color:#34c759;"></i>${escape(phone)}
                                    </div>
                                </div>
                            </div>`;
                    },
                    item: function (data, escape) {
                        const opt = Array.from(custSelect.options).find(o => String(o.value).trim() === String(data.value).trim());
                        if (!opt || !data.value) return `<div>${escape(data.text)}</div>`;
                        const name = opt.getAttribute('data-name') || data.text;
                        const phone = opt.getAttribute('data-phone') || '';
                        const route = opt.getAttribute('data-route') || '';
                        const routeStr = route ? ` - ${route}` : '';
                        return `<div style="font-weight:700; font-size:0.82rem; color:#1c1c1e;"><i class="bi bi-person-check-fill text-success me-1"></i>${escape(name)} ${phone ? `<span style="font-weight:500; color:#8e8e93;">(${escape(phone)}${escape(routeStr)})</span>` : ''}</div>`;
                    }
                }
            });
        }
    }, 200); // 200ms delay ensures standard orders.js code executes fully first, allowing our override to take hold
});
</script>

<?php include '../includes/footer.php'; ?>