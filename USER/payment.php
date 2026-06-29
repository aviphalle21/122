<?php
// USER/payment.php  –  UPI QR + Razorpay Link + UTR auto-confirm
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/payment_config.php';

if (!defined('RAZORPAY_PAYMENT_LINK')) {
    define('RAZORPAY_PAYMENT_LINK', '');
}

if (!isset($pdo) || !$pdo instanceof PDO) exit('A critical system error occurred.');
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id  = $_SESSION['user_id'];
$table_id = isset($_GET['table_id']) ? trim($_GET['table_id']) : '';
if (empty($table_id)) {
    header("Location: dashboard.php");
    exit;
}

$stmt = $pdo->prepare("SELECT full_name, email, phone FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$plansStmt = $pdo->query("SELECT plan_id, plan_name, price, duration_days FROM subscription_plans WHERE active = 1");
$plans = $plansStmt->fetchAll();

$success = isset($_GET['success']) && $_GET['success'] == 1;
$failed  = isset($_GET['failed'])  && $_GET['failed']  == 1;
$failMsg = isset($_GET['reason'])  ? urldecode($_GET['reason']) : '';

function jsonResponse(array $payload): void
{
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

// ─── Complete booking after payment confirmed ──────────────────────────────────
function completePaidBooking(PDO $pdo, int $user_id, string $table_number, string $payment_reference, array $user): array
{
    $pdo->beginTransaction();
    try {
        $paymentStmt = $pdo->prepare("
            SELECT p.payment_id, p.subscription_id, p.amount, p.payment_status,
                   p.created_at, us.plan_id, us.table_id, us.expiry_date, sp.duration_days
            FROM payments p
            JOIN user_subscriptions us ON p.subscription_id = us.subscription_id
            JOIN subscription_plans sp ON us.plan_id = sp.plan_id
            WHERE p.payment_reference = ? AND p.user_id = ?
            FOR UPDATE
        ");
        $paymentStmt->execute([$payment_reference, $user_id]);
        $payment = $paymentStmt->fetch();
        if (!$payment) throw new Exception('Payment request not found.');

        // Already completed
        if ($payment['payment_status'] === 'Paid') {
            $pdo->commit();
            return ['completed' => true, 'redirect' => 'payment.php?table_id=' . urlencode($table_number) . '&success=1'];
        }

        // Failed/Refunded
        if (in_array($payment['payment_status'], ['Failed', 'Refunded'])) {
            $pdo->commit();
            return [
                'completed' => false,
                'failed' => true,
                'redirect'  => 'payment.php?table_id=' . urlencode($table_number) . '&failed=1&reason=' . urlencode('This payment was already marked as ' . $payment['payment_status'] . '.'),
                'message'   => 'Payment was already ' . $payment['payment_status'] . '.',
            ];
        }

        // Check table still available
        $tableStmt = $pdo->prepare("SELECT status FROM library_tables WHERE table_id = ? FOR UPDATE");
        $tableStmt->execute([$payment['table_id']]);
        $tableRow = $tableStmt->fetch();
        if (!$tableRow || $tableRow['status'] === 'Booked') {
            $pdo->rollBack();
            return [
                'completed' => false,
                'failed' => true,
                'redirect'  => 'payment.php?table_id=' . urlencode($table_number) . '&failed=1&reason=' . urlencode('This table was taken by someone else. Your payment of ₹' . $payment['amount'] . ' will be refunded within 24 hours.'),
                'message'   => 'Table no longer available.',
            ];
        }

        // Mark Paid
        $pdo->prepare("UPDATE payments SET payment_status = 'Paid', payment_date = NOW() WHERE payment_id = ?")->execute([$payment['payment_id']]);

        // Activate subscription
        $pdo->prepare("UPDATE user_subscriptions SET payment_status = 'Paid', subscription_status = 'Active', start_date = CURDATE(), expiry_date = DATE_ADD(CURDATE(), INTERVAL ? DAY) WHERE subscription_id = ?")
            ->execute([$payment['duration_days'], $payment['subscription_id']]);

        // Create booking
        $bookingRef = 'BK-' . mt_rand(100000, 999999);
        $pdo->prepare("INSERT INTO bookings (user_id, table_id, start_date, expiry_date, booking_status, booking_reference, plan_price, booking_price) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? DAY), 'Active', ?, ?, ?)")
            ->execute([$user_id, $payment['table_id'], $payment['duration_days'], $bookingRef, $payment['amount'], $payment['amount']]);

        // Mark table booked
        $pdo->prepare("UPDATE library_tables SET status = 'Booked', current_user_id = ? WHERE table_id = ?")->execute([$user_id, $payment['table_id']]);

        // Notification
        $pdo->prepare("INSERT INTO system_notifications (type, title, message) VALUES ('payment','New Payment & Booking', ?)")
            ->execute([$user['full_name'] . " paid ₹" . $payment['amount'] . " and booked Table T-{$table_number}. Booking: {$bookingRef}"]);

        // Email
        @mail(
            $user['email'],
            'Booking Confirmed – Saraswati Abhyasika',
            "Hello {$user['full_name']},\n\nYour booking for Table T-{$table_number} is confirmed!\n\nBooking Reference: {$bookingRef}\nAmount Paid: ₹{$payment['amount']}\n\nThank you!",
            "From: noreply@saraswatiabhyasika.com\r\nX-Mailer: PHP/" . phpversion()
        );

        file_put_contents(
            'notification_logs.txt',
            '[' . date('Y-m-d H:i:s') . "] Booking {$bookingRef} confirmed for {$user['phone']} — Table T-{$table_number}, ₹{$payment['amount']}\n",
            FILE_APPEND
        );

        $pdo->commit();
        return ['completed' => true, 'redirect' => 'payment.php?table_id=' . urlencode($table_number) . '&success=1'];
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ─── AJAX ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        // ── Init: create pending record, return QR data ──────────────────────
        if ($_POST['action'] === 'init_payment') {
            $plan_id = $_POST['plan_id'] ?? '';
            if (empty($plan_id)) throw new Exception('Please select a plan.');

            $checkSub = $pdo->prepare("SELECT subscription_id FROM user_subscriptions WHERE user_id = ? AND subscription_status = 'Active' AND payment_status = 'Paid'");
            $checkSub->execute([$user_id]);
            if ($checkSub->fetch()) throw new Exception('You already have an active table booking.');

            $pStmt = $pdo->prepare("SELECT price, duration_days FROM subscription_plans WHERE plan_id = ? AND active = 1");
            $pStmt->execute([$plan_id]);
            $selectedPlan = $pStmt->fetch();
            if (!$selectedPlan) throw new Exception('Invalid plan selected.');

            $tStmt = $pdo->prepare("SELECT table_id, status FROM library_tables WHERE table_number = ?");
            $tStmt->execute([$table_id]);
            $tableRow = $tStmt->fetch();
            if (!$tableRow || $tableRow['status'] === 'Booked') throw new Exception('This table is no longer available.');

            $reference = 'TXN' . time() . mt_rand(1000, 9999);

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO user_subscriptions (user_id, plan_id, table_id, start_date, expiry_date, amount_paid, payment_status, subscription_status) VALUES (?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? DAY), ?, 'Pending', 'Active')")
                ->execute([$user_id, $plan_id, $tableRow['table_id'], $selectedPlan['duration_days'], $selectedPlan['price']]);
            $subscription_id = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO payments (user_id, subscription_id, amount, payment_method, payment_status, payment_reference, created_at, payment_date) VALUES (?, ?, ?, 'UPI', 'Pending', ?, NOW(), NOW())")
                ->execute([$user_id, $subscription_id, $selectedPlan['price'], $reference]);
            $pdo->commit();

            $upiUrl = 'upi://pay?' . http_build_query([
                'pa' => PAYMENT_UPI_ID,
                'pn' => PAYMENT_PAYEE_NAME,
                'am' => number_format($selectedPlan['price'], 2, '.', ''),
                'cu' => PAYMENT_CURRENCY,
                'tn' => 'Library table booking ' . $reference,
                'tr' => $reference,
            ]);

            jsonResponse([
                'ok'         => true,
                'reference'  => $reference,
                'amount'     => $selectedPlan['price'],
                'upi_url'    => $upiUrl,
                'rzp_link'   => RAZORPAY_PAYMENT_LINK,
                'window'     => PAYMENT_WINDOW_SECONDS,
            ]);
        }

        // ── Confirm: user submits UTR → mark Paid + complete booking ─────────
        if ($_POST['action'] === 'confirm_utr') {
            $reference = trim($_POST['reference'] ?? '');
            $utr       = trim($_POST['utr']       ?? '');

            if (empty($reference)) throw new Exception('Payment reference missing.');
            if (empty($utr))       throw new Exception('Please enter your UPI transaction number.');
            if (strlen($utr) < 6)  throw new Exception('Transaction number seems too short. Please check and try again.');

            $pdo->beginTransaction();
            $paymentStmt = $pdo->prepare("
                SELECT p.payment_id, p.subscription_id, p.amount, us.table_id, us.plan_id, sp.duration_days
                FROM payments p
                JOIN user_subscriptions us ON us.subscription_id = p.subscription_id
                JOIN subscription_plans sp ON sp.plan_id = us.plan_id
                WHERE p.payment_reference = ? AND p.user_id = ?
                FOR UPDATE
            ");
            $paymentStmt->execute([$reference, $user_id]);
            $payment = $paymentStmt->fetch();
            if (!$payment) throw new Exception('Payment request not found.');

            // Save UTR to payments table and keep it pending for admin verification.
            $pdo->prepare("UPDATE payments SET utr_number = ?, payment_method = 'UPI', payment_status = 'Pending' WHERE payment_id = ?")
                ->execute([$utr, $payment['payment_id']]);
            $pdo->prepare("UPDATE user_subscriptions SET payment_status = 'Pending', subscription_status = 'Active' WHERE subscription_id = ?")
                ->execute([$payment['subscription_id']]);

            $existingBooking = $pdo->prepare("SELECT booking_id FROM bookings WHERE user_id = ? AND table_id = ? AND booking_status = 'Pending' LIMIT 1");
            $existingBooking->execute([$user_id, $payment['table_id']]);
            if (!$existingBooking->fetch()) {
                $bookingRef = 'BK-' . mt_rand(100000, 999999);
                $pdo->prepare("INSERT INTO bookings (user_id, table_id, start_date, expiry_date, booking_status, booking_reference, plan_price, booking_price) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? DAY), 'Pending', ?, ?, ?)")
                    ->execute([$user_id, $payment['table_id'], $payment['duration_days'], $bookingRef, $payment['amount'], $payment['amount']]);
                $bookingId = $pdo->lastInsertId();
            } else {
                $bookingId = null;
            }

            $pdo->prepare("INSERT INTO system_notifications (type, title, message, related_id) VALUES ('booking', 'Booking Awaiting Admin Approval', ?, ?)")
                ->execute([$user['full_name'] . " submitted UPI Transaction ID {$utr} for Table T-{$table_id}. Please verify and accept/reject.", $bookingId]);
            $pdo->commit();

            @mail(
                $user['email'],
                'Booking Submitted for Review – Saraswati Abhyasika',
                "Hello {$user['full_name']},\n\nWe received your transaction ID {$utr} for Table T-{$table_id}. Your booking is pending admin verification. You will receive another email after it is accepted or rejected.\n\nThank you!",
                "From: noreply@saraswatiabhyasika.com\r\nX-Mailer: PHP/" . phpversion()
            );

            jsonResponse(['ok' => true, 'completed' => false, 'pending' => true, 'message' => 'Transaction ID submitted. Your booking is pending admin verification.', 'redirect' => 'dashboard.php']);
        }
    } catch (Exception $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['ok' => false, 'message' => $e->getMessage()]);
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment & Booking – Saraswati Abhyasika</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="dashboard.css">
    <style>
        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at 15% 15%, rgba(59, 130, 246, .35), transparent 30%),
                radial-gradient(circle at 85% 20%, rgba(236, 72, 153, .28), transparent 28%),
                radial-gradient(circle at 50% 90%, rgba(16, 185, 129, .28), transparent 30%),
                linear-gradient(135deg, #0f172a 0%, #1e1b4b 45%, #312e81 100%);
        }

        .checkout-card {
            max-width: 620px;
            margin: 40px auto;
            backdrop-filter: blur(22px);
            background: rgba(255, 255, 255, 0.94) !important;
            border: 1px solid rgba(255, 255, 255, .55);
            box-shadow: 0 24px 70px rgba(15, 23, 42, .28);
            animation: cardFloatIn .7s ease both;
        }

        @keyframes cardFloatIn {
            from {
                opacity: 0;
                transform: translateY(24px) scale(.98)
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1)
            }
        }

        .navbar {
            backdrop-filter: blur(22px);
            background: rgba(255, 255, 255, .15) !important;
            border-bottom: 1px solid rgba(255, 255, 255, .2);
        }

        .navbar h1 {
            font-size: 1.1rem;
        }

        .result-box {
            text-align: center;
            padding: 40px 20px;
        }

        .result-icon {
            font-size: 4rem;
            margin-bottom: 16px;
            display: block;
        }

        .result-title {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .result-subtitle {
            font-size: 1rem;
            color: var(--text-muted);
            margin-bottom: 8px;
            line-height: 1.6;
        }

        .refund-notice {
            margin: 20px 0;
            padding: 16px 20px;
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-radius: 12px;
            font-size: .9rem;
            color: #92400e;
            text-align: left;
            line-height: 1.6;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            margin-bottom: 20px;
            border-radius: 12px;
            border: 2px solid var(--border-color);
            background: var(--bg-primary);
            color: var(--text-main);
            font-size: 1rem;
            transition: border-color .2s;
            box-sizing: border-box;
        }

        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, .15);
        }

        .step-label {
            font-weight: 700;
            font-size: .82rem;
            color: #3b82f6;
            letter-spacing: .05em;
            text-transform: uppercase;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .step-num {
            background: #3b82f6;
            color: #fff;
            border-radius: 50%;
            width: 22px;
            height: 22px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .75rem;
            font-weight: 800;
            flex-shrink: 0;
        }

        .payment-box {
            display: none;
            margin-top: 4px;
        }

        /* ── Pay section ── */
        .pay-section {
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 18px;
            padding: 22px;
            margin-bottom: 16px;
        }

        .price-tag {
            font-size: 2.2rem;
            font-weight: 800;
            color: #10b981;
            text-align: center;
            margin-bottom: 2px;
        }

        .price-sub {
            font-size: .82rem;
            color: #64748b;
            text-align: center;
            margin-bottom: 18px;
        }

        /* QR */
        .qr-block {
            text-align: center;
            margin-bottom: 16px;
        }

        #dynamicQrImg {
            border-radius: 16px;
            padding: 10px;
            background: #fff;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .12);
            max-width: 210px;
            display: block;
            margin: 0 auto 10px;
        }

        .upi-id-pill {
            display: inline-block;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 30px;
            padding: 6px 16px;
            font-size: .85rem;
            font-weight: 600;
            color: #1d4ed8;
            margin-bottom: 4px;
        }

        .qr-apps {
            font-size: .75rem;
            color: #94a3b8;
            margin-top: 3px;
        }

        .or-divider {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #94a3b8;
            font-size: .8rem;
            font-weight: 700;
            margin: 14px 0;
        }

        .or-divider::before,
        .or-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }

        .rzp-link-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 13px 20px;
            border-radius: 13px;
            background: linear-gradient(135deg, #528ff5, #2563eb);
            color: #fff;
            font-weight: 700;
            font-size: .95rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(59, 130, 246, .3);
            transition: transform .18s, box-shadow .18s;
            box-sizing: border-box;
        }

        .rzp-link-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(59, 130, 246, .4);
        }

        /* ── UTR section ── */
        .utr-section {
            background: #f0fdf4;
            border: 1.5px solid #bbf7d0;
            border-radius: 18px;
            padding: 22px;
            margin-bottom: 16px;
        }

        .utr-section h4 {
            margin: 0 0 6px;
            font-size: .95rem;
            color: #065f46;
        }

        .utr-section p {
            margin: 0 0 14px;
            font-size: .82rem;
            color: #4b5563;
            line-height: 1.5;
        }

        .utr-input-row {
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }

        .utr-input-row .form-control {
            margin-bottom: 0;
            flex: 1;
            font-size: 1rem;
            font-family: monospace;
            letter-spacing: .05em;
        }

        .utr-submit-btn {
            padding: 13px 20px;
            border-radius: 12px;
            background: #10b981;
            color: #fff;
            border: none;
            cursor: pointer;
            font-weight: 700;
            font-size: .95rem;
            white-space: nowrap;
            transition: transform .18s, background .18s;
            flex-shrink: 0;
        }

        .utr-submit-btn:hover {
            background: #059669;
            transform: translateY(-1px);
        }

        .utr-submit-btn:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
        }

        .utr-hint {
            font-size: .75rem;
            color: #6b7280;
            margin-top: 8px;
        }

        /* Status */
        .payment-status {
            display: none;
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 16px;
            text-align: center;
            font-weight: 600;
            font-size: .92rem;
        }

        .status-waiting {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .status-success {
            background: #ecfdf5;
            color: #065f46;
        }

        .status-error {
            background: #fef2f2;
            color: #dc2626;
        }

        .spinner {
            display: inline-block;
            width: 15px;
            height: 15px;
            border: 2px solid currentColor;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin .7s linear infinite;
            vertical-align: middle;
            margin-right: 7px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg)
            }
        }

        /* Preview pills */
        .booking-preview {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 14px 0 18px;
        }

        .preview-pill {
            padding: 12px;
            border-radius: 13px;
            background: rgba(255, 255, 255, .7);
            border: 1px solid rgba(148, 163, 184, .3);
            text-align: center;
        }

        .preview-pill span {
            display: block;
            color: #64748b;
            font-size: .72rem;
            margin-bottom: 3px;
        }

        .preview-pill strong {
            color: #0f172a;
            font-size: .88rem;
        }

        .btn-primary,
        .btn-secondary {
            transition: transform .18s, box-shadow .18s;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(37, 99, 235, .25);
        }

        @media(max-width:500px) {
            .booking-preview {
                grid-template-columns: 1fr;
            }

            .utr-input-row {
                flex-direction: column;
            }

            .utr-submit-btn {
                width: 100%;
            }
        }
    </style>
