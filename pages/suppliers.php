<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

// --- AJAX ENDPOINTS (No page reload) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    // 1. Toggle Status
    if ($_POST['ajax_action'] == 'toggle_status') {
        $supplier_id = (int)$_POST['supplier_id'];
        $new_status = $_POST['new_status'];
        try {
            $stmt = $pdo->prepare("UPDATE suppliers SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $supplier_id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

$message = '';

// --- AUTO DB MIGRATION FOR ENHANCED FEATURES ---
try {
    $pdo->exec("ALTER TABLE suppliers ADD COLUMN email VARCHAR(150) NULL");
    $pdo->exec("ALTER TABLE suppliers ADD COLUMN tax_id VARCHAR(50) NULL");
    $pdo->exec("ALTER TABLE suppliers ADD COLUMN website VARCHAR(255) NULL");
    $pdo->exec("ALTER TABLE suppliers ADD COLUMN status ENUM('active', 'inactive') DEFAULT 'active'");
} catch(PDOException $e) {}
// ------------------------------------------

// Handle POST Actions (Add/Edit/Delete)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // ADD SUPPLIER
    if ($_POST['action'] == 'add_supplier') {
        $company_name = trim($_POST['company_name']);
        $name = trim($_POST['name']); // Contact person
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        $tax_id = trim($_POST['tax_id']);
        $website = trim($_POST['website']);
        $status = $_POST['status'] ?? 'active';
        
        if (!empty($company_name) && !empty($name)) {
            $stmt = $pdo->prepare("INSERT INTO suppliers (company_name, name, email, phone, address, tax_id, website, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$company_name, $name, $email, $phone, $address, $tax_id, $website, $status])) {
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Supplier added successfully!</div>";
            } else {
                $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error adding supplier. Please try again.</div>";
            }
        } else {
            $message = "<div class='ios-alert' style='background: rgba(255,149,0,0.1); color: #C07000;'><i class='bi bi-info-circle-fill me-2'></i> Company Name and Contact Person are required.</div>";
        }
    }
    
    // EDIT SUPPLIER
    if ($_POST['action'] == 'edit_supplier') {
        $supplier_id = (int)$_POST['supplier_id'];
        $company_name = trim($_POST['company_name']);
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        $tax_id = trim($_POST['tax_id']);
        $website = trim($_POST['website']);
        $status = $_POST['status'] ?? 'active';
        
        if ($supplier_id && !empty($company_name) && !empty($name)) {
            $stmt = $pdo->prepare("UPDATE suppliers SET company_name = ?, name = ?, email = ?, phone = ?, address = ?, tax_id = ?, website = ?, status = ? WHERE id = ?");
            if ($stmt->execute([$company_name, $name, $email, $phone, $address, $tax_id, $website, $status, $supplier_id])) {
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Supplier updated successfully!</div>";
            } else {
                $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error updating supplier.</div>";
            }
        }
    }

    // DELETE SUPPLIER
    if ($_POST['action'] == 'delete_supplier') {
        $supplier_id = (int)$_POST['supplier_id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ?");
            if ($stmt->execute([$supplier_id])) {
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-trash3-fill me-2'></i> Supplier deleted successfully!</div>";
            }
        } catch (PDOException $e) {
            // Usually fails if linked to products due to foreign key constraints (if RESTRICT is set)
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Cannot delete this supplier. They are linked to existing products or orders.</div>";
        }
    }
}

// --- SEARCH & PAGINATION ---
$limit = 15;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

$whereClause = "WHERE 1=1";
$params = [];

if ($search_query !== '') {
    $whereClause .= " AND (company_name LIKE ? OR name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $params = array_fill(0, 4, "%$search_query%");
}

// Get Total Rows
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM suppliers $whereClause");
$totalStmt->execute($params);
$totalRows = $totalStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Fetch Suppliers
$query = "SELECT * FROM suppliers $whereClause ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$suppliers = $stmt->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    /* Specific Page Styles */
    .contact-avatar-circle {
        width: 44px; height: 44px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: 1.1rem;
        flex-shrink: 0; margin-right: 14px;
    }
    
    .info-card {
        background: var(--ios-surface);
        border-radius: 12px;
        overflow: hidden;
        border: 1px solid var(--ios-separator);
        margin-bottom: 16px;
    }
    .info-row {
        padding: 10px 14px;
        border-bottom: 1px solid var(--ios-separator);
        display: flex;
        flex-direction: column;
    }
    .info-row:last-child { border-bottom: none; }
    .info-label {
        font-weight: 600;
        font-size: 0.7rem;
        color: var(--ios-label-2);
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }
    .info-value {
        font-weight: 600;
        font-size: 0.95rem;
        color: var(--ios-label);
        margin-top: 2px;
    }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Suppliers Management</h1>
        <div class="page-subtitle">Manage vendors, manufacturers, and supply chain contacts.</div>
    </div>
    <div>
        <button class="quick-btn quick-btn-primary" onclick="openAddModal()">
            <i class="bi bi-plus-lg"></i> Add New Supplier
        </button>
    </div>
</div>

<?php echo $message; ?>

<!-- Filter & Live Search Bar -->
<div class="dash-card mb-4" style="background: var(--ios-surface-2);">
    <div class="p-3">
        <form method="GET" action="" id="searchForm" class="row g-2 align-items-end">
            <div class="col-md-9">
                <label class="ios-label-sm">Search Directory</label>
                <div class="ios-search-wrapper">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" id="searchInput" class="ios-input" placeholder="Search by Company, Contact Name, Email, or Phone..." value="<?php echo htmlspecialchars($search_query); ?>" oninput="debounceSearch()">
                </div>
            </div>
            <div class="col-md-3">
                <?php if($search_query): ?>
                    <a href="suppliers.php" class="quick-btn" style="background: rgba(255,59,48,0.1); color: #CC2200; width: 100%; min-height: 42px;">Clear Search</a>
                <?php else: ?>
                    <button type="submit" class="quick-btn quick-btn-secondary w-100" style="min-height: 42px;"><i class="bi bi-search me-1"></i> Search</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Suppliers Table -->
<div class="dash-card mb-4 overflow-hidden">
    <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
        <span class="card-title">
            <span class="card-title-icon" style="background: rgba(0,122,255,0.1); color: #0055CC;">
                <i class="bi bi-buildings-fill"></i>
            </span>
            Vendor Directory
        </span>
    </div>
    <div class="table-responsive">
        <table class="ios-table">
            <thead>
                <tr class="table-ios-header">
                    <th style="width: 35%;" class="ps-4">Company</th>
                    <th style="width: 20%;">Contact Person</th>
                    <th style="width: 20%;">Contact Details</th>
                    <th style="width: 10%; text-align: center;">Status</th>
                    <th style="width: 15%; text-align: right;" class="pe-4">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $colors = ['#FF2D55', '#007AFF', '#34C759', '#FF9500', '#AF52DE', '#30B0C7'];
                foreach($suppliers as $s): 
                    // Generate initials & color
                    $words = explode(" ", $s['company_name']);
                    $initials = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
                    $color = $colors[$s['id'] % count($colors)];
                ?>
                <tr class="<?php echo $s['status'] == 'inactive' ? 'opacity-50' : ''; ?>">
                    <td class="ps-4">
                        <div class="d-flex align-items-center">
                            <div class="contact-avatar-circle" style="background: <?php echo $color; ?>20; color: <?php echo $color; ?>;">
                                <?php echo $initials; ?>
                            </div>
                            <div>
                                <div style="font-weight: 700; font-size: 1rem; color: var(--ios-label);">
                                    <?php echo htmlspecialchars($s['company_name']); ?>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--ios-label-3); margin-top: 2px;">
                                    <?php if($s['tax_id']): ?><span class="me-2"><i class="bi bi-file-text me-1"></i>Tax ID: <?php echo htmlspecialchars($s['tax_id']); ?></span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div style="font-weight: 600; font-size: 0.95rem; color: var(--ios-label);">
                            <i class="bi bi-person-fill text-muted me-1"></i> <?php echo htmlspecialchars($s['name']); ?>
                        </div>
                    </td>
                    <td>
                        <?php if($s['phone']): ?>
                            <div style="font-size: 0.85rem; font-weight: 500; color: var(--ios-label); margin-bottom: 2px;">
                                <i class="bi bi-telephone-fill text-muted me-1"></i> <?php echo htmlspecialchars($s['phone']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if($s['email']): ?>
                            <div style="font-size: 0.85rem; font-weight: 500; color: var(--ios-label);">
                                <i class="bi bi-envelope-fill text-primary me-1"></i> <?php echo htmlspecialchars($s['email']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if(!$s['phone'] && !$s['email']): ?>
                            <span class="text-muted small fst-italic">No contact info</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center; vertical-align: middle;">
                        <!-- AJAX Toggle Switch -->
                        <div class="form-check form-switch d-inline-block m-0">
                            <input class="form-check-input status-toggle" type="checkbox" role="switch" 
                                   data-id="<?php echo $s['id']; ?>" 
                                   <?php echo $s['status'] == 'active' ? 'checked' : ''; ?>
                                   title="Toggle Status">
                        </div>
                    </td>
                    <td style="text-align: right;" class="pe-4">
                        <div class="d-flex justify-content-end gap-1 flex-wrap">
                            <!-- Supplier Profile Button -->
                            <button class="quick-btn quick-btn-ghost" style="padding: 6px 10px;" title="Supplier Profile" onclick="openProfileModal(<?php echo $s['id']; ?>)">
                                <i class="bi bi-person-vcard-fill" style="color: #CC2200;"></i>
                            </button>

                            <!-- View Button -->
                            <button class="quick-btn quick-btn-ghost" style="padding: 6px 10px;" title="View Details" onclick='openViewModal(<?php echo htmlspecialchars(json_encode([
                                "company_name" => $s['company_name'],
                                "name" => $s['name'],
                                "email" => $s['email'],
                                "phone" => $s['phone'],
                                "address" => $s['address'],
                                "tax_id" => $s['tax_id'],
                                "website" => $s['website'],
                                "status" => $s['status'],
                                "initials" => $initials,
                                "color" => $color
                            ]), ENT_QUOTES, 'UTF-8'); ?>)'>
                                <i class="bi bi-eye-fill" style="color: #0055CC;"></i>
                            </button>
                            
                            <!-- Edit Button -->
                            <button class="quick-btn quick-btn-secondary" style="padding: 6px 10px;" title="Edit Supplier" onclick='openEditModal(<?php echo htmlspecialchars(json_encode([
                                "id" => $s['id'],
                                "company_name" => $s['company_name'],
                                "name" => $s['name'],
                                "email" => $s['email'],
                                "phone" => $s['phone'],
                                "address" => $s['address'],
                                "tax_id" => $s['tax_id'],
                                "website" => $s['website'],
                                "status" => $s['status']
                            ]), ENT_QUOTES, 'UTF-8'); ?>)'>
                                <i class="bi bi-pencil-square" style="color: #FF9500;"></i>
                            </button>

                            <!-- Delete Form -->
                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this supplier?');">
                                <input type="hidden" name="action" value="delete_supplier">
                                <input type="hidden" name="supplier_id" value="<?php echo $s['id']; ?>">
                                <button type="submit" class="quick-btn" style="padding: 6px 10px; background: rgba(255,59,48,0.1); color: #CC2200;" title="Delete">
                                    <i class="bi bi-trash3-fill"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($suppliers)): ?>
                <tr>
                    <td colspan="5">
                        <div class="empty-state">
                            <i class="bi bi-buildings" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2" style="font-weight: 500;">No suppliers found.</p>
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
        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search_query); ?>"><?php echo $i; ?></a>
    </li>
    <?php endfor; ?>
