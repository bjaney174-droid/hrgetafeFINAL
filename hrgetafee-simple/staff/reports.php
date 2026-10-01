<?php
session_start();
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

require_login();
check_role(2);

$staff = get_employee_info($conn, $_SESSION['employee_id']);

// Get report type from URL/form
$report_type = isset($_GET['type']) ? sanitizeInput($_GET['type']) : 'employee';
$valid_types = ['employee', 'attendance', 'leave', 'payroll'];
if (!in_array($report_type, $valid_types)) {
    $report_type = 'employee';
}

// Get filters
$filter_month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$filter_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

$filter_month = max(1, min(12, $filter_month));
$filter_year = max(2020, min(date('Y') + 1, $filter_year));

// Helper function to generate CSV
function generateCSV($headers, $data) {
    $output = implode(',', array_map(function($h) { return '"' . str_replace('"', '""', $h) . '"'; }, $headers)) . "\n";
    foreach ($data as $row) {
        $output .= implode(',', array_map(function($val) { return '"' . str_replace('"', '""', $val) . '"'; }, $row)) . "\n";
    }
    return $output;
}

// Helper function to export
function exportReport($headers, $data, $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo generateCSV($headers, $data);
    exit;
}

$report_data = [];
$report_headers = [];
$report_title = '';

// Generate report based on type
if ($report_type === 'employee') {
    $report_title = 'Employee Report';
    
    $stmt = $conn->prepare(
        "SELECT e.employee_id, e.employee_code, e.first_name, e.last_name, e.email, e.phone, 
                e.position, e.salary, e.date_hired, e.status
         FROM employees e
         WHERE e.status = 'active'
         ORDER BY e.first_name, e.last_name"
    );
    
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $employees = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $employees = [];
    }
    
    $report_headers = ['Employee Code', 'First Name', 'Last Name', 'Email', 'Phone', 'Position', 'Salary', 'Date Hired', 'Status'];
    
    foreach ($employees as $emp) {
        $report_data[] = [
            $emp['employee_code'],
            $emp['first_name'],
            $emp['last_name'],
            $emp['email'] ?? '',
            $emp['phone'] ?? '',
            $emp['position'] ?? '',
            number_format($emp['salary'], 2),
            $emp['date_hired'] ?? '',
            $emp['status']
        ];
    }
}
elseif ($report_type === 'attendance') {
    $report_title = 'Attendance Report - ' . date('F Y', mktime(0, 0, 0, $filter_month, 1, $filter_year));
    
    $month_start = date('Y-m-d', mktime(0, 0, 0, $filter_month, 1, $filter_year));
    $month_end = date('Y-m-d', mktime(0, 0, 0, $filter_month + 1, 0, $filter_year));
    
    $stmt = $conn->prepare(
        "SELECT e.employee_code, e.first_name, e.last_name, e.position,
                a.attendance_date, a.clock_in, a.clock_out, a.status
         FROM attendance a
         JOIN employees e ON a.employee_id = e.employee_id
         WHERE a.attendance_date BETWEEN ? AND ?
         ORDER BY a.attendance_date DESC, e.first_name"
    );
    
    if ($stmt) {
        $stmt->bind_param("ss", $month_start, $month_end);
        $stmt->execute();
        $result = $stmt->get_result();
        $attendance = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $attendance = [];
    }
    
    $report_headers = ['Employee Code', 'First Name', 'Last Name', 'Position', 'Date', 'Clock In', 'Clock Out', 'Status'];
    
    foreach ($attendance as $att) {
        $report_data[] = [
            $att['employee_code'],
            $att['first_name'],
            $att['last_name'],
            $att['position'] ?? '',
            $att['attendance_date'],
            $att['clock_in'] ? date('H:i:s', strtotime($att['clock_in'])) : '',
            $att['clock_out'] ? date('H:i:s', strtotime($att['clock_out'])) : '',
            $att['status']
        ];
    }
}
elseif ($report_type === 'leave') {
    $report_title = 'Leave Report';
    
    $stmt = $conn->prepare(
        "SELECT e.employee_code, e.first_name, e.last_name, lt.leave_type_name,
                lr.start_date, lr.end_date, lr.number_of_days, lr.reason, lr.status,
                lr.approved_date, u.username
         FROM leave_requests lr
         JOIN employees e ON lr.employee_id = e.employee_id
         JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
         LEFT JOIN users u ON lr.approved_by = u.user_id
         ORDER BY lr.created_at DESC"
    );
    
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $leaves = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $leaves = [];
    }
    
    $report_headers = ['Employee Code', 'First Name', 'Last Name', 'Leave Type', 'Start Date', 'End Date', 'Days', 'Reason', 'Status', 'Approved By', 'Approved Date'];
    
    foreach ($leaves as $leave) {
        $report_data[] = [
            $leave['employee_code'],
            $leave['first_name'],
            $leave['last_name'],
            $leave['leave_type_name'],
            $leave['start_date'],
            $leave['end_date'],
            $leave['number_of_days'],
            $leave['reason'] ?? '',
            $leave['status'],
            $leave['username'] ?? '',
            $leave['approved_date'] ? date('Y-m-d', strtotime($leave['approved_date'])) : ''
        ];
    }
}
elseif ($report_type === 'payroll') {
    $report_title = 'Payroll Report - ' . date('F Y', mktime(0, 0, 0, $filter_month, 1, $filter_year));
    
    $stmt = $conn->prepare(
        "SELECT e.employee_code, e.first_name, e.last_name, e.position,
                p.payroll_month, p.payroll_year, p.gross_salary, p.deductions, p.net_salary, p.status
         FROM payroll p
         JOIN employees e ON p.employee_id = e.employee_id
         WHERE p.payroll_month = ? AND p.payroll_year = ?
         ORDER BY e.first_name"
    );
    
    if ($stmt) {
        $stmt->bind_param("ii", $filter_month, $filter_year);
        $stmt->execute();
        $result = $stmt->get_result();
        $payroll = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $payroll = [];
    }
    
    $report_headers = ['Employee Code', 'First Name', 'Last Name', 'Position', 'Gross Salary', 'Deductions', 'Net Salary', 'Status'];
    
    foreach ($payroll as $pay) {
        $report_data[] = [
            $pay['employee_code'],
            $pay['first_name'],
            $pay['last_name'],
            $pay['position'] ?? '',
            number_format($pay['gross_salary'], 2),
            number_format($pay['deductions'], 2),
            number_format($pay['net_salary'], 2),
            $pay['status']
        ];
    }
}

