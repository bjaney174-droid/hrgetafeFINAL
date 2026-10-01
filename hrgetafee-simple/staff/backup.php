<?php
// hrgetafee-simple/staff/backup.php
session_start();
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

require_login();
check_role(2); // HR Staff only

$staff = get_employee_info($conn, $_SESSION['employee_id']);
$message = '';
$error = '';
$success = false;

$backupDir = __DIR__ . '/../scripts/backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
}

// Handle Create Backup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_backup'])) {
    if (empty($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = '❌ Invalid form token. Please try again.';
    } else {
        $timestamp = date('Ymd_His');
        $filename = "hrgetafee_backup_{$timestamp}.sql";
        $filepath = $backupDir . "/" . $filename;

        try {
            $output = '';

            // Get all tables
            $tables = [];
            $result = $conn->query("SHOW TABLES");
            if ($result) {
                while ($row = $result->fetch_row()) {
                    $tables[] = $row[0];
                }
            }

            if (empty($tables)) {
                $error = '❌ No tables found in database.';
            } else {
                // Build SQL dump header
                $output .= "-- HRGetafe Database Backup\n";
                $output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
                $output .= "-- Database: " . DB_NAME . "\n";
                $output .= "-- Created by: " . htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']) . "\n";
                $output .= "-- Tables: " . count($tables) . "\n\n";
                $output .= "SET FOREIGN_KEY_CHECKS=0;\n";
                $output .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";

                // Dump each table
                $totalRecords = 0;
                foreach ($tables as $table) {
                    $output .= "-- ==========================================\n";
                    $output .= "-- Table: `$table`\n";
                    $output .= "-- ==========================================\n";
                    $output .= "DROP TABLE IF EXISTS `$table`;\n";

                    // Create table
                    $createTableResult = $conn->query("SHOW CREATE TABLE `$table`");
                    if ($createTableResult) {
                        $createTableRow = $createTableResult->fetch_row();
                        $output .= $createTableRow[1] . ";\n\n";

                        // Insert data
                        $dataResult = $conn->query("SELECT * FROM `$table`");
                        if ($dataResult && $dataResult->num_rows > 0) {
                            $recordCount = $dataResult->num_rows;
                            $totalRecords += $recordCount;
                            $output .= "-- Inserting " . $recordCount . " record(s)\n";

                            while ($row = $dataResult->fetch_assoc()) {
                                $cols = '`' . implode('`, `', array_keys($row)) . '`';
                                $vals = array_map(function($v) use ($conn) {
                                    if ($v === null) {
                                        return 'NULL';
                                    }
                                    return "'" . $conn->real_escape_string((string)$v) . "'";
                                }, array_values($row));
                                $output .= "INSERT INTO `$table` (" . $cols . ") VALUES (" . implode(", ", $vals) . ");\n";
                            }
                            $output .= "\n";
                        }
                    }
                }

                $output .= "SET FOREIGN_KEY_CHECKS=1;\n";
                $output .= "-- Backup completed successfully\n";

                // Write to file
                $bytesWritten = file_put_contents($filepath, $output);

                if ($bytesWritten !== false && $bytesWritten > 0) {
                    $sizeKB = round($bytesWritten / 1024, 2);
                    $sizeMB = round($bytesWritten / (1024 * 1024), 3);
                    $displaySize = $sizeMB >= 1 ? $sizeMB . " MB" : $sizeKB . " KB";
                    
                    $message = "✅ Backup created successfully!\n";
                    $message .= "📁 File: " . $filename . "\n";
                    $message .= "📊 Size: " . $displaySize . "\n";
                    $message .= "📝 Records: " . $totalRecords . " total\n";
                    $message .= "🕐 Time: " . date('M d, Y H:i:s');
                    
                    $success = true;
                    
                    // Log the backup
                    logSecurityEvent($conn, $_SESSION['user_id'], 'DATABASE_BACKUP_STAFF', 'HR Staff created backup: ' . $filename);
                } else {
                    $error = '❌ Failed to write backup file. Check directory permissions.';
                    if (file_exists($filepath)) @unlink($filepath);
                }
            }
        } catch (Exception $e) {
            $error = '❌ Backup error: ' . $e->getMessage();
        }
    }
}

