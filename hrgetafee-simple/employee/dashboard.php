<?php
session_start();
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

require_login();
check_role(3);

$employee = get_employee_info($conn, $_SESSION['employee_id']);
$employee_id = $_SESSION['employee_id'];

// Get statistics for dashboard
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM attendance WHERE employee_id = ? AND MONTH(attendance_date) = MONTH(NOW()) AND YEAR(attendance_date) = YEAR(NOW())");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$this_month_attendance = $stmt->get_result()->fetch_assoc()['count'];
$stmt->close();

// Check if there's any attendance data at all
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM attendance LIMIT 1");
$stmt->execute();
$total_attendance_records = $stmt->get_result()->fetch_assoc()['count'];
$stmt->close();

// Get pending leaves
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM leave_requests WHERE employee_id = ? AND status = 'pending'");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$pending_leaves_count = $stmt->get_result()->fetch_assoc()['count'];
$stmt->close();

// Get approved leaves
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM leave_requests WHERE employee_id = ? AND status = 'approved'");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$approved_leaves_count = $stmt->get_result()->fetch_assoc()['count'];
$stmt->close();

// Get latest announcements
$stmt = $conn->prepare("SELECT * FROM announcements ORDER BY created_at DESC LIMIT 3");
$stmt->execute();
$latest_announcements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get announcements count
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM announcements");
$stmt->execute();
$announcements_count = $stmt->get_result()->fetch_assoc()['count'];
$stmt->close();

