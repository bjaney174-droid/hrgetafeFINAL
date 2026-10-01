<?php
session_start();
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

require_login();
check_role(3);

$employee_id = $_SESSION['employee_id'];
$employee = get_employee_info($conn, $employee_id);
$error_message = '';
$success_message = '';

// Get leave types (prepared)
$stmt = $conn->prepare("SELECT * FROM leave_types WHERE is_deleted = FALSE ORDER BY leave_type_name ASC");
if (!$stmt) {
    die("Error: " . $conn->error);
}
$stmt->execute();
$result = $stmt->get_result();
$leave_types = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get company holidays for current month and next month (prepared)
$start_date = date('Y-m-01');
$end_date = date('Y-m-t', strtotime('+1 month'));
$stmt = $conn->prepare("SELECT * FROM holidays WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date ASC");
if (!$stmt) {
    die("Error: " . $conn->error);
}
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();
$holidays = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error_message = '❌ Invalid form token. Please try again.';
    } else {
        $leave_type_id = (int)($_POST['leave_type_id'] ?? 0);
        $start_date_input = sanitizeInput($_POST['start_date'] ?? '');
        $end_date_input = sanitizeInput($_POST['end_date'] ?? '');
        $reason = sanitizeInput($_POST['reason'] ?? '');

        // Validation
        if ($leave_type_id <= 0) {
            $error_message = '❌ Please select a leave type.';
        } elseif (empty($start_date_input)) {
            $error_message = '❌ Please select a start date.';
        } elseif (empty($end_date_input)) {
            $error_message = '❌ Please select an end date.';
        } elseif (empty($reason)) {
            $error_message = '❌ Please provide a reason for your leave.';
        } elseif (strtotime($start_date_input) > strtotime($end_date_input)) {
            $error_message = '❌ End date must be after start date.';
        } elseif (strtotime($start_date_input) < strtotime(date('Y-m-d'))) {
            $error_message = '❌ Cannot apply for leave in the past.';
        } else {
            // Check for overlapping leaves (prepared)
            $stmt = $conn->prepare(
                "SELECT COUNT(*) as count FROM leave_requests 
                 WHERE employee_id = ? AND status IN ('pending', 'approved') 
                 AND ((start_date <= ? AND end_date >= ?) OR (start_date <= ? AND end_date >= ?))"
            );
            if ($stmt) {
                $stmt->bind_param("issss", $employee_id, $end_date_input, $start_date_input, $end_date_input, $start_date_input);
                $stmt->execute();
                $overlap_result = $stmt->get_result();
                $overlap_count = $overlap_result->fetch_assoc()['count'];
                $stmt->close();

                if ($overlap_count > 0) {
                    $error_message = '❌ You already have an overlapping leave request during this period.';
                } else {
                    // Calculate number of days
                    $start = new DateTime($start_date_input);
                    $end = new DateTime($end_date_input);
                    $interval = $start->diff($end);
                    $number_of_days = $interval->days + 1;

                    // Check leave balance
                    $balance = calculate_leave_balance($conn, $employee_id, $leave_type_id);

                    if ($balance < $number_of_days) {
                        $error_message = '❌ Insufficient leave balance. You have ' . $balance . ' days available.';
                    } else {
                        // Insert leave request (prepared)
                        $stmt = $conn->prepare(
                            "INSERT INTO leave_requests (employee_id, leave_type_id, start_date, end_date, number_of_days, reason, status, created_at) 
                             VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())"
                        );
                        if ($stmt) {
                            $status = 'pending';
                            $stmt->bind_param("iissds", $employee_id, $leave_type_id, $start_date_input, $end_date_input, $number_of_days, $reason);
                            
                            if ($stmt->execute()) {
                                logSecurityEvent($conn, $_SESSION['user_id'], 'APPLY_LEAVE', "Applied for leave from $start_date_input to $end_date_input");
                                $success_message = '✅ Leave request submitted successfully! HR will review your request within 2-3 business days.';
                                $_POST = [];
                            } else {
                                $error_message = '❌ Error submitting leave request. Please try again.';
                            }
                            $stmt->close();
                        } else {
                            $error_message = '❌ Server error. Please try again later.';
                        }
                    }
                }
            } else {
                $error_message = '❌ Error checking overlapping leaves. Please try again.';
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
    <title>Apply for Leave - HRGetafe</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Verdana, sans-serif; background: #f5f7fa; }

        .navbar { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            color: white; 
            padding: 15px 20px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .navbar h2 { margin: 0; font-size: 24px; }
        .navbar-user a { color: white; text-decoration: none; font-weight: 600; }

        .container { max-width: 1000px; margin: 0 auto; padding: 30px 20px; }

        .back-btn {
            display: inline-block;
            padding: 10px 20px;
            background: #f0f0f0;
            color: #333;
            text-decoration: none;
            border-radius: 4px;
            font-weight: 600;
            margin-bottom: 20px;
            transition: 0.3s;
        }
        .back-btn:hover { background: #e0e0e0; }

        .card {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 25px;
        }

        .card h3 {
            margin-top: 0;
            margin-bottom: 10px;
            color: #1f2937;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
            font-size: 20px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }

        input[type="text"],
        input[type="date"],
        select,
        textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            font-family: 'Segoe UI', Tahoma, Verdana, sans-serif;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        input[type="date"]:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid;
            font-size: 14px;
        }

        .alert-error {
            background: #fee2e2;
            border-color: #ef4444;
            color: #991b1b;
        }

        .alert-success {
            background: #d1fae5;
            border-color: #10b981;
            color: #065f46;
        }

        .info-box {
            background: #f0f4ff;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #667eea;
            margin-bottom: 25px;
            font-size: 13px;
            color: #1e40af;
            line-height: 1.6;
        }

        .leave-balance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
            margin-bottom: 25px;
        }

        .balance-card {
            background: #f9fafb;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #667eea;
        }

        .balance-label {
            font-size: 13px;
            color: #666;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .balance-number {
            font-size: 28px;
            font-weight: bold;
            color: #667eea;
        }

        .balance-unit {
            font-size: 12px;
            color: #999;
            margin-top: 5px;
        }

        .holidays-section {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 25px;
        }

        .holidays-title {
            font-weight: 600;
            color: #92400e;
            margin-bottom: 10px;
        }

        .holidays-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }

        .holiday-item {
            background: white;
            padding: 10px;
            border-radius: 4px;
            font-size: 13px;
            color: #666;
        }

        .calculated-days {
            background: #f9fafb;
            padding: 15px;
            border-radius: 6px;
            margin-top: 10px;
            font-size: 14px;
            color: #666;
        }

        .calculated-days strong {
            color: #667eea;
            font-size: 16px;
        }

        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 30px;
            justify-content: flex-start;
        }

        button,
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.3s;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
        }

        .btn-submit {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .btn-reset {
            background: #f0f0f0;
            color: #333;
        }

        .btn-reset:hover {
            background: #e0e0e0;
        }

        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; }
            .button-group { flex-direction: column; }
            button, .btn { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h2>📝 Apply for Leave</h2>
        <div class="navbar-user">
            <span>Welcome, <strong><?php echo htmlspecialchars($employee['first_name']); ?></strong></span>
            <a href="../logout.php">Logout</a>
        </div>
    </div>

    <div class="container">
        <a href="dashboard.php" class="back-btn">← Back to Dashboard</a>

        <div class="card">
            <h3>📝 Submit New Leave Request</h3>

            <?php if ($error_message): ?>
            <div class="alert alert-error"><?php echo $error_message; ?></div>
            <?php endif; ?>

            <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
            <?php endif; ?>

            <!-- Info Box -->
            <div class="info-box">
                ℹ️ <strong>Important:</strong> Review your available leave balance below before submitting your request. Leave requests must be submitted at least 5 working days in advance when possible. You will be notified once your request is approved or rejected.
            </div>

            <!-- Leave Balance -->
            <h4 style="margin-bottom: 15px; color: #1f2937;">Available Leave Balance</h4>
            <div class="leave-balance-grid">
                <?php foreach ($leave_types as $leave): ?>
                    <?php $balance = calculate_leave_balance($conn, $employee_id, $leave['leave_type_id']); ?>
                    <div class="balance-card">
                        <div class="balance-label"><?php echo htmlspecialchars($leave['leave_type_name']); ?></div>
                        <div class="balance-number"><?php echo $balance; ?></div>
                        <div class="balance-unit">days available</div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Company Holidays -->
            <?php if (!empty($holidays)): ?>
            <div class="holidays-section">
                <div class="holidays-title">📅 Upcoming Company Holidays</div>
                <div class="holidays-list">
                    <?php foreach ($holidays as $holiday): ?>
                        <div class="holiday-item">
                            <strong><?php echo format_date($holiday['holiday_date']); ?></strong><br>
                            <?php echo htmlspecialchars($holiday['holiday_name']); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Leave Application Form -->
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                <div class="form-group">
                    <label for="leave_type_id">Leave Type <span style="color: #ef4444;">*</span></label>
                    <select id="leave_type_id" name="leave_type_id" required onchange="updateBalance()">
                        <option value="">-- Select Leave Type --</option>
                        <?php foreach ($leave_types as $leave): ?>
                            <?php $balance = calculate_leave_balance($conn, $employee_id, $leave['leave_type_id']); ?>
                            <option value="<?php echo $leave['leave_type_id']; ?>" data-balance="<?php echo $balance; ?>">
                                <?php echo htmlspecialchars($leave['leave_type_name']); ?> (<?php echo $balance; ?> days available)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="start_date">Start Date <span style="color: #ef4444;">*</span></label>
                        <input 
                            type="date" 
                            id="start_date" 
                            name="start_date" 
                            required 
                            min="<?php echo date('Y-m-d'); ?>"
                            onchange="calculateDays()"
                            value="<?php echo isset($_POST['start_date']) ? htmlspecialchars($_POST['start_date']) : ''; ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="end_date">End Date <span style="color: #ef4444;">*</span></label>
                        <input 
                            type="date" 
                            id="end_date" 
                            name="end_date" 
                            required 
                            min="<?php echo date('Y-m-d'); ?>"
                            onchange="calculateDays()"
                            value="<?php echo isset($_POST['end_date']) ? htmlspecialchars($_POST['end_date']) : ''; ?>"
                        >
                    </div>
                </div>

                <div class="calculated-days" id="calculated-days" style="display: none;">
                    Number of days: <strong id="days-count">0</strong> day(s)
                </div>

                <div class="form-group">
                    <label for="reason">Reason for Leave <span style="color: #ef4444;">*</span></label>
                    <textarea 
                        id="reason" 
                        name="reason" 
                        required 
                        placeholder="Please provide the reason for your leave request (e.g., Personal, Medical, Family Emergency, etc.)..."
                    ><?php echo isset($_POST['reason']) ? htmlspecialchars($_POST['reason']) : ''; ?></textarea>
                </div>

                <div class="button-group">
                    <button type="submit" class="btn-submit">✅ Submit Leave Request</button>
                    <button type="reset" class="btn-reset">↺ Clear Form</button>
                </div>
            </form>
        </div>

        <!-- Information -->
        <div class="card" style="background: linear-gradient(135deg, #667eea15 0%, #764ba215 100%); border-left: 4px solid #667eea;">
            <h3 style="border: none; padding: 0; margin: 0 0 10px 0;">ℹ️ Leave Request Guidelines</h3>
            <ul style="color: #666; margin: 0; padding-left: 20px; line-height: 1.8; font-size: 14px;">
                <li>Submit leave requests as early as possible (at least 5 working days in advance when possible)</li>
                <li>Your request will be reviewed by the HR Department and your supervisor</li>
                <li>Approval will be based on department needs and your leave balance</li>
                <li>You will receive a notification email once your request is processed</li>
                <li>For emergency leave, contact the HR Department immediately by phone</li>
                <li>Check your "Leave Status" page to track the progress of your requests</li>
                <li>Once approved, your leave dates will appear in the company calendar</li>
            </ul>
        </div>
    </div>

    <script>
        function calculateDays() {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            const calculateDiv = document.getElementById('calculated-days');

            if (startDate && endDate) {
                const start = new Date(startDate);
                const end = new Date(endDate);
                const difference = end - start;
                const days = (difference / (1000 * 60 * 60 * 24)) + 1;

                if (days > 0) {
                    document.getElementById('days-count').textContent = Math.floor(days);
                    calculateDiv.style.display = 'block';
                } else {
                    calculateDiv.style.display = 'none';
                }
            }
        }

        function updateBalance() {
            const select = document.getElementById('leave_type_id');
            const selectedOption = select.options[select.selectedIndex];
            const balance = selectedOption.getAttribute('data-balance');
        }

        document.getElementById('start_date').min = new Date().toISOString().split('T')[0];
        document.getElementById('end_date').min = new Date().toISOString().split('T')[0];
    </script>
</body>
</html>