// Handle Delete Backup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_backup'])) {
    if (empty($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = '❌ Invalid form token.';
    } else {
        $filename = basename($_POST['delete_backup']);
        $filepath = $backupDir . "/" . $filename;

        // Security check
        if (realpath($filepath) && strpos(realpath($filepath), realpath($backupDir)) === 0) {
            if (file_exists($filepath) && unlink($filepath)) {
                $message = "✅ Backup deleted: " . $filename;
                logSecurityEvent($conn, $_SESSION['user_id'], 'DATABASE_BACKUP_DELETE', 'Deleted backup: ' . $filename);
            } else {
                $error = '❌ Failed to delete backup file.';
            }
        } else {
            $error = '❌ Invalid backup file.';
        }
    }
}

// List backups
$files = [];
if (is_dir($backupDir)) {
    foreach (glob($backupDir . '/hrgetafee_backup_*.sql') as $f) {
        $files[] = [
            'name' => basename($f),
            'size' => filesize($f),
            'time' => filemtime($f),
            'path' => $f
        ];
    }
}
usort($files, function($a, $b) {
    return $b['time'] <=> $a['time'];
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Records - HRGetafe</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .navbar { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        .navbar h2 { margin: 0; }
        .navbar-user { display: flex; gap: 15px; }
        .navbar-user a { color: white; text-decoration: none; }

        .container { max-width: 1000px; margin: 0 auto; padding: 30px 20px; }

        .card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .card h3 { margin-top: 0; color: #1f2937; border-bottom: 2px solid #667eea; padding-bottom: 10px; }

        .alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid; }
        .alert-success { background: #d1fae5; color: #065f46; border-color: #10b981; }
        .alert-danger { background: #fee2e2; color: #991b1b; border-color: #ef4444; }

        .btn { display: inline-block; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; text-decoration: none; margin-right: 10px; }
        .btn-primary { background: #667eea; color: white; }
        .btn-success { background: #10b981; color: white; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-secondary { background: #f0f0f0; color: #333; }
        .btn:hover { opacity: 0.9; }

        .backup-list { margin-top: 20px; }
        .backup-item { display: flex; justify-content: space-between; align-items: center; padding: 15px; border: 1px solid #e5e7eb; margin-bottom: 12px; border-radius: 6px; background: #f9fafb; }
        .backup-info { flex: 1; }
        .backup-name { font-weight: 600; color: #1f2937; margin-bottom: 8px; }
        .backup-details { font-size: 13px; color: #666; line-height: 1.6; }
        .backup-actions { display: flex; gap: 10px; }
        .backup-actions form { display: inline; margin: 0; }
        .backup-actions button { padding: 8px 12px; font-size: 12px; }

        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-box { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; text-align: center; }
        .stat-box h4 { margin: 0; font-size: 13px; opacity: 0.9; }
        .stat-number { font-size: 28px; font-weight: bold; margin-top: 10px; }

        .empty-state { text-align: center; color: #999; padding: 40px 20px; }

        .warning-box { background: #fef3c7; border: 1px solid #fcd34d; padding: 15px; border-radius: 6px; margin-bottom: 20px; color: #92400e; }
    </style>
</head>
<body>
    <div class="navbar">
        <h2>💾 Backup Records</h2>
        <div class="navbar-user">
            <span>Welcome, <strong><?php echo htmlspecialchars($staff['first_name']); ?></strong></span>
            <a href="../logout.php">Logout</a>
        </div>
    </div>

    <div class="container">
        <a href="dashboard.php" class="btn btn-secondary" style="margin-bottom: 20px;">← Back to Dashboard</a>

        <!-- Alerts -->
        <?php if ($success): ?>
            <div class="alert alert-success">
                <strong>✅ Success!</strong><br>
                <?php echo nl2br(htmlspecialchars($message)); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <strong>❌ Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats">
            <div class="stat-box">
                <h4>📁 Total Backups</h4>
                <div class="stat-number"><?php echo count($files); ?></div>
            </div>
            <?php if (!empty($files)): ?>
                <?php 
                    $totalSize = array_sum(array_map(function($f) { return $f['size']; }, $files));
                    $totalSizeMB = round($totalSize / (1024 * 1024), 2);
                ?>
                <div class="stat-box" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                    <h4>💾 Total Storage</h4>
                    <div class="stat-number"><?php echo $totalSizeMB; ?> MB</div>
                </div>
                <div class="stat-box" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
                    <h4>🕐 Latest Backup</h4>
                    <div class="stat-number" style="font-size: 14px;"><?php echo date('M d, Y', $files[0]['time']); ?></div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Create Backup Section -->
        <div class="card">
            <h3>📥 Create New Backup</h3>
            <p style="color: #666; margin: 10px 0 20px 0;">
                Create a complete backup of the HRGetafe database. This backup includes all employee records, attendance, leaves, payroll, and system data.
            </p>

            <div class="warning-box">
                <strong>⚠️ Important:</strong> Always download and save backups to a safe location. Keep regular backups to ensure data recovery in case of emergency.
            </div>

            <form method="POST" onsubmit="return confirm('Create a new database backup? This may take a minute...');">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <button type="submit" name="create_backup" value="1" class="btn btn-primary" style="font-size: 16px; padding: 12px 30px;">
                    📥 Create Backup Now
                </button>
                <p style="font-size: 12px; color: #999; margin-top: 10px;">Database will be backed up to the server. Download the file to keep it safe.</p>
            </form>
        </div>

        <!-- Available Backups Section -->
        <div class="card">
            <h3>📂 Available Backups</h3>

            <?php if (!empty($files)): ?>
                <p style="color: #666; margin-bottom: 20px;">
                    You have <strong><?php echo count($files); ?></strong> backup(s). Download and store them safely.
                </p>

                <div class="backup-list">
                    <?php foreach ($files as $backup): ?>
                        <div class="backup-item">
                            <div class="backup-info">
                                <div class="backup-name">
                                    📄 <?php echo htmlspecialchars($backup['name']); ?>
                                </div>
                                <div class="backup-details">
                                    <div>📊 Size: <strong><?php echo round($backup['size'] / 1024, 2); ?> KB</strong></div>
                                    <div>🕐 Created: <strong><?php echo date('M d, Y - H:i:s', $backup['time']); ?></strong></div>
                                </div>
                            </div>

                            <div class="backup-actions">
                                <a href="<?php echo '../scripts/backups/' . urlencode($backup['name']); ?>" download class="btn btn-success" style="font-size: 12px;">
                                    ⬇️ Download
                                </a>

                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this backup?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="delete_backup" value="<?php echo htmlspecialchars($backup['name']); ?>">
                                    <button type="submit" class="btn btn-danger" style="font-size: 12px;">
                                        🗑️ Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <div class="empty-state">
                    <p>📭 No backups available yet.</p>
                    <p style="font-size: 13px;">Click "Create Backup Now" to create your first backup.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Information Card -->
        <div class="card">
            <h3>ℹ️ About Backups</h3>
            <ul style="color: #666; font-size: 13px; line-height: 2;">
                <li>✅ <strong>What's included:</strong> All database tables (employees, attendance, leaves, payroll, etc.)</li>
                <li>✅ <strong>File format:</strong> SQL dump (.sql) - can be imported back into MySQL</li>
                <li>✅ <strong>Usage:</strong> Download backups and store in a safe location</li>
                <li>⚠️ <strong>Storage:</strong> Backups are stored on the server - download them regularly</li>
                <li>🔒 <strong>Security:</strong> Backups contain sensitive data - keep them secure and confidential</li>
                <li>📋 <strong>Frequency:</strong> Recommended to backup daily or weekly</li>
            </ul>
        </div>
    </div>
</body>
</html>