</head>

<body>

    <nav class="navbar">
        <div class="nav-left">
            <img src="../IMAGES/SHREE SARASWATI ABHYASIKA LOGO.png" alt="Logo" style="height:38px;">
            <h1 style="color:#fff;">सरस्वती अभ्यासिका – Checkout</h1>
        </div>
        <div class="nav-right">
            <a href="dashboard.php" class="btn-primary"
                style="text-decoration:none;background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);padding:8px 16px;">
                ← Back
            </a>
        </div>
    </nav>

    <div style="padding:0 20px;">
        <div class="card checkout-card">

            <?php if ($success): ?>
                <!-- ══ SUCCESS ══ -->
                <div class="result-box">
                    <span class="result-icon">✅</span>
                    <div class="result-title" style="color:#059669;">Booking Confirmed!</div>
                    <p class="result-subtitle">
                        Your table <strong>T-<?= htmlspecialchars($table_id) ?></strong> has been booked successfully.
                    </p>
                    <p class="result-subtitle" style="font-size:.85rem;">
                        A confirmation has been sent to <strong><?= htmlspecialchars($user['email']) ?></strong>.
                    </p>
                    <a href="dashboard.php" class="btn-primary"
                        style="text-decoration:none;display:inline-block;margin-top:24px;padding:14px 40px;">
                        Go to My Dashboard
                    </a>
                </div>

            <?php elseif ($failed): ?>
                <!-- ══ FAILURE ══ -->
                <div class="result-box">
                    <span class="result-icon">❌</span>
                    <div class="result-title" style="color:#dc2626;">Payment Not Confirmed</div>
                    <p class="result-subtitle">Booking for Table <strong>T-<?= htmlspecialchars($table_id) ?></strong>
                        could not be confirmed.</p>
                    <div class="refund-notice">
                        <?php if ($failMsg): ?>
                            <?= htmlspecialchars($failMsg) ?>
                        <?php else: ?>
                            <strong>💰 Refund Policy</strong>
                            If any amount was deducted, it will be <strong>automatically refunded within 24 hours</strong>.
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;gap:12px;justify-content:center;margin-top:20px;flex-wrap:wrap;">
                        <a href="payment.php?table_id=<?= urlencode($table_id) ?>" class="btn-primary"
                            style="text-decoration:none;width:auto;padding:12px 28px;">🔄 Try Again</a>
                        <a href="dashboard.php" class="btn-primary"
                            style="text-decoration:none;width:auto;padding:12px 28px;background:#64748b;">Dashboard</a>
                    </div>
                </div>

            <?php else: ?>
                <!-- ══ CHECKOUT ══ -->
                <h2 style="text-align:center;margin-bottom:4px;">Complete Your Booking</h2>
                <p style="text-align:center;color:var(--text-muted);margin-bottom:16px;">
                    Booking Table <strong style="color:#3b82f6;">T-<?= htmlspecialchars($table_id) ?></strong>
                </p>

                <div class="booking-preview">
                    <div class="preview-pill"><span>Table</span><strong>T-<?= htmlspecialchars($table_id) ?></strong>
                    </div>
                    <div class="preview-pill"><span>Plan</span><strong id="previewPlan">—</strong></div>
                    <div class="preview-pill"><span>Status</span><strong id="previewStatus">Select plan</strong></div>
                </div>

                <!-- Step 1: Plan -->
                <div class="step-label"><span class="step-num">1</span> Select Your Plan</div>
                <select class="form-control" id="planSelect" required>
                    <option value="">— Choose a Plan —</option>
                    <?php foreach ($plans as $plan): ?>
                        <option value="<?= htmlspecialchars($plan['plan_id']) ?>"
                            data-price="<?= htmlspecialchars($plan['price']) ?>"
                            data-days="<?= htmlspecialchars($plan['duration_days']) ?>">
                            <?= htmlspecialchars($plan['plan_name']) ?> — ₹<?= htmlspecialchars($plan['price']) ?> /
                            <?= htmlspecialchars($plan['duration_days']) ?> days
                        </option>
                    <?php endforeach; ?>
                </select>

                <!-- Steps 2 & 3 (shown after plan select) -->
                <div id="paymentBox" class="payment-box">

                    <!-- Step 2: Pay -->
                    <div class="step-label" style="margin-bottom:12px;"><span class="step-num">2</span> Pay the Amount
                    </div>
                    <div class="pay-section">
                        <div class="price-tag" id="priceDisplay"></div>
                        <div class="price-sub">Pay using UPI — scan QR or use the Razorpay link</div>

                        <div class="qr-block">
                            <img id="dynamicQrImg" src="" alt="UPI QR Code">
                            <div class="upi-id-pill">📲 <?= htmlspecialchars(PAYMENT_UPI_ID) ?></div>
                            <p class="qr-apps">GPay · PhonePe · Paytm · Any UPI app</p>
                        </div>

                        <div class="or-divider">OR</div>

                        <a id="rzpLinkBtn" href="<?= htmlspecialchars(RAZORPAY_PAYMENT_LINK) ?>" target="_blank"
                            class="rzp-link-btn">
                            <svg width="20" height="20" viewBox="0 0 40 40" fill="none">
                                <rect width="40" height="40" rx="8" fill="#002970" />
                                <path d="M8 28l8-16 5 9-3 7H8z" fill="#528ff5" />
                                <path d="M16 12l16 4-8 8-8-12z" fill="#fff" />
                            </svg>
                            Pay via Razorpay Link
                        </a>
                        <p style="text-align:center;font-size:.73rem;color:#94a3b8;margin-top:7px;">Opens secure
                            Razorpay checkout in a new tab</p>
                    </div>

                    <!-- Step 3: Enter UTR -->
                    <div class="step-label" style="margin-bottom:12px;"><span class="step-num">3</span> Enter Your UPI
                        Transaction Number</div>
                    <div class="utr-section">
                        <h4>After paying, enter your transaction number to confirm booking</h4>
                        <p>You can find this in your UPI app — it's usually a 12-digit number called
                            <strong>UTR</strong> or <strong>Transaction ID</strong>, shown in the payment success
                            screen.
                        </p>
                        <div class="utr-input-row">
                            <input type="text" id="utrInput" class="form-control" placeholder="e.g. 426812345678"
                                maxlength="30" autocomplete="off" inputmode="numeric">
                            <button type="button" id="utrSubmitBtn" class="utr-submit-btn">
                                Confirm Booking →
                            </button>
                        </div>
                        <div class="utr-hint">💡 Find UTR in GPay → History → tap the payment → "UPI transaction ID"
                        </div>
                    </div>

                    <!-- Status -->
                    <div class="payment-status" id="paymentStatus"></div>

                </div><!-- #paymentBox -->

            <?php endif; ?>
        </div>
    </div>

    <script>
        const TABLE_ID = <?= json_encode($table_id) ?>;
        let activeReference = '';

        const planSelect = document.getElementById('planSelect');
        const paymentBox = document.getElementById('paymentBox');
        const priceDisplay = document.getElementById('priceDisplay');
        const statusBox = document.getElementById('paymentStatus');
        const dynamicQrImg = document.getElementById('dynamicQrImg');
        const utrInput = document.getElementById('utrInput');
        const utrSubmitBtn = document.getElementById('utrSubmitBtn');
        const previewPlan = document.getElementById('previewPlan');
        const previewStatus = document.getElementById('previewStatus');
        const rzpLinkBtn = document.getElementById('rzpLinkBtn');

        function showStatus(msg, type = 'waiting') {
            statusBox.innerHTML = type === 'waiting' ? `<span class="spinner"></span>${msg}` : msg;
            statusBox.className = 'payment-status status-' + type;
            statusBox.style.display = 'block';
            if (previewStatus) previewStatus.textContent =
                type === 'success' ? '✅ Confirmed' : type === 'error' ? '❌ Failed' : '⏳ Processing';
        }

        async function postAction(action, extra = {}) {
            const body = new URLSearchParams({
                action,
                ...extra
            });
            const res = await fetch('payment.php?table_id=' + encodeURIComponent(TABLE_ID), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body
            });
            return res.json();
        }

        // Init payment when plan is selected
        async function createPaymentRequest() {
            const opt = planSelect.options[planSelect.selectedIndex];
            const price = opt.getAttribute('data-price');
            const planId = planSelect.value;

            activeReference = '';
            paymentBox.style.display = 'none';
            statusBox.style.display = 'none';
            if (previewPlan) previewPlan.textContent = '—';
            if (previewStatus) previewStatus.textContent = 'Select plan';
            if (!price || !planId) return;

            if (previewPlan) previewPlan.textContent = opt.textContent.trim();
            priceDisplay.textContent = '₹' + parseFloat(price).toLocaleString('en-IN');

            showStatus('Setting up payment…', 'waiting');
            paymentBox.style.display = 'block';

            try {
                const result = await postAction('init_payment', {
                    plan_id: planId
                });
                if (!result.ok) {
                    paymentBox.style.display = 'none';
                    showStatus('❌ ' + (result.message || 'Could not start payment.'), 'error');
                    return;
                }
                activeReference = result.reference;
                dynamicQrImg.src = 'https://api.qrserver.com/v1/create-qr-code/?size=210x210&data=' +
                    encodeURIComponent(result.upi_url);
                rzpLinkBtn.href = result.rzp_link;
                statusBox.style.display = 'none'; // hide "setting up" once ready
                if (previewStatus) previewStatus.textContent = '⏳ Awaiting payment';
            } catch (err) {
                paymentBox.style.display = 'none';
                showStatus('❌ Could not create payment request. Please try again.', 'error');
            }
        }

        // Submit UTR
        async function submitUtr() {
            const utr = utrInput.value.trim();
            if (!activeReference) {
                showStatus('❌ Please select a plan first.', 'error');
                return;
            }
            if (!utr) {
                showStatus('❌ Please enter your UPI transaction number.', 'error');
                utrInput.focus();
                return;
            }
            if (utr.length < 6) {
                showStatus('❌ Transaction number seems too short. Please check.', 'error');
                utrInput.focus();
                return;
            }

            utrSubmitBtn.disabled = true;
            utrSubmitBtn.textContent = 'Confirming…';
            showStatus('Submitting transaction ID for admin verification…', 'waiting');

            try {
                const result = await postAction('confirm_utr', {
                    reference: activeReference,
                    utr
                });
                if (result.ok && result.pending) {
                    showStatus('✅ Transaction ID submitted. Admin will verify and email you after accept/reject. Redirecting…', 'success');
                    setTimeout(() => window.location.href = result.redirect || 'dashboard.php', 1800);
                } else if (result.ok && result.completed) {
                    showStatus('✅ Booking confirmed! Redirecting…', 'success');
                    setTimeout(() => window.location.href = result.redirect, 1200);
                } else {
                    showStatus('❌ ' + (result.message || 'Could not confirm. Please contact support.'), 'error');
                    utrSubmitBtn.disabled = false;
                    utrSubmitBtn.textContent = 'Confirm Booking →';
                    if (result.redirect) setTimeout(() => window.location.href = result.redirect, 2500);
                }
            } catch (err) {
                showStatus('❌ Server error. Please try again or contact support.', 'error');
                utrSubmitBtn.disabled = false;
                utrSubmitBtn.textContent = 'Confirm Booking →';
            }
        }

        if (planSelect) planSelect.addEventListener('change', createPaymentRequest);
        if (utrSubmitBtn) utrSubmitBtn.addEventListener('click', submitUtr);
        if (utrInput) {
            utrInput.addEventListener('keydown', e => {
                if (e.key === 'Enter') submitUtr();
            });
        }
    </script>
</body>

</html>