<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/security.php';
require_once '../includes/soft_delete_helper.php';

require_login();
check_role(1);

$admin = get_employee_info($conn, $_SESSION['employee_id']);
$message = '';
$error = '';

// Add leave type
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_leave'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    
    if (empty($csrf) || !verifyCSRFToken($csrf)) {
        $error = 'Invalid form token. Please reload the page.';
    } else {
        $leave_type_name = sanitizeInput($_POST['leave_type_name'] ?? '');
        $max_days = intval($_POST['max_days'] ?? 0);
        $description = sanitizeInput($_POST['description'] ?? '');
        
        if (empty($leave_type_name) || $max_days <= 0) {
            $error = 'Leave type name and max days are required.';
        } else {
            // Check if leave type already exists (only active ones)
            $check_stmt = $conn->prepare("SELECT leave_type_id FROM leave_types WHERE LOWER(leave_type_name) = LOWER(?) AND is_deleted = FALSE LIMIT 1");
            if ($check_stmt) {
                $check_stmt->bind_param('s', $leave_type_name);
                $check_stmt->execute();
                $check_stmt->store_result();
                
                if ($check_stmt->num_rows > 0) {
                    $error = 'This leave type already exists.';
                    $check_stmt->close();
                } else {
                    $check_stmt->close();
                    
                    $stmt = $conn->prepare("INSERT INTO leave_types (leave_type_name, max_days_per_year, description, created_at) VALUES (?, ?, ?, NOW())");
                    if ($stmt) {
                        $stmt->bind_param("sis", $leave_type_name, $max_days, $description);
                        
                        if ($stmt->execute()) {
                            $message = '✓ Leave type added successfully';
                            logSecurityEvent($conn, $_SESSION['user_id'], 'ADD_LEAVE_TYPE', "Added leave type: $leave_type_name with $max_days days");
                            header('Location: manage_leaves.php');
                            exit;
                        } else {
                            $error = 'Error adding leave type: ' . htmlspecialchars($stmt->error);
                        }
                        $stmt->close();
                    } else {
                        $error = 'Database error: ' . htmlspecialchars($conn->error);
                    }
                }
            } else {
                $error = 'Database error: ' . htmlspecialchars($conn->error);
            }
        }
    }
}

// Delete leave type (soft delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_leave'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    
    if (empty($csrf) || !verifyCSRFToken($csrf)) {
        $error = 'Invalid form token. Please reload the page.';
    } else {
        $delete_id = intval($_POST['leave_type_id'] ?? 0);
        
        if ($delete_id <= 0) {
            $error = 'Invalid leave type.';
        } else {
            // Get leave type data before soft deleting
            $verify_stmt = $conn->prepare("SELECT leave_type_id, leave_type_name, max_days_per_year, description FROM leave_types WHERE leave_type_id = ? AND is_deleted = FALSE LIMIT 1");
            if ($verify_stmt) {
                $verify_stmt->bind_param('i', $delete_id);
                $verify_stmt->execute();
                $verify_res = $verify_stmt->get_result();
                $leave_data = $verify_res->fetch_assoc();
                $verify_stmt->close();
                
                if ($leave_data) {
                    // Soft delete using helper function
                    $result = softDeleteItem($conn, 'leave_type', $delete_id, $leave_data['leave_type_name'], $leave_data, $_SESSION['user_id']);
                    
                    if ($result['success']) {
                        $message = '✓ Leave type moved to recycle bin.';
                        logSecurityEvent($conn, $_SESSION['user_id'], 'DELETE_LEAVE_TYPE', "Moved to bin: {$leave_data['leave_type_name']}");
                    } else {
                        $error = $result['error'];
                    }
                } else {
                    $error = 'Leave type not found.';
                }
            } else {
                $error = 'Database error: ' . htmlspecialchars($conn->error);
            }
        }
    }
}

// Get active leave types only
$leave_types = [];
$stmt = $conn->prepare("SELECT leave_type_id, leave_type_name, max_days_per_year, description FROM leave_types WHERE is_deleted = FALSE ORDER BY leave_type_name");
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    $leave_types = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Leave Types - HRGetafe</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .recycle-link { display: inline-block; margin-left: 10px; padding: 8px 12px; background: #f59e0b; color: white; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 12px; }
        .recycle-link:hover { background: #d97706; }
        .alert { padding: 12px; border-radius: 6px; margin-bottom: 16px; }
        .alert-success { background: #ecfdf5; border: 1px solid #d1fae5; color: #065f46; }
        .alert-danger { background: #fff1f2; border: 1px solid #fecaca; color: #7f1d1d; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f7f7fb; font-weight: 600; }
        tr:hover { background: #f9f9f9; }
        .btn-delete { background: #ef4444; color: white; padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600; }
        .btn-delete:hover { background: #dc2626; }
        .badge-success { display: inline-block; background: #d1fae5; color: #065f46; padding: 4px 8px; border-radius: 4px; font-weight: 600; font-size: 12px; }
    </style>
</head>
<body>
    <div class="navbar">
        <h2>Leave Type Management</h2>
        <div class="navbar-user">
            <span>Welcome, <strong><?php echo htmlspecialchars($admin['first_name']); ?></strong></span>
            <a href="../logout.php">Logout</a>
        </div>
    </div>

    <div class="container">
        <a href="dashboard.php" class="btn btn-secondary" style="margin-bottom: 12px;">← Back to Dashboard</a>
        <a href="recycle_bin.php" class="recycle-link">🗑️ Recycle Bin</a>

        <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <!-- Add Leave Type Form -->
        <div class="card" style="max-width: 500px; margin-bottom: 30px;">
            <h3>➕ Add Leave Type</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="form-group">
                    <label for="leave_type_name">Leave Type Name</label>
                    <input type="text" name="leave_type_name" id="leave_type_name" placeholder="e.g., Vacation Leave" required>
                </div>

                <div class="form-group">
                    <label for="max_days">Max Days Per Year</label>
                    <input type="number" name="max_days" id="max_days" min="1" placeholder="e.g., 15" required>
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea name="description" id="description" placeholder="Describe this leave type..."></textarea>
                </div>

                <button type="submit" name="add_leave" class="btn btn-primary" style="width: 100%;">Add Leave Type</button>
            </form>
        </div>

        <!-- Leave Types List -->
        <div class="table-container">
            <h3>📋 All Leave Types (<?php echo count($leave_types); ?>)</h3>
            <?php if (empty($leave_types)): ?>
                <div style="padding: 20px; text-align: center; color: #666;">No leave types found. Create one above!</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Leave Type</th>
                            <th>Max Days/Year</th>
                            <th>Description</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leave_types as $leave): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($leave['leave_type_name']); ?></strong></td>
                            <td><span class="badge-success"><?php echo (int)$leave['max_days_per_year']; ?> days</span></td>
                            <td><?php echo htmlspecialchars($leave['description'] ?? '-'); ?></td>
                            <td>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Move this leave type to recycle bin?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="delete_leave" value="1">
                                    <input type="hidden" name="leave_type_id" value="<?php echo (int)$leave['leave_type_id']; ?>">
                                    <button class="btn-delete" type="submit">🗑️ Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
