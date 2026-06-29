<?php

/**
 * ════════════════════════════════════════════════════════════════════
 *  LIBRARY MANAGEMENT SYSTEM — ONE-TIME DATABASE INSTALLER
 *  Run this ONCE during initial deployment. Delete or restrict access
 *  to this file after successful installation.
 * ════════════════════════════════════════════════════════════════════
 *
 *  USAGE  : Open in browser → https://yourdomain.com/install.php
 *  AFTER  : Delete this file OR rename it to something secret.
 *
 *  SAFETY : If the database + all tables already exist, this script
 *           does NOTHING — it is fully idempotent and safe to re-run.
 * ════════════════════════════════════════════════════════════════════
 */

// ── 0. Guard: block re-installation if lock file exists ──────────────────────
define('LOCK_FILE', __DIR__ . '/.install.lock');

if (file_exists(LOCK_FILE)) {
    http_response_code(403);
    die(render(
        '🔒 Already Installed',
        'The database was already set up. This installer is now locked.<br>
         If you need to re-install, delete the <code>.install.lock</code> file first.',
        'error'
    ));
}

// ── 1. Load config ────────────────────────────────────────────────────────────
define('SARASWATI_INIT', true);
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    die(render(
        '❌ Missing config.php',
        'Could not find <code>config.php</code>. Make sure it exists and defines
         DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME, and DB_CHARSET.',
        'error'
    ));
}
require_once $configFile;

// Verify required constants
foreach (['DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASS', 'DB_NAME', 'DB_CHARSET'] as $const) {
    if (!defined($const)) {
        die(render(
            '❌ Config Incomplete',
            "The constant <code>$const</code> is not defined in config.php.",
            'error'
        ));
    }
}

// ── 2. Connect (without selecting a DB yet) ───────────────────────────────────
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$log = [];

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
    $conn->set_charset(DB_CHARSET);
    $log[] = ['ok', 'Connected to MySQL server at <b>' . DB_HOST . ':' . DB_PORT . '</b>'];
} catch (Throwable $e) {
    die(render(
        '❌ Connection Failed',
        'Could not connect to MySQL: <code>' . htmlspecialchars($e->getMessage()) . '</code>',
        'error'
    ));
}

// ── 3. Create database if it does not exist ───────────────────────────────────
$dbName = DB_NAME;

