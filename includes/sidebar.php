<?php
// Ensure auth functions exist
if (!function_exists('hasAccess')) {
    require_once __DIR__ . '/auth_check.php';
}

$cur = basename($_SERVER['PHP_SELF']);

// --- Module Group Mappings ---
$sales_pages     = ['sales_overview.php','create_order.php','orders_list.php','rep_targets.php','online_orders.php', 'create_quotation.php'];
$routes_pages    = ['dispatch.php', 'load_vehicle.php', 'vehicle_audit.php', 'territories.php', 'routes.php', 'route_sales.php', 'meter_readings.php'];
$pur_pages       = ['purchasing_overview.php','create_po.php','purchase_orders.php','create_grn.php','grn_list.php','stock_ledger.php'];
$setup_pages     = ['setup_overview.php','products.php','categories.php','main_categories.php','suppliers.php','product_gallery.php']; // Added main_categories.php
$fin_pages       = ['finance_overview.php','cheques.php','bank_cash.php','pnl_report.php','expenses.php','sales_returns.php', 'aging_reports.php']; // ADDED aging_reports.php
$hr_pages        = ['hr_overview.php','employees.php','payroll.php','salary_report.php']; 
$mkt_pages       = ['campaigns.php', 'promotions.php']; 
$analytics_pages = ['reports.php', 'promo_reports.php', 'agent_claims_report.php', 'category_sales.php', 'product_sales.php', 'area_sales.php', 'monthly_route_summary.php']; 
$tracking_pages  = ['live_tracking.php', 'route_tracking_history.php'];
?>

<style>
/* ─── iOS Sidebar Base ─────────────────────────────── */
#sidebarMenu { 
    width: 260px; 
    background: #FFFFFF; 
    border-right: 1px solid rgba(60,60,67,0.1); 
    overflow-y: auto; 
    overflow-x: hidden; 
    transition: width 0.3s cubic-bezier(0.2, 0, 0, 1); /* Smooth resize */
    scrollbar-width: none; /* Hide scrollbar for cleaner look */
}
#sidebarMenu::-webkit-scrollbar { display: none; }

.sb-inner { 
    padding: 16px 12px 40px; 
    display: flex;
    flex-direction: column;
}

/* Typography & Layout */
.sb-group-label { 
    font-size: 0.65rem; 
    font-weight: 700; 
    letter-spacing: 0.08em; 
    text-transform: uppercase; 
    color: rgba(60,60,67,0.4); 
    padding: 16px 10px 8px; 
    display: block; 
    transition: opacity 0.2s ease;
    white-space: nowrap;
}