// Handle export
if (isset($_GET['export'])) {
    exportReport($report_headers, $report_data, $report_type . '_report_' . date('Y-m-d_His') . '.csv');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - HRGetafe</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .navbar { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        .navbar h2 { margin: 0; }
        .navbar-user { display: flex; gap: 15px; }
        .navbar-user a { color: white; text-decoration: none; }
        
        .container { max-width: 1200px; margin: 0 auto; padding: 30px 20px; }
        
        .card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .card h3 { margin-top: 0; color: #1f2937; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
        
        .report-tabs { display: flex; gap: 10px; margin-bottom: 25px; flex-wrap: wrap; }
        .tab-btn { padding: 10px 20px; border: 2px solid #ddd; background: white; border-radius: 4px; cursor: pointer; font-weight: 600; transition: all 0.3s; text-decoration: none; color: #333; }
        .tab-btn.active { background: #667eea; color: white; border-color: #667eea; }
        .tab-btn:hover { border-color: #667eea; }
        
        .filter-section { background: #f9f9f9; padding: 20px; border-radius: 8px; margin-bottom: 25px; }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px; }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-weight: 600; margin-bottom: 8px; }
        .filter-group input, .filter-group select { padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
        
        .btn { display: inline-block; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; text-decoration: none; }
        .btn-primary { background: #667eea; color: white; }
        .btn-secondary { background: #f0f0f0; color: #333; }
        .btn-success { background: #10b981; color: white; }
        .btn-primary:hover { background: #764ba2; }
        
        .table-container { background: white; border-radius: 8px; overflow-x: auto; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .table-container table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .table-container th { background: #f8f9fa; padding: 12px; text-align: left; font-weight: 600; border-bottom: 2px solid #e1e1e1; }
        .table-container td { padding: 12px; border-bottom: 1px solid #e1e1e1; }
        .table-container tr:hover { background: #f8f9fa; }
        
        .summary-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-box { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; text-align: center; }
        .stat-box h4 { margin: 0; font-size: 13px; opacity: 0.9; }
        .stat-number { font-size: 28px; font-weight: bold; margin: 10px 0 0 0; }
        
        .no-data { text-align: center; color: #999; padding: 40px; }
    </style>
</head>
<body>
    <div class="navbar">
        <h2>📊 Report Generation</h2>
        <div class="navbar-user">
            <span>Welcome, <strong><?php echo htmlspecialchars($staff['first_name']); ?></strong></span>
            <a href="../logout.php">Logout</a>
        </div>
    </div>

    <div class="container">
        <a href="dashboard.php" class="btn btn-secondary" style="margin-bottom: 20px;">← Back to Dashboard</a>

        <!-- Report Type Tabs -->
        <div class="report-tabs">
            <a href="?type=employee" class="tab-btn <?php echo $report_type === 'employee' ? 'active' : ''; ?>">👥 Employee Report</a>
            <a href="?type=attendance" class="tab-btn <?php echo $report_type === 'attendance' ? 'active' : ''; ?>">📅 Attendance Report</a>
            <a href="?type=leave" class="tab-btn <?php echo $report_type === 'leave' ? 'active' : ''; ?>">📋 Leave Report</a>
            <a href="?type=payroll" class="tab-btn <?php echo $report_type === 'payroll' ? 'active' : ''; ?>">💰 Payroll Report</a>
        </div>

        <!-- Filters - EMPLOYEE REPORT -->
        <?php if ($report_type === 'employee'): ?>
        <div class="filter-section">
            <h4 style="margin-top: 0;">👥 Employee Report - No Additional Filters</h4>
            <p style="color: #666; margin: 10px 0;">Showing all active employees in the system.</p>
            <div style="display: flex; gap: 10px;">
                <a href="?type=<?php echo $report_type; ?>&export=1" class="btn btn-success">📥 Export CSV</a>
                <button type="button" class="btn btn-primary" onclick="window.print()" style="background: #10b981;">🖨️ Print</button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filters - ATTENDANCE REPORT -->
        <?php if ($report_type === 'attendance'): ?>
        <div class="filter-section">
            <h4 style="margin-top: 0;">📅 Attendance Report Filters</h4>
            <form method="GET" style="display: flex; flex-direction: column; gap: 15px;">
                <input type="hidden" name="type" value="<?php echo htmlspecialchars($report_type); ?>">
                
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Month</label>
                        <select name="month">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $filter_month == $m ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Year</label>
                        <select name="year">
                            <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $filter_year == $y ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary">🔍 Generate Report</button>
                    <a href="?type=<?php echo $report_type; ?>" class="btn btn-secondary">↻ Reset</a>
                    <a href="?type=<?php echo $report_type; ?>&export=1&month=<?php echo $filter_month; ?>&year=<?php echo $filter_year; ?>" class="btn btn-success">📥 Export CSV</a>
                    <button type="button" class="btn btn-primary" onclick="window.print()" style="background: #10b981;">🖨️ Print</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Filters - LEAVE REPORT -->
        <?php if ($report_type === 'leave'): ?>
        <div class="filter-section">
            <h4 style="margin-top: 0;">📋 Leave Report - No Additional Filters</h4>
            <p style="color: #666; margin: 10px 0;">Showing all leave requests in the system.</p>
            <div style="display: flex; gap: 10px;">
                <a href="?type=<?php echo $report_type; ?>&export=1" class="btn btn-success">📥 Export CSV</a>
                <button type="button" class="btn btn-primary" onclick="window.print()" style="background: #10b981;">🖨️ Print</button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filters - PAYROLL REPORT -->
        <?php if ($report_type === 'payroll'): ?>
        <div class="filter-section">
            <h4 style="margin-top: 0;">💰 Payroll Report Filters</h4>
            <form method="GET" style="display: flex; flex-direction: column; gap: 15px;">
                <input type="hidden" name="type" value="<?php echo htmlspecialchars($report_type); ?>">
                
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Month</label>
                        <select name="month">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $filter_month == $m ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Year</label>
                        <select name="year">
                            <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $filter_year == $y ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary">🔍 Generate Report</button>
                    <a href="?type=<?php echo $report_type; ?>" class="btn btn-secondary">↻ Reset</a>
                    <a href="?type=<?php echo $report_type; ?>&export=1&month=<?php echo $filter_month; ?>&year=<?php echo $filter_year; ?>" class="btn btn-success">📥 Export CSV</a>
                    <button type="button" class="btn btn-primary" onclick="window.print()" style="background: #10b981;">🖨️ Print</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Report Summary -->
        <div class="summary-stats">
            <div class="stat-box">
                <h4>📊 Total Records</h4>
                <div class="stat-number"><?php echo count($report_data); ?></div>
            </div>
            <?php if ($report_type === 'payroll' && !empty($report_data)): ?>
                <?php 
                    $total_salary = 0;
                    $total_deductions = 0;
                    $total_net = 0;
                    
                    foreach ($report_data as $row) {
                        $total_salary += (float)str_replace(',', '', $row[4]);
                        $total_deductions += (float)str_replace(',', '', $row[5]);
                        $total_net += (float)str_replace(',', '', $row[6]);
                    }
                ?>
                <div class="stat-box" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                    <h4>💰 Total Gross Salary</h4>
                    <div class="stat-number">₱<?php echo number_format($total_salary, 0); ?></div>
                </div>
                <div class="stat-box" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);">
                    <h4>💸 Total Deductions</h4>
                    <div class="stat-number">₱<?php echo number_format($total_deductions, 0); ?></div>
                </div>
                <div class="stat-box" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
                    <h4>✓ Total Net Salary</h4>
                    <div class="stat-number">₱<?php echo number_format($total_net, 0); ?></div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Report Title & Data -->
        <div class="card">
            <h3><?php echo htmlspecialchars($report_title); ?></h3>
            <p style="color: #666; font-size: 13px; margin: 10px 0 0 0;">Generated on: <?php echo date('M d, Y H:i:s'); ?></p>

            <?php if (!empty($report_data)): ?>
            <div style="margin-top: 20px; overflow-x: auto;">
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <?php foreach ($report_headers as $header): ?>
                                    <th><?php echo htmlspecialchars($header); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report_data as $row): ?>
                            <tr>
                                <?php foreach ($row as $cell): ?>
                                    <td><?php echo htmlspecialchars($cell); ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else: ?>
            <div class="no-data">
                <p>No data available for the selected filters</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Footer Notes -->
        <div class="card">
            <h4>📝 Report Information</h4>
            <ul style="color: #666; font-size: 13px; line-height: 1.8;">
                <li><strong>Employee Report:</strong> Lists all active employees with contact information and employment details</li>
                <li><strong>Attendance Report:</strong> Shows daily attendance records for a specific month including clock-in/out times</li>
                <li><strong>Leave Report:</strong> Displays all leave requests with approval status and approver information</li>
                <li><strong>Payroll Report:</strong> Shows payroll records for a specific month with gross/net salary breakdown</li>
                <li><strong>Export:</strong> All reports can be exported to CSV format for use in Excel or other applications</li>
                <li><strong>Print:</strong> Reports are print-friendly and optimized for professional documentation</li>
            </ul>
        </div>
    </div>

    <style>
        @media print {
            .navbar, .report-tabs, .filter-section, .btn, button { display: none; }
            .container { padding: 0; }
            .card { box-shadow: none; page-break-inside: avoid; }
        }
    </style>
</body>
</html>