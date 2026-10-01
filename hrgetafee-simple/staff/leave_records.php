<?php
session_start();
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

require_login();
check_role(2);

$staff = get_employee_info($conn, $_SESSION['employee_id']);

// Get filters
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$search_employee = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Build query
$where_clauses = [];

if ($filter_status) {
    $where_clauses[] = "lr.status = '{$filter_status}'";
}

if ($search_employee) {
    $where_clauses[] = "(e.first_name LIKE '%{$search_employee}%' OR e.last_name LIKE '%{$search_employee}%' OR e.employee_code LIKE '%{$search_employee}%')";
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Get leave records
$leave_records = $conn->query(
    "SELECT lr.*, lt.leave_type_name, e.first_name, e.last_name, e.employee_code, 
            u_approver.username as approver_name
     FROM leave_requests lr
     JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
     JOIN employees e ON lr.employee_id = e.employee_id
     LEFT JOIN users u_approver ON lr.approved_by = u_approver.user_id
     {$where_sql}
     ORDER BY lr.created_at DESC
     LIMIT 500"
)->fetch_all(MYSQLI_ASSOC);

// Get statistics
$stats = [];
$stats_result = $conn->query(
    "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
        SUM(CASE WHEN status = 'denied' THEN 1 ELSE 0 END) as denied_count
     FROM leave_requests"
);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Records - HRGetafe</title>
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
        .stat-box.warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .stat-box.success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .stat-box.danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        
        .filter-section { background: #f9f9f9; padding: 20px; border-radius: 8px; margin-bottom: 25px; }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px; }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-weight: 600; margin-bottom: 8px; }
        .filter-group input, .filter-group select { padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
        
        .btn { display: inline-block; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; text-decoration: none; }
        .btn-primary { background: #667eea; color: white; }
        .btn-secondary { background: #f0f0f0; color: #333; }
        .btn-primary:hover { background: #764ba2; }
        
        .table-container { background: white; border-radius: 8px; overflow-x: auto; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .table-container table { width: 100%; border-collapse: collapse; }
        .table-container th { background: #f8f9fa; padding: 12px; text-align: left; font-weight: 600; border-bottom: 2px solid #e1e1e1; font-size: 13px; }
        .table-container td { padding: 12px; border-bottom: 1px solid #e1e1e1; font-size: 13px; }
        .table-container tr:hover { background: #f8f9fa; }
        
        .badge { display: inline-block; padding: 5px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-approved { background: #d1fae5; color: #065f46; }
        .badge-denied { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    <div class="navbar">
        <h2>📝 Leave Records</h2>
        <div class="navbar-user">
            <span>Welcome, <strong><?php echo htmlspecialchars($staff['first_name']); ?></strong></span>
            <a href="../logout.php">Logout</a>
        </div>
    </div>

    <div class="container">
        <a href="dashboard.php" class="btn btn-secondary" style="margin-bottom: 20px;">← Back to Dashboard</a>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-box warning">
                <h4>⏳ Pending</h4>
                <div class="stat-number"><?php echo (int)($stats['pending_count'] ?? 0); ?></div>
            </div>
            <div class="stat-box success">
                <h4>✅ Approved</h4>
                <div class="stat-number"><?php echo (int)($stats['approved_count'] ?? 0); ?></div>
            </div>
            <div class="stat-box danger">
                <h4>❌ Denied</h4>
                <div class="stat-number"><?php echo (int)($stats['denied_count'] ?? 0); ?></div>
            </div>
            <div class="stat-box">
                <h4>📋 Total</h4>
                <div class="stat-number"><?php echo (int)($stats['total'] ?? 0); ?></div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <h4 style="margin-top: 0;">🔍 Filter Leave Records</h4>
            <form method="GET" style="display: flex; flex-direction: column; gap: 15px;">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Search Employee</label>
                        <input type="text" name="search" placeholder="Name or Employee Code" value="<?php echo htmlspecialchars($search_employee); ?>">
                    </div>

                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="">-- All Status --</option>
                            <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $filter_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="denied" <?php echo $filter_status === 'denied' ? 'selected' : ''; ?>>Denied</option>
                        </select>
                    </div>
                </div>

                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary">🔍 Search</button>
                    <a href="leave_records.php" class="btn btn-secondary">↻ Reset</a>
                </div>
            </form>
        </div>

        <!-- Leave Records Table -->
        <div class="card">
            <h3>📋 Leave Request Records (<?php echo count($leave_records); ?>)</h3>
            
            <?php if (!empty($leave_records)): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Leave Type</th>
                            <th>From - To</th>
                            <th>Days</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Approver</th>
                            <th>Approved Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leave_records as $leave): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($leave['first_name'] . ' ' . $leave['last_name']); ?></strong><br>
                                <small><?php echo htmlspecialchars($leave['employee_code']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($leave['leave_type_name']); ?></td>
                            <td><?php echo format_date($leave['start_date']) . ' - ' . format_date($leave['end_date']); ?></td>
                            <td><strong><?php echo (int)$leave['number_of_days']; ?></strong></td>
                            <td><?php echo htmlspecialchars(substr($leave['reason'], 0, 50)); ?></td>
                            <td>
                                <?php 
                                    $badge_class = 'badge-pending';
                                    $badge_text = '⏳ Pending';
                                    
                                    if ($leave['status'] === 'approved') {
                                        $badge_class = 'badge-approved';
                                        $badge_text = '✅ Approved';
                                    } elseif ($leave['status'] === 'denied') {
                                        $badge_class = 'badge-denied';
                                        $badge_text = '❌ Denied';
                                    }
                                ?>
                                <span class="badge <?php echo $badge_class; ?>"><?php echo $badge_text; ?></span>
                            </td>
                            <td><?php echo $leave['approver_name'] ? htmlspecialchars($leave['approver_name']) : '-'; ?></td>
                            <td><?php echo $leave['approved_date'] ? format_datetime($leave['approved_date']) : '-'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p style="text-align: center; color: #999; padding: 30px;">No leave records found</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
