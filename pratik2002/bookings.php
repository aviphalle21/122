<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit;
}

function ensureAdminBookingColumns(PDO $pdo): void
{
    $columns = $pdo->query("SHOW COLUMNS FROM bookings")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('rejection_reason', $columns, true)) {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN rejection_reason TEXT DEFAULT NULL AFTER booking_status");
    }
    if (!in_array('reviewed_by', $columns, true)) {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN reviewed_by INT(11) DEFAULT NULL AFTER rejection_reason");
    }
    if (!in_array('reviewed_at', $columns, true)) {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN reviewed_at DATETIME DEFAULT NULL AFTER reviewed_by");
    }

    $statusType = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'booking_status'")->fetch();
    if ($statusType && strpos($statusType['Type'], 'Rejected') === false) {
        $pdo->exec("ALTER TABLE bookings MODIFY booking_status ENUM('Pending','Active','Rejected','Expired','Cancelled','Maintenance') DEFAULT 'Pending'");
    }
}

function notifyStudent(string $email, string $name, string $subject, string $message): void
{
    @mail($email, $subject, $message, "From: noreply@saraswatiabhyasika.com\r\nX-Mailer: PHP/" . phpversion());
}

ensureAdminBookingColumns($pdo);
$alertMessage = '';
$alertType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_id'], $_POST['action'])) {
    $bookingId = (int) $_POST['booking_id'];
    $action = $_POST['action'];

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            SELECT b.*, u.full_name, u.email, t.table_number, p.payment_id, p.payment_reference, p.utr_number, p.amount, p.payment_status, us.subscription_id, us.plan_id, sp.duration_days
            FROM bookings b
            JOIN users u ON u.user_id = b.user_id
            JOIN library_tables t ON t.table_id = b.table_id
            JOIN user_subscriptions us ON us.user_id = b.user_id AND us.table_id = b.table_id
            JOIN subscription_plans sp ON sp.plan_id = us.plan_id
            LEFT JOIN payments p ON p.subscription_id = us.subscription_id
            WHERE b.booking_id = ?
            ORDER BY p.payment_id DESC, us.subscription_id DESC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch();
        if (!$booking) {
            throw new Exception('Booking not found.');
        }

        if ($action === 'accept') {
            if ($booking['payment_id']) {
                $pdo->prepare("UPDATE payments SET payment_status = 'Paid', payment_date = NOW() WHERE payment_id = ?")->execute([$booking['payment_id']]);
            }
            $pdo->prepare("UPDATE user_subscriptions SET payment_status = 'Paid', subscription_status = 'Active', start_date = CURDATE(), expiry_date = DATE_ADD(CURDATE(), INTERVAL ? DAY) WHERE subscription_id = ?")
                ->execute([(int) $booking['duration_days'], $booking['subscription_id']]);
            $pdo->prepare("UPDATE bookings SET booking_status = 'Active', start_date = CURDATE(), expiry_date = DATE_ADD(CURDATE(), INTERVAL ? DAY), rejection_reason = NULL, reviewed_by = ?, reviewed_at = NOW() WHERE booking_id = ?")
                ->execute([(int) $booking['duration_days'], $_SESSION['admin_id'], $bookingId]);
            $pdo->prepare("UPDATE library_tables SET status = 'Booked', current_user_id = ? WHERE table_id = ?")
                ->execute([$booking['user_id'], $booking['table_id']]);
            $pdo->prepare("INSERT INTO system_notifications (type, title, message, related_id) VALUES ('booking', 'Booking Accepted', ?, ?)")
                ->execute(["Booking {$booking['booking_reference']} accepted for {$booking['full_name']} at Table T-{$booking['table_number']}.", $bookingId]);
            notifyStudent($booking['email'], $booking['full_name'], 'Booking Accepted – Saraswati Abhyasika', "Hello {$booking['full_name']},\n\nYour booking for Table T-{$booking['table_number']} has been accepted.\nBooking Reference: {$booking['booking_reference']}\nPayment Reference: {$booking['payment_reference']}\n\nThank you!");
            $alertMessage = 'Booking accepted and student email sent.';
            $alertType = 'alert-success';
        } elseif ($action === 'reject') {
            $reason = trim($_POST['rejection_reason'] ?? '');
            if ($reason === '') {
                throw new Exception('Please enter a rejection reason.');
            }
            if ($booking['payment_id']) {
                $pdo->prepare("UPDATE payments SET payment_status = 'Refunded', payment_date = NOW() WHERE payment_id = ?")->execute([$booking['payment_id']]);
            }
            $pdo->prepare("UPDATE user_subscriptions SET payment_status = 'Refunded', subscription_status = 'Cancelled' WHERE subscription_id = ?")
                ->execute([$booking['subscription_id']]);
            $pdo->prepare("UPDATE bookings SET booking_status = 'Rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE booking_id = ?")
                ->execute([$reason, $_SESSION['admin_id'], $bookingId]);
            $pdo->prepare("UPDATE library_tables SET status = 'Available', current_user_id = NULL WHERE table_id = ? AND current_user_id IS NULL")
                ->execute([$booking['table_id']]);
            $pdo->prepare("INSERT INTO system_notifications (type, title, message, related_id) VALUES ('booking', 'Booking Rejected', ?, ?)")
                ->execute(["Booking {$booking['booking_reference']} rejected for {$booking['full_name']}. Reason: {$reason}", $bookingId]);
            notifyStudent($booking['email'], $booking['full_name'], 'Booking Rejected – Saraswati Abhyasika', "Hello {$booking['full_name']},\n\nYour booking for Table T-{$booking['table_number']} was rejected.\nReason: {$reason}\nPayment Reference: {$booking['payment_reference']}\n\nIf you paid, please contact the library for refund/support.");
            $alertMessage = 'Booking rejected with reason and student email sent.';
            $alertType = 'alert-success';
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $alertMessage = $e->getMessage();
        $alertType = 'alert-error';
    }
}

