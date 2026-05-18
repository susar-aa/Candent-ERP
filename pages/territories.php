<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); // Restricted to management

$message = '';

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // 1. Add Territory
    if ($_POST['action'] == 'add_territory') {
        $name = trim($_POST['name']);
        $desc = trim($_POST['description']);
        
        if (!empty($name)) {
            $stmt = $pdo->prepare("INSERT INTO territories (name, description, status) VALUES (?, ?, 'active')");
            if($stmt->execute([$name, $desc])) {
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-check-circle-fill me-2'></i> Territory '{$name}' created successfully!</div>";
            }
        }
    }
    
    // 2. Edit Territory
    if ($_POST['action'] == 'edit_territory') {
        $id = (int)$_POST['territory_id'];
        $name = trim($_POST['name']);
        $desc = trim($_POST['description']);
        
        if (!empty($name) && $id > 0) {
            $stmt = $pdo->prepare("UPDATE territories SET name = ?, description = ? WHERE id = ?");
            if($stmt->execute([$name, $desc, $id])) {
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-check-circle-fill me-2'></i> Territory updated successfully!</div>";
            }
        }
    }
    
    // 3. Toggle Status
    if ($_POST['action'] == 'toggle_status') {
        $id = (int)$_POST['territory_id'];
        $new_status = $_POST['current_status'] == 'active' ? 'inactive' : 'active';
        
        $stmt = $pdo->prepare("UPDATE territories SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $id]);
        $message = "<div class='ios-alert' style='background: rgba(0,122,255,0.1); color: #0055CC; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-arrow-repeat me-2'></i> Territory status updated to ".ucfirst($new_status).".</div>";
    }

    // 4. Delete Territory
    if ($_POST['action'] == 'delete_territory') {
        $id = (int)$_POST['territory_id'];
        
        // Safety: Unlink routes before deleting to prevent constraint errors
        $pdo->prepare("UPDATE routes SET territory_id = NULL WHERE territory_id = ?")->execute([$id]);
        
        $stmt = $pdo->prepare("DELETE FROM territories WHERE id = ?");
        if($stmt->execute([$id])) {
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-trash3-fill me-2'></i> Territory deleted permanently.</div>";
        }
    }
}

// --- FILTERING & PAGINATION (Added for Scalability) ---
$limit = 15;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

$whereClause = "WHERE 1=1";
$params = [];

