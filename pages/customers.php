<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor', 'rep']);

$message = '';

// --- AUTO DB MIGRATION FOR CONTACT & ROUTE FIELDS ---
try {
    $pdo->exec("ALTER TABLE customers ADD COLUMN whatsapp VARCHAR(20) NULL");
    $pdo->exec("ALTER TABLE customers ADD COLUMN email VARCHAR(150) NULL");
} catch(PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE customers ADD COLUMN route_id INT NULL AFTER rep_id");
    $pdo->exec("ALTER TABLE customers ADD CONSTRAINT fk_customers_route FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE SET NULL");
} catch(PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE customers ADD COLUMN latitude DECIMAL(10, 8) NULL");
    $pdo->exec("ALTER TABLE customers ADD COLUMN longitude DECIMAL(11, 8) NULL");
} catch(PDOException $e) {}
// ------------------------------------------------

// Handle POST Actions (Add/Edit/Delete)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // ADD CUSTOMER
    if ($_POST['action'] == 'add_customer') {
        $name = trim($_POST['name']);
        $owner_name = trim($_POST['owner_name']);
        $phone = trim($_POST['phone']);
        $whatsapp = trim($_POST['whatsapp']);
        $email = trim($_POST['email']);
        $address = trim($_POST['address']);
        $latitude = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
        $longitude = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;
        $rep_id = hasRole('rep') ? $_SESSION['user_id'] : (!empty($_POST['rep_id']) ? (int)$_POST['rep_id'] : null);
        $route_id = !empty($_POST['route_id']) ? (int)$_POST['route_id'] : null;

        if (!empty($name)) {
            $stmt = $pdo->prepare("INSERT INTO customers (name, owner_name, phone, whatsapp, email, address, latitude, longitude, rep_id, route_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$name, $owner_name, $phone, $whatsapp, $email, $address, $latitude, $longitude, $rep_id, $route_id])) {
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Customer added successfully!</div>";
            } else {
                $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error adding customer.</div>";
            }
        }
    }
    
    // EDIT CUSTOMER
    if ($_POST['action'] == 'edit_customer') {
        $customer_id = (int)$_POST['customer_id'];
        $name = trim($_POST['name']);
        $owner_name = trim($_POST['owner_name']);
        $phone = trim($_POST['phone']);
        $whatsapp = trim($_POST['whatsapp']);
        $email = trim($_POST['email']);
        $address = trim($_POST['address']);
        $latitude = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
        $longitude = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;
        $route_id = !empty($_POST['route_id']) ? (int)$_POST['route_id'] : null;
        
        $sql = "UPDATE customers SET name = ?, owner_name = ?, phone = ?, whatsapp = ?, email = ?, address = ?, latitude = ?, longitude = ?, route_id = ?";
        $params = [$name, $owner_name, $phone, $whatsapp, $email, $address, $latitude, $longitude, $route_id];
        
        if (hasRole(['admin', 'supervisor']) && isset($_POST['rep_id'])) {
            $sql .= ", rep_id = ?";
            $params[] = !empty($_POST['rep_id']) ? (int)$_POST['rep_id'] : null;
        }
        $sql .= " WHERE id = ?";
        $params[] = $customer_id;
        
        // Security check for reps
        $canUpdate = true;
        if (hasRole('rep')) {
            $check = $pdo->prepare("SELECT rep_id FROM customers WHERE id = ?");
            $check->execute([$customer_id]);
            if ($check->fetchColumn() != $_SESSION['user_id']) $canUpdate = false;
        }

        if ($canUpdate && $customer_id && !empty($name)) {
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute($params)) {
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Customer updated successfully!</div>";
            }
        }
    }

    // DELETE CUSTOMER (Admins/Supervisors only)
    if ($_POST['action'] == 'delete_customer' && hasRole(['admin', 'supervisor'])) {
        $customer_id = (int)$_POST['customer_id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM customers WHERE id = ?");
            if ($stmt->execute([$customer_id])) {
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-trash3-fill me-2'></i> Customer deleted successfully!</div>";
            }
        } catch (PDOException $e) {
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Cannot delete customer. They have linked orders in the system.</div>";
        }
    }
}

// --- SEARCH & STRICT PAGINATION ---
$limit = 15;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$route_filter = isset($_GET['route_id']) ? $_GET['route_id'] : '';
$territory_filter = isset($_GET['territory_id']) ? $_GET['territory_id'] : '';
$balance_filter = isset($_GET['balance_status']) ? $_GET['balance_status'] : '';

