<?php
// manage_employees.php - canonical employee records (create / edit only)

session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/security.php';

require_login();
check_role(1);

$admin = get_employee_info($conn, $_SESSION['employee_id']);
$message = '';
$error = '';
$edit_employee = null;

// Get all employees
$employees_result = $conn->query("SELECT * FROM employees ORDER BY employee_code");
$employees = $employees_result ? $employees_result->fetch_all(MYSQLI_ASSOC) : [];

// Check if editing
if (isset($_GET['edit'])) {
    $employee_id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM employees WHERE employee_id = ? LIMIT 1");
    $stmt->bind_param('i', $employee_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $edit_employee = $res ? $res->fetch_assoc() : null;
    $stmt->close();
}

// Handle form submission: add / edit (NO deactivate here)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CSRF
    if (empty($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid or expired form token.';
    } else {
        if ($action === 'add_employee') {
            $first_name = sanitizeInput($_POST['first_name'] ?? '');
            $last_name = sanitizeInput($_POST['last_name'] ?? '');
            $email = sanitizeInput($_POST['email'] ?? '');
            $phone = sanitizeInput($_POST['phone'] ?? '');
            $position = sanitizeInput($_POST['position'] ?? '');
            $salary = floatval($_POST['salary'] ?? 0);
            $date_hired = sanitizeInput($_POST['date_hired'] ?? date('Y-m-d'));

            if ($first_name === '' || $last_name === '' || $email === '') {
                $error = 'First name, last name, and email are required.';
            } else {
                // Generate employee code
                $year = date('Y');
                $like = "GETAFE-{$year}-%";
                $stmt = $conn->prepare("SELECT employee_code FROM employees WHERE employee_code LIKE ? ORDER BY employee_code DESC LIMIT 1");
                $stmt->bind_param('s', $like);
                $stmt->execute();
                $res = $stmt->get_result();
                $last_emp = $res->fetch_assoc();
                $stmt->close();

                $next_num = 1;
                if ($last_emp && preg_match('/GETAFE-\d+-(\d+)/', $last_emp['employee_code'], $matches)) {
                    $next_num = intval($matches[1]) + 1;
                }
                $employee_code = 'GETAFE-' . $year . '-' . str_pad($next_num, 3, '0', STR_PAD_LEFT);

                $stmt = $conn->prepare("INSERT INTO employees (employee_code, first_name, last_name, email, phone, position, salary, date_hired, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')");
                $stmt->bind_param('sssssdss', $employee_code, $first_name, $last_name, $email, $phone, $position, $salary, $date_hired);
                if ($stmt->execute()) {
                    $message = 'Employee added successfully.';
                    logSecurityEvent($conn, $_SESSION['user_id'], 'ADD_EMPLOYEE', "Added employee: $employee_code");
                    header('Location: manage_employees.php');
                    exit;
                } else {
                    $error = 'Error adding employee: ' . htmlspecialchars($stmt->error);
                }
                $stmt->close();
            }
        } elseif ($action === 'edit_employee') {
            $employee_id = intval($_POST['employee_id'] ?? 0);
            $first_name = sanitizeInput($_POST['first_name'] ?? '');
            $last_name = sanitizeInput($_POST['last_name'] ?? '');
            $email = sanitizeInput($_POST['email'] ?? '');
            $phone = sanitizeInput($_POST['phone'] ?? '');
            $position = sanitizeInput($_POST['position'] ?? '');
            $salary = floatval($_POST['salary'] ?? 0);
            $date_hired = sanitizeInput($_POST['date_hired'] ?? date('Y-m-d'));
            $status = sanitizeInput($_POST['status'] ?? 'active');

            if ($employee_id <= 0 || $first_name === '' || $last_name === '') {
                $error = 'Invalid input.';
            } else {
                $stmt = $conn->prepare("UPDATE employees SET first_name=?, last_name=?, email=?, phone=?, position=?, salary=?, date_hired=?, status=? WHERE employee_id=?");
                $stmt->bind_param('sssssdssi', $first_name, $last_name, $email, $phone, $position, $salary, $date_hired, $status, $employee_id);
                if ($stmt->execute()) {
                    $message = 'Employee updated successfully.';
                    logSecurityEvent($conn, $_SESSION['user_id'], 'EDIT_EMPLOYEE', "Updated employee_id: $employee_id");
                    header('Location: manage_employees.php');
                    exit;
                } else {
                    $error = 'Error updating employee: ' . htmlspecialchars($stmt->error);
                }
                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Employees - HRGetafe</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .card-form { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    </style>
</head>
<body>
    <div class="navbar">
        <h2>Manage Employee Records</h2>
        <div class="navbar-user">
            <span>Welcome, <strong><?php echo htmlspecialchars($admin['first_name']); ?></strong></span>
            <a href="../logout.php">Logout</a>
        </div>
    </div>

    <div class="container">
        <?php if (!empty($_SESSION['flash_error'])) { echo '<div class="alert alert-danger">'.htmlspecialchars($_SESSION['flash_error']).'</div>'; unset($_SESSION['flash_error']); }
              if (!empty($_SESSION['flash_success'])) { echo '<div class="alert alert-success">'.htmlspecialchars($_SESSION['flash_success']).'</div>'; unset($_SESSION['flash_success']); }
              if ($message) echo '<div class="alert alert-success">'.htmlspecialchars($message).'</div>';
              if ($error) echo '<div class="alert alert-danger">'.htmlspecialchars($error).'</div>';
        ?>

        <a href="dashboard.php" class="btn btn-secondary" style="margin-bottom: 20px;">← Back to Dashboard</a>

        <!-- Add/Edit Employee Form -->
        <div class="card-form">
            <h3><?php echo $edit_employee ? 'Edit Employee' : 'Add New Employee'; ?></h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="<?php echo $edit_employee ? 'edit_employee' : 'add_employee'; ?>">
                <?php if ($edit_employee): ?>
                    <input type="hidden" name="employee_id" value="<?php echo $edit_employee['employee_id']; ?>">
                <?php endif; ?>

                <div class="form-row">
                    <label>
                        First Name *
                        <input type="text" name="first_name" required value="<?php echo htmlspecialchars($edit_employee['first_name'] ?? ''); ?>">
                    </label>
                    <label>
                        Last Name *
                        <input type="text" name="last_name" required value="<?php echo htmlspecialchars($edit_employee['last_name'] ?? ''); ?>">
                    </label>
                    <label>
                        Email *
                        <input type="email" name="email" required value="<?php echo htmlspecialchars($edit_employee['email'] ?? ''); ?>">
                    </label>
                    <label>
                        Phone
                        <input type="text" name="phone" value="<?php echo htmlspecialchars($edit_employee['phone'] ?? ''); ?>">
                    </label>
                    <label>
                        Position *
                        <input type="text" name="position" required value="<?php echo htmlspecialchars($edit_employee['position'] ?? ''); ?>">
                    </label>
                    <label>
                        Salary (₱) *
                        <input type="number" step="0.01" min="0" name="salary" required value="<?php echo htmlspecialchars($edit_employee['salary'] ?? '0'); ?>">
                    </label>
                    <label>
                        Date Hired *
                        <input type="date" name="date_hired" required value="<?php echo htmlspecialchars($edit_employee['date_hired'] ?? date('Y-m-d')); ?>">
                    </label>
                    <label>
                        Status
                        <select name="status">
                          <option value="active" <?php echo (($edit_employee['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Active</option>
                          <option value="inactive" <?php echo (($edit_employee['status'] ?? '') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </label>
                </div>

                <div style="margin-top:12px;">
                    <button class="btn btn-primary" type="submit"><?php echo $edit_employee ? 'Update Employee' : 'Add Employee'; ?></button>
                    <?php if ($edit_employee): ?><a class="btn btn-secondary" href="manage_employees.php">Cancel</a><?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Employee List -->
        <div style="background:white;padding:16px;border-radius:8px">
            <h3>Employee Records (<?php echo count($employees); ?>)</h3>
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Position</th>
                        <th>Salary</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($employees)): ?>
                        <tr><td colspan="7" style="padding:12px;color:#666">No employees found.</td></tr>
                    <?php else: foreach ($employees as $emp): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($emp['employee_code']); ?></td>
                            <td><?php echo htmlspecialchars($emp['first_name'].' '.$emp['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($emp['email']); ?></td>
                            <td><?php echo htmlspecialchars($emp['position']); ?></td>
                            <td>₱<?php echo number_format($emp['salary'],2); ?></td>
                            <td><?php echo htmlspecialchars($emp['status']); ?></td>
                            <td>
                                <a href="manage_employees.php?edit=<?php echo (int)$emp['employee_id']; ?>" class="btn btn-secondary">Edit</a>
                                <!-- No manage-account or deactivate here; account actions are in Manage Users -->
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>