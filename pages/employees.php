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

    /* Segmented Control Styling */
    .ios-segment-btn {
        color: var(--ios-label-2);
        border: none;
        background: transparent;
        border-radius: 6px;
        padding: 4px 12px;
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }
    .ios-segment-btn:hover {
        color: var(--ios-label);
    }
    .ios-segment-btn.active {
        background: #FFFFFF !important;
        color: var(--ios-label) !important;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 1px rgba(0,0,0,0.04);
    }

    /* Calendar styles */
    .col-7th {
        width: 14.285%;
        flex: 0 0 14.285%;
        max-width: 14.285%;
    }
    .calendar-day-cell {
        min-height: 110px;
        border-right: 1px solid var(--ios-separator);
        border-bottom: 1px solid var(--ios-separator);
        position: relative;
        padding: 8px;
        background: #fff;
        transition: background 0.15s ease;
        display: flex;
        flex-direction: column;
        justify-content: flex-start;
        align-items: flex-start;
        cursor: pointer;
    }
    .calendar-day-cell:hover {
        background: rgba(0, 122, 255, 0.03);
    }
    .calendar-day-number {
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--ios-label-2);
        align-self: flex-end;
        margin-bottom: 4px;
    }
    .calendar-day-cell.other-month {
        background: #F8F9FA;
        color: var(--ios-label-2);
        opacity: 0.4;
        cursor: not-allowed;
    }
    .calendar-day-cell.today {
        background: rgba(48, 200, 138, 0.05);
    }
    .calendar-day-cell.today .calendar-day-number {
        color: var(--accent);
        font-weight: 800;
        background: rgba(48, 200, 138, 0.12);
        width: 22px;
        height: 22px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .calendar-event-badge {
        font-size: 0.72rem;
        font-weight: 600;
        padding: 4px 6px;
        border-radius: 6px;
        margin-top: 4px;
        border: 1px solid transparent;
        width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        text-align: left;
    }
    .calendar-event-badge.route {
        background: rgba(0,122,255,0.08);
        border-color: rgba(0,122,255,0.2);
        color: #0055CC;
    }
    .calendar-event-badge.present {
        background: rgba(52,199,89,0.08);
        border-color: rgba(52,199,89,0.2);
        color: #1A9A3A;
    }
    .calendar-event-badge.half-day {
        background: rgba(255,204,0,0.08);
        border-color: rgba(255,204,0,0.2);
        color: #B38600;
    }
    .calendar-event-badge.absent {
        background: rgba(255,59,48,0.08);
        border-color: rgba(255,59,48,0.2);
        color: #CC2200;
    }
    
    /* Hover quick log icon style */
    .calendar-day-cell .quick-log-btn {
        opacity: 0;
        transition: opacity 0.2s ease;
        margin-top: auto;
        font-size: 0.7rem;
        color: var(--ios-label-2);
        font-weight: 600;
        width: 100%;
        text-align: center;
    }
    .calendar-day-cell:not(.other-month):hover .quick-log-btn {
        opacity: 1;
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

                            <!-- Salary Report Button -->
                            <a href="salary_report.php?employee_id=<?php echo $e['id']; ?>&month=<?php echo date('Y-m'); ?>" class="quick-btn" style="padding: 6px 12px; background: rgba(48,200,138,0.15); color: #1A9A3A;" title="Salary Report">
                                <i class="bi bi-receipt"></i>
                            </a>

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
        <div class="d-flex align-items-center gap-2">
            <!-- iOS-style Segmented Control -->
            <div class="ios-segmented-control" style="display: inline-flex; background: rgba(120,120,128,0.12); padding: 2px; border-radius: 8px;">
                <button type="button" id="toggleListViewBtn" class="ios-segment-btn active" onclick="switchHistoryView('list')">
                    List View
                </button>
                <button type="button" id="toggleCalendarViewBtn" class="ios-segment-btn" onclick="switchHistoryView('calendar')">
                    Calendar View
                </button>
            </div>
            <button class="quick-btn text-muted" onclick="closeHistory()" style="background: transparent; border: none; padding: 4px 8px;">
                <i class="bi bi-x-lg"></i> Close
            </button>
        </div>
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

        <!-- List View Panel -->
        <div id="historyListPanel">
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

        <!-- Calendar View Panel -->
        <div id="historyCalendarPanel" class="d-none">
            <!-- Calendar Navigation Header -->
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="quick-btn quick-btn-ghost" onclick="navigateCalendarMonth(-1)" style="padding: 6px 12px; font-size: 0.85rem;">
                        <i class="bi bi-chevron-left"></i> Prev
                    </button>
                    <span id="calendarCurrentMonthName" class="fw-bold fs-5 text-dark" style="min-width: 150px; text-align: center;"></span>
                    <button type="button" class="quick-btn quick-btn-ghost" onclick="navigateCalendarMonth(1)" style="padding: 6px 12px; font-size: 0.85rem;">
                        Next <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
                <!-- Legend -->
                <div class="d-flex flex-wrap gap-3 small text-muted">
                    <span class="d-flex align-items-center gap-1">
                        <span style="display:inline-block; width: 12px; height: 12px; background: rgba(0,122,255,0.08); border: 1px solid rgba(0,122,255,0.2); border-radius: 3px;"></span>
                        Route Assigned
                    </span>
                    <span class="d-flex align-items-center gap-1">
                        <span style="display:inline-block; width: 12px; height: 12px; background: rgba(52,199,89,0.08); border: 1px solid rgba(52,199,89,0.2); border-radius: 3px;"></span>
                        Present (Office)
                    </span>
                    <span class="d-flex align-items-center gap-1">
                        <span style="display:inline-block; width: 12px; height: 12px; background: rgba(255,204,0,0.08); border: 1px solid rgba(255,204,0,0.2); border-radius: 3px;"></span>
                        Half Day
                    </span>
                    <span class="d-flex align-items-center gap-1">
                        <span style="display:inline-block; width: 12px; height: 12px; background: rgba(255,59,48,0.08); border: 1px solid rgba(255,59,48,0.2); border-radius: 3px;"></span>
                        Absent
                    </span>
                </div>
            </div>

            <!-- Calendar Grid -->
            <div class="border rounded-3 bg-white overflow-hidden shadow-sm">
                <!-- Days of Week Header -->
                <div class="row g-0 text-center border-bottom bg-light py-2 fw-semibold text-muted" style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; width: 100%;">
                    <div class="col-7th">Sun</div>
                    <div class="col-7th">Mon</div>
                    <div class="col-7th">Tue</div>
                    <div class="col-7th">Wed</div>
                    <div class="col-7th">Thu</div>
                    <div class="col-7th">Fri</div>
                    <div class="col-7th">Sat</div>
                </div>
                <!-- Calendar Body Grid -->
                <div id="calendarGridBody" class="row g-0" style="width: 100%;">
                    <!-- Days loaded dynamically -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Log Attendance Modal -->
<div class="modal fade" id="attendanceModal" tabindex="-1" aria-labelledby="attendanceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
        <div class="modal-content" style="border-radius: 20px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.15);">
            <div class="modal-header border-0 pb-0" style="padding: 20px 24px 10px 24px;">
                <h5 class="modal-title fw-bold text-dark" id="attendanceModalLabel">Record Attendance</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="attendanceForm" onsubmit="saveAttendance(event)">
                <div class="modal-body" style="padding: 10px 24px 24px 24px;">
                    <p class="text-muted small mb-3">Record work or absence for days when the employee was not assigned to an active delivery route.</p>
                    
                    <input type="hidden" id="attEmployeeId" name="employee_id">
                    <input type="hidden" id="attDate" name="date">
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Selected Date</label>
                        <input type="text" id="attDateDisplay" class="ios-input" style="background: var(--ios-bg); pointer-events: none;" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Work / Attendance Status</label>
                        <div class="d-flex flex-column gap-2 mt-1">
                            <label class="d-flex align-items-center gap-2 p-2 rounded border cursor-pointer hover-bg-light" style="cursor: pointer; background: rgba(52,199,89,0.05); margin-bottom: 0;">
                                <input type="radio" name="status" value="present" checked>
                                <div>
                                    <span class="fw-bold text-success d-block" style="font-size: 0.9rem; text-align: left;">Present (Office / Other Work)</span>
                                    <span class="text-muted d-block" style="font-size: 0.75rem; text-align: left;">Employee worked at the office or other non-route tasks.</span>
                                </div>
                            </label>
                            
                            <label class="d-flex align-items-center gap-2 p-2 rounded border cursor-pointer hover-bg-light" style="cursor: pointer; background: rgba(255,204,0,0.05); margin-bottom: 0;">
                                <input type="radio" name="status" value="half_day">
                                <div>
                                    <span class="fw-bold text-warning d-block" style="font-size: 0.9rem; text-align: left;">Half Day (Office / Other Work)</span>
                                    <span class="text-muted d-block" style="font-size: 0.75rem; text-align: left;">Employee worked half-day on non-route tasks.</span>
                                </div>
                            </label>
                            
                            <label class="d-flex align-items-center gap-2 p-2 rounded border cursor-pointer hover-bg-light" style="cursor: pointer; background: rgba(255,59,48,0.05); margin-bottom: 0;">
                                <input type="radio" name="status" value="absent">
                                <div>
                                    <span class="fw-bold text-danger d-block" style="font-size: 0.9rem; text-align: left;">Absent</span>
                                    <span class="text-muted d-block" style="font-size: 0.75rem; text-align: left;">Employee was absent from work on this day.</span>
                                </div>
                            </label>
                            
                            <label id="clearAttOption" class="d-flex align-items-center gap-2 p-2 rounded border cursor-pointer hover-bg-light" style="cursor: pointer; background: var(--ios-bg); margin-bottom: 0;">
                                <input type="radio" name="status" value="clear">
                                <div>
                                    <span class="fw-bold text-muted d-block" style="font-size: 0.9rem; text-align: left;">Clear Record</span>
                                    <span class="text-muted d-block" style="font-size: 0.75rem; text-align: left;">Delete the attendance log for this day.</span>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0" style="padding: 10px 24px 20px 24px;">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal" style="border-radius: 10px;">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4" style="background: var(--accent); border: none; border-radius: 10px; color: white;">Save Record</button>
                </div>
            </form>
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
let currentHistoryView = 'list'; // 'list' or 'calendar'
let calendarCurrentDate = new Date();
let calendarEvents = {};
let currentEmployeeIdForCalendar = null;
let currentEmployeeNameForCalendar = '';

function switchHistoryView(view) {
    currentHistoryView = view;
    
    const listBtn = document.getElementById('toggleListViewBtn');
    const calBtn = document.getElementById('toggleCalendarViewBtn');
    const listPanel = document.getElementById('historyListPanel');
    const calPanel = document.getElementById('historyCalendarPanel');
    const filterContainer = document.getElementById('monthFilterContainer');
    
    if (view === 'list') {
        listBtn.classList.add('active');
        calBtn.classList.remove('active');
        listPanel.classList.remove('d-none');
        calPanel.classList.add('d-none');
        filterContainer.classList.remove('d-none');
    } else {
        listBtn.classList.remove('active');
        calBtn.classList.add('active');
        listPanel.classList.add('d-none');
        calPanel.classList.remove('d-none');
        filterContainer.classList.add('d-none');
        
        loadCalendarData();
    }
}

function loadEmployeeHistory(employeeId, employeeName) {
    const card = document.getElementById('employeeHistoryCard');
    const headerName = document.getElementById('historyEmployeeName');
    const tableBody = document.getElementById('historyTableBody');
    const monthContainer = document.getElementById('monthFilterContainer');
    
    currentEmployeeIdForCalendar = employeeId;
    currentEmployeeNameForCalendar = employeeName;
    
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
    
    // Load whichever view is currently active
    if (currentHistoryView === 'calendar') {
        loadCalendarData();
    }
    
    // Fetch data for the list view anyway
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
    
    // Update stats only if we are currently in list view (calendar has its own dates scope)
    if (currentHistoryView === 'list') {
        document.getElementById('statTotalTrips').innerText = filteredHistory.length;
        document.getElementById('statTotalDistance').innerText = `${totalDistance.toFixed(1)} km`;
        document.getElementById('statTotalSales').innerText = `Rs ${totalSales.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
        document.getElementById('statTotalCollections').innerText = `Rs ${totalCollections.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    }
}

// Calendar View Data Loading & Rendering
function loadCalendarData() {
    if (!currentEmployeeIdForCalendar) return;
    
    const year = calendarCurrentDate.getFullYear();
    const month = String(calendarCurrentDate.getMonth() + 1).padStart(2, '0');
    const monthStr = `${year}-${month}`;
    
    // Update month display
    const options = { month: 'long', year: 'numeric' };
    document.getElementById('calendarCurrentMonthName').innerText = calendarCurrentDate.toLocaleDateString('en-US', options);
    
    const gridBody = document.getElementById('calendarGridBody');
    gridBody.innerHTML = `
        <div class="col-12 py-5 text-center">
            <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
            <span class="fw-medium text-muted">Loading calendar logs...</span>
        </div>
    `;
    
    fetch(`../ajax/get_employee_calendar.php?employee_id=${currentEmployeeIdForCalendar}&month=${monthStr}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                calendarEvents = data.events;
                renderCalendar();
            } else {
                gridBody.innerHTML = `
                    <div class="col-12 py-4 text-center text-danger">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i> Error loading calendar: ${data.message}
                    </div>
                `;
            }
        })
        .catch(err => {
            console.error('Error loading calendar:', err);
            gridBody.innerHTML = `
                <div class="col-12 py-4 text-center text-danger">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i> Failed to retrieve calendar logs.
                </div>
            `;
        });
}

function navigateCalendarMonth(direction) {
    calendarCurrentDate.setMonth(calendarCurrentDate.getMonth() + direction);
    loadCalendarData();
}

function renderCalendar() {
    const gridBody = document.getElementById('calendarGridBody');
    gridBody.innerHTML = '';
    
    const year = calendarCurrentDate.getFullYear();
    const month = calendarCurrentDate.getMonth();
    
    // First day of the month
    const firstDay = new Date(year, month, 1);
    const startDayOfWeek = firstDay.getDay(); // (0 = Sun, ..., 6 = Sat)
    
    // Total days in month
    const totalDays = new Date(year, month + 1, 0).getDate();
    
    // Previous month total days
    const prevMonthTotalDays = new Date(year, month, 0).getDate();
    
    // Today's date string YYYY-MM-DD
    const today = new Date();
    const todayStr = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
    
    let html = '';
    
    // Trailing days from previous month
    for (let i = startDayOfWeek - 1; i >= 0; i--) {
        const dayNum = prevMonthTotalDays - i;
        html += `<div class="col-7th calendar-day-cell other-month"><div class="calendar-day-number">${dayNum}</div></div>`;
    }
    
    // Calendar Stats Aggregation for Current Month
    let monthlyTrips = 0;
    let monthlyDistance = 0;
    let monthlySales = 0;
    let monthlyCollections = 0;
    
    // Current month's days
    for (let day = 1; day <= totalDays; day++) {
        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const isToday = dateStr === todayStr;
        
        let eventsHtml = '';
        let dayHasRoute = false;
        let dayHasAttendance = false;
        let attendanceStatus = '';
        
        if (calendarEvents[dateStr]) {
            const ev = calendarEvents[dateStr];
            
            // Render routes
            if (ev.has_route && ev.routes.length > 0) {
                dayHasRoute = true;
                monthlyTrips += ev.routes.length;
                
                ev.routes.forEach(r => {
                    const reportUrl = `route_detailed_report.php?id=${r.assignment_id}`;
                    eventsHtml += `
                        <a href="${reportUrl}" target="_blank" class="calendar-event-badge route" style="text-decoration: none; display: block;" title="Route: ${escapeHtml(r.route_name)} (${r.status})" onclick="event.stopPropagation();">
                            🚚 ${escapeHtml(r.route_name)}
                        </a>
                    `;
                });
            }
            
            // Render manual attendance status (office, half-day, absent)
            if (ev.attendance_status) {
                dayHasAttendance = true;
                attendanceStatus = ev.attendance_status;
                let badgeClass = 'present';
                let icon = '🏢';
                let label = 'Present (Office)';
                
                if (attendanceStatus === 'half_day') {
                    badgeClass = 'half-day';
                    icon = '🏢';
                    label = 'Half Day';
                } else if (attendanceStatus === 'absent') {
                    badgeClass = 'absent';
                    icon = '❌';
                    label = 'Absent';
                }
                
                eventsHtml += `
                    <div class="calendar-event-badge ${badgeClass}" title="${label}">
                        ${icon} ${label}
                    </div>
                `;
            }
        }
        
        // Log button helper on hover
        let logButtonHtml = '';
        if (!dayHasRoute) {
            logButtonHtml = `
                <div class="quick-log-btn text-primary mt-auto">
                    <i class="bi bi-pencil-square"></i> ${dayHasAttendance ? 'Edit' : '+ Log Office'}
                </div>
            `;
        } else {
            logButtonHtml = `<div class="mt-auto" style="height: 14px;"></div>`;
        }
        
        html += `
            <div class="col-7th calendar-day-cell ${isToday ? 'today' : ''}" onclick="openAttendanceModal('${dateStr}', ${dayHasRoute}, '${attendanceStatus}')">
                <div class="calendar-day-number">${day}</div>
                <div class="w-100 d-flex flex-column gap-1">
                    ${eventsHtml}
                </div>
                ${logButtonHtml}
            </div>
        `;
    }
    
    // Render next month's leading days to complete grid row
    const totalRendered = startDayOfWeek + totalDays;
    const remainingDays = (7 - (totalRendered % 7)) % 7;
    for (let day = 1; day <= remainingDays; day++) {
        html += `<div class="col-7th calendar-day-cell other-month"><div class="calendar-day-number">${day}</div></div>`;
    }
    
    gridBody.innerHTML = html;
    
    // Aggregate financial metrics & distances from current list history (for this specific month)
    const currentMonthKey = calendarCurrentDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    const currentMonthHistory = currentEmployeeHistory.filter(item => item.month_key === currentMonthKey);
    
    currentMonthHistory.forEach(item => {
        if (item.distance !== null) {
            monthlyDistance += item.distance;
        }
        monthlySales += item.total_sales;
        monthlyCollections += item.total_collections;
    });
    
    // Update stats cards when in calendar view
    if (currentHistoryView === 'calendar') {
        document.getElementById('statTotalTrips').innerText = monthlyTrips;
        document.getElementById('statTotalDistance').innerText = `${monthlyDistance.toFixed(1)} km`;
        document.getElementById('statTotalSales').innerText = `Rs ${monthlySales.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
        document.getElementById('statTotalCollections').innerText = `Rs ${monthlyCollections.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    }
}

function openAttendanceModal(dateStr, hasRoute, currentStatus) {
    if (hasRoute) {
        alert("This employee was assigned to a route on this day. Attendance is logged automatically upon route dispatch.");
        return;
    }
    
    document.getElementById('attEmployeeId').value = currentEmployeeIdForCalendar;
    document.getElementById('attDate').value = dateStr;
    
    const options = { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' };
    const dateObj = new Date(dateStr);
    document.getElementById('attDateDisplay').value = dateObj.toLocaleDateString('en-US', options);
    
    const radios = document.getElementsByName('status');
    radios.forEach(radio => {
        if (radio.value === currentStatus) {
            radio.checked = true;
        } else if (currentStatus === '' && radio.value === 'present') {
            radio.checked = true;
        }
    });
    
    const clearOption = document.getElementById('clearAttOption');
    if (currentStatus !== '') {
        clearOption.classList.remove('d-none');
    } else {
        clearOption.classList.add('d-none');
    }
    
    new bootstrap.Modal(document.getElementById('attendanceModal')).show();
}

function saveAttendance(event) {
    event.preventDefault();
    
    const form = document.getElementById('attendanceForm');
    const formData = new FormData(form);
    
    // Close Modal
    const modalEl = document.getElementById('attendanceModal');
    const modalInstance = bootstrap.Modal.getInstance(modalEl);
    if (modalInstance) {
        modalInstance.hide();
    }
    
    fetch('../ajax/save_employee_attendance.php', {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Reload calendar view
                loadCalendarData();
                
                // Fetch list data in background to keep stats in sync
                fetch(`../ajax/get_employee_history.php?employee_id=${currentEmployeeIdForCalendar}`)
                    .then(r => r.json())
                    .then(ld => {
                        if (ld.success) {
                            currentEmployeeHistory = ld.history;
                        }
                    });
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(err => {
            console.error('Error saving attendance:', err);
            alert('Failed to save attendance record.');
        });
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