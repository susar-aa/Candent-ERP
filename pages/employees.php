<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); 

$message = '';

// --- AUTO DB MIGRATION FOR HR MODULE ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS employees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        emp_code VARCHAR(20) UNIQUE NOT NULL,
        name VARCHAR(100) NOT NULL,
        phone VARCHAR(20),
        designation VARCHAR(50),
        daily_rate DECIMAL(10,2) DEFAULT 0.00,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch(PDOException $e) { error_log("HR Migration Error: " . $e->getMessage()); }
// ---------------------------------------

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] == 'add_employee') {
        $emp_code = trim($_POST['emp_code']);
        $name = trim($_POST['name']);
        $phone = trim($_POST['phone']);
        $designation = trim($_POST['designation']);
        $daily_rate = (float)$_POST['daily_rate'];
        $status = $_POST['status'] ?? 'active';

        try {
            $stmt = $pdo->prepare("INSERT INTO employees (emp_code, name, phone, designation, daily_rate, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$emp_code, $name, $phone, $designation, $daily_rate, $status]);
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Employee added successfully!</div>";
        } catch (PDOException $e) {
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error: Employee Code might already exist.</div>";
        }
    }
    
    if ($_POST['action'] == 'edit_employee') {
        $id = (int)$_POST['employee_id'];
        $emp_code = trim($_POST['emp_code']);
        $name = trim($_POST['name']);
        $phone = trim($_POST['phone']);
        $designation = trim($_POST['designation']);
        $daily_rate = (float)$_POST['daily_rate'];
        $status = $_POST['status'];

        try {
            $stmt = $pdo->prepare("UPDATE employees SET emp_code=?, name=?, phone=?, designation=?, daily_rate=?, status=? WHERE id=?");
            $stmt->execute([$emp_code, $name, $phone, $designation, $daily_rate, $status, $id]);
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Employee updated successfully!</div>";
        } catch (PDOException $e) {
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }

    if ($_POST['action'] == 'delete_employee') {
        $id = (int)$_POST['employee_id'];
        try {
            $pdo->prepare("DELETE FROM employees WHERE id=?")->execute([$id]);
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-trash3-fill me-2'></i> Employee deleted successfully!</div>";
        } catch (PDOException $e) {
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Cannot delete employee due to existing payroll or attendance records.</div>";
        }
    }
}

// --- FETCH DATA ---
$employees = $pdo->query("SELECT * FROM employees ORDER BY name ASC")->fetchAll();

// Calculate Metrics
$total_emps = count($employees);
$active_emps = 0;
$total_daily_wage = 0;
foreach($employees as $e) {
    if($e['status'] === 'active') {
        $active_emps++;
        $total_daily_wage += $e['daily_rate'];
    }
}

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
    
    .metrics-card {
        border-radius: 16px;
        padding: 20px 24px;
        color: #fff;
        box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        display: flex;
        align-items: center;
        gap: 20px;
        height: 100%;
        transition: transform 0.2s ease;
    }
    .metrics-icon {
        width: 54px; height: 54px;
        border-radius: 14px;
        background: rgba(255,255,255,0.2);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.8rem;
        flex-shrink: 0;
    }

    /* Explicit Modal Inputs Visibility */
    .modal-body .ios-input, .modal-body .form-select {
        background: #FFFFFF !important;
        border: 1px solid #C7C7CC !important;
        border-radius: 10px !important;
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
    
    /* Month-wise filter pills styling */
    .month-pill {
        border: 1px solid var(--ios-separator);
        background: var(--ios-surface);
        color: var(--ios-label);
        font-size: 0.8rem;
        font-weight: 600;
        padding: 6px 14px;
        border-radius: 50px;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .month-pill:hover {
        background: var(--ios-surface-2);
        color: var(--ios-label);
    }
    .month-pill.active {
        background: var(--accent);
        color: #fff;
        border-color: var(--accent);
        box-shadow: 0 4px 10px rgba(48,200,138,0.2);
    }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Employee Management</h1>
        <div class="page-subtitle">Manage personnel, roles, and daily wage rates.</div>
    </div>
    <div>
        <button class="quick-btn quick-btn-primary" onclick="openAddModal()">
            <i class="bi bi-person-plus-fill"></i> Add Employee
        </button>
    </div>
</div>

<?php echo $message; ?>

<!-- Top Metrics -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="metrics-card" style="background: linear-gradient(145deg, #007AFF, #0055CC);">
            <div class="metrics-icon"><i class="bi bi-people-fill"></i></div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Total Employees</div>
                <div style="font-size: 1.8rem; font-weight: 800; line-height: 1;"><?php echo $total_emps; ?> <span style="font-size: 0.9rem; font-weight: 600; opacity: 0.8;">Registered</span></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="metrics-card" style="background: linear-gradient(145deg, #34C759, #30D158);">
            <div class="metrics-icon"><i class="bi bi-person-check-fill"></i></div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Active Workforce</div>
                <div style="font-size: 1.8rem; font-weight: 800; line-height: 1;"><?php echo $active_emps; ?> <span style="font-size: 0.9rem; font-weight: 600; opacity: 0.8;">Working</span></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF9500, #E07800);">
            <div class="metrics-icon"><i class="bi bi-cash-stack"></i></div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Total Daily Wage (Active)</div>
                <div style="font-size: 1.8rem; font-weight: 800; line-height: 1;">Rs <?php echo number_format($total_daily_wage, 2); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Employees Table Card -->
<div class="dash-card mb-4 overflow-hidden">
    <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
        <span class="card-title">
            <span class="card-title-icon" style="background: rgba(0,122,255,0.1); color: #0055CC;">
                <i class="bi bi-person-badge-fill"></i>
            </span>
            Personnel Directory
        </span>
        
        <!-- Live JS Search Filter -->
        <div class="ios-search-wrapper" style="max-width: 300px;">
            <i class="bi bi-search"></i>
            <input type="text" id="tableSearchInput" class="ios-input" style="min-height: 36px; padding: 6px 14px 6px 38px; font-size: 0.85rem;" placeholder="Search by Name, Role, or Code...">
        </div>
    </div>
    <div class="table-responsive">
        <table class="ios-table text-center" id="employeesTable">
            <thead>
                <tr class="table-ios-header">
                    <th class="text-start ps-4" style="width: 30%;">Employee Info</th>
                    <th style="width: 20%;">Contact</th>
                    <th style="width: 20%;">Daily Wage Rate</th>
                    <th style="width: 15%;">Status</th>
                    <th class="text-end pe-4" style="width: 15%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $colors = ['#FF2D55', '#007AFF', '#34C759', '#FF9500', '#AF52DE', '#30B0C7'];
                foreach($employees as $e): 
                    // Generate initials & color
                    $words = explode(" ", $e['name']);
                    $initials = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
                    $color = $colors[$e['id'] % count($colors)];
                ?>
                <tr id="employee-row-<?php echo $e['id']; ?>" class="employee-row <?php echo $e['status'] == 'inactive' ? 'opacity-50' : ''; ?>">
                    <td class="text-start ps-4">
                        <div class="d-flex align-items-center">
                            <div class="contact-avatar-circle" style="background: <?php echo $color; ?>20; color: <?php echo $color; ?>;">
                                <?php echo $initials; ?>
                            </div>
                            <div>
                                <div style="font-weight: 700; font-size: 1rem; color: var(--ios-label);">
                                    <?php echo htmlspecialchars($e['name']); ?>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--ios-label-3); margin-top: 2px;">
                                    <span class="fw-bold text-muted me-2"><?php echo htmlspecialchars($e['emp_code']); ?></span>
                                    <i class="bi bi-briefcase-fill me-1"></i><?php echo htmlspecialchars($e['designation']); ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if($e['phone']): ?>
                            <span style="font-size: 0.9rem; font-weight: 500; color: var(--ios-label);">
                                <i class="bi bi-telephone-fill text-muted me-1"></i> <?php echo htmlspecialchars($e['phone']); ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted small fst-italic">No Phone</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span style="font-weight: 800; font-size: 1rem; color: #1A9A3A;">
                            Rs <?php echo number_format($e['daily_rate'], 2); ?>
                        </span>
                    </td>
                    <td>
                        <?php if($e['status'] == 'active'): ?>
                            <span class="ios-badge green"><i class="bi bi-check-circle-fill"></i> Active</span>
                        <?php else: ?>
                            <span class="ios-badge red"><i class="bi bi-x-circle-fill"></i> Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end pe-4">
                        <div class="d-flex justify-content-end gap-1 flex-wrap">
                            <!-- Route History Button -->
                            <button class="quick-btn" style="padding: 6px 12px; background: rgba(0,122,255,0.1); color: #007AFF;" title="Route History" 
                                onclick='loadEmployeeHistory(<?php echo $e['id']; ?>, "<?php echo htmlspecialchars($e['name'], ENT_QUOTES, 'UTF-8'); ?>")'>
                                <i class="bi bi-clock-history"></i>
                            </button>

                            <!-- Edit Button -->
                            <button class="quick-btn quick-btn-secondary" style="padding: 6px 12px;" title="Edit Employee" 
                                onclick='openEditModal(<?php echo htmlspecialchars(json_encode($e), ENT_QUOTES, 'UTF-8'); ?>)'>
                                <i class="bi bi-pencil-square" style="color: #FF9500;"></i>
                            </button>

                            <!-- Delete Form -->
                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to permanently delete this employee?');">
                                <input type="hidden" name="action" value="delete_employee">
                                <input type="hidden" name="employee_id" value="<?php echo $e['id']; ?>">
                                <button type="submit" class="quick-btn" style="padding: 6px 10px; background: rgba(255,59,48,0.1); color: #CC2200;" title="Delete">
                                    <i class="bi bi-trash3-fill"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($employees)): ?>
                <tr id="emptyRow">
                    <td colspan="5">
                        <div class="empty-state py-5">
                            <i class="bi bi-people" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2" style="font-weight: 500;">No employees registered yet.</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
                
                <!-- Hidden row for JS search empty state -->
                <tr id="noResultsRow" class="d-none">
                    <td colspan="5">
                        <div class="empty-state py-4">
                            <p class="mt-2" style="font-weight: 500; color: var(--ios-label-3);">No matching employees found.</p>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Employee Route History Card (Hidden by default, shown when employee is selected) -->
<div id="employeeHistoryCard" class="dash-card mb-4 overflow-hidden d-none">
    <div class="dash-card-header d-flex justify-content-between align-items-center" style="background: var(--ios-surface); padding: 18px 20px;">
        <span class="card-title">
            <span class="card-title-icon" style="background: rgba(0,122,255,0.1); color: #007AFF;">
                <i class="bi bi-clock-history"></i>
            </span>
            <span id="historyEmployeeName">Employee Route History</span>
        </span>
        <button class="quick-btn text-muted" onclick="closeHistory()" style="background: transparent; border: none; padding: 4px 8px;">
            <i class="bi bi-x-lg"></i> Close
        </button>
    </div>
    
    <div class="p-3" style="background: var(--ios-bg);">
        <!-- Summary Stats inside history -->
        <div class="row g-3 mb-3" id="historyStatsRow">
            <div class="col-md-3 col-6">
                <div class="p-3 rounded-3 border bg-white shadow-sm">
                    <div style="font-size: 0.72rem; font-weight: 700; color: var(--ios-label-2); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Total Trips</div>
                    <div id="statTotalTrips" style="font-size: 1.4rem; font-weight: 800; color: var(--ios-label);">0</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="p-3 rounded-3 border bg-white shadow-sm">
                    <div style="font-size: 0.72rem; font-weight: 700; color: var(--ios-label-2); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Total Distance</div>
                    <div id="statTotalDistance" style="font-size: 1.4rem; font-weight: 800; color: #007AFF;">0.0 km</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="p-3 rounded-3 border bg-white shadow-sm">
                    <div style="font-size: 0.72rem; font-weight: 700; color: var(--ios-label-2); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Total Sales</div>
                    <div id="statTotalSales" style="font-size: 1.4rem; font-weight: 800; color: #1A9A3A;">Rs 0.00</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="p-3 rounded-3 border bg-white shadow-sm">
                    <div style="font-size: 0.72rem; font-weight: 700; color: var(--ios-label-2); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Total Collections</div>
                    <div id="statTotalCollections" style="font-size: 1.4rem; font-weight: 800; color: #AF52DE;">Rs 0.00</div>
                </div>
            </div>
        </div>

        <!-- Month Filter Tabs/Pills -->
        <div class="mb-3 d-flex flex-wrap gap-1 align-items-center" id="monthFilterContainer">
            <span class="ios-label-sm me-2 mb-0" style="padding-left: 0;">Filter Month:</span>
        </div>

        <!-- Table of routes -->
        <div class="table-responsive bg-white rounded-3 border shadow-sm">
            <table class="ios-table text-center" id="historyTable" style="width: 100%;">
                <thead>
                    <tr class="table-ios-header">
                        <th style="width: 15%;">Date</th>
                        <th style="width: 25%; text-align: left;">Route Name</th>
                        <th style="width: 15%;">Role in Trip</th>
                        <th style="width: 15%;">Distance</th>
                        <th style="width: 15%;">Sales / Collections</th>
                        <th style="width: 15%;">Status</th>
                        <th style="width: 10%;">Actions</th>
                    </tr>
                </thead>
                <tbody id="historyTableBody">
                    <!-- Dynamically loaded rows -->
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- ==================== MODALS ==================== -->

<!-- Add Employee Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem;"><i class="bi bi-person-plus-fill text-primary me-2"></i>Add Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="add_employee">
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Emp Code <span class="text-danger">*</span></label>
                            <input type="text" name="emp_code" class="ios-input fw-bold" placeholder="EMP-001" style="font-family: monospace;" required>
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Designation</label>
                            <input type="text" name="designation" class="ios-input" placeholder="e.g. Driver, Helper">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="ios-input fw-bold" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Phone Number</label>
                        <input type="text" name="phone" class="ios-input">
                    </div>
                    
                    <div class="mb-4">
                        <label class="ios-label-sm">Daily Wage Rate (Rs) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="daily_rate" class="ios-input fw-bold" style="color: #1A9A3A; font-size: 1.1rem;" required placeholder="0.00">
                        <small class="text-muted d-block mt-2" style="font-size: 0.75rem;"><i class="bi bi-info-circle-fill me-1"></i> Used to calculate payroll based on attendance.</small>
                    </div>

                    <div class="mb-4">
                        <label class="ios-label-sm">Status</label>
                        <select name="status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn quick-btn-primary px-4">Save Employee</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Employee Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem; color: #C07000;"><i class="bi bi-pencil-square me-2"></i>Edit Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="edit_employee">
                    <input type="hidden" name="employee_id" id="edit_id">
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Emp Code <span class="text-danger">*</span></label>
                            <input type="text" name="emp_code" id="edit_code" class="ios-input fw-bold" style="font-family: monospace;" required>
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Designation</label>
                            <input type="text" name="designation" id="edit_designation" class="ios-input">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="edit_name" class="ios-input fw-bold" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Phone Number</label>
                        <input type="text" name="phone" id="edit_phone" class="ios-input">
                    </div>
                    
                    <div class="mb-4">
                        <label class="ios-label-sm">Daily Wage Rate (Rs) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="daily_rate" id="edit_rate" class="ios-input fw-bold" style="color: #1A9A3A; font-size: 1.1rem;" required>
                    </div>

                    <div class="mb-4">
                        <label class="ios-label-sm">Status</label>
                        <select name="status" id="edit_status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn px-4" style="background: #FF9500; color: #fff;">Update Employee</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Explicit JS functions for Modals
function openAddModal() {
    new bootstrap.Modal(document.getElementById('addModal')).show();
}

function openEditModal(e) {
    document.getElementById('edit_id').value = e.id;
    document.getElementById('edit_code').value = e.emp_code;
    document.getElementById('edit_name').value = e.name;
    document.getElementById('edit_phone').value = e.phone;
    document.getElementById('edit_designation').value = e.designation;
    document.getElementById('edit_rate').value = e.daily_rate;
    document.getElementById('edit_status').value = e.status;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

// Employee History AJAX and rendering functions
let currentEmployeeHistory = [];
let activeMonthFilter = 'All';

function loadEmployeeHistory(employeeId, employeeName) {
    const card = document.getElementById('employeeHistoryCard');
    const headerName = document.getElementById('historyEmployeeName');
    const tableBody = document.getElementById('historyTableBody');
    const monthContainer = document.getElementById('monthFilterContainer');
    
    // Highlight the active row
    document.querySelectorAll('.employee-row').forEach(row => {
        row.style.background = '';
    });
    const activeRow = document.getElementById(`employee-row-${employeeId}`);
    if (activeRow) {
        activeRow.style.background = 'rgba(0, 122, 255, 0.08)';
    }
    
    // Show card & loader
    card.classList.remove('d-none');
    headerName.innerText = `Route History: ${employeeName}`;
    tableBody.innerHTML = `
        <tr>
            <td colspan="7" class="py-5">
                <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                <span class="fw-medium text-muted">Retrieving employee route logs...</span>
            </td>
        </tr>
    `;
    monthContainer.innerHTML = '';
    
    // Scroll to card
    card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    
    // Fetch data
    fetch(`../ajax/get_employee_history.php?employee_id=${employeeId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                currentEmployeeHistory = data.history;
                activeMonthFilter = 'All';
                renderMonthFilters();
                renderHistoryTable();
            } else {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="7" class="py-4 text-danger">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> Error: ${data.message}
                        </td>
                    </tr>
                `;
            }
        })
        .catch(err => {
            console.error('Error fetching history:', err);
            tableBody.innerHTML = `
                <tr>
                    <td colspan="7" class="py-4 text-danger">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i> Failed to connect to server.
                    </td>
                </tr>
            `;
        });
}