try {
    $result = $conn->query("SHOW DATABASES LIKE '$dbName'");
    if ($result && $result->num_rows === 0) {
        $conn->query("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        $log[] = ['ok', "Database <b>$dbName</b> created successfully."];
    } else {
        $log[] = ['info', "Database <b>$dbName</b> already exists — skipping creation."];
    }
    $conn->select_db($dbName);
} catch (Throwable $e) {
    die(render(
        '❌ Database Error',
        'Failed to create/select database: <code>' . htmlspecialchars($e->getMessage()) . '</code>',
        'error'
    ));
}

// ── 4. Helper: create table only if missing ───────────────────────────────────
function createTable(mysqli $conn, string $name, string $sql, array &$log): void
{
    try {
        $exists = $conn->query("SHOW TABLES LIKE '$name'");
        if ($exists && $exists->num_rows > 0) {
            $log[] = ['info', "Table <b>$name</b> already exists — skipped."];
            return;
        }
        $conn->query($sql);
        $log[] = ['ok', "Table <b>$name</b> created."];
    } catch (Throwable $e) {
        $log[] = ['error', "Failed to create table <b>$name</b>: " . htmlspecialchars($e->getMessage())];
    }
}

// ── 5. Disable FK checks while building schema ───────────────────────────────
$conn->query("SET FOREIGN_KEY_CHECKS = 0");

// ── 6. Table 1 — admin ───────────────────────────────────────────────────────
createTable($conn, 'admin', "
CREATE TABLE `admin` (
  `admin_id`   INT(11)      NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) DEFAULT NULL,
  `email`       VARCHAR(100) NOT NULL,
  `username`    VARCHAR(50)  NOT NULL,
  `password`    VARCHAR(255) NOT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`admin_id`),
  UNIQUE KEY `email`    (`email`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
", $log);

// ── 7. Table 2 — users ───────────────────────────────────────────────────────
createTable($conn, 'users', "
CREATE TABLE `users` (
  `user_id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `unique_user_id`        VARCHAR(50)  NOT NULL,
  `full_name`             VARCHAR(100) NOT NULL,
  `email`                 VARCHAR(100) NOT NULL,
  `phone`                 VARCHAR(20)  NOT NULL,
  `address`               TEXT         DEFAULT NULL,
  `password`              VARCHAR(255) NOT NULL,
  `registration_date`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `account_status`        ENUM('Active','Inactive','Suspended') DEFAULT 'Active',
  `last_login`            DATETIME     DEFAULT NULL,
  `reset_otp`             VARCHAR(255) DEFAULT NULL,
  `reset_otp_expires`     DATETIME     DEFAULT NULL,
  `registration_ip`       VARCHAR(45)  DEFAULT NULL,
  `registration_device`   VARCHAR(50)  DEFAULT NULL,
  `registration_browser`  VARCHAR(100) DEFAULT NULL,
  `failed_login_attempts` INT(11)      DEFAULT 0,
  `locked_until`          DATETIME     DEFAULT NULL,
  `reset_otp_requested_at` DATETIME    DEFAULT NULL,
  `otp_resend_count`      INT(11)      DEFAULT 0,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `unique_user_id` (`unique_user_id`),
  UNIQUE KEY `email`          (`email`),
  UNIQUE KEY `phone`          (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
", $log);

// ── 8. Table 3 — library_tables ──────────────────────────────────────────────
createTable($conn, 'library_tables', "
CREATE TABLE `library_tables` (
  `table_id`        INT(11)     NOT NULL AUTO_INCREMENT,
  `unique_table_id` VARCHAR(50) NOT NULL,
  `table_number`    INT(11)     NOT NULL,
  `status`          ENUM('Available','Booked','Maintenance') DEFAULT 'Available',
  `current_user_id` INT(11)     DEFAULT NULL,
  `created_at`      TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`table_id`),
  UNIQUE KEY `unique_table_id` (`unique_table_id`),
  UNIQUE KEY `table_number`    (`table_number`),
  KEY `current_user_id`        (`current_user_id`),
  CONSTRAINT `library_tables_ibfk_1`
    FOREIGN KEY (`current_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
", $log);

// ── 9. Table 4 — subscription_plans ─────────────────────────────────────────
createTable($conn, 'subscription_plans', "
CREATE TABLE `subscription_plans` (
  `plan_id`       INT(11)        NOT NULL AUTO_INCREMENT,
  `plan_name`     VARCHAR(100)   NOT NULL,
  `duration_days` INT(11)        NOT NULL,
  `price`         DECIMAL(10,2)  NOT NULL,
  `active`        TINYINT(1)     DEFAULT 1,
  PRIMARY KEY (`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
", $log);

// ── 10. Table 5 — user_subscriptions ────────────────────────────────────────
createTable($conn, 'user_subscriptions', "
CREATE TABLE `user_subscriptions` (
  `subscription_id`     INT(11)       NOT NULL AUTO_INCREMENT,
  `user_id`             INT(11)       NOT NULL,
  `table_id`            INT(11)       NOT NULL,
  `plan_id`             INT(11)       NOT NULL,
  `start_date`          DATE          NOT NULL,
  `expiry_date`         DATE          NOT NULL,
  `amount_paid`         DECIMAL(10,2) NOT NULL,
  `payment_status`      ENUM('Pending','Paid','Failed','Refunded') DEFAULT 'Pending',
  `subscription_status` ENUM('Active','Expired','Cancelled')       DEFAULT 'Active',
  PRIMARY KEY (`subscription_id`),
  KEY `user_id`  (`user_id`),
  KEY `table_id` (`table_id`),
  KEY `plan_id`  (`plan_id`),
  CONSTRAINT `user_subscriptions_ibfk_1`
    FOREIGN KEY (`user_id`)  REFERENCES `users`              (`user_id`)  ON DELETE CASCADE,
  CONSTRAINT `user_subscriptions_ibfk_2`
    FOREIGN KEY (`table_id`) REFERENCES `library_tables`     (`table_id`) ON DELETE CASCADE,
  CONSTRAINT `user_subscriptions_ibfk_3`
    FOREIGN KEY (`plan_id`)  REFERENCES `subscription_plans` (`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
", $log);

// ── 11. Table 6 — payments ───────────────────────────────────────────────────
// NOTE: includes utr_number column for UPI Transaction Reference (entered by user after paying)
createTable($conn, 'payments', "
CREATE TABLE `payments` (
  `payment_id`        INT(11)        NOT NULL AUTO_INCREMENT,
  `payment_reference` VARCHAR(100)   NOT NULL,
  `utr_number`        VARCHAR(50)    DEFAULT NULL COMMENT 'UPI Transaction Reference entered by user',
  `user_id`           INT(11)        NOT NULL,
  `subscription_id`   INT(11)        NOT NULL,
  `amount`            DECIMAL(10,2)  NOT NULL,
  `payment_method`    VARCHAR(50)    NOT NULL,
  `payment_date`      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `payment_status`    ENUM('Pending','Paid','Failed','Refunded') DEFAULT 'Pending',
  `created_at`        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`payment_id`),
  UNIQUE KEY `payment_reference` (`payment_reference`),
  KEY `user_id`        (`user_id`),
  KEY `subscription_id`(`subscription_id`),
  CONSTRAINT `payments_ibfk_1`
    FOREIGN KEY (`user_id`)         REFERENCES `users`              (`user_id`)         ON DELETE CASCADE,
  CONSTRAINT `payments_ibfk_2`
    FOREIGN KEY (`subscription_id`) REFERENCES `user_subscriptions` (`subscription_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
", $log);

// ── 12. Table 7 — bookings ───────────────────────────────────────────────────
createTable($conn, 'bookings', "
CREATE TABLE `bookings` (
  `booking_id`        INT(11)       NOT NULL AUTO_INCREMENT,
  `booking_reference` VARCHAR(100)  NOT NULL,
  `user_id`           INT(11)       NOT NULL,
  `table_id`          INT(11)       NOT NULL,
  `booking_date`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `start_date`        DATE          NOT NULL,
  `expiry_date`       DATE          NOT NULL,
  `booking_status`    ENUM('Pending','Active','Expired','Cancelled','Maintenance') DEFAULT 'Pending',
  `booking_price`     DECIMAL(10,2) DEFAULT NULL,
  `plan_price`        DECIMAL(10,2) DEFAULT NULL,
  PRIMARY KEY (`booking_id`),
  UNIQUE KEY `booking_reference` (`booking_reference`),
  KEY `user_id`  (`user_id`),
  KEY `table_id` (`table_id`),
  CONSTRAINT `bookings_ibfk_1`
    FOREIGN KEY (`user_id`)  REFERENCES `users`          (`user_id`)  ON DELETE CASCADE,
  CONSTRAINT `bookings_ibfk_2`
    FOREIGN KEY (`table_id`) REFERENCES `library_tables` (`table_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
", $log);

// ── 13. Table 8 — attendance ─────────────────────────────────────────────────
createTable($conn, 'attendance', "
CREATE TABLE `attendance` (
  `attendance_id`   INT(11) NOT NULL AUTO_INCREMENT,
  `user_id`         INT(11) NOT NULL,
  `attendance_date` DATE    NOT NULL,
  `check_in_time`   TIME    NOT NULL,
  `status`          ENUM('Present','Absent','Late') DEFAULT 'Present',
  `ip_address`      VARCHAR(45) DEFAULT NULL,
  PRIMARY KEY (`attendance_id`),
  UNIQUE KEY `user_date_unique` (`user_id`, `attendance_date`),
  CONSTRAINT `attendance_ibfk_1`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
", $log);

// ── 14. Table 9 — audit_logs ─────────────────────────────────────────────────
createTable($conn, 'audit_logs', "
CREATE TABLE `audit_logs` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)      DEFAULT NULL,
  `admin_id`   INT(11)      DEFAULT NULL,
  `action`     VARCHAR(255) NOT NULL,
  `result`     VARCHAR(50)  NOT NULL,
  `ip_address` VARCHAR(45)  DEFAULT NULL,
  `browser`    VARCHAR(100) DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
", $log);

// ── 15. Table 10 — system_notifications ─────────────────────────────────────
createTable($conn, 'system_notifications', "
CREATE TABLE `system_notifications` (
  `notification_id` INT(11)      NOT NULL AUTO_INCREMENT,
  `type`            VARCHAR(50)  NOT NULL,
  `title`           VARCHAR(255) NOT NULL,
  `message`         TEXT         NOT NULL,
  `created_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `is_read`         TINYINT(1)   DEFAULT 0,
  `related_id`      INT(11)      DEFAULT NULL,
  PRIMARY KEY (`notification_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
", $log);

// ── 16. Table 11 — user_login_logs ───────────────────────────────────────────
createTable($conn, 'user_login_logs', "
CREATE TABLE `user_login_logs` (
  `log_id`      INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`     INT(11)      NOT NULL,
  `login_time`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `ip_address`  VARCHAR(45)  DEFAULT NULL,
  `logout_time` DATETIME     DEFAULT NULL,
  `browser`     VARCHAR(100) DEFAULT NULL,
  `os`          VARCHAR(100) DEFAULT NULL,
  `device_type` VARCHAR(50)  DEFAULT NULL,
  `country`     VARCHAR(100) DEFAULT NULL,
  `status`      ENUM('Success','Failed','Locked') DEFAULT 'Success',
  PRIMARY KEY (`log_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `user_login_logs_ibfk_1`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
", $log);

// ── 17. Re-enable FK checks ───────────────────────────────────────────────────
$conn->query("SET FOREIGN_KEY_CHECKS = 1");

// ── 18. Seed: default admin ───────────────────────────────────────────────────
try {
    $chk = $conn->query("SELECT admin_id FROM `admin` WHERE username = 'admin' LIMIT 1");
    if ($chk && $chk->num_rows === 0) {
        // Password: Admin@123  — CHANGE THIS IMMEDIATELY after first login
        $hash = '$2y$10$okADpmvFezYkhau6iX65uuq7IzaVl2w/jggL.xhaylNyi988uLkDO';
        $conn->query("
            INSERT INTO `admin` (`name`, `email`, `username`, `password`)
            VALUES ('Super Admin', 'admin@gmail.com', 'admin', '$hash')
        ");
        $log[] = ['ok', 'Default admin seeded — username: <b>admin</b> / password: <b>Admin@123</b>. <span style="color:#c0392b;font-weight:bold;">Change it immediately!</span>'];
    } else {
        $log[] = ['info', 'Default admin already exists — skipped.'];
    }
} catch (Throwable $e) {
    $log[] = ['error', 'Admin seed failed: ' . htmlspecialchars($e->getMessage())];
}

// ── 19. Seed: subscription plans ─────────────────────────────────────────────
try {
    $chk = $conn->query("SELECT plan_id FROM `subscription_plans` LIMIT 1");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("
            INSERT INTO `subscription_plans` (`plan_name`, `duration_days`, `price`, `active`) VALUES
            ('1 Month',  30, 800.00,  1),
            ('3 Months', 90, 2200.00, 1)
        ");
        $log[] = ['ok', 'Default subscription plans seeded (1 Month ₹800, 3 Months ₹2200).'];
    } else {
        $log[] = ['info', 'Subscription plans already exist — skipped.'];
    }
} catch (Throwable $e) {
    $log[] = ['error', 'Plan seed failed: ' . htmlspecialchars($e->getMessage())];
}

// ── 20. Seed: 39 library tables ───────────────────────────────────────────────
try {
    $chk = $conn->query("SELECT table_id FROM `library_tables` LIMIT 1");
    if ($chk && $chk->num_rows === 0) {
        $stmt = $conn->prepare("
            INSERT INTO `library_tables` (`unique_table_id`, `table_number`, `status`, `current_user_id`)
            VALUES (?, ?, 'Available', NULL)
        ");
        for ($i = 1; $i <= 39; $i++) {
            $uid = 'T' . $i;
            $stmt->bind_param('si', $uid, $i);
            $stmt->execute();
        }
        $stmt->close();
        $log[] = ['ok', '39 library tables seeded (T1–T39), all set to <b>Available</b>.'];
    } else {
        $log[] = ['info', 'Library tables already exist — skipped.'];
    }
} catch (Throwable $e) {
    $log[] = ['error', 'Table seed failed: ' . htmlspecialchars($e->getMessage())];
}

// ── 21. Check for any errors ──────────────────────────────────────────────────
$hasError = false;
foreach ($log as $entry) {
    if ($entry[0] === 'error') {
        $hasError = true;
        break;
    }
}

// ── 22. Write lock file (only on full success) ────────────────────────────────
if (!$hasError) {
    file_put_contents(LOCK_FILE, 'Installed on ' . date('Y-m-d H:i:s') . PHP_EOL);
    $log[] = ['ok', 'Lock file <code>.install.lock</code> created — this installer is now <b>locked</b>.'];
    $log[] = ['info', '⚡ <strong>Next step:</strong> Delete <code>install.php</code> from your server for security.'];
}

$conn->close();

// ── 23. Render result page ────────────────────────────────────────────────────
$title   = $hasError ? '⚠️ Installation Completed with Errors' : '✅ Installation Successful';
$summary = $hasError
    ? 'Some steps failed. Review the errors below and fix them before using the application.'
    : 'Database and all tables are ready. You can now use the application.';
echo render($title, $summary, $hasError ? 'warning' : 'success', $log);
exit;

// ══════════════════════════════════════════════════════════════════════════════
//  HTML renderer helper
// ══════════════════════════════════════════════════════════════════════════════
function render(string $title, string $summary, string $type = 'success', array $log = []): string
{
    $colors = [
        'success' => ['#27ae60', '#eafaf1'],
        'warning' => ['#e67e22', '#fef9e7'],
        'error'   => ['#c0392b', '#fdedec'],
        'info'    => ['#2980b9', '#eaf4fb'],
    ];
    [$accent, $bg] = $colors[$type] ?? $colors['success'];

    $rows = '';
    foreach ($log as [$status, $msg]) {
        $icon  = match ($status) {
            'ok' => '✅',
            'error' => '❌',
            default => 'ℹ️'
        };
        $color = match ($status) {
            'ok' => '#1e8449',
            'error' => '#c0392b',
            default => '#1a5276'
        };
        $rows .= "<tr><td style='padding:6px 10px;font-size:14px;'>$icon</td>
                      <td style='padding:6px 10px;font-size:14px;color:$color;'>$msg</td></tr>";
    }

    $table = $rows
        ? "<table style='width:100%;border-collapse:collapse;margin-top:20px;background:#fff;border-radius:6px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.1);'>
               <thead>
                 <tr style='background:#f2f3f4;'>
                   <th style='padding:8px 10px;text-align:left;font-size:13px;width:36px;'></th>
                   <th style='padding:8px 10px;text-align:left;font-size:13px;'>Step</th>
                 </tr>
               </thead>
               <tbody>$rows</tbody>
           </table>"
        : '';

    return "<!DOCTYPE html>
<html lang='en'>
<head>
  <meta charset='UTF-8'>
  <meta name='viewport' content='width=device-width,initial-scale=1'>
  <title>Library DB Installer</title>
  <style>
    body { font-family: system-ui, sans-serif; background: #f5f5f5; margin: 0; padding: 40px 16px; }
    .card { max-width: 700px; margin: 0 auto; background: $bg; border: 2px solid $accent;
            border-radius: 10px; padding: 32px; box-shadow: 0 4px 12px rgba(0,0,0,.12); }
    h1 { margin: 0 0 8px; color: $accent; font-size: 24px; }
    p  { margin: 0 0 4px; color: #333; font-size: 15px; }
    code { background: rgba(0,0,0,.07); padding: 2px 6px; border-radius: 4px; font-size: 13px; }
  </style>
</head>
<body>
  <div class='card'>
    <h1>$title</h1>
    <p>$summary</p>
    $table
  </div>
</body>
</html>";
}