// Get current page for active menu highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Dashboard - HRGetafe</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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

        .employee-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* ============ SIDEBAR STYLES ============ */
        .employee-sidebar {
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

        .employee-sidebar.collapsed {
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

        .employee-sidebar.collapsed .sidebar-logo-text {
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

        .employee-sidebar.collapsed .sidebar-section-title {
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

        .employee-sidebar.collapsed .sidebar-menu-text {
            opacity: 0;
            width: 0;
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

        .employee-sidebar.collapsed .sidebar-user-info {
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

        .employee-sidebar.collapsed .sidebar-logout-btn {
            padding: 12px 8px;
            font-size: 0;
        }

        .employee-sidebar.collapsed .sidebar-logout-btn i {
            font-size: 16px;
            margin: 0;
        }

        /* ============ MAIN CONTENT AREA ============ */
        .employee-main {
            flex: 1;
            margin-left: var(--sidebar-width);
            transition: margin-left var(--transition-speed) ease;
            display: flex;
            flex-direction: column;
        }

        .employee-sidebar.collapsed ~ .employee-main {
            margin-left: var(--sidebar-width-collapsed);
        }

        /* ============ TOP NAVBAR ============ */
        .employee-navbar {
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
            font-size: 14px;
            color: #666;
        }

        .navbar-date {
            color: #999;
            font-size: 13px;
        }

        /* ============ CONTENT AREA ============ */
        .employee-content {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
            background: #f8f9fa;
        }

        /* ============ WELCOME SECTION ============ */
        .welcome-header {
            margin-bottom: 30px;
        }

        .welcome-header h1 {
            font-size: 32px;
            color: #1f2937;
            margin-bottom: 5px;
            font-weight: 700;
        }

        .welcome-header p {
            color: #666;
            font-size: 14px;
        }

        /* ============ DASHBOARD GRID ============ */
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

        .stat-box.success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .stat-box.info {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        }

        /* ============ CARD STYLES ============ */
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

        /* ============ ANNOUNCEMENT STYLES ============ */
        .announcement-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .announcement-card {
            background: #f9fafb;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid var(--primary-color);
            transition: 0.3s;
        }

        .announcement-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .announcement-title {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 5px;
            font-size: 14px;
        }

        .announcement-preview {
            font-size: 13px;
            color: #666;
            margin-bottom: 8px;
            line-height: 1.4;
        }

        .announcement-date {
            font-size: 11px;
            color: #999;
        }

        .view-all-link {
            display: inline-block;
            margin-top: 15px;
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
            transition: 0.3s;
        }

        .view-all-link:hover {
            color: var(--secondary-color);
        }

        .empty-state {
            text-align: center;
            padding: 30px;
            color: #999;
        }

        .setup-notice {
            background: linear-gradient(135deg, #fff3cd 0%, #ffe69c 100%);
            border-left: 4px solid #ffc107;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 25px;
            color: #664d03;
        }

        .setup-notice strong {
            color: #664d03;
            font-weight: 700;
        }

        .badge-small {
            display: inline-block;
            background: #fef3c7;
            color: #92400e;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin-top: 10px;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
        }

        /* ============ QUICK SUMMARY ============ */
        .quick-summary-list {
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .quick-summary-list li {
            padding: 8px 0;
            color: #666;
            font-size: 14px;
            line-height: 1.6;
        }

        /* ============ SCROLLBAR ============ */
        .employee-sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .employee-sidebar::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
        }

        .employee-sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 3px;
        }

        .employee-sidebar::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .employee-content::-webkit-scrollbar {
            width: 8px;
        }

        .employee-content::-webkit-scrollbar-track {
            background: #f0f0f0;
        }

        .employee-content::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 4px;
        }

        .employee-content::-webkit-scrollbar-thumb:hover {
            background: #999;
        }

        /* ============ RESPONSIVE ============ */
        @media (max-width: 1024px) {
            :root {
                --sidebar-width: 250px;
            }

            .employee-content {
                padding: 20px;
            }
        }

        @media (max-width: 768px) {
            :root {
                --sidebar-width: 240px;
            }

            .employee-sidebar {
                transform: translateX(-100%);
                transition: transform var(--transition-speed) ease;
            }

            .employee-sidebar.mobile-open {
                transform: translateX(0);
            }

            .employee-main {
                margin-left: 0;
            }

            .employee-navbar {
                padding: 12px 18px;
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .navbar-left h2 {
                font-size: 20px;
            }

            .employee-content {
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
            .employee-content {
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
    </style>
</head>
<body>
    <div class="employee-wrapper">
        <!-- ============ SIDEBAR ============ -->
        <aside class="employee-sidebar" id="employeeSidebar">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>

            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-briefcase"></i>
                </div>
                <span class="sidebar-logo-text">Employee Portal</span>
            </div>

            <ul class="sidebar-menu">
                <!-- Dashboard -->
                <li class="sidebar-menu-section">
                    <a href="dashboard.php" class="sidebar-menu-link <?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
                        <i class="fas fa-home"></i>
                        <span class="sidebar-menu-text">Dashboard</span>
                    </a>
                </li>

                <!-- Personal Section -->
                <li class="sidebar-menu-section">
                    <div class="sidebar-section-title">Personal</div>
                    <ul class="sidebar-menu">
                        <li class="sidebar-menu-item">
                            <a href="view_profile.php" class="sidebar-menu-link <?php echo $current_page === 'view_profile.php' ? 'active' : ''; ?>">
                                <i class="fas fa-user-circle"></i>
                                <span class="sidebar-menu-text">My Profile</span>
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- Attendance Section -->
                <li class="sidebar-menu-section">
                    <div class="sidebar-section-title">Attendance</div>
                    <ul class="sidebar-menu">
                        <li class="sidebar-menu-item">
                            <a href="attendance.php" class="sidebar-menu-link <?php echo $current_page === 'attendance.php' ? 'active' : ''; ?>">
                                <i class="fas fa-calendar-check"></i>
                                <span class="sidebar-menu-text">Records</span>
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- Leave Management -->
                <li class="sidebar-menu-section">
                    <div class="sidebar-section-title">Leave Management</div>
                    <ul class="sidebar-menu">
                        <li class="sidebar-menu-item">
                            <a href="apply_leave.php" class="sidebar-menu-link <?php echo $current_page === 'apply_leave.php' ? 'active' : ''; ?>">
                                <i class="fas fa-file-alt"></i>
                                <span class="sidebar-menu-text">Apply for Leave</span>
                            </a>
                        </li>
                        <li class="sidebar-menu-item">
                            <a href="leave_status.php" class="sidebar-menu-link <?php echo $current_page === 'leave_status.php' ? 'active' : ''; ?>">
                                <i class="fas fa-clipboard-list"></i>
                                <span class="sidebar-menu-text">Leave Status</span>
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- Payroll Section -->
                <li class="sidebar-menu-section">
                    <div class="sidebar-section-title">Payroll</div>
                    <ul class="sidebar-menu">
                        <li class="sidebar-menu-item">
                            <a href="salary_slips.php" class="sidebar-menu-link <?php echo $current_page === 'salary_slips.php' ? 'active' : ''; ?>">
                                <i class="fas fa-file-invoice-dollar"></i>
                                <span class="sidebar-menu-text">My Payslips</span>
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- Communications -->
                <li class="sidebar-menu-section">
                    <div class="sidebar-section-title">Communications</div>
                    <ul class="sidebar-menu">
                        <li class="sidebar-menu-item">
                            <a href="announcements.php" class="sidebar-menu-link <?php echo $current_page === 'announcements.php' ? 'active' : ''; ?>">
                                <i class="fas fa-bullhorn"></i>
                                <span class="sidebar-menu-text">Announcements</span>
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
                        <div class="sidebar-user-name"><?php echo htmlspecialchars($employee['first_name'] ?? 'Employee'); ?></div>
                        <div class="sidebar-user-role">Employee</div>
                    </div>
                </div>
                <a href="../logout.php" class="sidebar-logout-btn" onclick="return confirm('Are you sure you want to logout?');">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </aside>

        <!-- ============ MAIN CONTENT ============ -->
        <div class="employee-main">
            <!-- TOP NAVBAR -->
            <nav class="employee-navbar">
                <div class="navbar-left">
                    <h2>
                        <i class="fas fa-chart-line"></i>
                        <span>Dashboard</span>
                    </h2>
                </div>
                <div class="navbar-right">
                    <span class="navbar-date"><?php echo date('l, F j, Y'); ?></span>
                </div>
            </nav>

            <!-- PAGE CONTENT -->
            <div class="employee-content">
                <!-- Welcome Section -->
                <div class="welcome-header">
                    <h1>Welcome back, <?php echo htmlspecialchars($employee['first_name']); ?> 👋</h1>
                    <p><?php echo htmlspecialchars($employee['position']); ?> • Employee ID: <?php echo htmlspecialchars($employee['employee_code']); ?></p>
                </div>

                <!-- Setup Notice if no attendance data -->
                <?php if ($total_attendance_records == 0): ?>
                <div class="setup-notice">
                    <strong>⏳ System Setup in Progress</strong><br>
                    <span style="font-size: 14px;">
                        The attendance system is being set up. HR Staff will import biometric data soon. Once imported, your attendance records will appear on the Attendance page.
                    </span>
                </div>
                <?php endif; ?>

                <!-- Dashboard Statistics -->
                <div class="dashboard-grid">
                    <div class="stat-box info">
                        <h4>📅 This Month Attendance</h4>
                        <div class="stat-number"><?php echo $this_month_attendance; ?></div>
                        <?php if ($total_attendance_records == 0): ?>
                            <div class="badge-small">Pending Import</div>
                        <?php endif; ?>
                    </div>

                    <div class="stat-box warning">
                        <h4>⏳ Pending Leaves</h4>
                        <div class="stat-number"><?php echo $pending_leaves_count; ?></div>
                    </div>

                    <div class="stat-box success">
                        <h4>✅ Approved Leaves</h4>
                        <div class="stat-number"><?php echo $approved_leaves_count; ?></div>
                    </div>

                    <div class="stat-box info">
                        <h4>📢 Announcements</h4>
                        <div class="stat-number"><?php echo $announcements_count; ?></div>
                    </div>
                </div>

                <!-- Recent Announcements & Quick Summary -->
                <div class="grid-2">
                    <!-- Recent Announcements -->
                    <div class="card">
                        <h3><i class="fas fa-bell"></i> Recent Announcements</h3>
                        
                        <?php if (!empty($latest_announcements)): ?>
                            <div class="announcement-list">
                                <?php foreach ($latest_announcements as $ann): ?>
                                    <div class="announcement-card">
                                        <div class="announcement-title"><?php echo htmlspecialchars($ann['title']); ?></div>
                                        <div class="announcement-preview">
                                            <?php echo htmlspecialchars(substr($ann['content'] ?? $ann['message'] ?? '', 0, 100)); ?>
                                            <?php if (strlen($ann['content'] ?? $ann['message'] ?? '') > 100) echo '...'; ?>
                                        </div>
                                        <div class="announcement-date">
                                            📅 <?php echo format_datetime($ann['created_at']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <a href="announcements.php" class="view-all-link">View all announcements →</a>
                        <?php else: ?>
                            <div class="empty-state">
                                <p>📭 No announcements at this time</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Quick Summary -->
                    <div class="card">
                        <h3><i class="fas fa-info-circle"></i> Quick Guide</h3>
                        <p style="color: #666; line-height: 1.8; font-size: 14px; margin: 0 0 15px 0;">
                            👋 Welcome to your employee dashboard! Manage your HR-related tasks and access important information.
                        </p>
                        <ul class="quick-summary-list">
                            <li>📊 View your attendance records (once imported by HR)</li>
                            <li>📝 Apply for leave and track request status</li>
                            <li>💰 Access and download your payslips anytime</li>
                            <li>👤 View and update your profile information</li>
                            <li>📢 Stay updated with company announcements</li>
                        </ul>
                        <p style="font-size: 12px; color: #999; margin-top: 15px; margin-bottom: 0;">
                            For HR-related questions, please contact the HR Department.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Sidebar Toggle
        const sidebarToggle = document.getElementById('sidebarToggle');
        const employeeSidebar = document.getElementById('employeeSidebar');

        sidebarToggle.addEventListener('click', function() {
            employeeSidebar.classList.toggle('collapsed');
        });

        // Close sidebar on mobile when link clicked
        document.querySelectorAll('.sidebar-menu-link').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    employeeSidebar.classList.remove('mobile-open');
                }
            });
        });

        // Highlight active page
        const currentPage = window.location.pathname.split('/').pop();
        document.querySelectorAll('.sidebar-menu-link').forEach(link => {
            const href = link.getAttribute('href');
            if (href === currentPage || (currentPage === '' && href === 'dashboard.php')) {
                link.classList.add('active');
            }
        });
    </script>
</body>
</html>
