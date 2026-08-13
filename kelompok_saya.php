<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

setCORSHeaders();
$conn = getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method tidak diizinkan', null, 405);
    exit;
}

$user = validateToken($conn);
requireRole($user, 'anggota');

// PENTING: kunci relasinya anggota.user_id = users.id (dari token),
// BUKAN anggota.id. Sebelumnya salah pakai anggota.id sehingga data
// ketuker ke anggota lain yang kebetulan id-nya sama dengan users.id.
$stmtA = $conn->prepare(
    "SELECT id, is_ketua, nama_kelompok, nama_desa, limit_pinjaman, jumlah_kredit_lunas
     FROM anggota WHERE user_id = ? LIMIT 1"
);
$stmtA->bind_param('i', $user['id']);
$stmtA->execute();
$resA = $stmtA->get_result();

if ($resA->num_rows === 0) {
    sendResponse(false, 'Data anggota tidak ditemukan untuk user ini', null, 404);
    exit;
}

$anggota = $resA->fetch_assoc();

// Pakai fungsi yang SAMA PERSIS dengan validasi submit di proposal.php,
// supaya angka yang tampil di form selalu sinkron dengan limit asli.
$hasilLimit = hitungLimitPengajuan($conn, $anggota);

$data = [
    'id'             => (int)$anggota['id'],
    'nama_kelompok'  => $anggota['nama_kelompok'],
    'desa'           => $anggota['nama_desa'],
    'limit_pinjaman' => $hasilLimit['limit_final'],
];

sendResponse(true, 'OK', $data);
$conn->close();