function closeHistory() {
    document.getElementById('employeeHistoryCard').classList.add('d-none');
    document.querySelectorAll('.employee-row').forEach(row => {
        row.style.background = '';
    });
}

function renderMonthFilters() {
    const monthContainer = document.getElementById('monthFilterContainer');
    monthContainer.innerHTML = '<span class="ios-label-sm me-2 mb-0" style="padding-left: 0;">Filter Month:</span>';
    
    // Get unique months in sorted order
    const months = new Set();
    currentEmployeeHistory.forEach(item => {
        if (item.month_key) {
            months.add(item.month_key);
        }
    });
    
    // Convert to array and sort (latest first)
    const sortedMonths = Array.from(months).sort((a, b) => {
        return new Date(b) - new Date(a);
    });
    
    // Render "All" pill
    const allBtn = document.createElement('button');
    allBtn.type = 'button';
    allBtn.className = `month-pill ${activeMonthFilter === 'All' ? 'active' : ''}`;
    allBtn.innerText = 'All';
    allBtn.onclick = () => filterByMonth('All');
    monthContainer.appendChild(allBtn);
    
    // Render each month pill
    sortedMonths.forEach(m => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `month-pill ${activeMonthFilter === m ? 'active' : ''}`;
        btn.innerText = m;
        btn.onclick = () => filterByMonth(m);
        monthContainer.appendChild(btn);
    });
}

