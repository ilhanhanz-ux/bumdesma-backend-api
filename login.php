<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

setCORSHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method tidak diizinkan', null, 405);
}

$data     = json_decode(file_get_contents('php://input'), true);
$username = trim($data['username'] ?? '');
$password = trim($data['password'] ?? '');

if (empty($username) || empty($password)) {
    sendResponse(false, 'Username dan password wajib diisi');
}

$conn = getConnection();

// Ambil data user
$stmt = $conn->prepare(
    "SELECT id, username, nama, no_telepon, role, password
     FROM users
     WHERE username = ?"
);
$stmt->bind_param('s', $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    sendResponse(false, 'Username tidak ditemukan');
}

$user = $result->fetch_assoc();

// Verifikasi password
if (!password_verify($password, $user['password'])) {
    sendResponse(false, 'Password salah');
}

// Kalau role anggota, cek anggota_id, status verifikasi, dan status ketua sekaligus
$anggotaId        = null;
$statusVerifikasi = null;
// ── BARU: status ketua ikut diambil di sini, supaya Android tahu jabatan
// anggota ini langsung dari sesi login, tanpa perlu call API terpisah.
$isKetua          = 0;

if ($user['role'] === 'anggota') {
    $stmtAnggota = $conn->prepare(
        "SELECT id, status_verifikasi, is_ketua FROM anggota WHERE user_id = ? LIMIT 1"
    );
    $stmtAnggota->bind_param('i', $user['id']);
    $stmtAnggota->execute();
    $resAnggota = $stmtAnggota->get_result();

    if ($resAnggota->num_rows === 0) {
        sendResponse(false, 'Data anggota tidak ditemukan. Hubungi admin.');
    }

    $anggota          = $resAnggota->fetch_assoc();
    $anggotaId        = $anggota['id'];
    $statusVerifikasi = $anggota['status_verifikasi'];
    $isKetua          = (int)$anggota['is_ketua'];

    // Gerbang verifikasi manual: hanya anggota berstatus "diterima" yang boleh login.
    // Belum sempat verifikasi (pending) atau ternyata bukan warga Randudongkal (ditolak)
    // -> login ditolak di sini, token tidak pernah diterbitkan.
    if ($statusVerifikasi === 'pending') {
        sendResponse(false, 'Akun kamu masih menunggu verifikasi admin. Silakan tunggu konfirmasi.', null, 403);
    }

    if ($statusVerifikasi === 'ditolak') {
        sendResponse(false, 'Akun kamu ditolak. Data tidak sesuai dengan warga Kecamatan Randudongkal.', null, 403);
    }
}

// Titik ini hanya tercapai kalau role admin, atau anggota berstatus "diterima"
$token = bin2hex(random_bytes(32));

// Simpan token ke database
$stmtToken = $conn->prepare(
    "UPDATE users SET token = ? WHERE id = ?"
);
$stmtToken->bind_param('si', $token, $user['id']);
$stmtToken->execute();

sendResponse(true, 'Login berhasil', [
    'token'      => $token,
    'role'       => $user['role'],
    'user_id'    => $user['id'],
    'anggota_id' => $anggotaId,
    'nama'       => $user['nama'],
    // ── BARU ──
    'is_ketua'   => $isKetua
]);

$conn->close();