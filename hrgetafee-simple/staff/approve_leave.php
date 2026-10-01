<?php
session_start();
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

require_login();
check_role(2);

$staff = get_employee_info($conn, $_SESSION['employee_id']);

// Handle POST (approve / reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrf)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid or expired form token.'];
        header('Location: dashboard.php');
        exit;
    }

    $request_id = intval($_POST['request_id'] ?? 0);
    $action = sanitizeInput($_POST['action'] ?? '');
    $note = sanitizeInput($_POST['note'] ?? '');

    if ($request_id <= 0 || !in_array($action, ['approve', 'reject'])) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid request.'];
        header('Location: dashboard.php');
        exit;
    }

    $new_status = $action === 'approve' ? 'approved' : 'denied';
    $staff_user_id = $_SESSION['user_id'];

    $stmt = $conn->prepare("UPDATE leave_requests SET status = ?, staff_note = ?, approved_by = ?, approved_date = NOW() WHERE leave_id = ?");
    if ($stmt) {
        $stmt->bind_param("ssii", $new_status, $note, $staff_user_id, $request_id);
        if ($stmt->execute()) {
            $_SESSION['flash'] = ['type' => 'success', 'message' => "Leave request #{$request_id} has been {$new_status}."];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Failed to update leave request.'];
        }
        $stmt->close();
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Server error.'];
    }

    header('Location: dashboard.php');
    exit;
}

// GET: show pending leave requests (prepared)
$pending = [];
$stmt = $conn->prepare(
    "SELECT lr.leave_id, lr.employee_id, lr.start_date, lr.end_date, lr.number_of_days, lr.reason, lt.leave_type_name, e.first_name, e.last_name, e.employee_code
     FROM leave_requests lr
     JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
     JOIN employees e ON lr.employee_id = e.employee_id
     WHERE lr.status = 'pending'
     ORDER BY lr.created_at DESC"
);
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    $pending = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Leave - HRGetafe</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .btn { display:inline-block; padding:6px 10px; border-radius:4px; text-decoration:none; color:#fff; border:none; cursor:pointer; }
        .btn-success { background:#28a745; }
        .btn-danger { background:#dc3545; }
        .table-container table { width:100%; border-collapse:collapse; }
        .table-container th, .table-container td { padding:8px 10px; border:1px solid #e1e1e1; text-align:left; }
        .flash { padding:10px; margin-bottom:12px; border-radius:6px; }
        .flash-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
        .flash-error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
    </style>
</head>
<body>
    <div class="navbar">
        <h2>Approve Leave Requests</h2>
        <div class="navbar-user">
            <span>Welcome, <strong><?php echo htmlspecialchars($staff['first_name']); ?></strong></span>
            <a href="../logout.php">Logout</a>
        </div>
    </div>

    <div class="container">
        <a href="dashboard.php" class="btn" style="background:#6c757d;color:#fff;margin-bottom:16px;display:inline-block;padding:8px 12px;border-radius:6px;">← Back to Dashboard</a>

        <?php if (!empty($_SESSION['flash'])): 
            $f = $_SESSION['flash']; unset($_SESSION['flash']);
            $cls = ($f['type'] === 'success') ? 'flash-success' : 'flash-error';
        ?>
            <div class="flash <?php echo $cls; ?>"><?php echo htmlspecialchars($f['message']); ?></div>
        <?php endif; ?>

        <div class="table-container">
            <h3>📮 Leave Requests for Approval (<?php echo count($pending); ?>)</h3>
            <?php if (!empty($pending)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Leave Type</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Days</th>
                        <th>Reason</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending as $leave): ?>
                    <tr>
                        <td>
                            <?php echo htmlspecialchars($leave['first_name'] . ' ' . $leave['last_name']); ?><br>
                            <small><?php echo htmlspecialchars($leave['employee_code']); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($leave['leave_type_name']); ?></td>
                        <td><?php echo format_date($leave['start_date']); ?></td>
                        <td><?php echo format_date($leave['end_date']); ?></td>
                        <td><?php echo (int)$leave['number_of_days']; ?></td>
                        <td><?php echo htmlspecialchars($leave['reason']); ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="request_id" value="<?php echo (int)$leave['leave_id']; ?>">
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="btn btn-success">✓ Approve</button>
                            </form>

                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="request_id" value="<?php echo (int)$leave['leave_id']; ?>">
                                <input type="hidden" name="action" value="reject">
                                <button type="submit" class="btn btn-danger">✗ Deny</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="text-center" style="padding: 30px;">✓ No pending leave requests</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>