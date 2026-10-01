<?php
session_start();
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

require_login();
check_role(2);

$staff = get_employee_info($conn, $_SESSION['employee_id']);
$message = '';
$error = '';

// Create biometric_imports table if not exists
$check_table = $conn->query("SHOW TABLES LIKE 'biometric_import_logs'");
if ($check_table->num_rows == 0) {
    $conn->query("
        CREATE TABLE IF NOT EXISTS biometric_import_logs (
            import_id INT PRIMARY KEY AUTO_INCREMENT,
            import_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            filename VARCHAR(255),
            total_records INT DEFAULT 0,
            successful_records INT DEFAULT 0,
            failed_records INT DEFAULT 0,
            import_notes TEXT,
            imported_by INT NOT NULL,
            FOREIGN KEY (imported_by) REFERENCES users(user_id)
        )
    ");
}

// Handle file upload and import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['attendance_file'])) {
    if (empty($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid or expired form token.';
    } else {
        $file = $_FILES['attendance_file'];
        
        // Validate file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = 'File upload failed. Please try again.';
        } elseif ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
            $error = 'File size exceeds 5MB limit.';
        } elseif (!in_array(pathinfo($file['name'], PATHINFO_EXTENSION), ['csv', 'txt', 'xlsx'])) {
            $error = 'Only CSV, TXT, or XLSX files are allowed.';
        } else {
            // Read and parse CSV
            $handle = fopen($file['tmp_name'], 'r');
            $total_records = 0;
            $successful_records = 0;
            $failed_records = 0;
            $import_errors = [];
            
            // Skip header row
            fgetcsv($handle);
            
            while (($data = fgetcsv($handle)) !== FALSE) {
                $total_records++;
                
                // Expected CSV format: employee_code, date, time_in, time_out, status
                if (count($data) < 4) {
                    $failed_records++;
                    $import_errors[] = "Row {$total_records}: Invalid format";
                    continue;
                }
                
                $employee_code = trim($data[0]);
                $attendance_date = trim($data[1]);
                $clock_in = isset($data[2]) ? trim($data[2]) : null;
                $clock_out = isset($data[3]) ? trim($data[3]) : null;
                $status = isset($data[4]) ? trim($data[4]) : 'present';
                
                // Validate date format
                if (!strtotime($attendance_date)) {
                    $failed_records++;
                    $import_errors[] = "Row {$total_records}: Invalid date format";
                    continue;
                }
                
                // Get employee ID from employee code
                $emp_stmt = $conn->prepare("SELECT employee_id FROM employees WHERE employee_code = ?");
                if ($emp_stmt) {
                    $emp_stmt->bind_param("s", $employee_code);
                    $emp_stmt->execute();
                    $emp_result = $emp_stmt->get_result();
                    
                    if ($emp_result->num_rows > 0) {
                        $emp_row = $emp_result->fetch_assoc();
                        $employee_id = $emp_row['employee_id'];
                        
                        // Format datetime if times are provided
                        $clock_in_datetime = null;
                        $clock_out_datetime = null;
                        
                        if ($clock_in) {
                            $clock_in_datetime = $attendance_date . ' ' . $clock_in;
                        }
                        if ($clock_out) {
                            $clock_out_datetime = $attendance_date . ' ' . $clock_out;
                        }
                        
                        // Check if attendance record already exists
                        $check_stmt = $conn->prepare("SELECT attendance_id FROM attendance WHERE employee_id = ? AND attendance_date = ?");
                        if ($check_stmt) {
                            $check_stmt->bind_param("is", $employee_id, $attendance_date);
                            $check_stmt->execute();
                            $check_result = $check_stmt->get_result();
                            
                            if ($check_result->num_rows > 0) {
                                // Update existing record
                                $update_stmt = $conn->prepare("UPDATE attendance SET clock_in = ?, clock_out = ?, status = ? WHERE employee_id = ? AND attendance_date = ?");
                                if ($update_stmt) {
                                    $update_stmt->bind_param("sssss", $clock_in_datetime, $clock_out_datetime, $status, $employee_id, $attendance_date);
                                    if ($update_stmt->execute()) {
                                        $successful_records++;
                                    } else {
                                        $failed_records++;
                                        $import_errors[] = "Row {$total_records}: Failed to update";
                                    }
                                    $update_stmt->close();
                                }
                            } else {
                                // Insert new record
                                $insert_stmt = $conn->prepare("INSERT INTO attendance (employee_id, clock_in, clock_out, attendance_date, status) VALUES (?, ?, ?, ?, ?)");
                                if ($insert_stmt) {
                                    $insert_stmt->bind_param("issss", $employee_id, $clock_in_datetime, $clock_out_datetime, $attendance_date, $status);
                                    if ($insert_stmt->execute()) {
                                        $successful_records++;
                                    } else {
                                        $failed_records++;
                                        $import_errors[] = "Row {$total_records}: Failed to insert";
                                    }
                                    $insert_stmt->close();
                                }
                            }
                            $check_stmt->close();
                        }
                    } else {
                        $failed_records++;
                        $import_errors[] = "Row {$total_records}: Employee code '{$employee_code}' not found";
                    }
                    $emp_stmt->close();
                }
            }
            
            fclose($handle);
            
            // Log import
            $imported_by = $_SESSION['user_id'];
            $import_notes = implode("; ", array_slice($import_errors, 0, 5));
            if (count($import_errors) > 5) {
                $import_notes .= "; ... and " . (count($import_errors) - 5) . " more errors";
            }
            
            $log_stmt = $conn->prepare("INSERT INTO biometric_import_logs (filename, total_records, successful_records, failed_records, import_notes, imported_by) VALUES (?, ?, ?, ?, ?, ?)");
            if ($log_stmt) {
                $log_stmt->bind_param("siiiisi", $file['name'], $total_records, $successful_records, $failed_records, $import_notes, $imported_by);
                $log_stmt->execute();
                $log_stmt->close();
            }
            
            // Show results
            $message = "
                <div class='alert alert-success'>
                    ✓ Import completed!<br>
                    <strong>Total Records:</strong> {$total_records}<br>
                    <strong>Successful:</strong> {$successful_records}<br>
                    <strong>Failed:</strong> {$failed_records}
                </div>
            ";
            
            if (!empty($import_errors)) {
                $message .= "
                    <div class='alert alert-warning'>
                        <strong>Errors:</strong><br>
                        " . htmlspecialchars(implode("<br>", array_slice($import_errors, 0, 10))) . "
                    </div>
                ";
            }
        }
    }
}

