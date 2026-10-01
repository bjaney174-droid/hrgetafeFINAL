<?php
session_start();
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/payroll_functions.php';

require_login();
check_role(2);

$staff = get_employee_info($conn, $_SESSION['employee_id']);

// Get filters
$filter_employee = isset($_GET['employee']) ? (int)$_GET['employee'] : 0;
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';

// Build query
$where_clauses = [];
if ($filter_employee > 0) {
    $where_clauses[] = "p.employee_id = {$filter_employee}";
}
if ($filter_status) {
    $where_clauses[] = "p.status = '{$filter_status}'";
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Get payslips
$payslips = $conn->query(
    "SELECT p.*, e.first_name, e.last_name, e.employee_code, e.position, e.salary
     FROM payroll p
     JOIN employees e ON p.employee_id = e.employee_id
     {$where_sql}
     ORDER BY p.payroll_year DESC, p.payroll_month DESC
     LIMIT 200"
)->fetch_all(MYSQLI_ASSOC);

// Get employees
$employees = $conn->query("SELECT employee_id, first_name, last_name, employee_code FROM employees WHERE status = 'active' ORDER BY first_name")->fetch_all(MYSQLI_ASSOC);

// Get statistics
$stats = [];
$stats_result = $conn->query(
    "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END) as processed_count,
        SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count
     FROM payroll"
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
    <title>View Payslips - HRGetafe</title>
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
        .stat-box.info { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        
        .filter-section { background: #f9f9f9; padding: 20px; border-radius: 8px; margin-bottom: 25px; }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px; }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-weight: 600; margin-bottom: 8px; }
        .filter-group input, .filter-group select { padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
        
        .btn { display: inline-block; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; text-decoration: none; }
        .btn-primary { background: #667eea; color: white; }
        .btn-secondary { background: #f0f0f0; color: #333; }
        .btn-small { padding: 6px 12px; font-size: 12px; }
        .btn-primary:hover { background: #764ba2; }
        
        .table-container { background: white; border-radius: 8px; overflow-x: auto; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .table-container table { width: 100%; border-collapse: collapse; }
        .table-container th { background: #f8f9fa; padding: 12px; text-align: left; font-weight: 600; border-bottom: 2px solid #e1e1e1; font-size: 13px; }
        .table-container td { padding: 12px; border-bottom: 1px solid #e1e1e1; font-size: 13px; }
        .table-container tr:hover { background: #f8f9fa; }
        
        .badge { display: inline-block; padding: 5px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-processed { background: #bfdbfe; color: #1e40af; }
        .badge-paid { background: #d1fae5; color: #065f46; }
    </style>
</head>
<body>
    <div class="navbar">
        <h2>📄 View Payslips</h2>
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
            <div class="stat-box info">
                <h4>⚙️ Processed</h4>
                <div class="stat-number"><?php echo (int)($stats['processed_count'] ?? 0); ?></div>
            </div>
            <div class="stat-box success">
                <h4>✅ Paid</h4>
                <div class="stat-number"><?php echo (int)($stats['paid_count'] ?? 0); ?></div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <h4 style="margin-top: 0;">🔍 Filter Payslips</h4>
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
                        <label>Status</label>
                        <select name="status">
                            <option value="">-- All Status --</option>
                            <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="processed" <?php echo $filter_status === 'processed' ? 'selected' : ''; ?>>Processed</option>
                            <option value="paid" <?php echo $filter_status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                        </select>
                    </div>
                </div>

                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary">🔍 Filter</button>
                    <a href="view_payslips.php" class="btn btn-secondary">↻ Reset</a>
                </div>
            </form>
        </div>

        <!-- Payslips Table -->
        <div class="card">
            <h3>📄 Payslips (<?php echo count($payslips); ?>)</h3>
            
            <?php if (!empty($payslips)): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Period</th>
                            <th>Gross Salary</th>
                            <th>Deductions</th>
                            <th>Net Salary</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payslips as $slip): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($slip['first_name'] . ' ' . $slip['last_name']); ?></strong><br>
                                <small><?php echo htmlspecialchars($slip['employee_code']); ?></small>
                            </td>
                            <td>
                                <strong><?php echo date('F Y', mktime(0, 0, 0, $slip['payroll_month'], 1, $slip['payroll_year'])); ?></strong>
                            </td>
                            <td><?php echo format_currency($slip['gross_salary']); ?></td>
                            <td><?php echo format_currency($slip['deductions']); ?></td>
                            <td><strong><?php echo format_currency($slip['net_salary']); ?></strong></td>
                            <td>
                                <?php 
                                    $badge_class = 'badge-pending';
                                    $badge_text = '⏳ Pending';
                                    
                                    if ($slip['status'] === 'processed') {
                                        $badge_class = 'badge-processed';
                                        $badge_text = '⚙️ Processed';
                                    } elseif ($slip['status'] === 'paid') {
                                        $badge_class = 'badge-paid';
                                        $badge_text = '✅ Paid';
                                    }
                                ?>
                                <span class="badge <?php echo $badge_class; ?>"><?php echo $badge_text; ?></span>
                            </td>
                            <td>
                                <a href="print_payslip.php?payroll_id=<?php echo $slip['payroll_id']; ?>" class="btn btn-primary btn-small" target="_blank">🖨️ Print</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p style="text-align: center; color: #999; padding: 30px;">No payslips found</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
