<?php
// Payment gateway / UPI settings for the user checkout flow.
//
// HOW AUTO-CONFIRM WORKS:
//   1. User scans QR and pays via UPI app.
//   2. The page polls /payment.php every PAYMENT_POLL_SECONDS.
//   3. After PAYMENT_WINDOW_SECONDS from QR generation, the server
//      checks the payment status:
//        - If payment_status is still 'Pending' after the window,
//          it is marked 'Failed' automatically and user sees a clear
//          failure message with refund info.
//        - If payment_status was updated to 'Paid' (via webhook /
//          admin confirm_payment.php), booking is completed instantly.
//   4. PAYMENT_AUTO_CONFIRM_DEMO = true means the server will auto-mark
//      Pending → Paid after PAYMENT_AUTO_CONFIRM_AFTER_SECONDS (for
//      testing only — remove in production with a real gateway).

const PAYMENT_UPI_ID                    = 'pratikshingare2002@okicici';
const PAYMENT_PAYEE_NAME                = 'Saraswati Abhyasika';
const PAYMENT_CURRENCY                  = 'INR';

// ── Demo / Test mode ────────────────────────────────────────────────
// Set true ONLY for local testing. In production set false and use
// confirm_payment.php webhook from your payment gateway.
const PAYMENT_AUTO_CONFIRM_DEMO = true;   // ← changed to true
const PAYMENT_AUTO_CONFIRM_AFTER_SECONDS = 1;    // wait 60 s then auto-confirm in demo

// ── Payment window ──────────────────────────────────────────────────
// How long (seconds) the user has to complete the UPI payment.
// After this the payment is treated as Not Done and marked Failed.
const PAYMENT_WINDOW_SECONDS = 60;    // 5 minutes

// ── Polling ─────────────────────────────────────────────────────────
const PAYMENT_POLL_SECONDS = 5;      // frontend polls every 5 s

// ── Webhook secret ──────────────────────────────────────────────────
// Change this to a strong random string and keep it secret.
const PAYMENT_WEBHOOK_SECRET            = '';
