<?php
session_start();
require_once '../config/db.php';

$is_staff = isset($_SESSION['user_id']);

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("<div style='padding:40px; font-family:system-ui, sans-serif; color:#CC2200; font-weight:600; text-align:center;'>Invalid Order ID.</div>");
}

$order_id = (int)$_GET['id'];

$stmt = $pdo->prepare("
    SELECT o.*, c.name as customer_name, c.address, c.phone, u.name as rep_name 
    FROM orders o 
    LEFT JOIN customers c ON o.customer_id = c.id 
    LEFT JOIN users u ON o.rep_id = u.id 
    WHERE o.id = ?
");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    die("<div style='padding:40px; font-family:system-ui, sans-serif; color:#CC2200; font-weight:600; text-align:center;'>Order not found or has been deleted.</div>");
}

$itemStmt = $pdo->prepare("
    SELECT oi.*, p.name as product_name, p.sku 
    FROM order_items oi 
    JOIN products p ON oi.product_id = p.id 
    WHERE oi.order_id = ?
");
$itemStmt->execute([$order_id]);
$items = $itemStmt->fetchAll();

$custNameStr = !empty($order['customer_name']) ? $order['customer_name'] : 'Walk-in';
$dateStr     = date('Y-m-d', strtotime($order['created_at']));
$payStr      = $order['payment_method'];
$rawFileName = "{$custNameStr} - {$dateStr} - {$payStr}";
$cleanFileName = preg_replace('/[^A-Za-z0-9 \-_]/', '', $rawFileName) . '.pdf';

$paidAmount = isset($order['paid_amount']) ? (float)$order['paid_amount'] : 0;
$balance    = (float)$order['total_amount'] - $paidAmount;

$statusText = strtoupper($order['payment_status']);
if ($order['payment_status'] == 'paid') {
    $statusText = 'PAID';
} elseif ($paidAmount > 0 && $balance > 0) {
    $statusText = 'PARTIAL';
}

$cust_outstanding = 0;
$cust_total_paid = 0;
if (isset($_GET['print_outstanding']) && $_GET['print_outstanding'] == 1) {
    $custMetricsStmt = $pdo->prepare("
        SELECT 
            SUM(paid_amount) as total_paid,
            SUM(total_amount - paid_amount) as outstanding_balance
        FROM orders 
        WHERE customer_id = ?
    ");
    $custMetricsStmt->execute([$order['customer_id']]);
    $custMetrics = $custMetricsStmt->fetch();
    $cust_outstanding = (float)($custMetrics['outstanding_balance'] ?? 0);
    $cust_total_paid = (float)($custMetrics['total_paid'] ?? 0);
}

// ─── SERVER-SIDE PDF (DomPDF) ──────────────────────────────────────────────
if (isset($_GET['pdf']) && $_GET['pdf'] == 1) {
    require_once '../vendor/autoload.php';

    $pdfStatusCol = '#1A9A3A'; $pdfStatusBrd = '#1A9A3A';
    if ($statusText === 'PAID')    { $pdfStatusCol = '#1A9A3A'; $pdfStatusBrd = '#1A9A3A'; }
    elseif ($statusText === 'PARTIAL') { $pdfStatusCol = '#0055CC'; $pdfStatusBrd = '#0055CC'; }
    else { $pdfStatusCol = '#888888'; $pdfStatusBrd = '#888888'; }

    $disp_date  = date('M d, Y', strtotime($order['created_at']));
    $rep_name   = htmlspecialchars($order['rep_name'] ?: 'System Admin');
    $pay_method = htmlspecialchars(ucfirst($order['payment_method']));
    $inv_num    = str_pad($order['id'], 6, '0', STR_PAD_LEFT);
    $c_name     = htmlspecialchars(!empty($order['customer_name']) ? $order['customer_name'] : 'Walk-in Customer');
    $c_addr     = nl2br(htmlspecialchars($order['address'] ?? ''));
    $c_phone    = htmlspecialchars($order['phone'] ?? '');

    // Fetch logo as base64
    $logo_url   = 'https://candent.suzxlabs.com/images/logo/croped-white-logo.png';
    $ch         = curl_init($logo_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $logo_data  = curl_exec($ch);
    curl_close($ch);
    $logo_b64   = $logo_data ? 'data:image/png;base64,' . base64_encode($logo_data) : '';

    $items_html = '';
    $counter = 1;
    foreach ($items as $item) {
        $gross   = $item['quantity'] * $item['price'];
        $net     = $gross - $item['discount'];
        $sku_h   = !empty($item['sku']) ? "<br><span style='font-size:8px;color:#888;'>SKU: ".htmlspecialchars($item['sku'])."</span>" : '';
        $bg      = ($counter % 2 == 0) ? "background:#F9FAFB;" : "background:#FFFFFF;";
        $items_html .= "
        <tr style='{$bg}'>
            <td style='text-align:center;padding:6px 8px;color:#888;border-bottom:1px solid #EEE;font-size:10px;'>".str_pad($counter++, 2, '0', STR_PAD_LEFT)."</td>
            <td style='padding:6px 8px;border-bottom:1px solid #EEE;'><strong style='color:#111;font-size:11px;'>".htmlspecialchars($item['product_name'])."</strong>{$sku_h}</td>
            <td style='text-align:center;font-weight:700;padding:6px 8px;border-bottom:1px solid #EEE;font-size:10px;'>{$item['quantity']}</td>
            <td style='text-align:right;padding:6px 8px;border-bottom:1px solid #EEE;font-size:10px;'>".number_format($item['price'],2)."</td>
            <td style='text-align:right;padding:6px 8px;border-bottom:1px solid #EEE; color:#999;font-size:10px;'>".($item['discount'] > 0 ? number_format($item['discount'],2) : '—')."</td>
            <td style='text-align:right;font-weight:700;padding:6px 8px;border-bottom:1px solid #EEE;font-size:10px;'>".number_format($net,2)."</td>
        </tr>";
    }

    $bal_color = $balance <= 0 ? '#1A9A3A' : '#CC2200';
    $bal_label = $balance < 0 ? 'Change Due' : 'Balance Due';

    $pdfHtml = "
<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; color: #333; background: #fff; padding: 20px 30px; }
  table { border-collapse: collapse; width: 100%; }
  .header-table td { vertical-align: top; }
  .brand-logo { height: 35px; margin-bottom: 6px; }
  .company-info strong { font-size: 14px; color: #000; display: block; margin-bottom: 2px; }
  .company-info p { color: #666; line-height: 1.3; margin: 0; font-size: 9px; }
  .inv-title { font-size: 22px; font-weight: 200; letter-spacing: 4px; color: #000; margin-bottom: 10px; text-transform: uppercase; }
  .meta-table { width: auto; }
  .meta-table td { padding: 2px 0; }
  .meta-table .k { color: #999; padding-right: 15px; font-size: 9px; }
  .meta-table .v { font-weight: 700; color: #000; font-size: 9px; }
  .status-badge { font-size: 8px; font-weight: 700; border: 1px solid {$pdfStatusBrd}; color: {$pdfStatusCol}; padding: 1px 4px; border-radius: 2px; }
  .divider { border: none; border-top: 1.5px solid #000; margin: 15px 0; }
  .info-grid td { vertical-align: top; width: 50%; }
  .label { font-size: 8px; text-transform: uppercase; font-weight: 700; color: #999; letter-spacing: 1px; margin-bottom: 3px; }
  .info-content h3 { font-size: 11px; margin-bottom: 2px; color: #000; }
  .info-content p { color: #555; line-height: 1.3; font-size: 9px; }
  .items-table th { background: #F9FAFB; padding: 6px 8px; font-size: 8.5px; text-transform: uppercase; color: #666; border-bottom: 1.5px solid #000; text-align: left; }
  .items-table td { padding: 6px 8px; font-size: 9.5px; }
  .totals-table { width: 220px; margin-left: auto; margin-top: 15px; }
  .totals-table td { padding: 3px 0; font-size: 9.5px; }
  .totals-table .val { text-align: right; font-weight: 700; }
  .grand-total { border-top: 1.5px solid #000; border-bottom: 1px solid #EEE; font-size: 11px; font-weight: 700; }
  .grand-total td { padding: 6px 0; }
  .terms { font-size: 9px; color: #777; line-height: 1.4; margin-top: 15px; }
  .sig-block { margin-top: 25px; border-top: 1px solid #DDD; width: 150px; text-align: center; padding-top: 4px; color: #999; font-size: 9px; }
  .footer { text-align: center; margin-top: 30px; color: #AAA; font-size: 8px; }
</style>
</head>
<body>
  <table class='header-table'>
    <tr>
      <td>
        <img src='{$logo_b64}' class='brand-logo'>
        <div class='company-info'>
          <strong>Candent</strong>
          <p>79, Dambakanda Estate, Boyagane,<br>Kurunegala, Sri Lanka.<br>Tel: 076 140 7876 | candentlk@gmail.com</p>
        </div>
      </td>
      <td style='text-align:right;'>
        <div class='inv-title'>INVOICE</div>
        <table class='meta-table' style='margin-left:auto;'>
          <tr><td class='k'>Invoice No</td><td class='v'>#{$inv_num}</td></tr>
          <tr><td class='k'>Date Issued</td><td class='v'>{$disp_date}</td></tr>
          <tr><td class='k'>Status</td><td class='v'><span class='status-badge'>{$statusText}</span></td></tr>
        </table>
      </td>
    </tr>
  </table>

  <hr class='divider'>

  <table class='info-grid'>
    <tr>
      <td>
        <div class='label'>Billed To</div>
        <div class='info-content'>
          <h3>{$c_name}</h3>
          <p>{$c_addr}<br>Tel: {$c_phone}</p>
        </div>
      </td>
      <td style='text-align:right;'>
        <div class='label'>Order Context</div>
        <table class='meta-table' style='margin-left:auto;'>
          <tr><td class='k'>Sales Rep</td><td class='v'>{$rep_name}</td></tr>
          <tr><td class='k'>Payment</td><td class='v'>{$pay_method}</td></tr>
        </table>
      </td>
    </tr>
  </table>

  <table class='items-table' style='margin-top:20px;'>
    <thead>
      <tr>
        <th style='width:30px;text-align:center;'>#</th>
        <th>Description</th>
        <th style='width:40px;text-align:center;'>Qty</th>
        <th style='width:70px;text-align:right;'>Price</th>
        <th style='width:60px;text-align:right;'>Disc</th>
        <th style='width:85px;text-align:right;'>Amount</th>
      </tr>
    </thead>
    <tbody>{$items_html}</tbody>
  </table>

  <table class='totals-table'>
    <tr><td>Subtotal</td><td class='val'>Rs ".number_format($order['subtotal'], 2)."</td></tr>
    ".($order['discount_amount'] > 0 ? "<tr><td style='color:#CC2200;'>Bill Discount</td><td class='val' style='color:#CC2200;'>- ".number_format($order['discount_amount'], 2)."</td></tr>" : "")."
    <tr class='grand-total'><td>Amount Due</td><td class='val'>Rs ".number_format($order['total_amount'], 2)."</td></tr>
    <tr><td style='padding-top:10px; color:#666;'>Paid</td><td class='val' style='padding-top:10px;'>Rs ".number_format($paidAmount, 2)."</td></tr>
    <tr><td style='font-weight:700; color:{$bal_color};'>{$bal_label}</td><td class='val' style='font-weight:700; color:{$bal_color}; font-size:12px;'>Rs ".number_format(abs($balance), 2)."</td></tr>
    ".(isset($_GET['print_outstanding']) && $_GET['print_outstanding'] == 1 ? "
    <tr style='border-top:1px dashed #DDD;'><td colspan='2' style='height:4px;'></td></tr>
    <tr><td style='color:#555;'>Cust. Total Paid</td><td class='val' style='color:#1A9A3A;'>Rs ".number_format($cust_total_paid, 2)."</td></tr>
    <tr><td style='color:#555;'>Cust. Outstanding</td><td class='val' style='color:#CC2200;'>Rs ".number_format($cust_outstanding, 2)."</td></tr>
    " : "")."
  </table>

  <div class='terms'>
    <div class='label' style='color:#666;'>Terms &amp; Conditions</div>
    Goods once sold will not be taken back unless defective. Please verify all items and quantities before leaving. For bank transfers, use Invoice # as payment reference.
    <div class='sig-block'>Authorized Signature</div>
  </div>

  <div class='footer'>THANK YOU FOR YOUR BUSINESS · System by suzxlabs.com</div>
</body>
</html>";

    $dompdf = new \Dompdf\Dompdf();
    $dompdf->set_option('isHtml5ParserEnabled', true);
    $dompdf->set_option('isRemoteEnabled', true);
    $dompdf->loadHtml($pdfHtml);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $cleanFileName . '"');
    echo $dompdf->output();
    exit;
}

// ─── HTML VARS ─────────────────────────────────────────────────────────────
$inv_num    = str_pad($order['id'], 6, '0', STR_PAD_LEFT);
$disp_date  = date('M d, Y', strtotime($order['created_at']));

$statClass = 'badge-pending';
if ($statusText === 'PAID')    $statClass = 'badge-paid';
elseif ($statusText === 'PARTIAL') $statClass = 'badge-partial';

$bal_color = $balance <= 0 ? 'var(--green)' : 'var(--red)';
$bal_label = $balance < 0 ? 'Change Due' : 'Balance Due';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Invoice #<?= $inv_num ?> · Candent</title>
  
  <!-- Important for Fixed A4 Zoom -->
  <meta name="viewport" content="width=1024">

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

  <style>
    :root {
      --bg: #F3F4F6;
      --paper: #FFFFFF;
      --ink: #000000;
      --ink-2: #4B5563;
      --ink-3: #9CA3AF;
      --border: #E5E7EB;
      --green: #059669;
      --red: #DC2626;
      --blue: #2563EB;
      --mono: 'DM Mono', monospace;
      --sans: 'DM Sans', sans-serif;
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { 
      font-family: var(--sans); 
      background: var(--bg); 
      color: var(--ink-2); 
      line-height: 1.4; 
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      padding-bottom: 120px; /* Space for floating bar */
    }

    /* ── FLOATING ACTION BAR ── */
    .action-bar {
      position: fixed;
      bottom: 30px;
      left: 50%;
      transform: translateX(-50%);
      z-index: 1000;
      background: #ffffff;
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 10px;
      box-shadow: 0 15px 50px rgba(0,0,0,0.15);
      width: 760px; /* Fixed standard size */
      max-width: 95vw;
    }
    .action-container {
      width: 100%;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      padding: 0 8px;
    }
    .action-group { display: flex; gap: 8px; align-items: center; }

    .btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 8px;
      padding: 12px 18px; border-radius: 8px; font-size: 13px; font-weight: 600;
      cursor: pointer; text-decoration: none; border: 1.5px solid transparent;
      transition: all 0.2s;
    }
    .btn:active { transform: scale(0.96); }
    .btn-ghost { background: #fff; color: var(--ink); border-color: var(--border); }
    .btn-primary { background: var(--ink); color: #fff; }
    .btn-share { background: var(--blue); color: #fff; }
    .btn-danger { background: var(--red); color: #fff; }
    .btn-profile { background: #6366F1; color: #fff; border-color: #6366F1; padding-left: 24px; padding-right: 24px; } /* Longer button */
    
    .spinner { width: 14px; height: 14px; border: 2px solid rgba(255,255,255,0.3); border-top-color: #fff; border-radius: 50%; animation: spin 0.6s linear infinite; display: none; }
    .loading .spinner { display: block; }
    .loading .btn-icon { display: none; }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* ── INVOICE WRAPPER (Fixed A4 Size) ── */
    .invoice-wrapper {
      width: 210mm; 
      min-height: 297mm;
      margin: 30px auto; 
      background: var(--paper);
      box-shadow: 0 10px 40px rgba(0,0,0,0.1);
      position: relative;
    }
    .invoice-body { padding: 25px 35px; }

    /* ── HEADER ── */
    .inv-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; }
    .brand-logo { height: 42px; margin-bottom: 6px; }
    .company-info strong { font-size: 18px; font-weight: 800; color: var(--ink); display: block; }
    .company-info p { font-size: 11px; color: var(--ink-2); line-height: 1.3; }

    .inv-title-block { text-align: right; }
    .inv-title { font-size: 28px; font-weight: 300; letter-spacing: 4px; margin-bottom: 8px; color: var(--ink); }
    .meta-grid { display: grid; grid-template-columns: auto auto; gap: 4px 15px; font-size: 12px; justify-content: flex-end; }
    .meta-grid .k { color: var(--ink-3); text-align: right; }
    .meta-grid .v { font-weight: 700; text-align: right; color: var(--ink); }

    .status-badge {
      display: inline-block; font-size: 10px; font-weight: 700; text-transform: uppercase;
      padding: 1.5px 8px; border-radius: 3px; border: 1.5px solid currentColor;
    }
    .badge-paid { color: var(--green); }
    .badge-partial { color: var(--blue); }
    .badge-pending { color: var(--ink-3); }

    .hdivider { border: 0; border-top: 1.5px solid var(--ink); margin: 15px 0; }

    /* ── BILLING ── */
    .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 20px; }
    .label { font-size: 9px; text-transform: uppercase; font-weight: 700; color: var(--ink-3); letter-spacing: 1.2px; margin-bottom: 4px; }
    .info-content h3 { font-size: 13px; font-weight: 700; color: var(--ink); margin-bottom: 2px; }
    .info-content p { font-size: 11.5px; color: var(--ink-2); line-height: 1.4; }
    .info-right { text-align: right; }
    .info-right .meta-grid { justify-content: flex-end; }

    /* ── TABLE ── */
    .items-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 12px; }
    .items-table th { background: #F9FAFB; padding: 6px 8px; font-size: 10px; text-transform: uppercase; font-weight: 700; color: var(--ink-2); border-bottom: 1.5px solid var(--ink); text-align: left; }
    .items-table td { padding: 5px 8px; border-bottom: 1px solid var(--border); vertical-align: middle; }
    .product-name { font-weight: 600; color: var(--ink); display: block; margin-bottom: 1px; font-size: 12px; }
    .product-sku { font-family: var(--mono); font-size: 10px; color: var(--ink-3); }

    /* ── FOOTER ── */
    .footer-grid { display: grid; grid-template-columns: 1fr 280px; gap: 30px; align-items: start; }
    .terms-text { font-size: 11px; color: var(--ink-3); line-height: 1.5; }
    .sig-block { margin-top: 25px; border-top: 1px solid var(--border); padding-top: 6px; width: 180px; text-align: center; font-size: 11px; color: var(--ink-3); }

    .totals-table { width: 100%; }
    .totals-table td { padding: 4px 0; font-size: 12px; }
    .totals-table .val { font-family: var(--mono); text-align: right; font-weight: 700; color: var(--ink); }
    .grand-total { border-top: 2px solid var(--ink); border-bottom: 1px solid var(--border); }
    .grand-total td { padding: 8px 0; font-size: 14px; font-weight: 800; color: var(--ink); }

    .branding-footer { margin-top: 30px; padding-top: 15px; border-top: 1px solid var(--border); text-align: center; }
    .branding-footer p { font-size: 10px; color: var(--ink-3); text-transform: uppercase; letter-spacing: 1.5px; }

    @media print {
      body { background: #fff; padding: 0; }
      .action-bar { display: none; }
      .invoice-wrapper { margin: 0; box-shadow: none; border: 0; width: 100%; }
      .invoice-body { padding: 0; }
    }
  </style>
</head>
<body>

<!-- ── FLOATING ACTION BAR ── -->
<div class="action-bar no-print">
  <div class="action-container">
    <div class="action-group">
      <a href="javascript:history.back();" class="btn btn-ghost">
        <span class="btn-icon"><i class="bi bi-arrow-left"></i></span>
        <span>Back</span>
      </a>
      <a href="https://candent.suzxlabs.com/pages/view_customer.php?id=<?= $order['customer_id'] ?>" class="btn btn-profile">
        <span class="btn-icon"><i class="bi bi-person-badge"></i></span>
        <span>View Profile</span>
      </a>
    </div>
    <div class="action-group">
      <?php if ($is_staff): ?>
      <button onclick="shareInvoice()" class="btn btn-share" id="shareBtn">
        <span class="spinner"></span>
        <span class="btn-icon"><i class="bi bi-share"></i></span>
        <span>Share</span>
      </button>
      <?php endif; ?>
      <button onclick="downloadPDF()" class="btn btn-danger" id="downloadBtn">
        <span class="spinner"></span>
        <span class="btn-icon"><i class="bi bi-file-earmark-pdf"></i></span>
        <span>PDF</span>
      </button>
      <button onclick="window.print()" class="btn btn-ghost">
        <span class="btn-icon"><i class="bi bi-printer"></i></span>
        <span>Print</span>
      </button>
    </div>
  </div>
</div>

<!-- ── INVOICE DOCUMENT (A4) ── -->
<div class="invoice-wrapper" id="invoice-content">
  <div class="invoice-body">
    
    <header class="inv-header">
      <div class="brand-box">
        <img src="https://candent.suzxlabs.com/images/logo/croped-white-logo.png" alt="Logo" class="brand-logo">
        <div class="company-info">
          <strong>Candent</strong>
          <p>79, Dambakanda Estate, Boyagane, Kurunegala.<br>Tel: 076 140 7876 | candentlk@gmail.com</p>
        </div>
      </div>

      <div class="inv-title-block">
        <h1 class="inv-title">INVOICE</h1>
        <div class="meta-grid">
          <span class="k">Invoice No</span><span class="v">#<?= $inv_num ?></span>
          <span class="k">Date Issued</span><span class="v"><?= $disp_date ?></span>
          <span class="k">Status</span><span class="v"><span class="status-badge <?= $statClass ?>"><?= $statusText ?></span></span>
        </div>
      </div>
    </header>

    <hr class="hdivider">

    <div class="info-grid">
      <div class="info-content">
        <div class="label">Billed To</div>
        <h3><?= !empty($order['customer_name']) ? htmlspecialchars($order['customer_name']) : 'Walk-in Customer' ?></h3>
        <p><?= !empty($order['address']) ? nl2br(htmlspecialchars($order['address'])) . '<br>' : '' ?>Tel: <?= htmlspecialchars($order['phone'] ?: 'N/A') ?></p>
      </div>
      <div class="info-content info-right">
        <div class="label">Order Context</div>
        <div class="meta-grid">
          <span class="k">Sales Rep</span><span class="v"><?= htmlspecialchars($order['rep_name'] ?: 'Admin') ?></span>
          <span class="k">Payment</span><span class="v"><?= htmlspecialchars(ucfirst($order['payment_method'])) ?></span>
        </div>
      </div>
    </div>

    <table class="items-table">
      <thead>
        <tr>
          <th style="width: 30px; text-align: center;">#</th>
          <th>Description</th>
          <th style="width: 40px; text-align: center;">Qty</th>
          <th style="width: 75px; text-align: right;">Price</th>
          <th style="width: 60px; text-align: right;">Disc</th>
          <th style="width: 90px; text-align: right;">Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php $counter = 1; foreach ($items as $item): $gross = $item['quantity'] * $item['price']; $net = $gross - $item['discount']; ?>
        <tr>
          <td style="text-align: center; color: var(--ink-3);"><?= str_pad($counter++, 2, '0', STR_PAD_LEFT) ?></td>
          <td><span class="product-name"><?= htmlspecialchars($item['product_name']) ?></span><span class="product-sku">SKU: <?= htmlspecialchars($item['sku'] ?: '—') ?></span></td>
          <td style="text-align: center; font-weight: 700;"><?= $item['quantity'] ?></td>
          <td style="text-align: right; font-family: var(--mono);"><?= number_format($item['price'], 2) ?></td>
          <td style="text-align: right; font-family: var(--mono); color: <?= $item['discount'] > 0 ? 'var(--red)' : 'var(--ink-3)' ?>;"><?= $item['discount'] > 0 ? number_format($item['discount'], 2) : '—' ?></td>
          <td style="text-align: right; font-family: var(--mono); font-weight: 700;"><?= number_format($net, 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="footer-grid">
      <div class="terms-column">
        <div class="label">Terms &amp; Conditions</div>
        <p class="terms-text">Goods once sold will not be taken back unless defective. Please verify all items and quantities before leaving. For bank transfers, use Invoice # as reference.</p>
        <div class="sig-block">Authorized Signature</div>
      </div>

      <div class="totals-column">
        <table class="totals-table">
          <tr><td>Subtotal</td><td class="val">Rs <?= number_format($order['subtotal'], 2) ?></td></tr>
          <?php if ($order['discount_amount'] > 0): ?>
          <tr><td style="color: var(--red);">Bill Discount</td><td class="val" style="color: var(--red);">- <?= number_format($order['discount_amount'], 2) ?></td></tr>
          <?php endif; ?>
          <tr class="grand-total"><td>Amount Due</td><td class="val">Rs <?= number_format($order['total_amount'], 2) ?></td></tr>
          <tr><td style="color: var(--ink-3);">Paid</td><td class="val" style="color: var(--ink-2);">Rs <?= number_format($paidAmount, 2) ?></td></tr>
          <tr><td style="font-weight: 700; color: <?= $bal_color ?>;"><?= $bal_label ?></td><td class="val" style="font-weight: 800; color: <?= $bal_color ?>; font-size: 14px;">Rs <?= number_format(abs($balance), 2) ?></td></tr>
          <?php if (isset($_GET['print_outstanding']) && $_GET['print_outstanding'] == 1): ?>
          <tr style="border-top: 1px dashed var(--border);"><td colspan="2" style="height: 4px;"></td></tr>
          <tr><td style="color: var(--ink-2); font-weight: 600;">Cust. Total Paid</td><td class="val" style="color: var(--green);">Rs <?= number_format($cust_total_paid, 2) ?></td></tr>
          <tr><td style="color: var(--ink-2); font-weight: 600;">Cust. Outstanding</td><td class="val" style="color: var(--red);">Rs <?= number_format($cust_outstanding, 2) ?></td></tr>
          <?php endif; ?>
        </table>
      </div>
    </div>

    <footer class="branding-footer">
      <p>THANK YOU FOR YOUR BUSINESS · System by <a href="https://suzxlabs.com" style="color:inherit; font-weight:700; text-decoration:none;">Suzxlabs</a></p>
    </footer>

  </div>
</div>

<script>
(function() {
  const FILE_NAME = '<?= addslashes($cleanFileName) ?>';
  const pdfOpts = {
    margin: [0, 0, 0, 0],
    filename: FILE_NAME,
    image: { type: 'jpeg', quality: 0.98 },
    html2canvas: { scale: 3, useCORS: true, logging: false },
    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
  };

  function setLoading(btn, loading) {
    if (!btn) return;
    btn.classList.toggle('loading', loading);
    btn.disabled = loading;
  }

  window.downloadPDF = async function() {
    const btn = document.getElementById('downloadBtn');
    const el = document.getElementById('invoice-content');
    setLoading(btn, true);
    try {
      await html2pdf().set(pdfOpts).from(el).save();
    } catch (e) {
      console.error(e);
    } finally {
      setLoading(btn, false);
    }
  };

  window.shareInvoice = async function() {
    const btn = document.getElementById('shareBtn');
    const el = document.getElementById('invoice-content');
    if (!navigator.share) { window.downloadPDF(); return; }
    setLoading(btn, true);
    try {
      const blob = await html2pdf().set(pdfOpts).from(el).output('blob');
      const file = new File([blob], FILE_NAME, { type: 'application/pdf' });
      if (navigator.canShare && navigator.canShare({ files: [file] })) {
        await navigator.share({ files: [file], title: 'Invoice #<?= $inv_num ?>', text: 'Invoice attached.' });
      } else { window.downloadPDF(); }
    } catch (e) { if (e.name !== 'AbortError') window.downloadPDF(); } finally { setLoading(btn, false); }
  };
})();
</script>

</body>
</html>