<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/soft_delete_helper.php';
require_once __DIR__ . '/../includes/security_bootstrap.php';
requireRole(1); // admin

require_login();
check_role(1);

$admin = get_employee_info($conn, $_SESSION['employee_id']);

// Get statistics - ALL EXISTING LOGIC PRESERVED
$total_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE status = 'active'")->fetch_assoc()['count'];
$total_employees = get_total_employees($conn);
$total_departments = $conn->query("SELECT COUNT(*) as count FROM departments WHERE is_deleted = FALSE")->fetch_assoc()['count'];
$pending_leaves = count_pending_leaves($conn);
$today_attendance = $conn->query("SELECT COUNT(*) as count FROM attendance WHERE attendance_date = CURDATE()")->fetch_assoc()['count'];
$recycle_bin_count = getRecycleBinCount($conn);

// Get pending payroll
$pending_payroll = $conn->query("SELECT COUNT(*) as count FROM payroll WHERE status = 'pending' AND payroll_month = MONTH(NOW()) AND payroll_year = YEAR(NOW())")->fetch_assoc()['count'];

// Get total announcements count (not is_active, just count all)
$total_announcements = $conn->query("SELECT COUNT(*) as count FROM announcements")->fetch_assoc()['count'];

// Get recent imports
$recent_imports = [];
$import_check = $conn->query("SHOW TABLES LIKE 'biometric_import_logs'");
if ($import_check->num_rows > 0) {
    $imports = $conn->query("SELECT import_id, import_date, filename, successful_records FROM biometric_import_logs ORDER BY import_date DESC LIMIT 5");
    $recent_imports = $imports ? $imports->fetch_all(MYSQLI_ASSOC) : [];
}