function filterByMonth(month) {
    activeMonthFilter = month;
    // Re-render pills to show active class
    const pills = document.querySelectorAll('#monthFilterContainer .month-pill');
    pills.forEach(pill => {
        if (pill.innerText === month) {
            pill.classList.add('active');
        } else {
            pill.classList.remove('active');
        }
    });
    
    renderHistoryTable();
}

function renderHistoryTable() {
    const tableBody = document.getElementById('historyTableBody');
    const filteredHistory = activeMonthFilter === 'All' 
        ? currentEmployeeHistory 
        : currentEmployeeHistory.filter(item => item.month_key === activeMonthFilter);
        
    if (filteredHistory.length === 0) {
        tableBody.innerHTML = `
            <tr>
                <td colspan="7" class="py-5 text-muted">
                    <i class="bi bi-map d-block fs-3 mb-2 opacity-50"></i>
                    No visited routes found for this selection.
                </td>
            </tr>
        `;
        // Update stats to 0
        document.getElementById('statTotalTrips').innerText = '0';
        document.getElementById('statTotalDistance').innerText = '0.0 km';
        document.getElementById('statTotalSales').innerText = 'Rs 0.00';
        document.getElementById('statTotalCollections').innerText = 'Rs 0.00';
        return;
    }
    
    // Calculate stats
    let totalDistance = 0;
    let totalSales = 0;
    let totalCollections = 0;
    
    let html = '';
    filteredHistory.forEach(item => {
        // Stats summation
        if (item.distance !== null) {
            totalDistance += item.distance;
        }
        totalSales += item.total_sales;
        totalCollections += item.total_collections;
        
        // Status Badge class
        let statusBadgeClass = 'gray';
        if (item.assignment_status === 'assigned') statusBadgeClass = 'blue';
        else if (item.assignment_status === 'accepted') statusBadgeClass = 'blue';
        else if (item.assignment_status === 'completed') statusBadgeClass = 'orange';
        else if (item.assignment_status === 'unloaded') statusBadgeClass = 'green';
        
        // Format Distance
        const distStr = item.distance !== null ? `${item.distance} km` : '<span class="text-muted small">N/A</span>';
        
        // Actions: View detailed audit report
        const reportUrl = `route_detailed_report.php?id=${item.assignment_id}`;
        
        html += `
            <tr>
                <td><span class="fw-bold">${item.formatted_date}</span></td>
                <td class="text-start">
                    <span class="fw-bold text-dark">${escapeHtml(item.route_name)}</span>
                    <div class="text-muted small" style="margin-top: 2px;">
                        <span>Rep: ${escapeHtml(item.rep_name || 'N/A')}</span>
                        <span class="mx-1">•</span>
                        <span>Driver: ${escapeHtml(item.driver_name || 'Self Driven')}</span>
                    </div>
                </td>
                <td><span class="badge bg-light text-dark border">${item.role_in_trip}</span></td>
                <td><span class="fw-semibold">${distStr}</span></td>
                <td>
                    <div style="font-size: 0.9rem;">
                        <span class="text-success fw-bold">S: Rs ${item.total_sales.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                    </div>
                    <div style="font-size: 0.8rem; margin-top: 2px;">
                        <span class="fw-bold" style="color: #AF52DE;">C: Rs ${item.total_collections.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                    </div>
                </td>
                <td><span class="ios-badge ${statusBadgeClass}">${escapeHtml(item.assignment_status.toUpperCase())}</span></td>
                <td>
                    <a href="${reportUrl}" target="_blank" class="quick-btn quick-btn-ghost" style="padding: 4px 10px; font-size: 0.8rem; text-decoration: none;">
                        <i class="bi bi-file-earmark-text"></i> Audit
                    </a>
                </td>
            </tr>
        `;
    });
    
    tableBody.innerHTML = html;
    
    // Update stats
    document.getElementById('statTotalTrips').innerText = filteredHistory.length;
    document.getElementById('statTotalDistance').innerText = `${totalDistance.toFixed(1)} km`;
    document.getElementById('statTotalSales').innerText = `Rs ${totalSales.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    document.getElementById('statTotalCollections').innerText = `Rs ${totalCollections.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
}

function escapeHtml(text) {
    if (!text) return '';
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// Live Search Filter for Employees Table
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('tableSearchInput');
    if(searchInput) {
        searchInput.addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('.employee-row');
            let hasVisible = false;

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if(text.includes(filter)) {
                    row.style.display = '';
                    hasVisible = true;
                } else {
                    row.style.display = 'none';
                }
            });

            // Toggle No Results Message
            const noResultsRow = document.getElementById('noResultsRow');
            const emptyRow = document.getElementById('emptyRow');
            
            if(noResultsRow) {
                if(!hasVisible && rows.length > 0) {
                    noResultsRow.classList.remove('d-none');
                } else {
                    noResultsRow.classList.add('d-none');
                }
            }
        });
    }
});
</script>

<?php include '../includes/footer.php'; ?>