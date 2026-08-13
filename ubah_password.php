<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

setCORSHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method tidak diizinkan', null, 405);
    exit;
}

$conn = getConnection();
$user = validateToken($conn);

$data = json_decode(file_get_contents('php://input'), true);

$passwordLama = trim($data['password_lama'] ?? '');
$passwordBaru = trim($data['password_baru'] ?? '');
$konfirmasi   = trim($data['konfirmasi']    ?? '');

if (empty($passwordLama)) {
    sendResponse(false, 'Password lama wajib diisi');
    exit;
}
if (strlen($passwordBaru) < 6) {
    sendResponse(false, 'Password baru minimal 6 karakter');
    exit;
}
if ($passwordBaru !== $konfirmasi) {
    sendResponse(false, 'Konfirmasi password baru tidak cocok');
    exit;
}

// Ambil hash password saat ini
$stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    sendResponse(false, 'User tidak ditemukan');
    exit;
}

$row = $result->fetch_assoc();

if (!password_verify($passwordLama, $row['password'])) {
    sendResponse(false, 'Password lama tidak sesuai');
    exit;
}

$passwordHashBaru = password_hash($passwordBaru, PASSWORD_BCRYPT);

$stmtUpdate = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
$stmtUpdate->bind_param('si', $passwordHashBaru, $user['id']);

if (!$stmtUpdate->execute()) {
    sendResponse(false, 'Gagal update password: ' . $conn->error);
    exit;
}

sendResponse(true, 'Password berhasil diubah');
$conn->close();