</ul>
<?php endif; ?>

<!-- ==================== MODALS ==================== -->

<!-- View Supplier Modal (Horizontal Redesign) -->
<div class="modal fade" id="viewSupplierModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <div class="modal-header" style="background: var(--ios-surface); border-bottom: 1px solid var(--ios-separator);">
                <h5 class="modal-title fw-bold" style="font-size: 1.1rem;"><i class="bi bi-buildings-fill text-primary me-2"></i>Supplier Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body" style="padding: 24px;">
                <div class="row g-4">
                    <!-- Left Column: Poster -->
                    <div class="col-md-5">
                        <div class="d-flex flex-column h-100 justify-content-center align-items-center text-center" style="background: var(--ios-surface); border: 1px solid var(--ios-separator); border-radius: 14px; padding: 30px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                            <div id="view_avatar" style="width: 90px; height: 90px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 2.2rem; margin-bottom: 16px;"></div>
                            <h3 id="view_company_name" style="font-weight: 800; font-size: 1.5rem; letter-spacing: -0.5px; margin-bottom: 8px; color: var(--ios-label);">Company</h3>
                            <div id="view_status_badge" class="mb-2"></div>
                        </div>
                    </div>

                    <!-- Right Column: Details -->
                    <div class="col-md-7">
                        <div class="info-card mb-3">
                            <div class="info-row">
                                <span class="info-label"><i class="bi bi-person-fill me-1"></i> Contact Person</span>
                                <span class="info-value text-dark" id="view_name"></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label"><i class="bi bi-envelope-fill me-1"></i> Email Address</span>
                                <span class="info-value" id="view_email"></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label"><i class="bi bi-telephone-fill me-1"></i> Phone Number</span>
                                <span class="info-value" id="view_phone"></span>
                            </div>
                        </div>

                        <div class="info-card mb-0">
                            <div class="info-row">
                                <span class="info-label"><i class="bi bi-geo-alt-fill me-1"></i> Physical Address</span>
                                <span class="info-value text-dark" id="view_address" style="line-height: 1.4; white-space: pre-wrap;"></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label"><i class="bi bi-file-text-fill me-1"></i> Tax / VAT ID</span>
                                <span class="info-value text-dark" id="view_tax_id" style="font-family: monospace; font-size: 1.1rem;"></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label"><i class="bi bi-globe me-1"></i> Website</span>
                                <span class="info-value" id="view_website"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer" style="background: var(--ios-surface); border-top: 1px solid var(--ios-separator);">
                <button type="button" class="quick-btn quick-btn-secondary px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Supplier Modal -->
