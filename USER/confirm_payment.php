<?php
require_once 'config.php';
require_once 'payment_config.php';

header('Content-Type: application/json');

// ════════════════════════════════════════════════════════════════════
//  confirm_payment.php — Manual Admin Route ONLY
//
//  Usage (GET or POST):
//    /confirm_payment.php
//      ?secret=YOUR_MANUAL_ADMIN_SECRET
//      &reference=TXN...
//      &status=Paid|Failed|Refunded
//
//  This is used by the admin to manually confirm a payment after
//  verifying it in the Razorpay / UPI dashboard.
// ════════════════════════════════════════════════════════════════════

$secret    = $_POST['secret']    ?? $_GET['secret']    ?? '';
$reference = $_POST['reference'] ?? $_GET['reference'] ?? '';
$status    = $_POST['status']    ?? $_GET['status']    ?? '';

// ── Auth ─────────────────────────────────────────────────────────────────────
if (empty($secret) || !hash_equals(PAYMENT_WEBHOOK_SECRET, $secret)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Invalid secret.']);
    exit;
}

// ── Validate ─────────────────────────────────────────────────────────────────
if (empty($reference) || !in_array($status, ['Paid', 'Failed', 'Refunded'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Missing or invalid reference / status.']);
    exit;
}

// ── Update payments + user_subscriptions ─────────────────────────────────────
$stmt = $pdo->prepare("
    UPDATE payments p
    JOIN user_subscriptions us ON p.subscription_id = us.subscription_id
    SET p.payment_status  = ?,
        p.payment_date    = NOW(),
        us.payment_status = ?
    WHERE p.payment_reference = ?
");
$stmt->execute([$status, $status, $reference]);

// ── If Paid → activate subscription + create booking ─────────────────────────
if ($status === 'Paid' && $stmt->rowCount() > 0) {

    // Activate subscription dates
    $pdo->prepare("
        UPDATE user_subscriptions us
        JOIN payments p ON p.subscription_id = us.subscription_id
        JOIN subscription_plans sp ON sp.plan_id = us.plan_id
        SET us.subscription_status = 'Active',
            us.start_date  = CURDATE(),
            us.expiry_date = DATE_ADD(CURDATE(), INTERVAL sp.duration_days DAY)
        WHERE p.payment_reference = ?
          AND us.subscription_status != 'Active'
    ")->execute([$reference]);

    // Create booking row (idempotent)
    $pdo->prepare("
        INSERT IGNORE INTO bookings
            (user_id, table_id, start_date, expiry_date, booking_status, booking_reference, plan_price, booking_price)
        SELECT p.user_id, us.table_id, CURDATE(),
               DATE_ADD(CURDATE(), INTERVAL sp.duration_days DAY),
               'Active',
               CONCAT('BK-', FLOOR(RAND() * 900000 + 100000)),
               p.amount, p.amount
        FROM payments p
        JOIN user_subscriptions us ON us.subscription_id = p.subscription_id
        JOIN subscription_plans sp ON sp.plan_id = us.plan_id
        WHERE p.payment_reference = ?
          AND NOT EXISTS (
              SELECT 1 FROM bookings b2
               WHERE b2.user_id  = p.user_id
                 AND b2.table_id = us.table_id
                 AND b2.booking_status = 'Active'
          )
    ")->execute([$reference]);

    // Mark table as Booked
    $pdo->prepare("
        UPDATE library_tables lt
        JOIN user_subscriptions us ON us.table_id = lt.table_id
        JOIN payments p ON p.subscription_id = us.subscription_id
        SET lt.status = 'Booked', lt.current_user_id = p.user_id
        WHERE p.payment_reference = ?
    ")->execute([$reference]);

    // System notification
    $pdo->prepare("
        INSERT INTO system_notifications (type, title, message)
        SELECT 'payment', 'Payment Confirmed Manually',
               CONCAT('Payment ', p.payment_reference, ' marked Paid by admin. Amount: ₹', p.amount)
        FROM payments p WHERE p.payment_reference = ? LIMIT 1
    ")->execute([$reference]);
}

echo json_encode([
    'ok'      => $stmt->rowCount() > 0,
    'message' => $stmt->rowCount() > 0
        ? "Payment status updated to {$status} for reference {$reference}."
        : "No matching payment record found for reference: {$reference}",
]);