$whereClause = "WHERE 1=1";
$params = [];

if (hasRole('rep')) {
    $whereClause .= " AND c.rep_id = ?";
    $params[] = $_SESSION['user_id'];
}
if ($search_query !== '') {
    $whereClause .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ?)";
    $params = array_merge($params, ["%$search_query%", "%$search_query%", "%$search_query%"]);
}
if ($route_filter !== '') {
    $whereClause .= " AND c.route_id = ?";
    $params[] = $route_filter;
}
if ($territory_filter !== '') {
    $whereClause .= " AND r.territory_id = ?";
    $params[] = $territory_filter;
}
if ($balance_filter !== '') {
    if ($balance_filter === 'outstanding') {
        $whereClause .= " AND (SELECT COALESCE(SUM(o.total_amount - o.paid_amount), 0) FROM orders o WHERE o.customer_id = c.id AND o.payment_status != 'paid' AND o.total_amount > o.paid_amount) > 0";
    } elseif ($balance_filter === 'cleared') {
        $whereClause .= " AND (SELECT COALESCE(SUM(o.total_amount - o.paid_amount), 0) FROM orders o WHERE o.customer_id = c.id AND o.payment_status != 'paid' AND o.total_amount > o.paid_amount) <= 0";
    }
}

// Get Total Rows
$totalStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM customers c 
    LEFT JOIN routes r ON c.route_id = r.id 
    $whereClause
");
$totalStmt->execute($params);
$totalRows = $totalStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Fetch Paginated Customers
$query = "
    SELECT c.*, u.name as rep_name, r.name as route_name, t.name as territory_name,
           (SELECT COALESCE(SUM(o.total_amount - o.paid_amount), 0) 
            FROM orders o 
            WHERE o.customer_id = c.id AND o.payment_status != 'paid' AND o.total_amount > o.paid_amount) as outstanding_balance
    FROM customers c 
    LEFT JOIN users u ON c.rep_id = u.id 
    LEFT JOIN routes r ON c.route_id = r.id
    LEFT JOIN territories t ON r.territory_id = t.id
    $whereClause 
    ORDER BY c.name ASC 
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$customers = $stmt->fetchAll();

// Fetch Reps, Routes, and Territories for dropdowns
$reps = [];
if (hasRole(['admin', 'supervisor'])) {
    $reps = $pdo->query("SELECT id, name FROM users WHERE role = 'rep' ORDER BY name ASC")->fetchAll();
}
$routes = $pdo->query("SELECT id, name FROM routes ORDER BY name ASC")->fetchAll();
$territories_list = $pdo->query("SELECT id, name FROM territories ORDER BY name ASC")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    /* --- Specific Page Styles (Candent Theme) --- */
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        padding: 24px 0 16px;
        border-bottom: 1px solid var(--ios-separator);
        margin-bottom: 24px;
    }
    .page-title {
        font-size: 1.8rem;
        font-weight: 700;
        letter-spacing: -0.8px;
        color: var(--ios-label);
        margin: 0;
    }
    .page-subtitle {
        font-size: 0.85rem;
        color: var(--ios-label-2);
        margin-top: 4px;
    }

    /* Contact Avatar Circle */
    .contact-avatar-circle {
        width: 44px; height: 44px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: 1.1rem;
        flex-shrink: 0; margin-right: 14px;
    }

    /* Modal Inputs explicit fix for guaranteed visibility */
    .modal-body .ios-input, .modal-body .form-select {
        background: #FFFFFF !important;
        border: 1px solid #C7C7CC !important;
        border-radius: 8px !important;
        padding: 10px 14px !important;
        font-size: 0.95rem !important;
        color: #000000 !important;
        width: 100%;
        outline: none;
        box-shadow: inset 0 1px 3px rgba(0,0,0,0.03) !important;
        transition: border 0.2s;
    }
    .modal-body .ios-input:focus, .modal-body .form-select:focus { 
        border-color: var(--accent) !important; 
        box-shadow: 0 0 0 3px rgba(48,200,138,0.2) !important;
    }
    .modal-body .ios-label-sm { 
        font-size: 0.75rem; 
        font-weight: 600; 
        color: var(--ios-label-2); 
        margin-bottom: 6px; 
        display: block; 
    }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Customers Directory</h1>
        <div class="page-subtitle">Manage your client database, contacts, and route assignments.</div>
    </div>
    <div>
        <button class="quick-btn quick-btn-primary" data-bs-toggle="modal" data-bs-target="#customerModal" onclick="openAddModal()">
            <i class="bi bi-plus-circle-fill"></i> Add Customer
        </button>
    </div>
