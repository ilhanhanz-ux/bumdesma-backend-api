<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

setCORSHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method tidak diizinkan', null, 405);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$step = trim($data['step'] ?? '');

$conn = getConnection();

// ── STEP 1: Verifikasi username + NIK ─────────────────
if ($step === 'verifikasi') {
    $username = trim($data['username'] ?? '');
    $nik      = trim($data['nik']      ?? '');

    if (empty($username) || empty($nik)) {
        sendResponse(false, 'Username dan NIK wajib diisi');
        exit;
    }

    // Cari user berdasarkan username + NIK
    $stmt = $conn->prepare("
        SELECT u.id, u.nama
        FROM users u
        JOIN anggota a ON a.user_id = u.id
        WHERE u.username = ?
        AND a.nik = ?
        LIMIT 1
    ");
    $stmt->bind_param('ss', $username, $nik);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        sendResponse(false, 'Username atau NIK tidak cocok');
        exit;
    }

    $user  = $result->fetch_assoc();
    $token = bin2hex(random_bytes(32));

    // Simpan token dengan expired 1 JAM
    // Pakai UTC_TIMESTAMP() untuk hindari masalah timezone
    $stmtSave = $conn->prepare("
        UPDATE users
        SET reset_token = ?,
            reset_token_expired = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR)
        WHERE id = ?
    ");
    $stmtSave->bind_param('si', $token, $user['id']);

    if (!$stmtSave->execute()) {
        sendResponse(false, 'Gagal menyimpan token: ' . $conn->error);
        exit;
    }

    // Verifikasi token tersimpan
    $stmtCek = $conn->prepare(
        "SELECT reset_token, reset_token_expired FROM users WHERE id = ?"
    );
    $stmtCek->bind_param('i', $user['id']);
    $stmtCek->execute();
    $cekResult = $stmtCek->get_result()->fetch_assoc();

    if ($cekResult['reset_token'] !== $token) {
        sendResponse(false, 'Gagal menyimpan sesi. Coba lagi.');
        exit;
    }

    sendResponse(true, 'Verifikasi berhasil', [
        'reset_token' => $token,
        'nama'        => $user['nama'],
        'expired_at'  => $cekResult['reset_token_expired']
    ]);
    exit;
}

// ── STEP 2: Reset password baru ───────────────────────
if ($step === 'reset') {
    $token        = trim($data['reset_token']   ?? '');
    $passwordBaru = trim($data['password_baru'] ?? '');
    $konfirmasi   = trim($data['konfirmasi']    ?? '');

    if (empty($token)) {
        sendResponse(false, 'Token tidak valid');
        exit;
    }
    if (strlen($passwordBaru) < 6) {
        sendResponse(false, 'Password minimal 6 karakter');
        exit;
    }
    if ($passwordBaru !== $konfirmasi) {
        sendResponse(false, 'Konfirmasi password tidak cocok');
        exit;
    }

    // Cari user dengan token valid
    // Gunakan UTC_TIMESTAMP() untuk konsistensi timezone
    $stmt = $conn->prepare("
        SELECT id FROM users
        WHERE reset_token = ?
        AND reset_token_expired > UTC_TIMESTAMP()
    ");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        // Cek apakah token ada tapi expired
        $stmtCek = $conn->prepare(
            "SELECT reset_token_expired FROM users WHERE reset_token = ?"
        );
        $stmtCek->bind_param('s', $token);
        $stmtCek->execute();
        $cekRow = $stmtCek->get_result()->fetch_assoc();

        if ($cekRow) {
            sendResponse(false,
                'Sesi kadaluarsa (expired: '
                . $cekRow['reset_token_expired']
                . '). Silakan ulangi dari awal.');
        } else {
            sendResponse(false, 'Token tidak ditemukan. Silakan ulangi dari awal.');
        }
        exit;
    }

    $user         = $result->fetch_assoc();
    $passwordHash = password_hash($passwordBaru, PASSWORD_BCRYPT);

    // Update password + hapus token
    $stmtUpdate = $conn->prepare("
        UPDATE users
        SET password = ?,
            reset_token = NULL,
            reset_token_expired = NULL
        WHERE id = ?
    ");
    $stmtUpdate->bind_param('si', $passwordHash, $user['id']);

    if (!$stmtUpdate->execute()) {
        sendResponse(false, 'Gagal update password: ' . $conn->error);
        exit;
    }

    sendResponse(true, 'Password berhasil diubah! Silakan login dengan password baru.');
    exit;
}

sendResponse(false, 'Step tidak valid: ' . $step);
$conn->close();