// Get current page for active menu highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR Admin Dashboard - HRGetafe</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* ============ ROOT STYLES ============ */
        :root {
            --primary-color: #667eea;
            --primary-dark: #5568d3;
            --secondary-color: #764ba2;
            --danger-color: #ef4444;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --info-color: #3b82f6;
            --sidebar-width: 270px;
            --sidebar-width-collapsed: 80px;
            --transition-speed: 0.3s;
        }

        /* ============ BODY & LAYOUT ============ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Verdana, sans-serif;
            background: #f8f9fa;
            color: #2c3e50;
        }

        .admin-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* ============ SIDEBAR STYLES ============ */
        .admin-sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, #2c3e50 0%, #34495e 100%);
            color: #ecf0f1;
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: width var(--transition-speed) ease;
            box-shadow: 3px 0 15px rgba(0, 0, 0, 0.15);
            display: flex;
            flex-direction: column;
        }

        .admin-sidebar.collapsed {
            width: var(--sidebar-width-collapsed);
        }

        /* Sidebar Header */
        .sidebar-header {
            padding: 25px 15px;
            border-bottom: 2px solid rgba(255, 255, 255, 0.1);
            text-align: center;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            position: relative;
            flex-shrink: 0;
        }

        .sidebar-logo {
            font-size: 28px;
            font-weight: 700;
            color: white;
            margin-bottom: 5px;
            transition: opacity var(--transition-speed) ease;
        }

        .sidebar-logo-text {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.8);
            opacity: 1;
            transition: opacity var(--transition-speed) ease;
            display: block;
            letter-spacing: 0.5px;
        }

        .admin-sidebar.collapsed .sidebar-logo-text {
            opacity: 0;
            display: none;
        }

        /* Toggle Button */
        .sidebar-toggle {
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 35px;
            height: 35px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            transition: all var(--transition-speed) ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .sidebar-toggle:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.05);
        }

        /* ============ SIDEBAR MENU ============ */
        .sidebar-menu {
            list-style: none;
            padding: 20px 0;
            margin: 0;
            flex: 1;
            overflow-y: auto;
        }

        .sidebar-menu-section {
            margin-bottom: 5px;
        }

        .sidebar-section-title {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.45);
            padding: 15px 15px 8px 15px;
            letter-spacing: 1px;
            opacity: 1;
            transition: opacity var(--transition-speed) ease;
        }

        .admin-sidebar.collapsed .sidebar-section-title {
            opacity: 0;
            padding: 10px 5px;
            font-size: 7px;
            height: 0;
            overflow: hidden;
        }

        .sidebar-menu-item {
            position: relative;
        }

        .sidebar-menu-link {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px 15px;
            color: rgba(255, 255, 255, 0.75);
            text-decoration: none;
            transition: all var(--transition-speed) ease;
            border-left: 3px solid transparent;
            font-size: 14px;
            cursor: pointer;
            font-weight: 500;
        }

        .sidebar-menu-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: var(--primary-color);
            padding-left: 20px;
        }

        .sidebar-menu-link.active {
            background: rgba(102, 126, 234, 0.25);
            color: white;
            border-left-color: var(--primary-color);
            font-weight: 600;
            box-shadow: inset 0 0 10px rgba(102, 126, 234, 0.1);
        }

        .sidebar-menu-link i {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }

        .sidebar-menu-text {
            flex: 1;
            opacity: 1;
            transition: opacity var(--transition-speed) ease;
            white-space: nowrap;
            overflow: hidden;
        }

        .admin-sidebar.collapsed .sidebar-menu-text {
            opacity: 0;
            width: 0;
        }

        .sidebar-menu-link.submenu-toggle::after {
            content: '';
            width: 0;
            height: 0;
            border-left: 4px solid transparent;
            border-right: 4px solid transparent;
            border-top: 5px solid rgba(255, 255, 255, 0.75);
            transition: transform var(--transition-speed) ease;
            margin-left: auto;
        }

        .sidebar-menu-link.submenu-toggle.active::after {
            transform: rotate(180deg);
        }

        /* ============ SUBMENU ============ */
        .sidebar-submenu {
            display: none;
            background: rgba(0, 0, 0, 0.15);
            list-style: none;
            margin: 0;
            padding: 0;
            animation: slideDown 0.3s ease;
            border-left: 3px solid var(--info-color);
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                max-height: 0;
            }
            to {
                opacity: 1;
                max-height: 500px;
            }
        }

        .sidebar-submenu.show {
            display: block;
        }

        .sidebar-submenu-item {
            position: relative;
        }

        .sidebar-submenu-link {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 10px 15px 10px 45px;
            color: rgba(255, 255, 255, 0.6);
            text-decoration: none;
            font-size: 13px;
            transition: all var(--transition-speed) ease;
            border-left: 2px solid transparent;
        }

        .sidebar-submenu-link:hover {
            background: rgba(255, 255, 255, 0.05);
            color: white;
            border-left-color: var(--info-color);
            padding-left: 50px;
        }

        .sidebar-submenu-link.active {
            color: var(--primary-color);
            border-left-color: var(--primary-color);
            font-weight: 600;
            background: rgba(102, 126, 234, 0.15);
        }

        .admin-sidebar.collapsed .sidebar-submenu-link {
            padding: 10px 15px;
        }

        /* ============ SIDEBAR FOOTER (LOGOUT) ============ */
        .sidebar-footer {
            padding: 15px;
            border-top: 2px solid rgba(255, 255, 255, 0.1);
            background: rgba(0, 0, 0, 0.2);
            flex-shrink: 0;
            margin-top: auto;
        }

        .sidebar-user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            margin-bottom: 10px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 6px;
            opacity: 1;
            transition: opacity var(--transition-speed) ease;
        }

        .admin-sidebar.collapsed .sidebar-user-info {
            opacity: 0;
        }

        .sidebar-user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .sidebar-user-details {
            flex: 1;
            overflow: hidden;
        }

        .sidebar-user-name {
            font-size: 12px;
            font-weight: 600;
            color: white;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-user-role {
            font-size: 10px;
            color: rgba(255, 255, 255, 0.7);
            margin-top: 2px;
        }

        .sidebar-logout-btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, var(--danger-color), #dc2626);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all var(--transition-speed) ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }

        .sidebar-logout-btn:hover {
            background: linear-gradient(135deg, #dc2626, #991b1b);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        .admin-sidebar.collapsed .sidebar-logout-btn {
            padding: 12px 8px;
            font-size: 0;
        }

        .admin-sidebar.collapsed .sidebar-logout-btn i {
            font-size: 16px;
            margin: 0;
        }

        /* ============ MAIN CONTENT AREA ============ */
        .admin-main {
            flex: 1;
            margin-left: var(--sidebar-width);
            transition: margin-left var(--transition-speed) ease;
            display: flex;
            flex-direction: column;
        }

        .admin-sidebar.collapsed ~ .admin-main {
            margin-left: var(--sidebar-width-collapsed);
        }

        /* ============ TOP NAVBAR ============ */
        .admin-navbar {
            background: white;
            padding: 18px 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e0e7ff;
        }

        .navbar-left h2 {
            color: #2c3e50;
            font-size: 26px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
        }

        .navbar-left i {
            color: var(--primary-color);
        }

        .navbar-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .navbar-user-name {
            font-size: 14px;
            color: #2c3e50;
        }

        .navbar-user-name strong {
            color: var(--primary-color);
            font-weight: 600;
        }

        /* ============ CONTENT AREA ============ */
        .admin-content {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
            background: #f8f9fa;
        }

        /* ============ DASHBOARD CONTENT STYLES ============ */
        .dashboard-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
            gap: 18px; 
            margin-bottom: 35px; 
        }

        .stat-box { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            color: white; 
            padding: 24px; 
            border-radius: 10px; 
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.2);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-box::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .stat-box:hover {
            transform: translateY(-8px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        .stat-box h4 { 
            margin: 0; 
            font-size: 13px; 
            opacity: 0.95;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-number { 
            font-size: 36px; 
            font-weight: bold; 
            margin: 12px 0 0 0;
            position: relative;
            z-index: 1;
        }

        .stat-box.warning { 
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); 
        }

        .stat-box.danger { 
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); 
        }

        .stat-box.success { 
            background: linear-gradient(135deg, #10b981 0%, #059669 100%); 
        }

        .stat-box.info { 
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); 
        }
        
        .card { 
            background: white; 
            padding: 25px; 
            border-radius: 10px; 
            box-shadow: 0 2px 8px rgba(0,0,0,0.08); 
            margin-bottom: 25px;
            border-top: 4px solid var(--primary-color);
        }

        .card h3 { 
            margin-top: 0; 
            color: #1f2937; 
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 15px;
            font-size: 18px;
            font-weight: 700;
        }

        .card p {
            color: #666;
            margin: 15px 0;
            font-size: 14px;
        }
        
        .activity-list { 
            list-style: none; 
            padding: 0; 
            margin: 0; 
        }

        .activity-list li { 
            padding: 12px 0; 
            border-bottom: 1px solid #f0f0f0;
            transition: all 0.2s ease;
        }

        .activity-list li:hover {
            padding-left: 5px;
        }

        .activity-list li:last-child { 
            border-bottom: none; 
        }

        .activity-time { 
            color: #999; 
            font-size: 12px;
            margin-top: 4px;
            display: block;
        }

        .activity-list a {
            color: var(--primary-color);
            font-weight: 600;
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .activity-list a:hover {
            color: var(--secondary-color);
            text-decoration: underline;
        }
        
        .alert-box { 
            padding: 16px 18px; 
            border-radius: 8px; 
            margin-bottom: 20px;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-warning { 
            background: #fef3c7; 
            border-left-color: #f59e0b;
            color: #92400e; 
        }

        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
        }

        /* ============ RESPONSIVE ============ */
        @media (max-width: 1024px) {
            :root {
                --sidebar-width: 250px;
            }

            .admin-content {
                padding: 20px;
            }
        }

        @media (max-width: 768px) {
            :root {
                --sidebar-width: 240px;
            }

            .admin-sidebar {
                transform: translateX(-100%);
                transition: transform var(--transition-speed) ease;
                box-shadow: 2px 0 15px rgba(0, 0, 0, 0.2);
            }

            .admin-sidebar.mobile-open {
                transform: translateX(0);
            }

            .admin-main {
                margin-left: 0;
            }

            .admin-navbar {
                padding: 12px 18px;
            }

            .navbar-left h2 {
                font-size: 20px;
            }

            .admin-content {
                padding: 15px;
            }

            .dashboard-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .grid-2 {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .admin-content {
                padding: 10px;
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .card {
                padding: 15px;
            }

            .sidebar-logo {
                font-size: 24px;
            }
        }

        /* ============ SCROLLBAR ============ */
        .admin-sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .admin-sidebar::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
        }

        .admin-sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 3px;
        }

        .admin-sidebar::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .admin-content::-webkit-scrollbar {
            width: 8px;
        }

        .admin-content::-webkit-scrollbar-track {
            background: #f0f0f0;
        }

        .admin-content::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 4px;
        }

        .admin-content::-webkit-scrollbar-thumb:hover {
            background: #999;
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <!-- ============ SIDEBAR ============ -->
        <aside class="admin-sidebar" id="adminSidebar">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>

            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-briefcase"></i>
                </div>
                <span class="sidebar-logo-text">Admin Panel</span>
            </div>

            <ul class="sidebar-menu">
                <!-- Dashboard -->
                <li class="sidebar-menu-section">
                    <a href="dashboard.php" class="sidebar-menu-link <?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
                        <i class="fas fa-home"></i>
                        <span class="sidebar-menu-text">Dashboard</span>
                    </a>
                </li>

                <!-- System Management -->
                <li class="sidebar-menu-section">
                    <div class="sidebar-section-title">System Management</div>
                    <ul class="sidebar-menu">
                        <li class="sidebar-menu-item">
                            <a href="#" class="sidebar-menu-link submenu-toggle" onclick="toggleSubmenu(event, 'submenu-users')">
                                <i class="fas fa-users"></i>
                                <span class="sidebar-menu-text">Users & Accounts</span>
                            </a>
                            <ul class="sidebar-submenu" id="submenu-users">
                                <li class="sidebar-submenu-item">
                                    <a href="manage_users.php" class="sidebar-submenu-link <?php echo $current_page === 'manage_users.php' ? 'active' : ''; ?>">
                                        <i class="fas fa-user-circle"></i>
                                        <span>Manage Users</span>
                                    </a>
                                </li>
                                <li class="sidebar-submenu-item">
                                    <a href="manage_roles.php" class="sidebar-submenu-link <?php echo $current_page === 'manage_roles.php' ? 'active' : ''; ?>">
                                        <i class="fas fa-shield-alt"></i>
                                        <span>Roles & Permissions</span>
                                    </a>
                                </li>
                            </ul>
                        </li>

                        <li class="sidebar-menu-item">
                            <a href="manage_employees.php" class="sidebar-menu-link <?php echo $current_page === 'manage_employees.php' ? 'active' : ''; ?>">
                                <i class="fas fa-id-card"></i>
                                <span class="sidebar-menu-text">Employees</span>
                            </a>
                        </li>

                        <li class="sidebar-menu-item">
                            <a href="manage_departments.php" class="sidebar-menu-link <?php echo $current_page === 'manage_departments.php' ? 'active' : ''; ?>">
                                <i class="fas fa-building"></i>
                                <span class="sidebar-menu-text">Departments</span>
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- Organization Settings -->
                <li class="sidebar-menu-section">
                    <div class="sidebar-section-title">Organization</div>
                    <ul class="sidebar-menu">
                        <li class="sidebar-menu-item">
                            <a href="manage_holidays.php" class="sidebar-menu-link <?php echo $current_page === 'manage_holidays.php' ? 'active' : ''; ?>">
                                <i class="fas fa-calendar-alt"></i>
                                <span class="sidebar-menu-text">Holidays</span>
                            </a>
                        </li>

                        <li class="sidebar-menu-item">
                            <a href="manage_leaves.php" class="sidebar-menu-link <?php echo $current_page === 'manage_leaves.php' ? 'active' : ''; ?>">
                                <i class="fas fa-clipboard-list"></i>
                                <span class="sidebar-menu-text">Leave Types</span>
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- Operations -->
                <li class="sidebar-menu-section">
                    <div class="sidebar-section-title">Operations</div>
                    <ul class="sidebar-menu">
                        <li class="sidebar-menu-item">
                            <a href="#" class="sidebar-menu-link submenu-toggle" onclick="toggleSubmenu(event, 'submenu-attendance')">
                                <i class="fas fa-clock"></i>
                                <span class="sidebar-menu-text">Attendance</span>
                            </a>
                            <ul class="sidebar-submenu" id="submenu-attendance">
                                <li class="sidebar-submenu-item">
                                    <a href="import_attendance.php" class="sidebar-submenu-link <?php echo $current_page === 'import_attendance.php' ? 'active' : ''; ?>">
                                        <i class="fas fa-file-upload"></i>
                                        <span>Import Biometric</span>
                                    </a>
                                </li>
                            </ul>
                        </li>

                        <li class="sidebar-menu-item">
                            <a href="#" class="sidebar-menu-link submenu-toggle" onclick="toggleSubmenu(event, 'submenu-reports')">
                                <i class="fas fa-chart-line"></i>
                                <span class="sidebar-menu-text">Reports</span>
                            </a>
                            <ul class="sidebar-submenu" id="submenu-reports">
                                <li class="sidebar-submenu-item">
                                    <a href="reports.php?type=employee" class="sidebar-submenu-link">
                                        <i class="fas fa-users"></i>
                                        <span>Employee Report</span>
                                    </a>
                                </li>
                                <li class="sidebar-submenu-item">
                                    <a href="reports.php?type=attendance" class="sidebar-submenu-link">
                                        <i class="fas fa-calendar-check"></i>
                                        <span>Attendance Report</span>
                                    </a>
                                </li>
                                <li class="sidebar-submenu-item">
                                    <a href="reports.php?type=leave" class="sidebar-submenu-link">
                                        <i class="fas fa-file-alt"></i>
                                        <span>Leave Report</span>
                                    </a>
                                </li>
                                <li class="sidebar-submenu-item">
                                    <a href="reports.php?type=payroll" class="sidebar-submenu-link">
                                        <i class="fas fa-money-bill"></i>
                                        <span>Payroll Report</span>
                                    </a>
                                </li>
                            </ul>
                        </li>
                    </ul>
                </li>

                <!-- Communications -->
                <li class="sidebar-menu-section">
                    <div class="sidebar-section-title">Communications</div>
                    <ul class="sidebar-menu">
                        <li class="sidebar-menu-item">
                            <a href="manage_announcements.php" class="sidebar-menu-link <?php echo $current_page === 'manage_announcements.php' ? 'active' : ''; ?>">
                                <i class="fas fa-bullhorn"></i>
                                <span class="sidebar-menu-text">Announcements</span>
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- Maintenance -->
                <li class="sidebar-menu-section">
                    <div class="sidebar-section-title">Maintenance</div>
                    <ul class="sidebar-menu">
                        <li class="sidebar-menu-item">
                            <a href="recycle_bin.php" class="sidebar-menu-link <?php echo $current_page === 'recycle_bin.php' ? 'active' : ''; ?>">
                                <i class="fas fa-trash-alt"></i>
                                <span class="sidebar-menu-text">Recycle Bin</span>
                            </a>
                        </li>

                        <li class="sidebar-menu-item">
                            <a href="backup.php" class="sidebar-menu-link <?php echo $current_page === 'backup.php' ? 'active' : ''; ?>">
                                <i class="fas fa-server"></i>
                                <span class="sidebar-menu-text">Backup</span>
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>

            <!-- ============ SIDEBAR FOOTER ============ -->
            <div class="sidebar-footer">
                <div class="sidebar-user-info">
                    <div class="sidebar-user-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="sidebar-user-details">
                        <div class="sidebar-user-name"><?php echo htmlspecialchars($admin['first_name'] ?? 'Admin'); ?></div>
                        <div class="sidebar-user-role">Administrator</div>
                    </div>
                </div>
                <a href="../logout.php" class="sidebar-logout-btn" onclick="return confirm('Are you sure you want to logout?');">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </aside>

        <!-- ============ MAIN CONTENT ============ -->
        <div class="admin-main">
            <!-- TOP NAVBAR -->
            <nav class="admin-navbar">
                <div class="navbar-left">
                    <h2>
                        <i class="fas fa-chart-line"></i>
                        <span>Dashboard</span>
                    </h2>
                </div>
                <div class="navbar-right">
                    <div class="navbar-user-name">Welcome, <strong><?php echo htmlspecialchars($admin['first_name'] ?? 'Admin'); ?></strong></div>
                </div>
            </nav>

            <!-- PAGE CONTENT -->
            <div class="admin-content">
                <!-- Quick Stats -->
                <div class="dashboard-grid">
                    <div class="stat-box">
                        <h4>👥 Active Users</h4>
                        <div class="stat-number"><?php echo $total_users; ?></div>
                    </div>
                    <div class="stat-box success">
                        <h4>👨‍💼 Total Employees</h4>
                        <div class="stat-number"><?php echo $total_employees; ?></div>
                    </div>
                    <div class="stat-box info">
                        <h4>📅 Departments</h4>
                        <div class="stat-number"><?php echo $total_departments; ?></div>
                    </div>
                    <div class="stat-box warning">
                        <h4>📋 Pending Leaves</h4>
                        <div class="stat-number"><?php echo $pending_leaves; ?></div>
                    </div>
                    <div class="stat-box info">
                        <h4>📊 Today's Attendance</h4>
                        <div class="stat-number"><?php echo $today_attendance; ?></div>
                    </div>
                    <div class="stat-box danger">
                        <h4>💰 Pending Payroll</h4>
                        <div class="stat-number"><?php echo $pending_payroll; ?></div>
                    </div>
                    <div class="stat-box">
                        <h4>📢 Total Announcements</h4>
                        <div class="stat-number"><?php echo $total_announcements; ?></div>
                    </div>
                    <?php if ($recycle_bin_count > 0): ?>
                        <div class="stat-box warning">
                            <h4>🗑️ Recycle Bin</h4>
                            <div class="stat-number"><?php echo $recycle_bin_count; ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Alerts -->
                <?php if ($recycle_bin_count > 0): ?>
                    <div class="alert-box alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div>
                            <strong>Recycle Bin Alert:</strong> You have <strong><?php echo $recycle_bin_count; ?> deleted items</strong>. 
                            <a href="recycle_bin.php" style="color: #92400e; font-weight: 600;">View →</a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Recent Activity & Quick Actions -->
                <div class="grid-2">
                    <!-- Recent Imports -->
                    <div class="card">
                        <h3><i class="fas fa-history"></i> Recent Biometric Imports</h3>
                        <?php if (!empty($recent_imports)): ?>
                            <ul class="activity-list">
                                <?php foreach ($recent_imports as $import): ?>
                                    <li>
                                        <strong><?php echo htmlspecialchars($import['filename']); ?></strong>
                                        <span class="activity-time">
                                            ✓ <?php echo (int)$import['successful_records']; ?> records on <?php echo format_datetime($import['import_date']); ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <a href="import_attendance.php" style="display: inline-block; margin-top: 15px;">
                                View all imports →
                            </a>
                        <?php else: ?>
                            <p style="color: #999; text-align: center; padding: 20px;">No recent imports</p>
                        <?php endif; ?>
                    </div>

                    <!-- Quick Actions -->
                    <div class="card">
                        <h3><i class="fas fa-lightning-bolt"></i> Quick Actions</h3>
                        <ul class="activity-list">
                            <li>
                                <a href="manage_users.php"><i class="fas fa-plus-circle"></i> Add New User</a>
                            </li>
                            <li>
                                <a href="manage_employees.php"><i class="fas fa-plus-circle"></i> Add Employee</a>
                            </li>
                            <li>
                                <a href="manage_announcements.php"><i class="fas fa-bullhorn"></i> Create Announcement</a>
                            </li>
                            <li>
                                <a href="import_attendance.php"><i class="fas fa-file-upload"></i> Import Attendance</a>
                            </li>
                            <li>
                                <a href="reports.php"><i class="fas fa-file-pdf"></i> Generate Report</a>
                            </li>
                            <li>
                                <a href="recycle_bin.php"><i class="fas fa-trash"></i> Manage Recycle Bin</a>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- System Info -->
                <div class="card">
                    <h3><i class="fas fa-info-circle"></i> System Information</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px;">
                        <div style="padding: 15px; background: #f8f9fa; border-radius: 8px;">
                            <strong style="color: var(--primary-color);">System</strong>
                            <br><span style="font-size: 13px; color: #666;">HRGetafe</span>
                            <br><small style="color: #999;">Human Resources Information System</small>
                        </div>
                        <div style="padding: 15px; background: #f8f9fa; border-radius: 8px;">
                            <strong style="color: var(--primary-color);">Organization</strong>
                            <br><span style="font-size: 13px; color: #666;">Getafe LGU</span>
                            <br><small style="color: #999;">Local Government Unit</small>
                        </div>
                        <div style="padding: 15px; background: #f8f9fa; border-radius: 8px;">
                            <strong style="color: var(--primary-color);">Your Role</strong>
                            <br><span style="font-size: 13px; color: #666;">HR Administrator</span>
                            <br><small style="color: #999;">Full system access</small>
                        </div>
                        <div style="padding: 15px; background: #f8f9fa; border-radius: 8px;">
                            <strong style="color: var(--primary-color);">Session</strong>
                            <br><span style="font-size: 13px; color: #666;"><?php echo date('M d, Y'); ?></span>
                            <br><small style="color: #999;"><?php echo date('h:i A'); ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Sidebar Toggle
        const sidebarToggle = document.getElementById('sidebarToggle');
        const adminSidebar = document.getElementById('adminSidebar');

        sidebarToggle.addEventListener('click', function() {
            adminSidebar.classList.toggle('collapsed');
        });

        // Submenu Toggle
        function toggleSubmenu(event, submenuId) {
            event.preventDefault();
            const submenu = document.getElementById(submenuId);
            const link = event.currentTarget;

            // Close other submenus
            document.querySelectorAll('.sidebar-submenu').forEach(menu => {
                if (menu.id !== submenuId) {
                    menu.classList.remove('show');
                }
            });

            // Toggle current submenu
            submenu.classList.toggle('show');
            link.classList.toggle('active');
        }

        // Close submenu when link is clicked
        document.querySelectorAll('.sidebar-submenu-link').forEach(link => {
            link.addEventListener('click', function() {
                const submenu = this.closest('.sidebar-submenu');
                if (submenu) {
                    submenu.classList.remove('show');
                    const toggle = submenu.previousElementSibling;
                    if (toggle) {
                        toggle.classList.remove('active');
                    }
                }
            });
        });
    </script>
</body>
</html>