.sb-link { 
    display: flex; 
    align-items: center; 
    gap: 12px; 
    padding: 8px 10px; 
    border-radius: 12px; 
    text-decoration: none; 
    font-size: 0.9rem; 
    font-weight: 600; 
    color: rgba(0,0,0,0.85); 
    transition: all 0.2s ease; 
    margin-bottom: 4px; 
    cursor: pointer;
    white-space: nowrap;
}
.sb-link:hover { background: rgba(60,60,67,0.06); color: #000; }
.sb-link.active { background: rgba(48,200,138,0.12); color: #1A8A5A; }
.sb-link .sb-icon { 
    width: 32px; 
    height: 32px; 
    border-radius: 8px; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    font-size: 1rem; 
    flex-shrink: 0; 
    transition: background 0.2s, color 0.2s; 
}
.sb-link .sb-label { flex: 1; transition: opacity 0.2s ease; }
.sb-link .sb-chevron { font-size: 0.7rem; color: rgba(60,60,67,0.3); transition: transform 0.3s ease; flex-shrink: 0; }
.sb-link.section-open .sb-chevron { transform: rotate(180deg); }

/* Color Themes for Icons */
.icon-green  { background: rgba(48,200,138,0.12); color: #25A872; }
.icon-blue   { background: rgba(0,122,255,0.10);  color: #007AFF; }
.icon-indigo { background: rgba(88,86,214,0.10);  color: #5856D6; }
.icon-orange { background: rgba(255,149,0,0.10);  color: #E07800; }
.icon-red    { background: rgba(255,59,48,0.10);  color: #CC2200; }
.icon-teal   { background: rgba(48,176,199,0.10); color: #1A8A9A; }
.icon-purple { background: rgba(175,82,222,0.10); color: #8B2BAA; }
.icon-gray   { background: rgba(60,60,67,0.08);   color: rgba(60,60,67,0.6); }

.sb-link.active .sb-icon { background: #30C88A; color: #fff; box-shadow: 0 2px 8px rgba(48,200,138,0.3); }

/* Submenus */
.sb-sub { list-style: none; padding: 4px 0 4px 44px; margin: 0 0 6px; }
.sb-sub a { 
    display: flex; 
    align-items: center; 
    gap: 8px; 
    padding: 8px 10px; 
    border-radius: 8px; 
    text-decoration: none; 
    font-size: 0.85rem; 
    font-weight: 500; 
    color: rgba(60,60,67,0.75); 
    transition: all 0.2s ease; 
    margin-bottom: 2px; 
    white-space: nowrap;
}
.sb-sub a:hover { background: rgba(60,60,67,0.06); color: #000; }
.sb-sub a.active { color: #1A8A5A; font-weight: 700; background: rgba(48,200,138,0.05); }
.sb-sub a .sub-dot { width: 6px; height: 6px; border-radius: 50%; background: rgba(60,60,67,0.25); flex-shrink: 0; transition: background 0.2s; }
.sb-sub a.active .sub-dot { background: #30C88A; box-shadow: 0 0 4px rgba(48,200,138,0.5); }

.sb-divider { height: 1px; background: rgba(60,60,67,0.08); margin: 16px 12px; }

/* ─── iOS Sidebar MINIMIZED STATE ─────────────────────────────── */
#sidebarMenu.minimized { 
    width: 80px; 
}
#sidebarMenu.minimized .sb-inner {
    padding: 16px 8px 40px; /* Tighter padding */
    align-items: center;
}
#sidebarMenu.minimized .sb-group-label,
#sidebarMenu.minimized .sb-label,
#sidebarMenu.minimized .sb-chevron { 
    display: none; 
    opacity: 0;
}
#sidebarMenu.minimized .sb-link { 
    padding: 10px; 
    justify-content: center; 
    width: 100%;
    margin-bottom: 8px;
}
#sidebarMenu.minimized .sb-link .sb-icon {
    width: 36px;
    height: 36px;
    font-size: 1.1rem;
}
#sidebarMenu.minimized .collapse { 
    display: none !important; /* Hide submenus completely */
}
#sidebarMenu.minimized .sb-divider {
    margin: 16px 0;
    width: 40px;
}
</style>

<nav id="sidebarMenu" class="sidebar d-md-block">
    <div class="position-sticky sb-inner">

        <?php if(hasAccess('dashboard.php')): ?>
        <a class="sb-link <?php echo $cur=='dashboard.php'?'active':''; ?>" href="dashboard.php">
            <span class="sb-icon icon-green"><i class="bi bi-house-fill"></i></span>
            <span class="sb-label">Dashboard</span>
        </a>
        <?php endif; ?>

        <?php if(canViewGroup(array_merge($sales_pages, $routes_pages, ['customers.php']))): ?>
        <span class="sb-group-label">Operations</span>

        <?php if(canViewGroup($sales_pages)): ?>
        <?php $is_sales = in_array($cur, $sales_pages); ?>
        <a class="sb-link <?php echo $is_sales?'active section-open':''; ?>" href="sales_overview.php" 
           data-bs-toggle="collapse" data-bs-target="#sub-sales" aria-expanded="<?php echo $is_sales?'true':'false'; ?>">
            <span class="sb-icon icon-green"><i class="bi bi-cart-check-fill"></i></span>
            <span class="sb-label">Sales &amp; Orders</span>
            <i class="bi bi-chevron-down sb-chevron"></i>
        </a>
        <div class="collapse <?php echo $is_sales?'show':''; ?>" id="sub-sales">
            <ul class="sb-sub">
                <?php if(hasAccess('create_order.php')): ?>
                <li><a href="create_order.php" class="<?php echo $cur=='create_order.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Create Order (POS)
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('create_quotation.php')): ?>
                <li><a href="create_quotation.php" class="<?php echo $cur=='create_quotation.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Create Quotation
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('online_orders.php')): ?>
                <li><a href="online_orders.php" class="<?php echo $cur=='online_orders.php'?'active':''; ?>">
                    <span class="sub-dot"></span> E-Commerce Orders
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('orders_list.php')): ?>
                <li><a href="orders_list.php" class="<?php echo $cur=='orders_list.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Sales History
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('rep_targets.php')): ?>
                <li><a href="rep_targets.php" class="<?php echo $cur=='rep_targets.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Rep Targets
                </a></li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if(canViewGroup($routes_pages)): ?>
        <?php $is_routes = in_array($cur, $routes_pages); ?>
        <a class="sb-link <?php echo $is_routes?'active section-open':''; ?>" href="routes.php"
           data-bs-toggle="collapse" data-bs-target="#sub-routes" aria-expanded="<?php echo $is_routes?'true':'false'; ?>">
            <span class="sb-icon icon-teal"><i class="bi bi-truck"></i></span>
            <span class="sb-label">Dispatch &amp; Routes</span>
            <i class="bi bi-chevron-down sb-chevron"></i>
        </a>
        <div class="collapse <?php echo $is_routes?'show':''; ?>" id="sub-routes">
            <ul class="sb-sub">
                <?php if(hasAccess('dispatch.php')): ?>
                <li><a href="dispatch.php" class="<?php echo $cur=='dispatch.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Vehicle Dispatch
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('load_vehicle.php')): ?>
                <li><a href="load_vehicle.php" class="<?php echo $cur=='load_vehicle.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Load Vehicle Stock
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('vehicle_audit.php')): ?>
                <li><a href="vehicle_audit.php" class="<?php echo $cur=='vehicle_audit.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Vehicle Stock Audit
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('territories.php')): ?>
                <li><a href="territories.php" class="<?php echo $cur=='territories.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Manage Territories
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('routes.php')): ?>
                <li><a href="routes.php" class="<?php echo $cur=='routes.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Manage Routes
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('route_sales.php')): ?>
                <li><a href="route_sales.php" class="<?php echo $cur=='route_sales.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Route Sales
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('meter_readings.php')): ?>
                <li><a href="meter_readings.php" class="<?php echo $cur=='meter_readings.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Meter Readings
                </a></li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if(hasAccess('customers.php')): ?>
        <a class="sb-link <?php echo $cur=='customers.php'?'active':''; ?>" href="customers.php">
            <span class="sb-icon icon-blue"><i class="bi bi-people-fill"></i></span>
            <span class="sb-label">Customers</span>
        </a>
        <?php endif; ?>

        <?php endif; ?>

        <?php if(canViewGroup(array_merge($pur_pages, $setup_pages))): ?>
        <span class="sb-group-label">Inventory</span>

        <?php if(canViewGroup($pur_pages)): ?>
        <?php $is_pur = in_array($cur, $pur_pages); ?>
        <a class="sb-link <?php echo $is_pur?'active section-open':''; ?>" href="purchasing_overview.php"
           data-bs-toggle="collapse" data-bs-target="#sub-pur" aria-expanded="<?php echo $is_pur?'true':'false'; ?>">
            <span class="sb-icon icon-orange"><i class="bi bi-box-arrow-in-down"></i></span>
            <span class="sb-label">Purchasing</span>
            <i class="bi bi-chevron-down sb-chevron"></i>
        </a>
        <div class="collapse <?php echo $is_pur?'show':''; ?>" id="sub-pur">
            <ul class="sb-sub">
                <?php if(hasAccess('purchase_orders.php')): ?>
                <li><a href="purchase_orders.php" class="<?php echo $cur=='purchase_orders.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Purchase Orders
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('create_grn.php')): ?>
                <li><a href="create_grn.php" class="<?php echo $cur=='create_grn.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Receive Goods (GRN)
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('grn_list.php')): ?>
                <li><a href="grn_list.php" class="<?php echo $cur=='grn_list.php'?'active':''; ?>">
                    <span class="sub-dot"></span> GRN History
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('stock_ledger.php')): ?>
                <li><a href="stock_ledger.php" class="<?php echo $cur=='stock_ledger.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Stock Ledger &amp; Adj.
                </a></li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if(canViewGroup($setup_pages)): ?>
        <?php $is_setup = in_array($cur, $setup_pages); ?>
        <a class="sb-link <?php echo $is_setup?'active section-open':''; ?>" href="setup_overview.php"
           data-bs-toggle="collapse" data-bs-target="#sub-setup" aria-expanded="<?php echo $is_setup?'true':'false'; ?>">
            <span class="sb-icon icon-indigo"><i class="bi bi-grid-1x2-fill"></i></span>
            <span class="sb-label">Catalogue Setup</span>
            <i class="bi bi-chevron-down sb-chevron"></i>
        </a>
        <div class="collapse <?php echo $is_setup?'show':''; ?>" id="sub-setup">
            <ul class="sb-sub">
                <?php if(hasAccess('products.php')): ?>
                <li><a href="products.php" class="<?php echo $cur=='products.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Products
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('categories.php')): ?>
                <li><a href="categories.php" class="<?php echo $cur=='categories.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Sub Categories
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('main_categories.php')): ?>
                <li><a href="main_categories.php" class="<?php echo $cur=='main_categories.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Main Categories
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('suppliers.php')): ?>
                <li><a href="suppliers.php" class="<?php echo $cur=='suppliers.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Suppliers
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('product_gallery.php')): ?>
                <li><a href="product_gallery.php" class="<?php echo $cur=='product_gallery.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Digital Catalog
                </a></li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php endif; ?>

        <?php if(canViewGroup($fin_pages)): ?>
        <span class="sb-group-label">Finance</span>
        <?php $is_fin = in_array($cur, $fin_pages); ?>
        <a class="sb-link <?php echo $is_fin?'active section-open':''; ?>" href="finance_overview.php"
           data-bs-toggle="collapse" data-bs-target="#sub-fin" aria-expanded="<?php echo $is_fin?'true':'false'; ?>">
            <span class="sb-icon icon-red"><i class="bi bi-bank"></i></span>
            <span class="sb-label">Finance Core</span>
            <i class="bi bi-chevron-down sb-chevron"></i>
        </a>
        <div class="collapse <?php echo $is_fin?'show':''; ?>" id="sub-fin">
            <ul class="sb-sub">
                <?php if(hasAccess('bank_cash.php')): ?>
                <li><a href="bank_cash.php" class="<?php echo $cur=='bank_cash.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Bank &amp; Cash Ledgers
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('cheques.php')): ?>
                <li><a href="cheques.php" class="<?php echo $cur=='cheques.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Manage Cheques
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('expenses.php')): ?>
                <li><a href="expenses.php" class="<?php echo $cur=='expenses.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Company Expenses
                </a></li>
                <?php endif; ?>
                
                <!-- NEW AGING REPORTS LINK HERE -->
                <?php if(hasAccess('aging_reports.php')): ?>
                <li><a href="aging_reports.php" class="<?php echo $cur=='aging_reports.php'?'active':''; ?>">
                    <span class="sub-dot"></span> AR / AP Aging
                </a></li>
                <?php endif; ?>

                <?php if(hasAccess('sales_returns.php')): ?>
                <li><a href="sales_returns.php" class="<?php echo $cur=='sales_returns.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Returns &amp; Credits
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('pnl_report.php')): ?>
                <li><a href="pnl_report.php" class="<?php echo $cur=='pnl_report.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Profit &amp; Loss
                </a></li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if(canViewGroup($tracking_pages)): ?>
        <span class="sb-group-label">Location Tracking</span>
        <?php $is_tracking = in_array($cur, $tracking_pages); ?>
        <a class="sb-link <?php echo $is_tracking?'active section-open':''; ?>" href="live_tracking.php"
           data-bs-toggle="collapse" data-bs-target="#sub-tracking" aria-expanded="<?php echo $is_tracking?'true':'false'; ?>">
            <span class="sb-icon icon-teal"><i class="bi bi-geo-alt-fill"></i></span>
            <span class="sb-label">Rep Tracking</span>
            <i class="bi bi-chevron-down sb-chevron"></i>
        </a>
        <div class="collapse <?php echo $is_tracking?'show':''; ?>" id="sub-tracking">
            <ul class="sb-sub">
                <?php if(hasAccess('live_tracking.php')): ?>
                <li><a href="live_tracking.php" class="<?php echo $cur=='live_tracking.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Live Location
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('route_tracking_history.php')): ?>
                <li><a href="route_tracking_history.php" class="<?php echo $cur=='route_tracking_history.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Route History
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('customer_map.php')): ?>
                <li><a href="customer_map.php" class="<?php echo $cur=='customer_map.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Customer Map
                </a></li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if(canViewGroup(array_merge($hr_pages, $mkt_pages, $analytics_pages))): ?>
        <span class="sb-group-label">People &amp; Growth</span>

        <?php if(canViewGroup($hr_pages)): ?>
        <?php $is_hr = in_array($cur, $hr_pages); ?>
        <a class="sb-link <?php echo $is_hr?'active section-open':''; ?>" href="hr_overview.php"
           data-bs-toggle="collapse" data-bs-target="#sub-hr" aria-expanded="<?php echo $is_hr?'true':'false'; ?>">
            <span class="sb-icon icon-purple"><i class="bi bi-person-lines-fill"></i></span>
            <span class="sb-label">HR &amp; Team</span>
            <i class="bi bi-chevron-down sb-chevron"></i>
        </a>
        <div class="collapse <?php echo $is_hr?'show':''; ?>" id="sub-hr">
            <ul class="sb-sub">
                <?php if(hasAccess('employees.php')): ?>
                <li><a href="employees.php" class="<?php echo $cur=='employees.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Employees
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('payroll.php')): ?>
                <li><a href="payroll.php" class="<?php echo $cur=='payroll.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Payroll &amp; Salaries
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('salary_report.php')): ?>
                <li><a href="salary_report.php" class="<?php echo $cur=='salary_report.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Salary Report
                </a></li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if(canViewGroup($mkt_pages)): ?>
        <?php $is_mkt = in_array($cur, $mkt_pages); ?>
        <a class="sb-link <?php echo $is_mkt?'active section-open':''; ?>" href="campaigns.php"
           data-bs-toggle="collapse" data-bs-target="#sub-mkt" aria-expanded="<?php echo $is_mkt?'true':'false'; ?>">
            <span class="sb-icon icon-mint"><i class="bi bi-megaphone-fill"></i></span>
            <span class="sb-label">Marketing &amp; Promos</span>
            <i class="bi bi-chevron-down sb-chevron"></i>
        </a>
        <div class="collapse <?php echo $is_mkt?'show':''; ?>" id="sub-mkt">
            <ul class="sb-sub">
                <?php if(hasAccess('campaigns.php')): ?>
                <li><a href="campaigns.php" class="<?php echo $cur=='campaigns.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Email Campaigns
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('promotions.php')): ?>
                <li><a href="promotions.php" class="<?php echo $cur=='promotions.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Promotions Engine
                </a></li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if(canViewGroup($analytics_pages)): ?>
        <?php $is_analytics = in_array($cur, $analytics_pages); ?>
        <a class="sb-link <?php echo $is_analytics?'active section-open':''; ?>" href="reports.php"
           data-bs-toggle="collapse" data-bs-target="#sub-analytics" aria-expanded="<?php echo $is_analytics?'true':'false'; ?>">
            <span class="sb-icon icon-blue"><i class="bi bi-bar-chart-fill"></i></span>
            <span class="sb-label">Analytics &amp; Reports</span>
            <i class="bi bi-chevron-down sb-chevron"></i>
        </a>
        <div class="collapse <?php echo $is_analytics?'show':''; ?>" id="sub-analytics">
            <ul class="sb-sub">
                <?php if(hasAccess('reports.php')): ?>
                <li><a href="reports.php" class="<?php echo $cur=='reports.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Sales Analytics
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('monthly_route_summary.php')): ?>
                <li><a href="monthly_route_summary.php" class="<?php echo $cur=='monthly_route_summary.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Monthly Route Summary
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('promo_reports.php')): ?>
                <li><a href="promo_reports.php" class="<?php echo $cur=='promo_reports.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Promos &amp; FOC Report
                </a></li>
                <?php endif; ?>
				<?php if(hasAccess('agent_claims_report.php')): ?>
                <li><a href="agent_claims_report.php" class="<?php echo $cur=='agent_claims_report.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Agent Profit Claims
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('category_sales.php')): ?>
                <li><a href="category_sales.php" class="<?php echo $cur=='category_sales.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Category Wise Sales
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('product_sales.php')): ?>
                <li><a href="product_sales.php" class="<?php echo $cur=='product_sales.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Product Wise Sales
                </a></li>
                <?php endif; ?>
                <?php if(hasAccess('area_sales.php')): ?>
                <li><a href="area_sales.php" class="<?php echo $cur=='area_sales.php'?'active':''; ?>">
                    <span class="sub-dot"></span> Area Wise Sales
                </a></li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php endif; ?>

        <?php if(hasRole(['admin'])): ?>
        <div class="sb-divider"></div>
        <span class="sb-group-label">Administration</span>

        <a class="sb-link <?php echo $cur=='users.php'?'active':''; ?>" href="users.php">
            <span class="sb-icon icon-gray"><i class="bi bi-person-gear"></i></span>
            <span class="sb-label">System Users</span>
        </a>

        <a class="sb-link danger-link" href="backup.php" target="_blank" title="Download Database Backup">
            <span class="sb-icon"><i class="bi bi-database-down"></i></span>
            <span class="sb-label">Database Backup</span>
        </a>
        <a class="sb-link" href="../admin_app/dashboard.php">
            <span class="sb-icon icon-blue"><i class="bi bi-phone"></i></span>
            <span class="sb-label">Switch to Mobile App</span>
        </a>
        <?php endif; ?>

    </div>
