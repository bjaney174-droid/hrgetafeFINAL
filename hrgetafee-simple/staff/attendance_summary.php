<?php
session_start();
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

require_login();
check_role(2);

$staff = get_employee_info($conn, $_SESSION['employee_id']);

// Get parameters
$report_month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$report_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// Validate month and year
$report_month = max(1, min(12, $report_month));
$report_year = max(2020, min(date('Y'), $report_year));

// Get date range for the month
$month_start = date('Y-m-d', mktime(0, 0, 0, $report_month, 1, $report_year));
$month_end = date('Y-m-d', mktime(0, 0, 0, $report_month + 1, 0, $report_year));

// Get summary statistics
$summary = $conn->query(
    "SELECT 
        COUNT(DISTINCT employee_id) as total_employees,
        COUNT(*) as total_records,
        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days
     FROM attendance
     WHERE attendance_date BETWEEN '{$month_start}' AND '{$month_end}'"
)->fetch_assoc();

// Get employee attendance summary
$employee_summary = $conn->query(
    "SELECT 
        e.employee_id,
        e.first_name,
        e.last_name,
        e.employee_code,
        e.position,
        COUNT(*) as total_days,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_days,
        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_days,
        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_days
    FROM employees e
    LEFT JOIN attendance a ON e.employee_id = a.employee_id AND a.attendance_date BETWEEN '{$month_start}' AND '{$month_end}'
    WHERE e.status = 'active'
    GROUP BY e.employee_id, e.first_name, e.last_name, e.employee_code, e.position
    ORDER BY e.first_name, e.last_name"
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Summary Report - HRGetafe</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .navbar { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        .navbar h2 { margin: 0; }
        .navbar-user { display: flex; gap: 15px; }
        .navbar-user a { color: white; text-decoration: none; }
        
        .container { max-width: 1200px; margin: 0 auto; padding: 30px 20px; }
        
        .card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .card h3 { margin-top: 0; color: #1f2937; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-box { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; text-align: center; }
        .stat-box h4 { margin: 0; font-size: 12px; opacity: 0.9; }
        .stat-number { font-size: 32px; font-weight: bold; margin: 10px 0 0 0; }
        .stat-box.success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .stat-box.warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .stat-box.danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        
        .filter-section { background: #f9f9f9; padding: 20px; border-radius: 8px; margin-bottom: 25px; }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 15px; }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-weight: 600; margin-bottom: 8px; font-size: 13px; }
        .filter-group input, .filter-group select { padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
        
        .btn { display: inline-block; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; text-decoration: none; }
        .btn-primary { background: #667eea; color: white; }
        .btn-secondary { background: #f0f0f0; color: #333; }
        .btn-primary:hover { background: #764ba2; }
        
        .table-container { background: white; border-radius: 8px; overflow-x: auto; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .table-container table { width: 100%; border-collapse: collapse; }
        .table-container th { background: #f8f9fa; padding: 12px; text-align: left; font-weight: 600; border-bottom: 2px solid #e1e1e1; font-size: 12px; }
        .table-container td { padding: 12px; border-bottom: 1px solid #e1e1e1; font-size: 13px; }
        .table-container tr:hover { background: #f8f9fa; }
        
        .badge { display: inline-block; padding: 4px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        
        .report-header { margin-bottom: 25px; padding-bottom: 20px; border-bottom: 2px solid #e1e1e1; }
        
        @media print {
            .filter-section, .btn, button { display: none; }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h2>📊 Generate Attendance Summary</h2>
        <div class="navbar-user">
            <span>Welcome, <strong><?php echo htmlspecialchars($staff['first_name']); ?></strong></span>
            <a href="../logout.php">Logout</a>
        </div>
    </div>

    <div class="container">
        <a href="dashboard.php" class="btn btn-secondary" style="margin-bottom: 20px;">← Back to Dashboard</a>

        <!-- Report Header -->
        <div class="report-header">
            <h3 style="margin: 0;">Attendance Summary Report - <?php echo date('F Y', mktime(0, 0, 0, $report_month, 1, $report_year)); ?></h3>
            <p style="color: #666; margin: 10px 0 0 0;">Generated on <?php echo date('M d, Y H:i:s'); ?></p>
        </div>

        <!-- Overall Statistics -->
        <div class="stats-grid">
            <div class="stat-box">
                <h4>👥 Employees</h4>
                <div class="stat-number"><?php echo (int)($summary['total_employees'] ?? 0); ?></div>
            </div>
            <div class="stat-box success">
                <h4>✅ Present Days</h4>
                <div class="stat-number"><?php echo (int)($summary['present_days'] ?? 0); ?></div>
            </div>
            <div class="stat-box warning">
                <h4>⏰ Late Days</h4>
                <div class="stat-number"><?php echo (int)($summary['late_days'] ?? 0); ?></div>
            </div>
            <div class="stat-box danger">
                <h4>❌ Absent Days</h4>
                <div class="stat-number"><?php echo (int)($summary['absent_days'] ?? 0); ?></div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <h4 style="margin-top: 0;">📅 Select Month & Year</h4>
            <form method="GET" style="display: flex; flex-direction: column; gap: 15px;">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Month</label>
                        <select name="month">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $report_month == $m ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Year</label>
                        <select name="year">
                            <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $report_year == $y ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary">📊 Generate Report</button>
                    <button type="button" class="btn btn-primary" onclick="window.print()" style="background: #10b981;">🖨️ Print</button>
                </div>
            </form>
        </div>

        <!-- Employee Summary Table -->
        <div class="card">
            <h3>👥 Employee Attendance Summary</h3>
            
            <?php if (!empty($employee_summary)): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Code</th>
                            <th>Position</th>
                            <th style="text-align: center;">Total Days</th>
                            <th style="text-align: center;">Present</th>
                            <th style="text-align: center;">Late</th>
                            <th style="text-align: center;">Absent</th>
                            <th style="text-align: center;">Attendance %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employee_summary as $emp): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($emp['employee_code']); ?></td>
                            <td><?php echo htmlspecialchars($emp['position'] ?? '-'); ?></td>
                            <td style="text-align: center;"><?php echo (int)$emp['total_days']; ?></td>
                            <td style="text-align: center;">
                                <span class="badge badge-success"><?php echo (int)$emp['present_days']; ?></span>
                            </td>
                            <td style="text-align: center;">
                                <span class="badge badge-warning"><?php echo (int)$emp['late_days']; ?></span>
                            </td>
                            <td style="text-align: center;">
                                <span class="badge badge-danger"><?php echo (int)$emp['absent_days']; ?></span>
                            </td>
                            <td style="text-align: center;">
                                <?php 
                                    $attendance_rate = $emp['total_days'] > 0 ? (($emp['present_days'] + $emp['late_days']) / $emp['total_days'] * 100) : 0;
                                    $rate_color = $attendance_rate >= 90 ? '#10b981' : ($attendance_rate >= 75 ? '#f59e0b' : '#ef4444');
                                ?>
                                <strong style="color: <?php echo $rate_color; ?>;"><?php echo round($attendance_rate, 1); ?>%</strong>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e1e1e1;">
                <h4>📝 Legend:</h4>
                <ul style="color: #666; font-size: 13px;">
                    <li><strong>Present:</strong> Days marked as "present" (on time)</li>
                    <li><strong>Late:</strong> Days marked as "late" (after scheduled time)</li>
                    <li><strong>Absent:</strong> Days marked as "absent"</li>
                    <li><strong>Attendance %:</strong> (Present + Late) / Total Days × 100</li>
                </ul>
            </div>
            <?php else: ?>
            <p style="text-align: center; color: #999; padding: 30px;">No data available for the selected period</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