</div>

<?php echo $message; ?>

<!-- Search & Filters -->
<div class="dash-card mb-4" style="background: var(--ios-surface-2);">
    <div class="p-3">
        <form method="GET" action="" id="searchForm" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="ios-label-sm">Search Customers</label>
                <div class="ios-search-wrapper">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" id="searchInput" class="ios-input" placeholder="Search name, phone, address..." value="<?php echo htmlspecialchars($search_query); ?>">
                </div>
            </div>
            <div class="col-md-3">
                <label class="ios-label-sm">Filter by Territory</label>
                <select name="territory_id" class="form-select">
                    <option value="">All Territories</option>
                    <?php foreach($territories_list as $t): ?>
                        <option value="<?php echo $t['id']; ?>" <?php echo $territory_filter == $t['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="ios-label-sm">Filter by Route</label>
                <select name="route_id" class="form-select">
                    <option value="">All Routes</option>
                    <?php foreach($routes as $r): ?>
                        <option value="<?php echo $r['id']; ?>" <?php echo $route_filter == $r['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($r['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="ios-label-sm">Filter by Balance</label>
                <select name="balance_status" class="form-select">
                    <option value="">All Balances</option>
                    <option value="outstanding" <?php echo $balance_filter == 'outstanding' ? 'selected' : ''; ?>>Outstanding Customers</option>
                    <option value="cleared" <?php echo $balance_filter == 'cleared' ? 'selected' : ''; ?>>Cleared Customers</option>
                </select>
            </div>
        </form>
    </div>
</div>

<!-- Customers Table -->
<div class="dash-card mb-4 overflow-hidden">
    <div class="table-responsive">
        <table class="ios-table">
            <thead>
                <tr class="table-ios-header">
                    <th style="width: <?php echo hasRole(['admin', 'supervisor']) ? '25%' : '30%'; ?>;">Business / Owner</th>
                    <th style="width: <?php echo hasRole(['admin', 'supervisor']) ? '20%' : '22%'; ?>;">Contact Info</th>
                    <th style="width: <?php echo hasRole(['admin', 'supervisor']) ? '20%' : '22%'; ?>;">Location / Route</th>
                    <th style="width: <?php echo hasRole(['admin', 'supervisor']) ? '15%' : '16%'; ?>;">Outstanding Balance</th>
                    <?php if(hasRole(['admin', 'supervisor'])): ?>
                        <th style="width: 12%;">Assigned Rep</th>
                    <?php endif; ?>
                    <th style="width: <?php echo hasRole(['admin', 'supervisor']) ? '8%' : '10%'; ?>; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $colors = ['#FF2D55', '#007AFF', '#34C759', '#FF9500', '#AF52DE', '#30B0C7'];
                foreach($customers as $c): 
                    // Generate initials & color
                    $words = explode(" ", $c['name']);
                    $initials = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
                    $color = $colors[$c['id'] % count($colors)];
                ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center">
                            <div class="contact-avatar-circle" style="background: <?php echo $color; ?>20; color: <?php echo $color; ?>;">
                                <?php echo $initials; ?>
                            </div>
                            <div>
                                <div style="font-weight: 700; font-size: 0.95rem; color: var(--ios-label);">
                                    <a href="view_customer.php?id=<?php echo $c['id']; ?>" class="text-primary text-decoration-none fw-bold" title="View Profile">
                                        <?php echo htmlspecialchars($c['name']); ?>
                                    </a>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--ios-label-2); margin-top: 2px;">
                                    <?php echo htmlspecialchars($c['owner_name'] ?: 'Business Account'); ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if($c['phone']): ?>
                            <div style="font-size: 0.85rem; font-weight: 500; color: var(--ios-label); margin-bottom: 2px;">
                                <i class="bi bi-telephone-fill text-muted me-1"></i> <?php echo htmlspecialchars($c['phone']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if($c['whatsapp']): ?>
                            <div style="font-size: 0.85rem; font-weight: 500; color: var(--ios-label); margin-bottom: 2px;">
                                <i class="bi bi-whatsapp text-success me-1"></i> <?php echo htmlspecialchars($c['whatsapp']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if($c['email']): ?>
                            <div style="font-size: 0.85rem; font-weight: 500; color: var(--ios-label);">
                                <i class="bi bi-envelope-fill text-primary me-1"></i> <?php echo htmlspecialchars($c['email']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if(!$c['phone'] && !$c['whatsapp'] && !$c['email']): ?>
                            <span class="text-muted small fst-italic">No contact info</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="ios-badge gray outline mb-1">
                            <i class="bi bi-signpost-split-fill me-1"></i> <?php echo htmlspecialchars($c['route_name'] ?: 'No Route'); ?>
                        </span>
                        <?php if($c['territory_name']): ?>
                            <span class="ios-badge blue outline mb-1" style="font-size: 0.68rem; padding: 2px 6px;">
                                <i class="bi bi-map-fill me-1"></i> <?php echo htmlspecialchars($c['territory_name']); ?>
                            </span>
                        <?php endif; ?>
                        <div style="font-size: 0.75rem; color: var(--ios-label-2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px;">
                            <?php echo htmlspecialchars($c['address'] ?: 'No address provided'); ?>
                        </div>
                    </td>
                    <td>
                        <?php 
                        $bal = (float)$c['outstanding_balance'];
                        if ($bal > 0): 
                        ?>
                            <div style="font-size: 0.95rem; font-weight: 700; color: #CC2200;">
                                Rs <?php echo number_format($bal, 2); ?>
                            </div>
                        <?php else: ?>
                            <span class="ios-badge green">
                                <i class="bi bi-check-circle-fill"></i> Cleared
                            </span>
                        <?php endif; ?>
                    </td>
                    
                    <?php if(hasRole(['admin', 'supervisor'])): ?>
                    <td>
                        <div style="font-size: 0.85rem; font-weight: 600; color: var(--ios-label);">
                            <i class="bi bi-person-badge me-1 text-muted"></i> <?php echo htmlspecialchars($c['rep_name'] ?: 'Unassigned'); ?>
                        </div>
                    </td>
                    <?php endif; ?>
                    
                    <td style="text-align: right;">
                        <div class="d-flex justify-content-end gap-1 flex-wrap">
                            <!-- View Profile Button -->
                            <button class="quick-btn quick-btn-ghost" style="padding: 6px 10px;" title="Sales Profile" onclick="openProfileModal(<?php echo $c['id']; ?>)">
                                <i class="bi bi-person-vcard-fill" style="color: #0055CC;"></i>
                            </button>
                            
                            <!-- Edit Button -->
                            <button class="quick-btn quick-btn-secondary" style="padding: 6px 10px;" title="Edit Customer" 
                                onclick='openEditModal(<?php echo json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                <i class="bi bi-pencil-square" style="color: #FF9500;"></i>
                            </button>

                            <!-- Delete Form -->
                            <?php if(hasRole(['admin', 'supervisor'])): ?>
                            <button class="quick-btn" style="padding: 6px 10px; background: rgba(255,59,48,0.1); color: #CC2200;" title="Delete Customer" onclick="confirmDelete(<?php echo $c['id']; ?>)">
                                <i class="bi bi-trash3-fill"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($customers)): ?>
                <tr>
                    <td colspan="5">
                        <div class="empty-state">
                            <i class="bi bi-people" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2" style="font-weight: 500;">No customers found matching your criteria.</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if($totalPages > 1): ?>
<ul class="ios-pagination mb-4">
    <?php for($i = 1; $i <= $totalPages; $i++): ?>
    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search_query); ?>&route_id=<?php echo $route_filter; ?>&territory_id=<?php echo $territory_filter; ?>&balance_status=<?php echo $balance_filter; ?>"><?php echo $i; ?></a>
    </li>
    <?php endfor; ?>
</ul>
<?php endif; ?>

<!-- ==================== ADD / EDIT CUSTOMER MODAL ==================== -->
<div class="modal fade" id="customerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST" action="" id="customerForm">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="customerModalTitle">New Customer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" id="modal_action" value="add_customer">
                    <input type="hidden" name="customer_id" id="modal_customer_id">
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Business Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="modal_name" class="ios-input fw-bold" required placeholder="e.g., City Supermarket">
                    </div>
                    <div class="mb-3">
                        <label class="ios-label-sm">Owner / Contact Name</label>
                        <input type="text" name="owner_name" id="modal_owner" class="ios-input" placeholder="e.g., John Doe">
                    </div>
                    
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="ios-label-sm">Phone Number</label>
                            <input type="tel" name="phone" id="modal_phone" class="ios-input" placeholder="07XXXXXXXX">
                        </div>
                        <div class="col-6">
                            <label class="ios-label-sm" style="color: #1A9A3A;">WhatsApp</label>
                            <input type="tel" name="whatsapp" id="modal_whatsapp" class="ios-input" placeholder="Include code">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="ios-label-sm">Email Address</label>
                        <input type="email" name="email" id="modal_email" class="ios-input" placeholder="customer@example.com">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="ios-label-sm">Assigned Route</label>
                            <select name="route_id" id="modal_route" class="form-select">
                                <option value="">-- No Route --</option>
                                <?php foreach($routes as $r): ?>
                                    <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if(hasRole(['admin', 'supervisor'])): ?>
                        <div class="col-6">
                            <label class="ios-label-sm">Sales Rep</label>
                            <select name="rep_id" id="modal_rep" class="form-select">
                                <option value="">-- Unassigned --</option>
                                <?php foreach($reps as $r): ?>
                                    <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-4">
                        <label class="ios-label-sm">Street Address</label>
                        <textarea name="address" id="modal_address" class="ios-input" rows="2"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="ios-label-sm">Location (Click map to pin)</label>
                        <div id="customerMap" style="height: 250px; border-radius: 8px; border: 1px solid #C7C7CC; z-index: 1;"></div>
                        <div class="row g-2 mt-2">
                            <div class="col-6">
                                <input type="text" name="latitude" id="modal_latitude" class="ios-input ios-label-sm" placeholder="Latitude" readonly>
                            </div>
                            <div class="col-6">
                                <input type="text" name="longitude" id="modal_longitude" class="ios-input ios-label-sm" placeholder="Longitude" readonly>
                            </div>
                        </div>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn quick-btn-primary px-4" id="modal_btn_save">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Invisible form for deleting -->
<form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="action" value="delete_customer">
    <input type="hidden" name="customer_id" id="delete_customer_id">
</form>

<!-- Customer Profile Iframe Modal (Triggered via "Profile" button) -->
<div class="modal fade" id="customerProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;border:none;box-shadow:0 20px 40px rgba(0,0,0,0.2);">
            <div class="modal-header" style="background:var(--ios-surface);border-bottom:1px solid var(--ios-separator);padding:14px 20px;">
                <h5 class="modal-title fw-bold" style="font-size:1rem;"><i class="bi bi-person-vcard text-primary me-2"></i>Sales Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" style="background:var(--ios-bg);">
                <iframe id="customerProfileIframe" src="" style="width:100%;height:80vh;border:none;"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
// Open Sales Profile inside the iframe modal
function openProfileModal(id) {
    document.getElementById('customerProfileIframe').src = 'view_customer.php?id=' + id;
    new bootstrap.Modal(document.getElementById('customerProfileModal')).show();
}

// Open Modals
function openAddModal() {
    document.getElementById('customerForm').reset();
    document.getElementById('modal_action').value = 'add_customer';
    document.getElementById('modal_customer_id').value = '';
    document.getElementById('customerModalTitle').innerHTML = '<i class="bi bi-person-plus-fill me-2 text-primary"></i>New Customer';
    document.getElementById('modal_btn_save').innerText = 'Save Customer';
}

function openEditModal(c) {
    document.getElementById('modal_action').value = 'edit_customer';
    document.getElementById('modal_customer_id').value = c.id;
    document.getElementById('modal_name').value = c.name;
    document.getElementById('modal_owner').value = c.owner_name;
    document.getElementById('modal_phone').value = c.phone;
    document.getElementById('modal_whatsapp').value = c.whatsapp;
    document.getElementById('modal_email').value = c.email;
    document.getElementById('modal_address').value = c.address;
    document.getElementById('modal_latitude').value = c.latitude || '';
    document.getElementById('modal_longitude').value = c.longitude || '';
    document.getElementById('modal_route').value = c.route_id || '';
    
    const repField = document.getElementById('modal_rep');
    if(repField) repField.value = c.rep_id || '';

    document.getElementById('customerModalTitle').innerHTML = '<i class="bi bi-pencil-square me-2 text-warning"></i>Edit Customer';
    document.getElementById('modal_btn_save').innerText = 'Update Details';
    
    new bootstrap.Modal(document.getElementById('customerModal')).show();
}

function confirmDelete(id) {
    if(confirm('Are you sure you want to permanently delete this contact?')) {
        document.getElementById('delete_customer_id').value = id;
        document.getElementById('deleteForm').submit();
    }
}

// AJAX Live Search & Debounce
let searchTimer;
const searchInput = document.getElementById('searchInput');

if (searchInput) {
    // Auto focus on page load if search is already active
    if (searchInput.value !== '') {
        searchInput.focus();
        const val = searchInput.value;
        searchInput.value = '';
        searchInput.value = val;
    }

    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            performLiveSearch();
        }, 300);
    });
}

