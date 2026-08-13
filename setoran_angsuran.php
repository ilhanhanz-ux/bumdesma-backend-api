<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

setCORSHeaders();
$conn = getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    sendResponse(false, 'Method tidak diizinkan', null, 405);
    exit;
}

$user = validateToken($conn);

if ($user['role'] !== 'admin') {
    sendResponse(false, 'Hanya admin yang bisa mencatat setoran', null, 403);
    exit;
}

// PUT + JSON biasa (bukan multipart) — menghindari limitasi parsing PHP untuk PUT+multipart
$data = json_decode(file_get_contents('php://input'), true);

$angsuran_id     = isset($data['angsuran_id']) ? intval($data['angsuran_id']) : 0;
$tanggal_bayar   = isset($data['tanggal_bayar']) ? trim($data['tanggal_bayar']) : date('Y-m-d');
$keterangan      = isset($data['keterangan']) ? trim($data['keterangan']) : null;
$bukti_bayar_b64 = $data['bukti_bayar'] ?? null; // string base64 dari Android

if ($angsuran_id <= 0) {
    sendResponse(false, 'angsuran_id wajib diisi', null, 400);
    exit;
}

if (empty($bukti_bayar_b64)) {
    sendResponse(false, 'Foto bukti transfer wajib diisi', null, 400);
    exit;
}

// Pastikan angsuran ada dan belum lunas
$stmt = $conn->prepare("SELECT id, status_bayar FROM angsuran WHERE id = ?");
$stmt->bind_param('i', $angsuran_id);
$stmt->execute();
$angsuran = $stmt->get_result()->fetch_assoc();

if (!$angsuran) {
    sendResponse(false, 'Angsuran tidak ditemukan', null, 404);
    exit;
}

if ($angsuran['status_bayar'] === 'sudah_bayar') {
    sendResponse(false, 'Angsuran ini sudah tercatat lunas', null, 400);
    exit;
}

// Buang prefix "data:image/jpeg;base64," kalau ada
if (strpos($bukti_bayar_b64, ',') !== false) {
    $bukti_bayar_b64 = explode(',', $bukti_bayar_b64)[1];
}
$imageData = base64_decode($bukti_bayar_b64);

if ($imageData === false) {
    sendResponse(false, 'Format foto tidak valid', null, 400);
    exit;
}

// Simpan file ke folder uploads (di luar folder api, sejajar dengannya)
$uploadDir = '../uploads/bukti_bayar/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$fileName = 'bukti_' . $angsuran_id . '_' . time() . '.jpg';
$filePath = $uploadDir . $fileName;

if (!file_put_contents($filePath, $imageData)) {
    sendResponse(false, 'Gagal menyimpan foto bukti transfer', null, 500);
    exit;
}

$relativePath = 'uploads/bukti_bayar/' . $fileName;

// Update angsuran jadi lunas + evaluasi kenaikan limit (konsisten dengan
// angsuran.php, angsuran_porsi.php, dan setoran_kolektif.php)
finalisasiPembayaranLunas($conn, $angsuran_id, (int)$user['id'], $tanggal_bayar, $relativePath, $keterangan);

sendResponse(true, 'Setoran angsuran berhasil dicatat', [
    'angsuran_id'   => $angsuran_id,
    'tanggal_bayar' => $tanggal_bayar,
    'bukti_bayar'   => $relativePath
]);
$conn->close();