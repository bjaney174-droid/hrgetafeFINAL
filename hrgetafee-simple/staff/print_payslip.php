<?php
session_start();
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/payroll_functions.php';

require_login();
check_role(2);

$staff = get_employee_info($conn, $_SESSION['employee_id']);

// Get payroll ID from URL
$payroll_id = isset($_GET['payroll_id']) ? (int)$_GET['payroll_id'] : 0;

if ($payroll_id <= 0) {
    die('<div style="padding: 20px; background: #f8d7da; color: #721c24; border-radius: 5px; margin: 20px;">Invalid payroll ID</div>');
}

// Get payroll details
$payroll = $conn->query(
    "SELECT p.*, e.first_name, e.last_name, e.employee_code, e.position, e.salary, e.date_hired
     FROM payroll p
     JOIN employees e ON p.employee_id = e.employee_id
     WHERE p.payroll_id = {$payroll_id}"
)->fetch_assoc();

if (!$payroll) {
    die('<div style="padding: 20px; background: #f8d7da; color: #721c24; border-radius: 5px; margin: 20px;">Payroll record not found</div>');
}

// Get payroll details breakdown
$details = get_payroll_details($conn, $payroll_id);

// Calculate enhanced payroll data
$payroll_data = calculate_enhanced_payroll($conn, $payroll['employee_id'], $payroll['payroll_month'], $payroll['payroll_year']);
$leave_earned = calculate_leave_earned($conn, $payroll['employee_id'], $payroll['payroll_month'], $payroll['payroll_year']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payslip - HRGetafe</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Courier New', monospace;
            background: #f5f5f5;
            padding: 20px;
        }
        
        .payslip-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border: 2px solid #667eea;
            border-radius: 8px;
            padding: 40px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }
        
        .slip-header {
            text-align: center;
            border-bottom: 3px solid #667eea;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        
        .slip-header h1 {
            font-size: 24px;
            color: #667eea;
            margin-bottom: 5px;
        }
        
        .slip-header p {
            color: #666;
            font-size: 12px;
        }
        
        .slip-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
            font-size: 13px;
        }
        
        .slip-row.header {
            background: #f9f9f9;
            font-weight: bold;
            border-top: 1px solid #ddd;
            border-bottom: 2px solid #ddd;
        }
        
        .slip-row.total {
            font-weight: bold;
            font-size: 15px;
            border-top: 2px solid #667eea;
            border-bottom: 2px solid #667eea;
            padding: 15px 0;
            background: #f0f0f0;
        }
        
        .slip-row.net-salary {
            font-weight: bold;
            font-size: 18px;
            border-top: 3px solid #667eea;
            border-bottom: none;
            padding: 20px 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            margin-top: 20px;
        }
        
        .slip-section {
            margin-top: 25px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
        }
        
        .section-title {
            background: #667eea;
            color: white;
            padding: 8px 10px;
            font-weight: bold;
            font-size: 12px;
            margin-bottom: 10px;
            border-radius: 3px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .info-item {
            font-size: 12px;
        }
        
        .info-label {
            font-weight: bold;
            color: #333;
        }
        
        .info-value {
            color: #666;
        }
        
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            text-align: center;
            font-size: 11px;
            color: #999;
        }
        
        .print-btn {
            background: #667eea;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 20px;
        }
        
        .print-btn:hover {
            background: #764ba2;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            .print-btn {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div style="text-align: center; margin-bottom: 20px;">
        <button class="print-btn" onclick="window.print()">🖨️ Print Payslip</button>
    </div>

    <div class="payslip-container">
        <!-- Header -->
        <div class="slip-header">
            <h1>SALARY SLIP</h1>
            <p>HRGetafe - Getafe Local Government Unit</p>
            <p>Payroll Period: <?php echo date('F Y', mktime(0, 0, 0, $payroll['payroll_month'], 1, $payroll['payroll_year'])); ?></p>
        </div>

        <!-- Employee Information -->
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Employee Name:</div>
                <div class="info-value"><?php echo htmlspecialchars($payroll['first_name'] . ' ' . $payroll['last_name']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Employee Code:</div>
                <div class="info-value"><?php echo htmlspecialchars($payroll['employee_code']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Position:</div>
                <div class="info-value"><?php echo htmlspecialchars($payroll['position']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Payroll Period:</div>
                <div class="info-value"><?php echo $payroll['payroll_month'] . '/' . $payroll['payroll_year']; ?></div>
            </div>
        </div>

        <!-- Attendance Section -->
        <div class="slip-section">
            <div class="section-title">ATTENDANCE SUMMARY</div>
            <div class="slip-row">
                <span>Present Days:</span>
                <span><?php echo $payroll_data['present_days']; ?> days</span>
            </div>
            <div class="slip-row">
                <span>Absences:</span>
                <span><?php echo $payroll_data['absences']; ?> days</span>
            </div>
            <div class="slip-row">
                <span>Late Arrivals:</span>
                <span><?php echo $payroll_data['lates']; ?> times</span>
            </div>
            <div class="slip-row">
                <span>Approved Leaves:</span>
                <span><?php echo $payroll_data['approved_leaves']; ?> days</span>
            </div>
            <div class="slip-row">
                <span>Years of Service (AMS):</span>
                <span><?php echo number_format($payroll_data['ams'], 1); ?> years</span>
            </div>
        </div>

        <!-- Earnings & Deductions -->
        <div class="slip-section">
            <div class="section-title">EARNINGS & DEDUCTIONS</div>
            
            <div class="slip-row header">
                <span>EARNINGS</span>
                <span>AMOUNT</span>
            </div>
            
            <div class="slip-row">
                <span>Gross Salary</span>
                <span><?php echo format_currency($payroll_data['gross_salary']); ?></span>
            </div>

            <div class="slip-row header" style="margin-top: 15px;">
                <span>STATUTORY DEDUCTIONS</span>
                <span>AMOUNT</span>
            </div>
            
            <div class="slip-row">
                <span>SSS Contribution (3.63%)</span>
                <span>-<?php echo format_currency($payroll_data['sss_deduction']); ?></span>
            </div>
            <div class="slip-row">
                <span>PhilHealth Premium (2.75%)</span>
                <span>-<?php echo format_currency($payroll_data['philhealth_deduction']); ?></span>
            </div>
            <div class="slip-row">
                <span>Pag-IBIG (2.00%)</span>
                <span>-<?php echo format_currency($payroll_data['pagibig_deduction']); ?></span>
            </div>

            <div class="slip-row header" style="margin-top: 15px;">
                <span>SPECIAL DEDUCTIONS</span>
                <span>AMOUNT</span>
            </div>
            
            <div class="slip-row">
                <span>CPF (Consolidated Police Fund)</span>
                <span>-<?php echo format_currency($payroll_data['cpf_deduction']); ?></span>
            </div>
            <div class="slip-row">
                <span>CBIF (Comprehensive Benefit Insurance)</span>
                <span>-<?php echo format_currency($payroll_data['cbif_deduction']); ?></span>
            </div>
            <div class="slip-row">
                <span>MPL (Magna Carta for Public Employees)</span>
                <span>-<?php echo format_currency($payroll_data['mpl_deduction']); ?></span>
            </div>

            <div class="slip-row header" style="margin-top: 15px;">
                <span>VARIABLE DEDUCTIONS</span>
                <span>AMOUNT</span>
            </div>
            
            <div class="slip-row">
                <span>Absence Deduction (<?php echo $payroll_data['absences']; ?> × ₱500)</span>
                <span>-<?php echo format_currency($payroll_data['absence_deduction']); ?></span>
            </div>
            <div class="slip-row">
                <span>Late Deduction (<?php echo $payroll_data['lates']; ?> × ₱100)</span>
                <span>-<?php echo format_currency($payroll_data['late_deduction']); ?></span>
            </div>
            <div class="slip-row">
                <span>BIR Income Tax (12%)</span>
                <span>-<?php echo format_currency($payroll_data['bir_deduction']); ?></span>
            </div>

            <div class="slip-row total">
                <span>TOTAL DEDUCTIONS:</span>
                <span>-<?php echo format_currency($payroll_data['total_deductions']); ?></span>
            </div>
        </div>

        <!-- Leave Earned -->
        <div class="slip-section">
            <div class="section-title">LEAVE EARNED (Based on AMS)</div>
            <div class="slip-row">
                <span>Leave Earned (Days):</span>
                <span><?php echo number_format($leave_earned['leave_earned_days'], 3); ?> days</span>
            </div>
            <div class="slip-row">
                <span>Leave Value:</span>
                <span><?php echo format_currency($leave_earned['leave_earned_amount']); ?></span>
            </div>
        </div>

        <!-- Net Salary -->
        <div class="slip-row net-salary">
            <span>NET SALARY (Take-home):</span>
            <span><?php echo format_currency($payroll_data['net_salary']); ?></span>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p><strong>IMPORTANT:</strong> This is an authorized salary slip generated from the HRGetafe system.</p>
            <p>For inquiries, please contact the HR Department</p>
            <p>Generated on: <?php echo date('M d, Y H:i:s'); ?></p>
        </div>
    </div>
</body>
</html>