// Get recent imports
$recent_imports = $conn->query(
    "SELECT il.*, u.username FROM biometric_import_logs il
     JOIN users u ON il.imported_by = u.user_id
     ORDER BY il.import_date DESC LIMIT 10"
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Biometric Attendance - HRGetafe</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .navbar { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        .navbar h2 { margin: 0; }
        .navbar-user { display: flex; gap: 15px; }
        .navbar-user a { color: white; text-decoration: none; }
        
        .container { max-width: 1000px; margin: 0 auto; padding: 30px 20px; }
        
        .card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .card h3 { margin-top: 0; color: #1f2937; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 8px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-family: inherit; }
        
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 15px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        
        .upload-area { border: 2px dashed #667eea; border-radius: 8px; padding: 30px; text-align: center; cursor: pointer; background: #f9f9f9; transition: all 0.3s; }
        .upload-area:hover { background: #f0f0f0; border-color: #764ba2; }
        .upload-area.dragover { background: #e8eaf6; border-color: #764ba2; }
        
        .btn { display: inline-block; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; text-decoration: none; }
        .btn-primary { background: #667eea; color: white; }
        .btn-secondary { background: #f0f0f0; color: #333; }
        .btn-primary:hover { background: #764ba2; }
        
        .table-container { background: white; border-radius: 8px; overflow-x: auto; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .table-container table { width: 100%; border-collapse: collapse; }
        .table-container th { background: #f8f9fa; padding: 12px; text-align: left; font-weight: 600; border-bottom: 2px solid #e1e1e1; }
        .table-container td { padding: 12px; border-bottom: 1px solid #e1e1e1; }
        .table-container tr:hover { background: #f8f9fa; }
        
        .badge { display: inline-block; padding: 5px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        
        .sample-csv {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 4px;
            margin: 15px 0;
            font-family: monospace;
            font-size: 12px;
            overflow-x: auto;
        }
        
        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h2>📤 Import Biometric Attendance</h2>
        <div class="navbar-user">
            <span>Welcome, <strong><?php echo htmlspecialchars($staff['first_name']); ?></strong></span>
            <a href="../logout.php">Logout</a>
        </div>
    </div>

    <div class="container">
        <a href="dashboard.php" class="btn btn-secondary" style="margin-bottom: 20px;">← Back to Dashboard</a>

        <?php if ($message): ?>
            <div><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Upload Instructions -->
        <div class="info-box">
            <strong>📋 How to prepare your biometric file:</strong><br>
            <ul style="margin: 10px 0; padding-left: 20px;">
                <li>Export attendance data from your biometric system as CSV format</li>
                <li>Include columns: Employee Code, Date, Time In, Time Out, Status</li>
                <li>Date format: YYYY-MM-DD (e.g., 2026-07-12)</li>
                <li>Time format: HH:MM:SS (e.g., 08:30:45)</li>
                <li>Status values: present, absent, late</li>
                <li>Maximum file size: 5MB</li>
            </ul>
        </div>

        <!-- Upload Form -->
        <div class="card">
            <h3>Upload Biometric Data</h3>
            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="form-group">
                    <label>Select CSV File *</label>
                    <div class="upload-area" id="uploadArea">
                        <div style="font-size: 48px; margin-bottom: 10px;">📁</div>
                        <p><strong>Drag and drop your CSV file here</strong></p>
                        <p style="color: #999; font-size: 13px;">or click to browse</p>
                        <input type="file" name="attendance_file" id="fileInput" accept=".csv,.txt,.xlsx" style="display: none;" required>
                    </div>
                    <small style="color: #999; display: block; margin-top: 8px;">Accepted formats: CSV, TXT, XLSX | Max size: 5MB</small>
                </div>

                <div class="form-group">
                    <label>Import Notes (Optional)</label>
                    <textarea name="import_notes" style="resize: vertical; min-height: 80px;" placeholder="Add any notes about this import..."></textarea>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%;">✓ Import Attendance Data</button>
            </form>

            <!-- Sample CSV Format -->
            <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e1e1e1;">
                <h4>📝 Sample CSV Format</h4>
                <p style="color: #666; font-size: 13px;">Here's how your CSV file should be formatted:</p>
                <div class="sample-csv">
employee_code,date,time_in,time_out,status<br>
GETAFE-2026-001,2026-07-12,08:00:00,17:00:00,present<br>
GETAFE-2026-002,2026-07-12,08:30:00,17:00:00,late<br>
GETAFE-2026-003,2026-07-12,,, absent<br>
GETAFE-2026-004,2026-07-12,08:00:00,,present<br>
                </div>
                <button type="button" class="btn btn-secondary" style="margin-top: 10px;" onclick="downloadSampleCSV()">📥 Download Sample Template</button>
            </div>
        </div>

        <!-- Recent Imports -->
        <div class="card">
            <h3>📊 Recent Import History</h3>
            <?php if (!empty($recent_imports)): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Import Date</th>
                            <th>Filename</th>
                            <th>Total</th>
                            <th>Success</th>
                            <th>Failed</th>
                            <th>Imported By</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_imports as $import): ?>
                        <tr>
                            <td><?php echo date('M d, Y H:i', strtotime($import['import_date'])); ?></td>
                            <td><strong><?php echo htmlspecialchars($import['filename']); ?></strong></td>
                            <td><?php echo (int)$import['total_records']; ?></td>
                            <td><span class="badge badge-success"><?php echo (int)$import['successful_records']; ?></span></td>
                            <td><span class="badge badge-warning"><?php echo (int)$import['failed_records']; ?></span></td>
                            <td><?php echo htmlspecialchars($import['username']); ?></td>
                            <td>
                                <?php 
                                    $success_rate = $import['total_records'] > 0 ? ($import['successful_records'] / $import['total_records'] * 100) : 0;
                                    $badge_class = $success_rate >= 90 ? 'badge-success' : 'badge-warning';
                                ?>
                                <span class="badge <?php echo $badge_class; ?>"><?php echo round($success_rate, 1); ?>% Success</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p style="text-align: center; color: #999; padding: 30px;">No imports yet</p>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const uploadArea = document.getElementById('uploadArea');
        const fileInput = document.getElementById('fileInput');

        // Click to upload
        uploadArea.addEventListener('click', () => fileInput.click());

        // Drag and drop
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        });

        uploadArea.addEventListener('dragleave', () => {
            uploadArea.classList.remove('dragover');
        });

        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
            fileInput.files = e.dataTransfer.files;
            updateFileName();
        });

        fileInput.addEventListener('change', updateFileName);

        function updateFileName() {
            if (fileInput.files.length > 0) {
                uploadArea.innerHTML = `<div style="font-size: 32px; margin-bottom: 10px;">✓</div><p><strong>${fileInput.files[0].name}</strong></p><p style="color: #999; font-size: 13px;">Click to change</p>`;
            }
        }

        function downloadSampleCSV() {
            const csv = `employee_code,date,time_in,time_out,status
GETAFE-2026-001,2026-07-12,08:00:00,17:00:00,present
GETAFE-2026-002,2026-07-12,08:30:00,17:00:00,late
GETAFE-2026-003,2026-07-12,,, absent`;
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'attendance_sample.csv';
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);
        }
    </script>
</body>
</html>