</nav>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebarMenu');
    const links = sidebar.querySelectorAll('.sb-link');
    
    // Auto-maximize sidebar when a menu link is clicked while minimized
    links.forEach(link => {
        link.addEventListener('click', function(e) {
            if (sidebar.classList.contains('minimized') || document.body.classList.contains('sidebar-minimized')) {
                // Remove minimized state first
                sidebar.classList.remove('minimized');
                document.body.classList.remove('sidebar-minimized');
            }
        });
    });

    // Update Chevron animation on Submenu Open/Close
    const collapses = document.querySelectorAll('.collapse');
    collapses.forEach(collapse => {
        collapse.addEventListener('show.bs.collapse', function () {
            // Ensure sidebar expands if a sub-menu is triggered while minimized
            if (sidebar.classList.contains('minimized') || document.body.classList.contains('sidebar-minimized')) {
                sidebar.classList.remove('minimized');
                document.body.classList.remove('sidebar-minimized');
            }
            const link = document.querySelector(`[data-bs-target="#${this.id}"]`);
            if(link) link.classList.add('section-open');
        });
        collapse.addEventListener('hide.bs.collapse', function () {
            const link = document.querySelector(`[data-bs-target="#${this.id}"]`);
            if(link) link.classList.remove('section-open');
        });
    });
});
</script>

<main id="mainContent" class="px-md-4 pb-5" style="transition: margin-left 0.3s cubic-bezier(0.2, 0, 0, 1);">