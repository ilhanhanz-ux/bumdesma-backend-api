<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

setCORSHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    sendResponse(false, 'Method tidak diizinkan', null, 405);
    exit;
}

$conn = getConnection();
$user = validateToken($conn);
requireRole($user, 'admin');

$data = getJsonBody();
$nama = trim($data['nama'] ?? '');

if (empty($nama)) {
    sendResponse(false, 'Nama tidak boleh kosong');
    exit;
}

$stmt = $conn->prepare("UPDATE users SET nama = ? WHERE id = ?");
$stmt->bind_param('si', $nama, $user['id']);

if (!$stmt->execute()) {
    sendResponse(false, 'Gagal memperbarui nama: ' . $conn->error);
    exit;
}

// Catat ke audit log, konsisten dengan aksi admin lain yang sudah tercatat
catatAktivitas($conn, $user['id'], $user['role'], 'profil', 'ubah_nama',
    'Mengubah nama menjadi: ' . $nama);

sendResponse(true, 'Nama berhasil diperbarui', ['nama' => $nama]);
$conn->close();