<div class="modal fade" id="addSupplierModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST" action="">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem;"><i class="bi bi-plus-circle-fill text-primary me-2"></i>Add New Supplier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="add_supplier">
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Company Name <span class="text-danger">*</span></label>
                            <input type="text" name="company_name" class="ios-input fw-bold" required>
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Contact Person <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="ios-input" required>
                        </div>
                    </div>
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Email Address</label>
                            <input type="email" name="email" class="ios-input">
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Phone Number</label>
                            <input type="tel" name="phone" class="ios-input">
                        </div>
                    </div>

                    <div class="row g-3 mb-3 pb-3 border-bottom border-secondary border-opacity-10">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Tax ID / VAT Number</label>
                            <input type="text" name="tax_id" class="ios-input" style="font-family: monospace;">
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Company Website</label>
                            <input type="url" name="website" class="ios-input" placeholder="https://...">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="ios-label-sm">Physical Address</label>
                        <textarea name="address" class="ios-input" rows="2"></textarea>
                    </div>

                    <div class="mb-4">
                        <label class="ios-label-sm">Account Status</label>
                        <select name="status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn quick-btn-primary px-4">Save Supplier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Supplier Modal -->
<div class="modal fade" id="editSupplierModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST" action="">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem; color: #C07000;"><i class="bi bi-pencil-square me-2"></i>Edit Supplier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="edit_supplier">
                    <input type="hidden" name="supplier_id" id="edit_supplier_id">
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Company Name <span class="text-danger">*</span></label>
                            <input type="text" name="company_name" id="edit_company_name" class="ios-input fw-bold" required>
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Contact Person <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="edit_name" class="ios-input" required>
                        </div>
                    </div>
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Email Address</label>
                            <input type="email" name="email" id="edit_email" class="ios-input">
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Phone Number</label>
                            <input type="tel" name="phone" id="edit_phone" class="ios-input">
                        </div>
                    </div>

                    <div class="row g-3 mb-3 pb-3 border-bottom border-secondary border-opacity-10">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Tax ID / VAT Number</label>
                            <input type="text" name="tax_id" id="edit_tax_id" class="ios-input" style="font-family: monospace;">
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Company Website</label>
                            <input type="url" name="website" id="edit_website" class="ios-input" placeholder="https://...">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="ios-label-sm">Physical Address</label>
                        <textarea name="address" id="edit_address" class="ios-input" rows="2"></textarea>
                    </div>

                    <div class="mb-4">
                        <label class="ios-label-sm">Account Status</label>
                        <select name="status" id="edit_status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn px-4" style="background: #FF9500; color: #fff;">Update Supplier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // --- 1. Live Search Debounce & Focus Restoration ---
    const searchInput = document.getElementById('searchInput');
    const searchForm = document.getElementById('searchForm');

    if (searchInput && searchInput.value !== '') {
        searchInput.focus();
        const val = searchInput.value;
        searchInput.value = '';
        searchInput.value = val;
    }

    let searchTimer;
    function triggerSearch() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => { searchForm.submit(); }, 700); 
    }
    if (searchInput) searchInput.addEventListener('input', triggerSearch);

    // --- 2. AJAX Status Toggle Slider ---
    document.querySelectorAll('.status-toggle').forEach(toggle => {
        toggle.addEventListener('change', function() {
            const supplierId = this.dataset.id;
            const newStatus = this.checked ? 'active' : 'inactive';
            
            fetch('suppliers.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `ajax_action=toggle_status&supplier_id=${supplierId}&new_status=${newStatus}`
            })
            .then(response => response.json())
            .then(data => {
                if(!data.success) {
                    alert('Failed to update status: ' + data.error);
                    this.checked = !this.checked; 
                }
            });
        });
    });
});