$bookings = $pdo->query("
    SELECT b.*, u.full_name, u.email, u.phone, t.table_number, p.payment_reference, p.utr_number, p.amount, p.payment_status
    FROM bookings b
    JOIN users u ON u.user_id = b.user_id
    JOIN library_tables t ON t.table_id = b.table_id
    LEFT JOIN user_subscriptions us ON us.user_id = b.user_id AND us.table_id = b.table_id
    LEFT JOIN payments p ON p.subscription_id = us.subscription_id
    GROUP BY b.booking_id
    ORDER BY FIELD(b.booking_status, 'Pending', 'Active', 'Rejected', 'Cancelled', 'Expired', 'Maintenance'), b.booking_date DESC
")->fetchAll();
$pageTitle = 'Bookings Management';
$showBackButton = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bookings - library Management</title>
    <link rel="stylesheet" href="Dashboard.css">
    <style>.reason-box{min-width:220px;min-height:42px;padding:8px;border:1px solid var(--border-gray);border-radius:8px}.actions-cell form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.metric-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:18px}.metric{background:#fff;border-radius:12px;padding:16px;box-shadow:0 4px 10px rgba(15,23,42,.06)}.metric strong{display:block;font-size:1.6rem;color:var(--navy-blue)}</style>
</head>
<body>
<?php include 'header.php'; ?>
<div class="table-container">
    <?php if ($alertMessage): ?><div class="alert <?= $alertType ?>"><?= htmlspecialchars($alertMessage) ?></div><?php endif; ?>
    <div class="metric-strip">
        <div class="metric">Pending<strong><?= count(array_filter($bookings, fn($b) => $b['booking_status'] === 'Pending')) ?></strong></div>
        <div class="metric">Confirmed<strong><?= count(array_filter($bookings, fn($b) => $b['booking_status'] === 'Active')) ?></strong></div>
        <div class="metric">Rejected<strong><?= count(array_filter($bookings, fn($b) => $b['booking_status'] === 'Rejected')) ?></strong></div>
    </div>
    <div class="table-header"><h2>Pending & Confirmed Bookings</h2></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Student</th><th>Table</th><th>Booking Ref</th><th>Payment / Transaction ID</th><th>Amount</th><th>Status</th><th>Dates / Reason</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($bookings as $b): ?>
        <tr>
            <td><strong><?= htmlspecialchars($b['full_name']) ?></strong><br><span class="text-sm"><?= htmlspecialchars($b['email']) ?> | <?= htmlspecialchars($b['phone']) ?></span></td>
            <td><strong>T-<?= htmlspecialchars($b['table_number']) ?></strong></td>
            <td><?= htmlspecialchars($b['booking_reference']) ?></td>
            <td><strong><?= htmlspecialchars($b['utr_number'] ?: 'Not submitted') ?></strong><br><span class="text-sm"><?= htmlspecialchars($b['payment_reference'] ?? '-') ?> (<?= htmlspecialchars($b['payment_status'] ?? '-') ?>)</span></td>
            <td>₹<?= number_format((float)($b['amount'] ?? $b['booking_price']), 2) ?></td>
            <td><span class="badge <?= $b['booking_status'] === 'Active' ? 'badge-active' : 'badge-pending' ?>"><?= htmlspecialchars($b['booking_status']) ?></span></td>
            <td><span class="text-sm"><?= htmlspecialchars($b['start_date']) ?> to <?= htmlspecialchars($b['expiry_date']) ?></span><?php if ($b['rejection_reason']): ?><br><span class="text-danger"><?= htmlspecialchars($b['rejection_reason']) ?></span><?php endif; ?></td>
            <td class="actions-cell">
                <?php if ($b['booking_status'] === 'Pending'): ?>
                    <form method="POST"><input type="hidden" name="booking_id" value="<?= $b['booking_id'] ?>"><button class="btn-primary" name="action" value="accept" onclick="return confirm('Accept this booking after checking the transaction ID?')">Accept</button></form>
                    <form method="POST"><input type="hidden" name="booking_id" value="<?= $b['booking_id'] ?>"><textarea name="rejection_reason" class="reason-box" placeholder="Rejection reason" required></textarea><button class="btn-action" style="background:#dc2626" name="action" value="reject">Reject</button></form>
                <?php else: ?><span class="text-sm">Reviewed <?= htmlspecialchars($b['reviewed_at'] ?? '-') ?></span><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$bookings): ?><tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted)">No bookings found.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
</div>
<?php include 'footer.php'; ?>
