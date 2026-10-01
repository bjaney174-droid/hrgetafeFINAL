<?php
session_start();
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

require_login();
check_role(2);

$staff = get_employee_info($conn, $_SESSION['employee_id']);

// Get filters
$filter_employee = isset($_GET['employee']) ? (int)$_GET['employee'] : 0;
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$filter_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';

// Build where clause - use proper table reference
$where_parts = [];
$where_parts[] = "a.attendance_date BETWEEN '{$filter_date_from}' AND '{$filter_date_to}'";

if ($filter_employee > 0) {
    $where_parts[] = "a.employee_id = {$filter_employee}";
}

if ($filter_status) {
    $where_parts[] = "a.status = '{$filter_status}'";
}

$where = implode(" AND ", $where_parts);

// Get logs - properly select from attendance table
$logs = $conn->query(
    "SELECT a.attendance_id, a.employee_id, a.clock_in, a.clock_out, a.attendance_date, a.status, a.remarks, 
            e.first_name, e.last_name, e.employee_code, e.position
     FROM attendance a
     JOIN employees e ON a.employee_id = e.employee_id
     WHERE {$where}
     ORDER BY a.attendance_date DESC, a.clock_in DESC
     LIMIT 500"
);

if (!$logs) {
    die("Database error: " . $conn->error);
}

$logs = $logs->fetch_all(MYSQLI_ASSOC);

// Get employees for filter
$employees = $conn->query("SELECT employee_id, first_name, last_name, employee_code FROM employees WHERE status = 'active' ORDER BY first_name")->fetch_all(MYSQLI_ASSOC);

// Calculate stats
$stats_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
    SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
    SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count
FROM attendance a
WHERE {$where}";

$stats = $conn->query($stats_query);
if (!$stats) {
    die("Database error in stats: " . $conn->error);
}
$stats = $stats->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Logs - HRGetafe</title>
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
        .stat-box h4 { margin: 0; font-size: 13px; opacity: 0.9; }
        .stat-number { font-size: 28px; font-weight: bold; margin: 10px 0 0 0; }
        .stat-box.success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .stat-box.warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .stat-box.danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        
        .filter-section { background: #f9f9f9; padding: 20px; border-radius: 8px; margin-bottom: 25px; }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 15px; }
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
        
        .badge { display: inline-block; padding: 5px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .badge-present { background: #d1fae5; color: #065f46; }
        .badge-late { background: #fef3c7; color: #92400e; }
        .badge-absent { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    <div class="navbar">
        <h2>📋 View Attendance Logs</h2>
        <div class="navbar-user">
            <span>Welcome, <strong><?php echo htmlspecialchars($staff['first_name']); ?></strong></span>
            <a href="../logout.php">Logout</a>
        </div>
    </div>

    <div class="container">
        <a href="dashboard.php" class="btn btn-secondary" style="margin-bottom: 20px;">← Back to Dashboard</a>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-box success">
                <h4>✅ Present</h4>
                <div class="stat-number"><?php echo (int)($stats['present_count'] ?? 0); ?></div>
            </div>
            <div class="stat-box warning">
                <h4>⏰ Late</h4>
                <div class="stat-number"><?php echo (int)($stats['late_count'] ?? 0); ?></div>
            </div>
            <div class="stat-box danger">
                <h4>❌ Absent</h4>
                <div class="stat-number"><?php echo (int)($stats['absent_count'] ?? 0); ?></div>
            </div>
            <div class="stat-box">
                <h4>📊 Total Records</h4>
                <div class="stat-number"><?php echo (int)($stats['total'] ?? 0); ?></div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <h4 style="margin-top: 0;">🔍 Filter Attendance Logs</h4>
            <form method="GET" style="display: flex; flex-direction: column; gap: 15px;">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Employee</label>
                        <select name="employee">
                            <option value="">-- All Employees --</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['employee_id']; ?>" <?php echo $filter_employee == $emp['employee_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>From Date</label>
                        <input type="date" name="date_from" value="<?php echo $filter_date_from; ?>">
                    </div>

                    <div class="filter-group">
                        <label>To Date</label>
                        <input type="date" name="date_to" value="<?php echo $filter_date_to; ?>">
                    </div>

                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="">-- All Status --</option>
                            <option value="present" <?php echo $filter_status === 'present' ? 'selected' : ''; ?>>Present</option>
                            <option value="late" <?php echo $filter_status === 'late' ? 'selected' : ''; ?>>Late</option>
                            <option value="absent" <?php echo $filter_status === 'absent' ? 'selected' : ''; ?>>Absent</option>
                        </select>
                    </div>
                </div>

                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary">🔍 Apply Filters</button>
                    <a href="attendance_logs.php" class="btn btn-secondary">↻ Reset</a>
                </div>
            </form>
        </div>

        <!-- Attendance Logs Table -->
        <div class="card">
            <h3>📊 Attendance Records (<?php echo count($logs); ?>)</h3>
            
            <?php if (!empty($logs)): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Employee</th>
                            <th>Code</th>
                            <th>Position</th>
                            <th>Clock In</th>
                            <th>Clock Out</th>
                            <th>Duration</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><strong><?php echo isset($log['attendance_date']) ? format_date($log['attendance_date']) : '-'; ?></strong></td>
                            <td><?php echo htmlspecialchars($log['first_name'] . ' ' . $log['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($log['employee_code']); ?></td>
                            <td><?php echo htmlspecialchars($log['position'] ?? '-'); ?></td>
                            <td><?php echo $log['clock_in'] ? date('H:i:s', strtotime($log['clock_in'])) : '-'; ?></td>
                            <td><?php echo $log['clock_out'] ? date('H:i:s', strtotime($log['clock_out'])) : '-'; ?></td>
                            <td>
                                <?php 
                                    if ($log['clock_in'] && $log['clock_out']) {
                                        $start = new DateTime($log['clock_in']);
                                        $end = new DateTime($log['clock_out']);
                                        $diff = $start->diff($end);
                                        echo $diff->h . 'h ' . $diff->i . 'm';
                                    } else {
                                        echo '-';
                                    }
                                ?>
                            </td>
                            <td>
                                <?php 
                                    $badge_class = 'badge-present';
                                    $badge_text = '✓ Present';
                                    
                                    if ($log['status'] === 'late') {
                                        $badge_class = 'badge-late';
                                        $badge_text = '⏰ Late';
                                    } elseif ($log['status'] === 'absent') {
                                        $badge_class = 'badge-absent';
                                        $badge_text = '✗ Absent';
                                    }
                                ?>
                                <span class="badge <?php echo $badge_class; ?>"><?php echo $badge_text; ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p style="text-align: center; color: #999; padding: 30px;">No attendance records found</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
