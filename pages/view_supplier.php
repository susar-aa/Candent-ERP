<?php
// Enable error reporting for easier debugging of 500 errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../config/db.php';

$is_staff = isset($_SESSION['user_id']);

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("<div class='clean-alert error-alert m-4'><i class='bi bi-x-circle-fill'></i><p>Invalid Supplier ID.</p></div>");
}

$supplier_id = (int)$_GET['id'];
$is_modal = isset($_GET['modal']) && $_GET['modal'] == 'true';

// --- AUTHENTICATION & PERMISSIONS ---
if (!$is_staff) {
    header("Location: ../login.php");
    exit;
} else {
    function hasRole($allowed_roles) {
        if (!isset($_SESSION['user_role'])) return false;
        if (!is_array($allowed_roles)) $allowed_roles = [$allowed_roles];
        return in_array($_SESSION['user_role'], $allowed_roles);
    }
}
$isRep = hasRole('rep');

$message = '';

// --- AUTO DB MIGRATIONS (FAIL-SAFE) ---
try { $pdo->exec("ALTER TABLE suppliers ADD COLUMN email VARCHAR(150) NULL"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE suppliers ADD COLUMN website VARCHAR(150) NULL"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE suppliers ADD COLUMN tax_id VARCHAR(50) NULL"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE suppliers ADD COLUMN latitude DECIMAL(10,8) NULL"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE suppliers ADD COLUMN longitude DECIMAL(11,8) NULL"); } catch(PDOException $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        method ENUM('Cash', 'Bank Transfer', 'Cheque', 'Other') NOT NULL,
        reference VARCHAR(100) NULL,
        notes VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (supplier_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch(PDOException $e) {}

// --- DYNAMIC RECONCILIATION OF PAST GRN PAYMENTS ---
try {
    $all_grns = $pdo->prepare("SELECT id, supplier_id, paid_amount, grn_date, payment_method FROM grns WHERE paid_amount > 0 AND supplier_id = ?");
    $all_grns->execute([$supplier_id]);
    foreach ($all_grns->fetchAll() as $g) {
        $grn_no = "GRN-" . str_pad($g['id'], 6, '0', STR_PAD_LEFT);
        // Check if this initial payment is already logged in supplier_payments
        $chk = $pdo->prepare("SELECT COUNT(*) FROM supplier_payments WHERE supplier_id = ? AND reference = ?");
        $chk->execute([$g['supplier_id'], $grn_no]);
        if ($chk->fetchColumn() == 0) {
            $pdo->prepare("INSERT INTO supplier_payments (supplier_id, amount, method, reference, notes, created_at) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$g['supplier_id'], $g['paid_amount'], $g['payment_method'], $grn_no, "Initial Payment at GRN Creation", $g['grn_date'] . ' 00:00:00']);
        }
    }
} catch(Exception $e) {}

// --- HANDLE POST ACTIONS (Record Payment - STAFF ONLY) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'record_payment') {
    $pay_amount = (float)$_POST['payment_amount'];
    $payment_method = $_POST['payment_method']; // 'Cash', 'Bank Transfer', 'Cheque'
    
    if ($pay_amount > 0) {
        try {
            $pdo->beginTransaction();

            // Verify sufficient company finances if cash or bank transfer
            $fin = $pdo->query("SELECT * FROM company_finances WHERE id = 1 FOR UPDATE")->fetch();
            if ($payment_method === 'Cash' && $pay_amount > $fin['cash_on_hand']) {
                throw new Exception("Insufficient Cash on Hand to process this payment. (Available: Rs " . number_format($fin['cash_on_hand'], 2) . ")");
            }
            if ($payment_method === 'Bank Transfer' && $pay_amount > $fin['bank_balance']) {
                throw new Exception("Insufficient Bank Balance to process this payment. (Available: Rs " . number_format($fin['bank_balance'], 2) . ")");
            }

            // Distribute payment across unpaid GRNs, oldest first
            $stmt = $pdo->prepare("SELECT id, total_amount, paid_amount FROM grns WHERE supplier_id = ? AND total_amount > paid_amount ORDER BY grn_date ASC, created_at ASC FOR UPDATE");
            $stmt->execute([$supplier_id]);
            $unpaid_grns = $stmt->fetchAll();

            $remaining_payment = $pay_amount;
            $first_grn_id = null; // Used to link the cheque if paying multiple GRNs

            foreach ($unpaid_grns as $grn) {
                if ($remaining_payment <= 0) break;
                
                if (!$first_grn_id) $first_grn_id = $grn['id'];

                $amount_due = $grn['total_amount'] - $grn['paid_amount'];
                $amount_to_apply = min($amount_due, $remaining_payment);

                $new_paid_amount = $grn['paid_amount'] + $amount_to_apply;
                
                // Determine new status
                if ($payment_method === 'Cheque') {
                    $new_status = 'waiting';
                } else {
                    $new_status = ($new_paid_amount >= $grn['total_amount']) ? 'paid' : 'pending';
                }

                $updateStmt = $pdo->prepare("UPDATE grns SET paid_amount = ?, payment_status = ?, payment_method = ? WHERE id = ?");
                $updateStmt->execute([$new_paid_amount, $new_status, $payment_method, $grn['id']]);

                $remaining_payment -= $amount_to_apply;
            }

            // Capture reference and handle cheque linking
            $reference = trim($_POST['payment_reference'] ?? "");
            if ($payment_method === 'Cheque' && $first_grn_id) {
                $bank_name = trim($_POST['cheque_bank']);
                $cheque_number = trim($_POST['cheque_number']);
                $banking_date = $_POST['cheque_date'];
                
                if(empty($reference)) $reference = "$bank_name - $cheque_number";
                
                $chkStmt = $pdo->prepare("
                    INSERT INTO cheques (type, supplier_id, grn_id, bank_name, cheque_number, banking_date, amount, status) 
                    VALUES ('outgoing', ?, ?, ?, ?, ?, ?, 'pending')
                ");
                $chkStmt->execute([$supplier_id, $first_grn_id, $bank_name, $cheque_number, $banking_date, $pay_amount]);
            } else {
                // Deduct from Company Finances instantly for Cash or Bank Transfer
                if ($payment_method === 'Cash') {
                    $pdo->prepare("UPDATE company_finances SET cash_on_hand = cash_on_hand - ? WHERE id = 1")->execute([$pay_amount]);
                    $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('cash_out', ?, ?, ?)")->execute([$pay_amount, "Supplier Account Pay (Cash) - Supplier #$supplier_id", $_SESSION['user_id']]);
                } elseif ($payment_method === 'Bank Transfer') {
                    $pdo->prepare("UPDATE company_finances SET bank_balance = bank_balance - ? WHERE id = 1")->execute([$pay_amount]);
                    $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('bank_out', ?, ?, ?)")->execute([$pay_amount, "Supplier Account Pay (Bank) - Supplier #$supplier_id", $_SESSION['user_id']]);
                }
            }
            
            // Log in the dedicated ledger table
            $pdo->prepare("INSERT INTO supplier_payments (supplier_id, amount, method, reference, notes) VALUES (?, ?, ?, ?, ?)")
                ->execute([$supplier_id, $pay_amount, $payment_method, $reference, "Admin Recorded Supplier Payment"]);

            $pdo->commit();
            $message = "<div class='clean-alert success-alert mb-4'><i class='bi bi-check-circle-fill'></i><div><h6 class='m-0 fw-bold'>Payment Recorded</h6><p class='m-0 small'>Payment of Rs " . number_format($pay_amount, 2) . " processed successfully via " . htmlspecialchars($payment_method) . ".</p></div></div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='clean-alert error-alert mb-4'><i class='bi bi-exclamation-triangle-fill'></i><div><h6 class='m-0 fw-bold'>Error</h6><p class='m-0 small'>" . htmlspecialchars($e->getMessage()) . "</p></div></div>";
        }
    } else {
        $message = "<div class='clean-alert warning-alert mb-4'><i class='bi bi-info-circle-fill'></i><p class='m-0'>Invalid payment amount.</p></div>";
    }
}

// --- HANDLE POST ACTIONS (Edit Profile) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'edit_profile') {
    $company_name = trim($_POST['company_name']);
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $website = trim($_POST['website']);
    $tax_id = trim($_POST['tax_id']);
    $address = trim($_POST['address']);
    $latitude = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $longitude = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;
    
    if (!empty($company_name)) {
        try {
            $stmt = $pdo->prepare("UPDATE suppliers SET company_name=?, name=?, phone=?, email=?, website=?, tax_id=?, address=?, latitude=?, longitude=? WHERE id=?");
            $stmt->execute([$company_name, $name, $phone, $email, $website, $tax_id, $address, $latitude, $longitude, $supplier_id]);
            $message = "<div class='clean-alert success-alert mb-4'><i class='bi bi-check-circle-fill'></i><p class='m-0 fw-bold'>Supplier profile updated successfully!</p></div>";
        } catch (Exception $e) {
            $message = "<div class='clean-alert error-alert mb-4'><i class='bi bi-exclamation-triangle-fill'></i><p class='m-0'>" . htmlspecialchars($e->getMessage()) . "</p></div>";
        }
    } else {
        $message = "<div class='clean-alert error-alert mb-4'><i class='bi bi-exclamation-triangle-fill'></i><p class='m-0'>Company Name is required.</p></div>";
    }
}

// Fetch Supplier Info
$stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
$stmt->execute([$supplier_id]);
$supplier = $stmt->fetch();

if (!$supplier) {
    die("<div class='clean-alert error-alert m-4'><i class='bi bi-x-circle-fill'></i><p class='m-0'>Supplier not found.</p></div>");
}

// WhatsApp cleansing helper
$whatsapp_raw = $supplier['phone'] ?? '';
$whatsapp_clean = preg_replace('/[^0-9]/', '', $whatsapp_raw);
if (strlen($whatsapp_clean) == 10 && str_starts_with($whatsapp_clean, '0')) {
    $whatsapp_clean = '94' . substr($whatsapp_clean, 1); 
}

// Fetch Financial Metrics (Total Billed, Total Paid, Outstanding)
$metricsStmt = $pdo->prepare("
    SELECT 
        COUNT(id) as total_grns,
        SUM(total_amount) as total_billed,
        SUM(paid_amount) as total_paid,
        SUM(total_amount - paid_amount) as outstanding_balance
    FROM grns 
    WHERE supplier_id = ?
");
$metricsStmt->execute([$supplier_id]);
$metrics = $metricsStmt->fetch();

// Fetch Total PO Count
$poCountStmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ?");
$poCountStmt->execute([$supplier_id]);
$total_pos = $poCountStmt->fetchColumn() ?: 0;

$outstanding_balance = $metrics['outstanding_balance'] ?: 0;

// Fetch Recent GRNs
$grnsStmt = $pdo->prepare("
    SELECT g.* 
    FROM grns g
    WHERE g.supplier_id = ? 
    ORDER BY g.grn_date DESC LIMIT 15
");
$grnsStmt->execute([$supplier_id]);
$grns = $grnsStmt->fetchAll();

// Fetch Recent POs
$posStmt = $pdo->prepare("
    SELECT po.* 
    FROM purchase_orders po
    WHERE po.supplier_id = ? 
    ORDER BY po.po_date DESC LIMIT 15
");
$posStmt->execute([$supplier_id]);
$pos = $posStmt->fetchAll();

// Fetch Linked Outgoing Cheques
$chequesStmt = $pdo->prepare("
    SELECT ch.*, g.grn_date 
    FROM cheques ch 
    LEFT JOIN grns g ON ch.grn_id = g.id 
    WHERE ch.supplier_id = ? AND ch.type = 'outgoing'
    ORDER BY ch.banking_date ASC
");
$chequesStmt->execute([$supplier_id]);
$cheques = $chequesStmt->fetchAll();

// --- FETCH DATA FOR COMPREHENSIVE SUPPLIER LEDGER ---
$ledger = [];

// 1. Credits (Cr): GRNs (Invoices received - increases liability)
$grnLedgerStmt = $pdo->prepare("SELECT id, total_amount, grn_date, 'GRN' as type FROM grns WHERE supplier_id = ?");
$grnLedgerStmt->execute([$supplier_id]);
foreach($grnLedgerStmt->fetchAll() as $gl) {
    $ledger[] = [
        'date' => $gl['grn_date'] . ' 00:00:00',
        'description' => "GRN #" . str_pad($gl['id'], 6, '0', STR_PAD_LEFT),
        'amount' => $gl['total_amount'],
        'entry_type' => 'credit',
        'ref_id' => $gl['id']
    ];
}

// 2. Debits (Dr): Dedicated Payments (reduces liability)
$payLedgerStmt = $pdo->prepare("SELECT amount, method, reference, created_at FROM supplier_payments WHERE supplier_id = ?");
$payLedgerStmt->execute([$supplier_id]);
foreach($payLedgerStmt->fetchAll() as $sl) {
    $ledger[] = [
        'date' => $sl['created_at'],
        'description' => "Payment via " . htmlspecialchars($sl['method']),
        'reference' => $sl['reference'],
        'amount' => $sl['amount'],
        'entry_type' => 'debit'
    ];
}

// Sort ledger chronologically
usort($ledger, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']); // Newest first
});

// Generate initials & color for Avatar
$words = explode(" ", $supplier['company_name']);
$initials = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
$colors = ['#EF4444', '#F59E0B', '#10B981', '#2563EB', '#8B5CF6', '#EC4899'];
$avatar_color = $colors[$supplier['id'] % count($colors)];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Supplier Profile - <?php echo htmlspecialchars($supplier['company_name']); ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        :root {
            --bg-color: #F8FAFC;         
            --surface: #FFFFFF;          
            --text-main: #0F172A;        
            --text-muted: #64748B;       
            --border: #E2E8F0;           
            
            --primary: #CC2200;          
            --primary-bg: #FEE2E2;
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
            padding-bottom: 40px;
            -webkit-font-smoothing: antialiased;
            margin: 0;
        }

        .app-header {
            background: var(--surface);
            padding: 20px 20px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
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
            text-decoration: none; cursor: pointer;
        }
        .back-btn:active { background: var(--border); }
        .header-title { font-size: 18px; font-weight: 700; margin: 0; letter-spacing: -0.01em; }

        .btn-edit {
            background: var(--primary-bg); color: var(--primary);
            border: none; border-radius: 100px; padding: 6px 14px;
            font-size: 13px; font-weight: 600; transition: transform 0.1s;
        }
        .btn-edit:active { transform: scale(0.96); }

        .page-content { padding: 20px 16px; max-width: 1200px; margin: 0 auto; }

        .clean-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); padding: 20px; margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
        }
        .card-title-sm {
            font-size: 12px; font-weight: 700; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 16px;
            display: flex; align-items: center; gap: 8px;
        }

        .profile-header { display: flex; align-items: center; gap: 16px; margin-bottom: 20px; }
        .cust-avatar {
            width: 64px; height: 64px; border-radius: 50%; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; font-weight: 700;
        }
        .profile-name { font-size: 20px; font-weight: 700; color: var(--text-main); margin: 0 0 4px 0; }
        .profile-owner { font-size: 13px; color: var(--text-muted); font-weight: 500; }

        .btn-sm-outline {
            background: var(--surface); border: 1px solid var(--border); color: var(--text-main);
            border-radius: 100px; padding: 8px 16px; font-size: 13px; font-weight: 600;
            display: inline-flex; align-items: center; gap: 6px; text-decoration: none;
            transition: background 0.1s;
        }
        .btn-sm-outline:active { background: var(--bg-color); }
        .btn-sm-outline i { font-size: 15px; }

        .profile-address { font-size: 14px; color: var(--text-muted); line-height: 1.5; margin-bottom: 16px; }
        
        .badge-custom {
            display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 600;
            padding: 6px 10px; border-radius: 6px; white-space: nowrap; font-family: 'JetBrains Mono', monospace;
        }
        .badge-custom.gray { background: var(--bg-color); color: var(--text-muted); border: 1px solid var(--border); }

        .map-container {
            width: 100%; height: 200px; border-radius: var(--radius-md);
            overflow: hidden; background: var(--bg-color); border: 1px solid var(--border);
            display: flex; align-items: center; justify-content: center;
        }

        .metric-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-md); padding: 16px;
            display: flex; flex-direction: column; justify-content: center;
            height: 100%; box-shadow: var(--shadow-sm);
        }
        .metric-card.highlight { background: var(--danger-bg); border-color: #FECACA; }
        .metric-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 8px; }
        .metric-card.highlight .metric-title { color: var(--danger); }
        .metric-value { font-family: 'JetBrains Mono', monospace; font-size: 20px; font-weight: 700; color: var(--text-main); line-height: 1; }
        .metric-card.highlight .metric-value { color: var(--danger); font-size: 24px; }

        .btn-action {
            width: 100%; border: none; border-radius: 100px; padding: 10px;
            font-size: 13px; font-weight: 600; background: var(--danger); color: #fff;
            margin-top: 12px; transition: transform 0.1s;
        }
        .btn-action:active { transform: scale(0.97); }

        .table-responsive-custom { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: var(--radius-md); border: 1px solid var(--border); background: var(--surface); }
        .clean-table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 500px; }
        .clean-table th { 
            background: var(--bg-color); color: var(--text-muted); 
            font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; 
            padding: 12px 16px; border-bottom: 1px solid var(--border); position: sticky; top: 0; white-space: nowrap;
        }
        .clean-table td { padding: 14px 16px; border-bottom: 1px solid var(--border); font-size: 14px; vertical-align: middle; color: var(--text-main); }
        .clean-table tr:last-child td { border-bottom: none; }
        .clean-table tr:hover td { background: var(--bg-color); }
        
        .table-mono { font-family: 'JetBrains Mono', monospace; font-weight: 600; }
        .table-desc { font-weight: 600; color: var(--text-main); margin-bottom: 2px; }
        .table-meta { font-size: 11px; color: var(--text-muted); }

        .status-badge { display: inline-flex; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .status-paid { background: var(--success-bg); color: var(--success); }
        .status-pending { background: var(--warning-bg); color: #92400E; }
        .status-waiting { background: var(--bg-color); color: var(--text-muted); border: 1px solid var(--border); }
        .status-returned { background: var(--danger-bg); color: var(--danger); }

        .modal-content { border: none; border-radius: 24px; box-shadow: var(--shadow-lg); }
        .modal-header { border-bottom: 1px solid var(--border); padding: 20px; }
        .modal-title { font-weight: 700; font-size: 18px; color: var(--text-main); }
        .modal-body { padding: 20px; }
        
        .clean-input {
            width: 100%; background: var(--bg-color); border: 1px solid var(--border);
            border-radius: var(--radius-md); padding: 14px 16px; font-size: 15px;
            font-family: 'Inter', sans-serif; color: var(--text-main); outline: none;
            transition: border 0.2s;
        }
        .clean-input:focus { border-color: var(--primary); background: #fff; }
        
        select.clean-input {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%2364748B%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E");
            background-repeat: no-repeat; background-position: right 14px top 50%; background-size: 10px auto;
            padding-right: 40px; font-weight: 600;
        }

        .btn-full {
            width: 100%; border: none; border-radius: var(--radius-md); padding: 16px;
            font-size: 16px; font-weight: 600; cursor: pointer; transition: transform 0.1s;
            text-align: center; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px;
            background: var(--primary); color: #fff;
        }
        .btn-full:active { transform: scale(0.98); }

        .clean-alert {
            background: var(--surface); border-radius: var(--radius-md); padding: 16px;
            display: flex; gap: 12px; align-items: center; border: 1px solid var(--border);
            margin-bottom: 20px;
        }
        .clean-alert.success-alert { background: var(--success-bg); border-color: #A7F3D0; color: var(--success); }
        .clean-alert.error-alert { background: var(--danger-bg); border-color: #FECACA; color: var(--danger); }
        .clean-alert.warning-alert { background: var(--warning-bg); border-color: #FDE68A; color: #92400E; }
        .clean-alert.info-alert { background: var(--primary-bg); border-color: #BFDBFE; color: var(--primary); }
        .clean-alert i { font-size: 24px; margin-top: -2px; }

    </style>
</head>
<body>

    <header class="app-header">
        <div class="header-stack" id="backBtnContainer">
            <?php if(!$is_modal): ?>
                <a href="javascript:history.back()" class="back-btn"><i class="bi bi-arrow-left"></i></a>
            <?php endif; ?>
            <div>
                <h1 class="header-title">Supplier Profile</h1>
                <span class="header-sub"><?php echo htmlspecialchars($supplier['company_name']); ?></span>
            </div>
        </div>
    </header>

    <div class="page-content">
        
        <?php echo $message; ?>

        <!-- Supplier Header & Map -->
        <div class="row g-3 mb-3">
            <div class="col-md-7">
                <div class="clean-card h-100 position-relative">
                    <button class="btn-edit position-absolute top-0 end-0 m-3" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                        <i class="bi bi-pencil-square me-1"></i> Edit
                    </button>
                    
                    <div class="profile-header">
                        <div class="cust-avatar" style="background: <?php echo $avatar_color; ?>20; color: <?php echo $avatar_color; ?>;">
                            <?php echo $initials; ?>
                        </div>
                        <div>
                            <h3 class="profile-name"><?php echo htmlspecialchars($supplier['company_name']); ?></h3>
                            <div class="profile-owner"><i class="bi bi-person-badge me-1"></i> Contact: <?php echo htmlspecialchars($supplier['name'] ?: 'N/A'); ?></div>
                        </div>
                    </div>
                    
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <?php if($supplier['phone']): ?>
                            <a href="tel:<?php echo htmlspecialchars($supplier['phone']); ?>" class="btn-sm-outline"><i class="bi bi-telephone text-primary"></i> <?php echo htmlspecialchars($supplier['phone']); ?></a>
                        <?php endif; ?>
                        <?php if($supplier['phone']): ?>
                            <a href="https://wa.me/<?php echo $whatsapp_clean; ?>" target="_blank" class="btn-sm-outline"><i class="bi bi-whatsapp text-success"></i> WhatsApp</a>
                        <?php endif; ?>
                        <?php if($supplier['email']): ?>
                            <a href="mailto:<?php echo htmlspecialchars($supplier['email']); ?>" class="btn-sm-outline"><i class="bi bi-envelope text-danger"></i> Email</a>
                        <?php endif; ?>
                        <?php if($supplier['website']): ?>
                            <a href="<?php echo htmlspecialchars($supplier['website']); ?>" target="_blank" class="btn-sm-outline"><i class="bi bi-globe text-info"></i> Website</a>
                        <?php endif; ?>
                    </div>

                    <div class="profile-address">
                        <i class="bi bi-geo-alt-fill me-1 text-muted"></i> <?php echo nl2br(htmlspecialchars($supplier['address'] ?: 'No address recorded.')); ?>
                    </div>
                    
                    <div class="d-flex gap-2 flex-wrap mt-3">
                        <span class="badge-custom gray"><i class="bi bi-tag"></i> Tax ID: <?php echo htmlspecialchars($supplier['tax_id'] ?: 'None'); ?></span>
                        <span class="badge-custom gray"><i class="bi bi-building"></i> Status: <?php echo ucfirst(htmlspecialchars($supplier['status'])); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="col-md-5">
                <div class="clean-card h-100 d-flex flex-column">
                    <div class="card-title-sm mb-2"><i class="bi bi-map"></i> Supplier Location</div>
                    <?php if($supplier['latitude'] && $supplier['longitude']): ?>
                        <div class="map-container" style="flex: 1; min-height: 180px;">
                            <iframe src="https://maps.google.com/maps?q=<?php echo $supplier['latitude']; ?>,<?php echo $supplier['longitude']; ?>&z=15&output=embed" frameborder="0" style="width: 100%; height: 100%; border:0;" allowfullscreen></iframe>
                        </div>
                        <div class="mt-2 text-end">
                            <a href="https://maps.google.com/?q=<?php echo $supplier['latitude']; ?>,<?php echo $supplier['longitude']; ?>" target="_blank" class="text-primary fw-bold text-decoration-none" style="font-size: 13px;"><i class="bi bi-box-arrow-up-right me-1"></i> Open in Maps</a>
                        </div>
                    <?php else: ?>
                        <div class="map-container flex-column text-center p-4">
                            <i class="bi bi-geo-slash text-muted" style="font-size: 2.5rem; margin-bottom: 10px;"></i>
                            <span class="fw-bold text-muted" style="font-size: 14px;">No Location Coordinates</span>
                            <small class="text-muted mt-1" style="font-size: 12px;">Update coordinates to view map.</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Financial Metrics -->
        <div class="row mb-4 g-3">
            <div class="col-md-3 col-6">
                <div class="metric-card">
                    <div class="metric-title">Total POs</div>
                    <div class="metric-value"><?php echo $total_pos; ?></div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="metric-card">
                    <div class="metric-title">Total Purchases (Billed)</div>
                    <div class="metric-value">Rs <?php echo number_format($metrics['total_billed'] ?: 0, 2); ?></div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="metric-card">
                    <div class="metric-title">Total Paid</div>
                    <div class="metric-value text-success">Rs <?php echo number_format($metrics['total_paid'] ?: 0, 2); ?></div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="metric-card highlight">
                    <div class="metric-title">Outstanding Payable</div>
                    <div class="metric-value">Rs <?php echo number_format($outstanding_balance, 2); ?></div>
                    
                    <?php if($outstanding_balance > 0): ?>
                        <button class="btn-action animate-bounce" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
                            <i class="bi bi-cash-coin me-1"></i> Record Payment
                        </button>
                    <?php else: ?>
                        <div class="text-success mt-2 fw-bold" style="font-size: 12px;"><i class="bi bi-check-circle-fill me-1"></i> Account fully paid</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Layout Grid for Tables -->
        <div class="row g-4">
            
            <!-- Activity Ledger -->
            <div class="col-lg-12">
                <div class="clean-card p-0 overflow-hidden mb-0">
                    <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-light">
                        <h6 class="card-title-sm m-0"><i class="bi bi-journal-text text-danger"></i> Supplier Payment Ledger</h6>
                        <div style="font-size: 11px; font-weight: 600; color: var(--text-muted);">Complete Flow</div>
                    </div>
                    <div class="table-responsive-custom border-0 m-0 rounded-0" style="max-height: 400px;">
                        <table class="clean-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Description</th>
                                    <th class="text-end">Debit (Dr) - Paid</th>
                                    <th class="text-end">Credit (Cr) - GRN</th>
                                    <th class="text-end">Payable Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $mathLedger = array_reverse($ledger);
                                $running = 0;
                                $ledgerWithBalance = [];
                                foreach($mathLedger as $entry) {
                                    if($entry['entry_type'] == 'credit') $running += $entry['amount']; // Credit is invoice increase
                                    else $running -= $entry['amount']; // Debit is payment decrease
                                    $entry['running'] = $running;
                                    $ledgerWithBalance[] = $entry;
                                }
                                $finalDisplay = array_reverse($ledgerWithBalance);
                                
                                foreach($finalDisplay as $entry): 
                                ?>
                                <tr>
                                    <td>
                                        <div class="table-mono text-muted" style="font-size: 12px;"><?php echo date('M d, Y', strtotime($entry['date'])); ?></div>
                                        <div class="table-meta"><?php echo date('h:i A', strtotime($entry['date'])); ?></div>
                                    </td>
                                    <td>
                                        <div class="table-desc"><?php echo $entry['description']; ?></div>
                                        <?php if(!empty($entry['reference'])): ?>
                                            <div class="table-meta"><i class="bi bi-info-circle me-1"></i>Ref: <?php echo htmlspecialchars($entry['reference']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end table-mono">
                                        <?php echo $entry['entry_type'] == 'debit' ? '<span class="text-success">Rs ' . number_format($entry['amount'], 2) . '</span>' : '<span class="text-muted">-</span>'; ?>
                                    </td>
                                    <td class="text-end table-mono">
                                        <?php echo $entry['entry_type'] == 'credit' ? '<span class="text-danger">Rs ' . number_format($entry['amount'], 2) . '</span>' : '<span class="text-muted">-</span>'; ?>
                                    </td>
                                    <td class="text-end table-mono">
                                        <span style="color: <?php echo $entry['running'] > 0 ? 'var(--primary)' : 'var(--success)'; ?>; font-weight: 700;">
                                            Rs <?php echo number_format($entry['running'], 2); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($ledger)): ?>
                                <tr><td colspan="5" class="text-center py-5 text-muted fw-bold">No transactions found for this supplier.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Goods Received Notes (GRN) -->
            <div class="col-lg-6">
                <div class="clean-card p-0 overflow-hidden h-100 mb-0">
                    <div class="p-3 border-bottom bg-light">
                        <h6 class="card-title-sm m-0"><i class="bi bi-box-seam text-danger"></i> Goods Received Notes (GRN)</h6>
                    </div>
                    <div class="table-responsive-custom border-0 m-0 rounded-0" style="max-height: 350px;">
                        <table class="clean-table text-center">
                            <thead>
                                <tr>
                                    <th>GRN #</th>
                                    <th>Date</th>
                                    <th>Total Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($grns as $g): ?>
                                <tr>
                                    <td>
                                        <a href="view_grn.php?id=<?php echo $g['id']; ?>" class="table-mono text-primary text-decoration-none fw-bold" target="_blank">#<?php echo str_pad($g['id'], 6, '0', STR_PAD_LEFT); ?></a>
                                    </td>
                                    <td class="table-mono text-muted" style="font-size: 12px;"><?php echo date('M d, Y', strtotime($g['grn_date'])); ?></td>
                                    <td class="table-mono text-dark font-monospace">Rs <?php echo number_format($g['total_amount'], 2); ?></td>
                                    <td>
                                        <?php if($g['payment_status'] == 'paid'): ?>
                                            <span class="status-badge status-paid">Paid</span>
                                        <?php elseif($g['payment_status'] == 'waiting'): ?>
                                            <span class="status-badge status-waiting">Waiting</span>
                                        <?php else: ?>
                                            <span class="status-badge status-pending">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($grns)): ?>
                                    <tr><td colspan="4" class="text-center py-4 text-muted small">No GRNs recorded.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Purchase Orders (PO) -->
            <div class="col-lg-6">
                <div class="clean-card p-0 overflow-hidden h-100 mb-0">
                    <div class="p-3 border-bottom bg-light">
                        <h6 class="card-title-sm m-0"><i class="bi bi-file-earmark-text text-danger"></i> Purchase Orders (PO)</h6>
                    </div>
                    <div class="table-responsive-custom border-0 m-0 rounded-0" style="max-height: 350px;">
                        <table class="clean-table text-center">
                            <thead>
                                <tr>
                                    <th>PO #</th>
                                    <th>Date</th>
                                    <th>Discount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($pos as $po): ?>
                                <tr>
                                    <td>
                                        <a href="view_po.php?id=<?php echo $po['id']; ?>" class="table-mono text-primary text-decoration-none fw-bold" target="_blank">#<?php echo str_pad($po['id'], 6, '0', STR_PAD_LEFT); ?></a>
                                    </td>
                                    <td class="table-mono text-muted" style="font-size: 12px;"><?php echo date('M d, Y', strtotime($po['po_date'])); ?></td>
                                    <td class="table-mono text-dark">Rs <?php echo number_format($po['discount_amount'], 2); ?></td>
                                    <td>
                                        <?php if($po['status'] == 'completed'): ?>
                                            <span class="status-badge status-paid">Completed</span>
                                        <?php elseif($po['status'] == 'cancelled'): ?>
                                            <span class="status-badge status-returned">Cancelled</span>
                                        <?php else: ?>
                                            <span class="status-badge status-pending">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($pos)): ?>
                                    <tr><td colspan="4" class="text-center py-4 text-muted small">No purchase orders recorded.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Latest Outgoing Cheques -->
            <div class="col-lg-12">
                <div class="clean-card p-0 overflow-hidden h-100 mb-0">
                    <div class="p-3 border-bottom bg-light">
                        <h6 class="card-title-sm m-0"><i class="bi bi-credit-card-2-front text-warning"></i> Outgoing Post-Dated Cheques</h6>
                    </div>
                    <div class="table-responsive-custom border-0 m-0 rounded-0" style="max-height: 350px;">
                        <table class="clean-table text-center">
                            <thead>
                                <tr>
                                    <th class="text-start">Bank & Cheque No.</th>
                                    <th>Banking Date</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($cheques as $ch): ?>
                                <tr>
                                    <td class="text-start">
                                        <div class="table-desc" style="font-size: 13px;"><?php echo htmlspecialchars($ch['bank_name']); ?></div>
                                        <div class="table-meta"><i class="bi bi-upc-scan me-1"></i> No: <?php echo htmlspecialchars($ch['cheque_number']); ?></div>
                                    </td>
                                    <td class="table-mono text-muted" style="font-size: 12px;"><?php echo date('M d, Y', strtotime($ch['banking_date'])); ?></td>
                                    <td class="table-mono text-dark font-monospace fw-bold">Rs <?php echo number_format($ch['amount'], 2); ?></td>
                                    <td>
                                        <?php 
                                            if($ch['status'] === 'passed') echo '<span class="status-badge status-paid">Passed</span>';
                                            elseif($ch['status'] === 'returned') echo '<span class="status-badge status-returned">Returned</span>';
                                            else echo '<span class="status-badge status-pending">Pending</span>';
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($cheques)): ?>
                                    <tr><td colspan="4" class="text-center py-4 text-muted small">No outgoing cheques registered for this supplier.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

<!-- ================= MODALS ================= -->

<!-- Edit Profile Modal -->
<div class="modal fade" id="editProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header bg-light pb-3">
                    <h5 class="modal-title"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Supplier Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_profile">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase" style="letter-spacing: 0.05em;">Company Name <span class="text-danger">*</span></label>
                        <input type="text" name="company_name" class="clean-input fw-bold" value="<?php echo htmlspecialchars($supplier['company_name'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase" style="letter-spacing: 0.05em;">Contact Person Name</label>
                        <input type="text" name="name" class="clean-input" value="<?php echo htmlspecialchars($supplier['name'] ?? ''); ?>">
                    </div>
                    
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase" style="letter-spacing: 0.05em;">Phone / WhatsApp</label>
                            <input type="tel" name="phone" class="clean-input mono" value="<?php echo htmlspecialchars($supplier['phone'] ?? ''); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase" style="letter-spacing: 0.05em;">Tax ID (VAT/SVAT)</label>
                            <input type="text" name="tax_id" class="clean-input mono" value="<?php echo htmlspecialchars($supplier['tax_id'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase" style="letter-spacing: 0.05em;">Email Address</label>
                            <input type="email" name="email" class="clean-input" value="<?php echo htmlspecialchars($supplier['email'] ?? ''); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase" style="letter-spacing: 0.05em;">Website Link</label>
                            <input type="url" name="website" class="clean-input" value="<?php echo htmlspecialchars($supplier['website'] ?? ''); ?>" placeholder="https://">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase" style="letter-spacing: 0.05em;">Latitude Coordinates</label>
                            <input type="number" step="any" name="latitude" class="clean-input mono" value="<?php echo htmlspecialchars($supplier['latitude'] ?? ''); ?>" placeholder="e.g. 6.9271">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase" style="letter-spacing: 0.05em;">Longitude Coordinates</label>
                            <input type="number" step="any" name="longitude" class="clean-input mono" value="<?php echo htmlspecialchars($supplier['longitude'] ?? ''); ?>" placeholder="e.g. 79.8612">
                        </div>
                    </div>
                    
                    <div class="mb-2">
                        <label class="form-label fw-bold small text-muted text-uppercase" style="letter-spacing: 0.05em;">Office / Warehouse Address</label>
                        <textarea name="address" class="clean-input" rows="2"><?php echo htmlspecialchars($supplier['address'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="submit" class="btn-full" style="background: var(--primary);">Save Profile Details</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Record Payment Modal -->
<?php if($outstanding_balance > 0): ?>
<div class="modal fade" id="recordPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header bg-light pb-3">
                    <h5 class="modal-title"><i class="bi bi-cash-coin text-success me-2"></i>Record Outgoing Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="record_payment">
                    
                    <div class="clean-alert info-alert text-center flex-column mb-4 py-3" style="background: rgba(204,34,0,0.06); border-color: rgba(204,34,0,0.15);">
                        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--primary);">Outstanding Payable Balance</div>
                        <div class="font-monospace fs-3 fw-bold mt-1" style="color: var(--primary);">Rs <?php echo number_format($outstanding_balance, 2); ?></div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase" style="letter-spacing: 0.05em;">Payment Method <span class="text-danger">*</span></label>
                        <select name="payment_method" id="payMethodSelect" class="clean-input fw-bold border-dark" required>
                            <option value="Cash">Cash (Deducts cash on hand)</option>
                            <option value="Bank Transfer">Bank Transfer (Deducts bank balance)</option>
                            <option value="Cheque">Cheque (Post-dated Outgoing)</option>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold small text-muted text-uppercase" style="letter-spacing: 0.05em;">Payment Amount (Rs) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="payment_amount" id="payAmountInput" class="clean-input mono text-center fs-3 py-3" max="<?php echo $outstanding_balance; ?>" required placeholder="0.00" style="color: var(--primary); font-weight: 700;">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase" style="letter-spacing: 0.05em;">Reference / Note</label>
                        <input type="text" name="payment_reference" class="clean-input" placeholder="Bank receipt ref, PO reference...">
                        <small class="text-muted mt-1 d-block" style="font-size: 11px;"><i class="bi bi-info-circle me-1"></i> Automatically settles oldest pending GRNs first.</small>
                    </div>

                    <!-- Hidden Cheque Fields -->
                    <div id="chequeFields" class="d-none bg-light p-3 rounded-3 border mb-3">
                        <h6 class="fw-bold mb-3 small text-uppercase text-warning" style="letter-spacing: 0.05em; color: #D97706 !important;"><i class="bi bi-credit-card-2-front me-2"></i>Outgoing Cheque Details</h6>
                        <div class="mb-3">
                            <input type="text" name="cheque_bank" id="chkBank" class="clean-input" placeholder="Bank Name (e.g. BOC)">
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <input type="text" name="cheque_number" id="chkNum" class="clean-input mono" placeholder="Cheque No.">
                            </div>
                            <div class="col-6">
                                <input type="date" name="cheque_date" id="chkDate" class="clean-input mono">
                            </div>
                        </div>
                        <small class="text-muted mt-2 d-block" style="font-size: 11px;">Note: GRN will update to 'Waiting' until the cheque is passed.</small>
                    </div>

                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="submit" class="btn-full" style="background: var(--primary);" onclick="return confirm('Confirm processing this payment? Company finances will be adjusted instantly.');"><i class="bi bi-check2-circle me-1"></i> Confirm Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    // Hide Back Button if opened inside an iframe modal popup
    if (window.self !== window.top) {
        const backBtnContainer = document.getElementById('backBtnContainer');
        if (backBtnContainer) {
            backBtnContainer.style.display = 'none';
        }
    }

    // Outgoing Cheque Toggle Logic
    const methodSelect = document.getElementById('payMethodSelect');
    const chequeFields = document.getElementById('chequeFields');
    const chkBank = document.getElementById('chkBank');
    const chkNum = document.getElementById('chkNum');
    const chkDate = document.getElementById('chkDate');

    if (methodSelect) {
        methodSelect.addEventListener('change', function() {
            if (this.value === 'Cheque') {
                chequeFields.classList.remove('d-none');
                chkBank.required = true;
                chkNum.required = true;
                chkDate.required = true;
            } else {
                chequeFields.classList.add('d-none');
                chkBank.required = false;
                chkNum.required = false;
                chkDate.required = false;
            }
        });
    }
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bundle.min.js"></script>
</body>
</html>
