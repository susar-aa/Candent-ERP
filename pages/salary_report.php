<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: text/html');

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

// Get parameters
$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

if (!$employee_id) {
    die('<div class="alert alert-danger">Employee ID is required.</div>');
}

// Fetch employee details
$empStmt = $pdo->prepare('SELECT id, name, daily_rate FROM employees WHERE id = ?');
$empStmt->execute([$employee_id]);
$employee = $empStmt->fetch(PDO::FETCH_ASSOC);
if (!$employee) {
    die('<div class="alert alert-danger">Employee not found.</div>');
}

// Build page
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    /* Calendar styles – same as employee history calendar */
    .col-7th { width:14.285%; flex:0 0 14.285%; max-width:14.285%; }
    .calendar-day-cell {
        min-height:110px; border-right:1px solid var(--ios-separator); border-bottom:1px solid var(--ios-separator);
        position:relative; padding:8px; background:#fff; transition:background .15s ease;
        display:flex; flex-direction:column; justify-content:flex-start; align-items:flex-start; cursor:pointer;
    }
    .calendar-day-cell:hover { background:rgba(0,122,255,0.03); }
    .calendar-day-number { font-size:.85rem; font-weight:700; color:var(--ios-label-2); align-self:flex-end; margin-bottom:4px; }
    .calendar-day-cell.other-month { background:#F8F9FA; opacity:0.4; cursor:not-allowed; }
    .calendar-day-cell.today { background:rgba(48,200,138,0.05); }
    .calendar-day-cell.today .calendar-day-number { color:var(--accent); font-weight:800; background:rgba(48,200,138,0.12);
        width:22px; height:22px; border-radius:50%; display:flex; align-items:center; justify-content:center; }
    .calendar-event-badge { font-size:.72rem; font-weight:600; padding:4px 6px; border-radius:6px; margin-top:4px; border:1px solid transparent;
        width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; text-align:left; }
    .calendar-event-badge.route { background:rgba(0,122,255,0.08); border-color:rgba(0,122,255,0.2); color:#0055CC; }
    .calendar-event-badge.present { background:rgba(52,199,89,0.08); border-color:rgba(52,199,89,0.2); color:#1A9A3A; }
    .calendar-event-badge.half-day { background:rgba(255,204,0,0.08); border-color:rgba(255,204,0,0.2); color:#B38600; }
    .calendar-event-badge.absent { background:rgba(255,59,48,0.08); border-color:rgba(255,59,48,0.2); color:#CC2200; }
    .quick-log-btn { opacity:0; transition:opacity .2s ease; margin-top:auto; font-size:.7rem; color:var(--ios-label-2); font-weight:600; width:100%; text-align:center; }
    .calendar-day-cell:not(.other-month):hover .quick-log-btn { opacity:1; }
</style>

<div class="page-header">
    <div>
        <h1 class="h2">Salary Report – <?php echo htmlspecialchars($employee['name']); ?></h1>
        <div class="page-subtitle">Detailed salary calculation for the selected month.</div>
    </div>
</div>

<?php
// Summary cards
// We'll compute totals client‑side, but for a quick preview we can pre‑calculate route days via SQL
$rangeStart = $selected_month . '-01';
$rangeEnd = date('Y-m-t', strtotime($rangeStart));
// Route days count (unique dates where employee had a route as driver or rep)
$routeDaysStmt = $pdo->prepare("SELECT COUNT(DISTINCT rr.assign_date) AS route_days FROM rep_routes rr
    WHERE (rr.driver_id = :emp_id OR rr.rep_id = (SELECT user_id FROM employees WHERE id = :emp_id) OR rr.rep_id IN (SELECT id FROM users WHERE employee_id = :emp_id))
    AND rr.assign_date BETWEEN :start AND :end");
$routeDaysStmt->execute(['emp_id'=>$employee_id,'start'=>$rangeStart,'end'=>$rangeEnd]);
$routeDays = (int)$routeDaysStmt->fetchColumn();
// Office attendance days (present=1, half_day=0.5)
$attStmt = $pdo->prepare("SELECT SUM(CASE WHEN status='present' THEN 1 WHEN status='half_day' THEN 0.5 ELSE 0 END) FROM attendance
    WHERE employee_id = ? AND work_date BETWEEN ? AND ?");
$attStmt->execute([$employee_id,$rangeStart,$rangeEnd]);
$officeDays = (float)$attStmt->fetchColumn();
$totalDays = $routeDays + $officeDays;
$totalPay = $totalDays * $employee['daily_rate'];
?>

<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="p-3 rounded-3 border bg-white shadow-sm">
            <div style="font-size:0.72rem; font-weight:700; color:var(--ios-label-2); text-transform:uppercase; margin-bottom:2px;">Route Days</div>
            <div id="statRouteDays" style="font-size:1.4rem; font-weight:800; color:#007AFF;"><?php echo $routeDays; ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="p-3 rounded-3 border bg-white shadow-sm">
            <div style="font-size:0.72rem; font-weight:700; color:var(--ios-label-2); text-transform:uppercase; margin-bottom:2px;">Office Days</div>
            <div id="statOfficeDays" style="font-size:1.4rem; font-weight:800; color:#34C759;"><?php echo $officeDays; ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="p-3 rounded-3 border bg-white shadow-sm">
            <div style="font-size:0.72rem; font-weight:700; color:var(--ios-label-2); text-transform:uppercase; margin-bottom:2px;">Total Days</div>
            <div id="statTotalDays" style="font-size:1.4rem; font-weight:800; color:#AF52DE;"><?php echo $totalDays; ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="p-3 rounded-3 border bg-white shadow-sm">
            <div style="font-size:0.72rem; font-weight:700; color:var(--ios-label-2); text-transform:uppercase; margin-bottom:2px;">Salary (Rs)</div>
            <div id="statSalary" style="font-size:1.4rem; font-weight:800; color:#1A9A3A;">Rs <?php echo number_format($totalPay,2); ?></div>
        </div>
    </div>
</div>

<div class="dash-card mb-4 overflow-hidden">
    <div class="dash-card-header d-flex justify-content-between align-items-center" style="background: var(--ios-surface); padding:18px 20px;">
        <span class="card-title">
            <span class="card-title-icon" style="background: rgba(0,122,255,0.1); color:#007AFF;">
                <i class="bi bi-calendar-event"></i>
            </span>
            <span>Month Calendar – <?php echo htmlspecialchars(date('F Y', strtotime($rangeStart))); ?></span>
        </span>
        <a href="payroll.php?month=<?php echo htmlspecialchars($selected_month); ?>" class="quick-btn quick-btn-primary" style="padding:6px 12px;">Go to Payroll</a>
    </div>
    <div class="p-3" style="background: var(--ios-bg);">
        <div class="border rounded-3 bg-white overflow-hidden shadow-sm">
            <div class="row g-0 text-center border-bottom bg-light py-2 fw-semibold text-muted" style="font-size:0.8rem; text-transform:uppercase; letter-spacing:0.05em; width:100%;">
                <div class="col-7th">Sun</div><div class="col-7th">Mon</div><div class="col-7th">Tue</div><div class="col-7th">Wed</div><div class="col-7th">Thu</div><div class="col-7th">Fri</div><div class="col-7th">Sat</div>
            </div>
            <div id="salaryCalendarBody" class="row g-0" style="width:100%;">
                <div class="col-12 py-5 text-center">
                    <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                    <span class="fw-medium text-muted">Loading calendar...</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const employeeId = <?php echo $employee_id; ?>;
    const monthStr = '<?php echo $selected_month; ?>';
    const rangeStart = '<?php echo $rangeStart; ?>';
    const rangeEnd = '<?php echo $rangeEnd; ?>';
    let calendarEvents = {};
    let calendarDate = new Date(rangeStart);
    const calendarBody = document.getElementById('salaryCalendarBody');
    const monthNameEl = document.querySelector('.dash-card-header span.card-title span');
    function loadCalendar(){
        const year = calendarDate.getFullYear();
        const month = String(calendarDate.getMonth()+1).padStart(2,'0');
        const monthKey = `${year}-${month}`;
        monthNameEl.textContent = `Month Calendar – ${calendarDate.toLocaleDateString('en-US',{month:'long',year:'numeric'})}`;
        calendarBody.innerHTML = `<div class="col-12 py-5 text-center"><div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div><span class="fw-medium text-muted">Loading...</span></div>`;
        fetch(`../ajax/get_employee_calendar.php?employee_id=${employeeId}&month=${monthKey}`)
            .then(r=>r.json())
            .then(data=>{
                if(data.success){
                    calendarEvents = data.events;
                    renderCalendar();
                }else{
                    calendarBody.innerHTML = `<div class="col-12 py-4 text-center text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i> ${data.message}</div>`;
                }
            })
            .catch(err=>{
                console.error(err);
                calendarBody.innerHTML = `<div class="col-12 py-4 text-center text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i> Failed to load.</div>`;
            });
    }
    function renderCalendar(){
        const year = calendarDate.getFullYear();
        const month = calendarDate.getMonth();
        const firstDay = new Date(year, month, 1);
        const startDow = firstDay.getDay();
        const totalDays = new Date(year, month+1, 0).getDate();
        const prevMonthDays = new Date(year, month, 0).getDate();
        const today = new Date();
        const todayStr = today.toISOString().split('T')[0];
        let html='';
        // previous month filler
        for(let i=startDow-1;i>=0;i--){
            const d = prevMonthDays - i;
            html+=`<div class="col-7th calendar-day-cell other-month"><div class="calendar-day-number">${d}</div></div>`;
        }
        // current month days
        for(let d=1; d<=totalDays; d++){
            const dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
            const isToday = dateStr===todayStr;
            let eventsHtml='';
            let hasRoute=false;
            let attStatus='';
            if(calendarEvents[dateStr]){
                const ev = calendarEvents[dateStr];
                if(ev.has_route && ev.routes.length){
                    hasRoute=true;
                    ev.routes.forEach(r=>{
                        const url=`route_detailed_report.php?id=${r.assignment_id}`;
                        eventsHtml+=`<a href="${url}" target="_blank" class="calendar-event-badge route" style="text-decoration:none;display:block;" title="Route: ${r.route_name} (${r.status})" onclick="event.stopPropagation();">🚚 ${r.route_name}</a>`;
                    });
                }
                if(ev.attendance_status){
                    attStatus = ev.attendance_status;
                    let badge='present', icon='🏢', label='Present (Office)';
                    if(attStatus==='half_day'){badge='half-day';label='Half Day';}
                    else if(attStatus==='absent'){badge='absent';icon='❌';label='Absent';}
                    eventsHtml+=`<div class="calendar-event-badge ${badge}" title="${label}">${icon} ${label}</div>`;
                }
            }
            let logBtn='';
            if(!hasRoute){
                logBtn=`<div class="quick-log-btn text-primary mt-auto"><i class="bi bi-pencil-square"></i> ${attStatus?'Edit':'Log Office'}</div>`;
            }
            html+=`<div class="col-7th calendar-day-cell ${isToday?'today':''}" onclick="openLogModal('${dateStr}',${hasRoute},'${attStatus}')">
                <div class="calendar-day-number">${d}</div>
                <div class="w-100 d-flex flex-column gap-1">${eventsHtml}</div>
                ${logBtn}
            </div>`;
        }
        // next month filler
        const rendered = startDow + totalDays;
        const remaining = (7 - (rendered % 7)) % 7;
        for(let i=1;i<=remaining;i++){
            html+=`<div class="col-7th calendar-day-cell other-month"><div class="calendar-day-number">${i}</div></div>`;
        }
        calendarBody.innerHTML = html;
    }
    function openLogModal(dateStr, hasRoute, currentStatus){
        if(hasRoute){
            alert('Route assigned – attendance already accounted for.');
            return;
        }
        // Reuse existing attendance modal from employees page via dynamic import? For simplicity we just redirect to attendance page.
        window.location.href = `attendance.php?employee_id=${employeeId}&date=${dateStr}`;
    }
    // Initial load
    loadCalendar();
</script>

<?php include '../includes/footer.php'; ?>
