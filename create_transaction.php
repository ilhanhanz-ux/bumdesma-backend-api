<?php
require_once '../config/database.php';
require_once '../config/helpers.php';
require_once 'config_midtrans.php';

setCORSHeaders();
$conn = getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method tidak diizinkan', null, 405);
    exit;
}

$user = validateToken($conn);

if ($user['role'] !== 'anggota') {
    sendResponse(false, 'Hanya anggota yang bisa melakukan pembayaran', null, 403);
    exit;
}

// Ambil anggota_id dari user yang login (pola sama seperti angsuran.php)
$stmtA = $conn->prepare("SELECT id, nama_lengkap FROM anggota WHERE user_id = ? LIMIT 1");
$stmtA->bind_param('i', $user['id']);
$stmtA->execute();
$resA = $stmtA->get_result();

if ($resA->num_rows === 0) {
    sendResponse(false, 'Data anggota tidak ditemukan untuk user ini', null, 404);
    exit;
}
$anggotaRow  = $resA->fetch_assoc();
$anggotaId   = $anggotaRow['id'];
$namaAnggota = $anggotaRow['nama_lengkap'];

// Ambil angsuran_id dari body request
$data = json_decode(file_get_contents('php://input'), true);
$angsuran_id = isset($data['angsuran_id']) ? intval($data['angsuran_id']) : 0;

if ($angsuran_id <= 0) {
    sendResponse(false, 'angsuran_id wajib diisi', null, 400);
    exit;
}

// Pastikan angsuran itu milik anggota yang login
$stmt = $conn->prepare("SELECT id, total_bayar, status_bayar FROM angsuran WHERE id = ? AND anggota_id = ?");
$stmt->bind_param('ii', $angsuran_id, $anggotaId);
$stmt->execute();
$angsuran = $stmt->get_result()->fetch_assoc();

if (!$angsuran) {
    sendResponse(false, 'Angsuran tidak ditemukan atau bukan milik anda', null, 404);
    exit;
}

if ($angsuran['status_bayar'] === 'sudah_bayar') {
    sendResponse(false, 'Angsuran ini sudah lunas', null, 400);
    exit;
}

// Generate order_id unik
$order_id     = "ANGSURAN-" . $angsuran_id . "-" . time();
$gross_amount = (int) $angsuran['total_bayar'];

// Panggil Snap API Midtrans
$payload = [
    "transaction_details" => [
        "order_id"     => $order_id,
        "gross_amount" => $gross_amount
    ],
    "customer_details" => [
        "first_name" => $namaAnggota,
        "email"      => "anggota" . $anggotaId . "@bumdesma.local"
    ]
];

$ch = curl_init(MIDTRANS_IS_PRODUCTION
    ? 'https://app.midtrans.com/snap/v1/transactions'
    : 'https://app.sandbox.midtrans.com/snap/v1/transactions'
);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Accept: application/json",
    "Authorization: Basic " . base64_encode(MIDTRANS_SERVER_KEY . ":")
]);
$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$midtrans_result = json_decode($response, true);

if ($http_code !== 201 || !isset($midtrans_result['token'])) {
    sendResponse(false, 'Gagal membuat transaksi Midtrans', $midtrans_result, 502);
    exit;
}

// Simpan record transaksi
$stmt2 = $conn->prepare("
    INSERT INTO transaksi_pembayaran (angsuran_id, order_id, gross_amount, snap_token, status)
    VALUES (?, ?, ?, ?, 'pending')
");
$stmt2->bind_param('isds', $angsuran_id, $order_id, $gross_amount, $midtrans_result['token']);
$stmt2->execute();

sendResponse(true, 'Transaksi berhasil dibuat', [
    'order_id'     => $order_id,
    'snap_token'   => $midtrans_result['token'],
    'redirect_url' => $midtrans_result['redirect_url']
]);
$conn->close();