const searchForm = document.getElementById('searchForm');
if (searchForm) {
    searchForm.addEventListener('submit', function(e) {
        e.preventDefault();
        performLiveSearch();
    });
    
    // Automatically perform AJAX search when any filter dropdown changes
    searchForm.querySelectorAll('select').forEach(select => {
        select.addEventListener('change', function() {
            performLiveSearch();
        });
    });
}

// Handle pagination via AJAX
document.addEventListener('click', function(e) {
    const pageLink = e.target.closest('.ios-pagination .page-link');
    if (pageLink) {
        e.preventDefault();
        const url = pageLink.getAttribute('href');
        if (url) {
            history.pushState(null, '', url);
            fetchResults(url);
        }
    }
});

// Handle browser back/forward buttons
window.addEventListener('popstate', function() {
    fetchResults(window.location.href);
});

function fetchResults(url) {
    const tableContainer = document.querySelector('.table-responsive');
    if (tableContainer) {
        tableContainer.style.opacity = '0.5';
        tableContainer.style.transition = 'opacity 0.15s ease';
    }
    
    fetch(url)
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            
            // Replace the table body
            const newTable = doc.querySelector('.table-responsive');
            const currentTable = document.querySelector('.table-responsive');
            if (newTable && currentTable) {
                currentTable.innerHTML = newTable.innerHTML;
                currentTable.style.opacity = '1';
            }
            
            // Replace the pagination bar
            const newPagination = doc.querySelector('.ios-pagination');
            const currentPagination = document.querySelector('.ios-pagination');
            if (currentPagination) {
                if (newPagination) {
                    currentPagination.outerHTML = newPagination.outerHTML;
                } else {
                    currentPagination.remove();
                }
            } else if (newPagination) {
                const tableCard = document.querySelector('.dash-card.mb-4.overflow-hidden');
                if (tableCard) {
                    tableCard.insertAdjacentHTML('afterend', newPagination.outerHTML);
                }
            }
        })
        .catch(err => {
            console.error('Error fetching search results:', err);
            if (tableContainer) tableContainer.style.opacity = '1';
        });
}