if ($search_query !== '') {
    $whereClause .= " AND (t.name LIKE ? OR t.description LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

// Global Metrics for Dashboard Card
$metrics = $pdo->query("SELECT COUNT(*) as total_territories, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_territories FROM territories")->fetch();

// Get Total Rows for Pagination
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM territories t $whereClause");
$totalStmt->execute($params);
$totalRows = $totalStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Fetch Territories and count assigned routes
$query = "
    SELECT t.*, 
           (SELECT COUNT(id) FROM routes WHERE territory_id = t.id) as route_count 
    FROM territories t 
    $whereClause
    ORDER BY t.status ASC, t.name ASC
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$territories = $stmt->fetchAll();

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

    /* Metrics Card */
    .metrics-card {
        border-radius: 16px;
        padding: 20px 24px;
        color: #fff;
        box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        display: flex;
        align-items: center;
        gap: 20px;
        height: 100%;
    }
    .metrics-icon {
        width: 54px; height: 54px;
        border-radius: 14px;
        background: rgba(255,255,255,0.2);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.8rem;
        flex-shrink: 0;
    }

    /* iOS Inputs & Labels */
    .ios-input {
        background: var(--ios-surface);
        border: 1px solid var(--ios-separator);
        border-radius: 10px;
        padding: 10px 14px;
        font-size: 0.9rem;
        color: var(--ios-label);
        transition: all 0.2s ease;
        box-shadow: none;
        width: 100%;
        min-height: 42px;
    }
    .ios-input:focus {
        background: #fff;
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(48,200,138,0.15) !important;
        outline: none;
    }
    .ios-label-sm {
        display: block;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--ios-label-2);
        margin-bottom: 6px;
        padding-left: 4px;
    }

    /* Search Bar */
    .ios-search-wrapper { position: relative; }
    .ios-search-wrapper .bi-search {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--ios-label-3);
    }
    .ios-search-wrapper .ios-input { padding-left: 38px; }

    /* Custom Tables */
    .table-ios-header th {
        background: var(--ios-surface-2) !important;
        color: var(--ios-label-2) !important;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 700;
        border-bottom: 1px solid var(--ios-separator);
        padding: 14px 20px;
    }
    .ios-table { width: 100%; border-collapse: collapse; }
    .ios-table td {
        vertical-align: middle;
        padding: 14px 20px;
        border-bottom: 1px solid var(--ios-separator);
    }
    .ios-table tr:last-child td { border-bottom: none; }
    .ios-table tr:hover td { background: var(--ios-bg); }

    /* Badges */
    .ios-badge {
        font-size: 0.7rem;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 50px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        white-space: nowrap;
        letter-spacing: 0.02em;
    }
    .ios-badge.green   { background: rgba(52,199,89,0.12); color: #1A9A3A; }
    .ios-badge.blue    { background: rgba(0,122,255,0.12); color: #0055CC; }
    .ios-badge.gray    { background: rgba(60,60,67,0.1); color: var(--ios-label-2); }

    /* Modals */
    .modal-content { border-radius: 20px; border: none; box-shadow: 0 20px 40px rgba(0,0,0,0.2); overflow: hidden; }
    .modal-header { background: var(--ios-surface); border-bottom: 1px solid var(--ios-separator); padding: 18px 24px; }
    .modal-footer { border-top: 1px solid var(--ios-separator); padding: 16px 24px; background: var(--ios-surface); }
    
    /* Pagination */
    .ios-pagination { display: flex; gap: 4px; list-style: none; padding: 0; justify-content: center; margin-top: 20px; }
    .ios-pagination .page-link {
        border: none;
        color: var(--ios-label);
        background: var(--ios-surface);
        border-radius: 8px;
        width: 36px; height: 36px;
        display: flex; align-items: center; justify-content: center;
        font-weight: 600; font-size: 0.9rem;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    .ios-pagination .page-item.active .page-link {
        background: var(--accent); color: #fff; box-shadow: 0 4px 10px rgba(48,200,138,0.3);
    }
    .ios-pagination .page-link:hover:not(.active) { background: var(--ios-surface-2); }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Sales Territories</h1>
        <div class="page-subtitle">Manage main areas and regional groupings for dispatch routes.</div>
    </div>
    <div class="d-flex gap-2">
        <button class="quick-btn quick-btn-primary" data-bs-toggle="modal" data-bs-target="#addTerritoryModal">
            <i class="bi bi-plus-lg"></i> Add Territory
        </button>
    </div>
</div>

<?php echo $message; ?>

<!-- Summary & Filter Row -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF9500, #E07800);">
            <div class="metrics-icon"><i class="bi bi-globe-americas"></i></div>
            <div>
                <div style="font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.9;">Total Territories</div>
                <div class="d-flex align-items-baseline gap-2">
                    <span style="font-size: 1.8rem; font-weight: 800; letter-spacing: -0.5px;"><?php echo $metrics['total_territories']; ?></span>
                    <span style="font-size: 0.85rem; font-weight: 600; opacity: 0.8;">(<?php echo $metrics['active_territories']; ?> Active)</span>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-8">
        <div class="dash-card h-100 p-4 d-flex flex-column justify-content-center" style="background: var(--ios-surface-2);">
            <form method="GET" action="" id="filterForm">
                <label class="ios-label-sm">Search Territories</label>
                <div class="d-flex gap-2">
                    <div class="ios-search-wrapper flex-grow-1">
                        <i class="bi bi-search"></i>
                        <input type="text" name="search" id="searchInput" class="ios-input" placeholder="Search by Territory Name or Description..." value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                    <button type="submit" class="quick-btn quick-btn-secondary" style="min-height: 42px;">
                        <i class="bi bi-funnel-fill"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Territories Table -->
<div class="dash-card mb-4 overflow-hidden">
    <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
        <span class="card-title">
            <span class="card-title-icon" style="background: rgba(255,149,0,0.1); color: #FF9500;">
                <i class="bi bi-map-fill"></i>
            </span>
            Configured Territories Directory
        </span>
    </div>
    <div class="table-responsive">
        <table class="ios-table">
            <thead>
                <tr class="table-ios-header">
                    <th style="width: 25%;">Territory Info</th>
                    <th style="width: 35%;">Description</th>
                    <th style="width: 15%; text-align: center;">Assigned Routes</th>
                    <th style="width: 10%; text-align: center;">Status</th>
                    <th style="width: 15%; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($territories as $t): ?>
                <tr class="<?php echo $t['status'] == 'inactive' ? 'opacity-50' : ''; ?>" style="<?php echo $t['status'] == 'inactive' ? 'background: var(--ios-bg);' : ''; ?>">
                    <td>
                        <div style="font-weight: 700; font-size: 0.95rem; color: var(--ios-label);">
                            <?php echo htmlspecialchars($t['name']); ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--ios-label-3); margin-top: 2px;">
                            ID: TR-<?php echo str_pad($t['id'], 4, '0', STR_PAD_LEFT); ?>
                        </div>
                    </td>
                    <td>
                        <div style="font-size: 0.85rem; color: var(--ios-label-2); line-height: 1.4;">
                            <?php echo htmlspecialchars($t['description'] ?: 'No description provided.'); ?>
                        </div>
                    </td>
                    <td style="text-align: center;">
                        <span class="ios-badge <?php echo $t['route_count'] > 0 ? 'blue' : 'gray'; ?>">
                            <i class="bi bi-sign-turn-right-fill"></i> <?php echo $t['route_count']; ?> Routes
                        </span>
                    </td>
                    <td style="text-align: center;">
                        <form method="POST">
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="territory_id" value="<?php echo $t['id']; ?>">
                            <input type="hidden" name="current_status" value="<?php echo $t['status']; ?>">
                            <button type="submit" class="ios-badge <?php echo $t['status'] == 'active' ? 'green' : 'gray'; ?>" style="border: none; cursor: pointer;">
                                <?php echo ucfirst($t['status']); ?>
                            </button>
                        </form>
                    </td>
                    <td style="text-align: right;">
                        <div class="d-flex justify-content-end gap-1 flex-wrap">
                            <button class="quick-btn quick-btn-ghost" style="padding: 6px 10px;" title="Edit Territory" 
                                    data-bs-toggle="modal" data-bs-target="#editTerritoryModal"
                                    data-id="<?php echo $t['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($t['name'], ENT_QUOTES); ?>"
                                    data-desc="<?php echo htmlspecialchars($t['description'], ENT_QUOTES); ?>">
                                <i class="bi bi-pencil-square" style="color: #FF9500;"></i>
                            </button>
                            
                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this territory? Any assigned routes will be unlinked automatically.');">
                                <input type="hidden" name="action" value="delete_territory">
                                <input type="hidden" name="territory_id" value="<?php echo $t['id']; ?>">
                                <button type="submit" class="quick-btn" style="padding: 6px 10px; background: rgba(255,59,48,0.1); color: #CC2200;" title="Delete Territory">
                                    <i class="bi bi-trash3-fill"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($territories)): ?>
                <tr>
                    <td colspan="5">
                        <div class="empty-state">
                            <i class="bi bi-globe-americas" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2" style="font-weight: 500;">No territories found matching your criteria.</p>
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
<ul class="ios-pagination">
    <?php for($i = 1; $i <= $totalPages; $i++): ?>
    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
        <a class="page-link" href="?search=<?php echo urlencode($search_query); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
    </li>
    <?php endfor; ?>
</ul>
<?php endif; ?>

<!-- Add Territory Modal -->
<div class="modal fade" id="addTerritoryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" style="font-size: 1.1rem; font-weight: 700;">
                        <i class="bi bi-plus-circle-fill text-primary me-2"></i>Create New Territory
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="background: var(--ios-bg);">
                    <input type="hidden" name="action" value="add_territory">
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Territory Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="ios-input fw-bold" style="background: #fff;" required placeholder="e.g. Western Province North">
                    </div>
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Description / Landmarks</label>
                        <textarea name="description" class="ios-input" style="background: #fff;" rows="3" placeholder="e.g. Covers Colombo 1-15 and suburbs"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn quick-btn-primary px-4">Save Territory</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Territory Modal -->
<div class="modal fade" id="editTerritoryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" style="font-size: 1.1rem; font-weight: 700; color: #FF9500;">
                        <i class="bi bi-pencil-square me-2"></i>Edit Territory
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="background: var(--ios-bg);">
                    <input type="hidden" name="action" value="edit_territory">
                    <input type="hidden" name="territory_id" id="edit_territory_id">
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Territory Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="edit_name" class="ios-input fw-bold" style="background: #fff;" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Description / Landmarks</label>
                        <textarea name="description" id="edit_description" class="ios-input" style="background: #fff;" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn quick-btn-primary px-4" style="background: #FF9500; color: #fff;">Update Territory</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Populate Edit Modal Data dynamically
    const editModal = document.getElementById('editTerritoryModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            document.getElementById('edit_territory_id').value = button.getAttribute('data-id');
            document.getElementById('edit_name').value = button.getAttribute('data-name');
            document.getElementById('edit_description').value = button.getAttribute('data-desc');
        });
    }

    // Live Search Debounce
    const searchInput = document.getElementById('searchInput');
    if (searchInput && searchInput.value !== '') {
        searchInput.focus();
        const val = searchInput.value;
        searchInput.value = '';
        searchInput.value = val;
    }

    let searchTimer;
    if(searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => { document.getElementById('filterForm').submit(); }, 800); 
        });
    }
});
</script>

<?php include '../includes/footer.php'; ?>
