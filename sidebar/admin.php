<?php
/**
 * Admin Sidebar
 *
 * A single overview of every module in the system. Individual roles keep their own
 * focused sidebars (custodian, supply_incharge, gso, purchasing) — this one is for
 * administrators who need to reach any page.
 *
 * Links are grouped by department so the list stays scannable as it grows.
 */
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!-- Tailwind CSS CDN (Optional: Remove if already in header) -->
<script src="https://cdn.tailwindcss.com"></script>

<div class="sidebar no-print flex flex-col h-screen w-64 bg-[#073b1d] text-white fixed left-0 top-0 z-50 shadow-2xl transition-all duration-300 ease-in-out">
    <!-- Sidebar Header -->
    <div class="p-6 border-b border-white/10">
        <div class="flex items-center space-x-3">
            <div class="w-10 h-10 bg-yellow-400 rounded-lg flex items-center justify-center text-[#073b1d]">
                <i class="fas fa-user-shield text-xl"></i>
            </div>
            <div>
                <h1 class="text-xl font-bold tracking-wider">DARTS</h1>
                <p class="text-[10px] text-white/60 uppercase tracking-tighter">Administrator</p>
            </div>
        </div>
    </div>

    <!-- Navigation Links -->
    <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-1 custom-scrollbar">
        <!-- Dashboard -->
        <a href="../dashboard.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'dashboard.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-th-large w-6 <?= ($current_page == 'dashboard.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Dashboard</span>
        </a>
        <div class="pt-4 pb-2 px-4">
            <p class="text-[10px] text-white/40 uppercase font-semibold tracking-widest">Procurement &amp; Purchasing</p>
        </div>

        <a href="procurement_statistics.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'procurement_statistics.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-chart-line w-6 <?= ($current_page == 'procurement_statistics.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Procurement Statistics</span>
        </a>

        <a href="suppliers.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'suppliers.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-truck w-6 <?= ($current_page == 'suppliers.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Supplier List</span>
        </a>

        <a href="procurement.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'procurement.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-table w-6 <?= ($current_page == 'procurement.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Procurement Tables</span>
        </a>

        <a href="canvass_form.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'canvass_form.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-clipboard w-6 <?= ($current_page == 'canvass_form.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Canvass Form</span>
        </a>

        <a href="canvass_form_list.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'canvass_form_list.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-list-ul w-6 <?= ($current_page == 'canvass_form_list.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Canvass Form List</span>
        </a>

        <a href="purchase_order.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'purchase_order.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-shopping-bag w-6 <?= ($current_page == 'purchase_order.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Purchase Order</span>
        </a>

        <a href="purchase_order_list.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'purchase_order_list.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-clipboard-check w-6 <?= ($current_page == 'purchase_order_list.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Purchase Order List</span>
        </a>

        <a href="received_items.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'received_items.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-box-open w-6 <?= ($current_page == 'received_items.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Received Items</span>
        </a>
        <div class="pt-4 pb-2 px-4">
            <p class="text-[10px] text-white/40 uppercase font-semibold tracking-widest">Supply Office</p>
        </div>

        <a href="Inventory.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'Inventory.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-archive w-6 <?= ($current_page == 'Inventory.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Supply Inventory</span>
        </a>

        <a href="office_inventory.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'office_inventory.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-file-invoice w-6 <?= ($current_page == 'office_inventory.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Office Inventory Form</span>
        </a>

        <a href="issuance.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'issuance.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-file-export w-6 <?= ($current_page == 'issuance.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Supply Issuance</span>
        </a>

        <a href="supply_request.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'supply_request.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-box w-6 <?= ($current_page == 'supply_request.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Supply Requests</span>
        </a>

        <a href="supply_offices_request.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'supply_offices_request.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-sitemap w-6 <?= ($current_page == 'supply_offices_request.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Supply Office Requests</span>
        </a>

        <a href="employee_inventory_list.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'employee_inventory_list.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-users w-6 <?= ($current_page == 'employee_inventory_list.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Employee Inventory</span>
        </a>
        <div class="pt-4 pb-2 px-4">
            <p class="text-[10px] text-white/40 uppercase font-semibold tracking-widest">Property Office</p>
        </div>

        <a href="property_inventory.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'property_inventory.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-boxes w-6 <?= ($current_page == 'property_inventory.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Property Inventory</span>
        </a>

        <a href="rooms_inventory.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'rooms_inventory.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-door-open w-6 <?= ($current_page == 'rooms_inventory.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Rooms Inventory</span>
        </a>

        <a href="property_request.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'property_request.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-building w-6 <?= ($current_page == 'property_request.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Property Requests</span>
        </a>

        <a href="property_issuance.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'property_issuance.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-file-export w-6 <?= ($current_page == 'property_issuance.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Property Issuance</span>
        </a>

        <a href="other_property_logs.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'other_property_logs.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-clipboard-list w-6 <?= ($current_page == 'other_property_logs.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Other Property Logs</span>
        </a>

        <a href="equipment_transfer_request.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'equipment_transfer_request.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-exchange-alt w-6 <?= ($current_page == 'equipment_transfer_request.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Transfer Request</span>
        </a>

        <a href="borrowers_forms.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'borrowers_forms.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-user-tag w-6 <?= ($current_page == 'borrowers_forms.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Borrowers Form</span>
        </a>

        <div class="pt-4 pb-2 px-4">
            <p class="text-[10px] text-white/40 uppercase font-semibold tracking-widest">Release Records</p>
        </div>

        <a href="bulb_release_logs.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'bulb_release_logs.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-lightbulb w-6 <?= ($current_page == 'bulb_release_logs.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Supply Release Logs</span>
        </a>

        <a href="property_release_logs.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'property_release_logs.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-key w-6 <?= ($current_page == 'property_release_logs.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Property Release Logs</span>
        </a>
        <div class="pt-4 pb-2 px-4">
            <p class="text-[10px] text-white/40 uppercase font-semibold tracking-widest">General Services</p>
        </div>

        <a href="aircon_list.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'aircon_list.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-fan w-6 <?= ($current_page == 'aircon_list.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Aircons</span>
        </a>

        <a href="maintenance.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'maintenance.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-tools w-6 <?= ($current_page == 'maintenance.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Maintenance</span>
        </a>

        <a href="service_forms.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'service_forms.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-file-signature w-6 <?= ($current_page == 'service_forms.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Service Forms</span>
        </a>

        <div class="pt-4 pb-2 px-4">
            <p class="text-[10px] text-white/40 uppercase font-semibold tracking-widest">Reports</p>
        </div>

        <a href="supply_reports.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'supply_reports.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-chart-pie w-6 <?= ($current_page == 'supply_reports.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Supply Reports</span>
        </a>

        <a href="property_reports.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'property_reports.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-chart-bar w-6 <?= ($current_page == 'property_reports.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Property Reports</span>
        </a>

        <a href="service_form_reports.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'service_form_reports.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-chart-column w-6 <?= ($current_page == 'service_form_reports.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Service Form Reports</span>
        </a>

        <a href="transaction_list.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'transaction_list.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-list-check w-6 <?= ($current_page == 'transaction_list.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Transaction List</span>
        </a>
        <div class="pt-4 pb-2 px-4">
            <p class="text-[10px] text-white/40 uppercase font-semibold tracking-widest">Administration</p>
        </div>

        <a href="users.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'users.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-users-cog w-6 <?= ($current_page == 'users.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">User Accounts</span>
        </a>

        <a href="positions.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'positions.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-id-badge w-6 <?= ($current_page == 'positions.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Positions</span>
        </a>

        <a href="school_year.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'school_year.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-calendar-alt w-6 <?= ($current_page == 'school_year.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">School Year</span>
        </a>

        <a href="budget.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'budget.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-coins w-6 <?= ($current_page == 'budget.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Budget</span>
        </a>

        <a href="printer_header.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'printer_header.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-print w-6 <?= ($current_page == 'printer_header.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Printer Header</span>
        </a>

        <a href="notifications.php"
           class="flex items-center px-4 py-3 rounded-xl transition-all duration-200 group <?= ($current_page == 'notifications.php') ? 'bg-yellow-400 text-[#073b1d] shadow-lg' : 'hover:bg-white/10' ?>">
            <i class="fas fa-bell w-6 <?= ($current_page == 'notifications.php') ? 'text-[#073b1d]' : 'text-white/70 group-hover:text-white' ?>"></i>
            <span class="font-medium">Notifications</span>
        </a>
    </nav>

    <!-- User Section -->
    <div class="p-4 border-t border-white/10 bg-black/10">
        <div class="flex items-center space-x-3 mb-4">
            <div class="w-8 h-8 rounded-full bg-yellow-400 flex items-center justify-center text-[#073b1d] font-bold text-xs">
                <?= strtoupper(substr($_SESSION['user']['first_name'] ?? 'U', 0, 1)) ?>
            </div>
            <div class="flex-1 truncate">
                <p class="text-sm font-semibold truncate"><?= htmlspecialchars($_SESSION['user']['first_name'] ?? 'User') ?></p>
                <p class="text-[10px] text-white/50 truncate">Administrator</p>
            </div>
        </div>
        <a href="../logout.php" class="flex items-center px-4 py-2 text-xs text-red-400 hover:bg-red-400/10 rounded-lg transition-colors group">
            <i class="fas fa-sign-out-alt mr-2 group-hover:translate-x-1 transition-transform"></i>
            Logout
        </a>
    </div>
</div>

<style>
    .custom-scrollbar::-webkit-scrollbar {
        width: 4px;
    }
    .custom-scrollbar::-webkit-scrollbar-track {
        background: transparent;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
    }
</style>
