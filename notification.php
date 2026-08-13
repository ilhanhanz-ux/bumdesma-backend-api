<?php
require_once '../config/database.php';
require_once '../config/helpers.php';
require_once 'config_midtrans.php';

$conn = getConnection();

$payload = json_decode(file_get_contents('php://input'), true);

if (!$payload || !isset($payload['order_id'])) {
    sendResponse(false, 'Payload tidak valid', null, 400);
    exit;
}

$order_id            = $payload['order_id'];
$status_code         = $payload['status_code'] ?? '';
$gross_amount        = $payload['gross_amount'] ?? '';
$signature_key       = $payload['signature_key'] ?? '';
$transaction_status  = $payload['transaction_status'] ?? '';

// Verifikasi signature — WAJIB, mencegah notifikasi palsu
$expected_signature = hash('sha512', $order_id . $status_code . $gross_amount . MIDTRANS_SERVER_KEY);

if ($signature_key !== $expected_signature) {
    sendResponse(false, 'Signature tidak valid', null, 403);
    exit;
}

$stmt = $conn->prepare("SELECT id, angsuran_id, status FROM transaksi_pembayaran WHERE order_id = ?");
$stmt->bind_param('s', $order_id);
$stmt->execute();
$transaksi = $stmt->get_result()->fetch_assoc();

if (!$transaksi) {
    sendResponse(false, 'Transaksi tidak ditemukan', null, 404);
    exit;
}

$new_status = $transaksi['status'];
switch ($transaction_status) {
    case 'capture':
    case 'settlement':
        $new_status = 'settlement';
        break;
    case 'pending':
        $new_status = 'pending';
        break;
    case 'deny':
        $new_status = 'deny';
        break;
    case 'cancel':
        $new_status = 'cancel';
        break;
    case 'expire':
        $new_status = 'expire';
        break;
}

$stmt2 = $conn->prepare("UPDATE transaksi_pembayaran SET status = ? WHERE id = ?");
$stmt2->bind_param('si', $new_status, $transaksi['id']);
$stmt2->execute();

if ($new_status === 'settlement') {
    $stmt3 = $conn->prepare("UPDATE angsuran SET status_bayar = 'sudah_bayar', tanggal_bayar = CURDATE() WHERE id = ?");
    $stmt3->bind_param('i', $transaksi['angsuran_id']);
    $stmt3->execute();
}

sendResponse(true, 'Notification diproses');
$conn->close();