// --- Modals Triggers ---
function openAddModal() {
    new bootstrap.Modal(document.getElementById('addSupplierModal')).show();
}

function openEditModal(data) {
    document.getElementById('edit_supplier_id').value = data.id;
    document.getElementById('edit_company_name').value = data.company_name;
    document.getElementById('edit_name').value = data.name;
    document.getElementById('edit_email').value = data.email;
    document.getElementById('edit_phone').value = data.phone;
    document.getElementById('edit_address').value = data.address;
    document.getElementById('edit_tax_id').value = data.tax_id;
    document.getElementById('edit_website').value = data.website;
    document.getElementById('edit_status').value = data.status;
    
    new bootstrap.Modal(document.getElementById('editSupplierModal')).show();
}

function openViewModal(data) {
    document.getElementById('view_company_name').textContent = data.company_name;
    document.getElementById('view_name').textContent = data.name;
    
    // Set Avatar & Gradient
    const avatar = document.getElementById('view_avatar');
    avatar.textContent = data.initials;
    avatar.style.backgroundColor = data.color + '20'; // 20% opacity
    avatar.style.color = data.color;

    document.getElementById('view_email').innerHTML = data.email ? `<a href="mailto:${data.email}" class="text-decoration-none fw-bold" style="color: #0055CC;">${data.email}</a>` : '<span class="text-muted fw-normal">N/A</span>';
    document.getElementById('view_phone').innerHTML = data.phone ? `<a href="tel:${data.phone}" class="text-decoration-none fw-bold" style="color: #1A9A3A;">${data.phone}</a>` : '<span class="text-muted fw-normal">N/A</span>';
    document.getElementById('view_address').textContent = data.address || 'N/A';
    document.getElementById('view_tax_id').textContent = data.tax_id || 'N/A';
    
    document.getElementById('view_website').innerHTML = data.website ? `<a href="${data.website}" target="_blank" class="text-decoration-none fw-bold" style="color: #0055CC;">Visit Website <i class="bi bi-box-arrow-up-right ms-1"></i></a>` : '<span class="text-muted fw-normal">N/A</span>';
    
    document.getElementById('view_status_badge').innerHTML = data.status === 'active' 
        ? '<span class="ios-badge green"><i class="bi bi-check-circle-fill me-1"></i> Active Account</span>' 
        : '<span class="ios-badge red"><i class="bi bi-x-circle-fill me-1"></i> Inactive Account</span>';

    new bootstrap.Modal(document.getElementById('viewSupplierModal')).show();
}
</script>

<!-- Supplier Profile Iframe Modal (Triggered via "Profile" button) -->
<div class="modal fade" id="supplierProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;border:none;box-shadow:0 20px 40px rgba(0,0,0,0.2);">
            <div class="modal-header" style="background:var(--ios-surface);border-bottom:1px solid var(--ios-separator);padding:14px 20px;">
                <h5 class="modal-title fw-bold" style="font-size:1rem;"><i class="bi bi-person-vcard text-danger me-2"></i>Supplier Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" style="background:var(--ios-bg);">
                <iframe id="supplierProfileIframe" src="" style="width:100%;height:80vh;border:none;"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
// Open Supplier Profile inside the iframe modal
function openProfileModal(id) {
    document.getElementById('supplierProfileIframe').src = 'view_supplier.php?id=' + id + '&modal=true';
    new bootstrap.Modal(document.getElementById('supplierProfileModal')).show();
}
// Reload page on modal close to refresh payables and outstanding amounts
document.getElementById('supplierProfileModal').addEventListener('hidden.bs.modal', function () {
    location.reload();
});
</script>

<?php include '../includes/footer.php'; ?>