function performLiveSearch() {
    const form = document.getElementById('searchForm');
    const formData = new FormData(form);
    const searchParams = new URLSearchParams(formData);
    const url = 'customers.php?' + searchParams.toString();
    
    history.pushState(null, '', url);
    fetchResults(url);
}
</script>

<!-- Leaflet CSS & JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
    let map, marker;
    function initMap() {
        if (!map) {
            map = L.map('customerMap').setView([7.8731, 80.7718], 7);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);

            map.on('click', function(e) {
                setMarker(e.latlng.lat, e.latlng.lng);
            });
        }
    }

    function setMarker(lat, lng) {
        if (marker) {
            marker.setLatLng([lat, lng]);
        } else {
            marker = L.marker([lat, lng]).addTo(map);
        }
        document.getElementById('modal_latitude').value = lat;
        document.getElementById('modal_longitude').value = lng;
    }

    // Initialize map when modal is fully shown
    document.getElementById('customerModal').addEventListener('shown.bs.modal', function () {
        initMap();
        setTimeout(() => {
            map.invalidateSize();
            const lat = parseFloat(document.getElementById('modal_latitude').value);
            const lng = parseFloat(document.getElementById('modal_longitude').value);
            if (!isNaN(lat) && !isNaN(lng)) {
                setMarker(lat, lng);
                map.setView([lat, lng], 13);
            } else {
                if (marker) {
                    map.removeLayer(marker);
                    marker = null;
                }
                map.setView([7.8731, 80.7718], 7);
            }
        }, 100);
    });
</script>

<?php include '../includes/footer.php'; ?>