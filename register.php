<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

setCORSHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method tidak diizinkan', null, 405);
}

// ── PERUBAHAN: sebelumnya JSON via php://input, sekarang Multipart ──
// Field teks masuk lewat $_POST, file KTP masuk lewat $_FILES.
$nama         = trim($_POST['nama_lengkap']  ?? '');
$username     = trim($_POST['username']      ?? '');
$password     = trim($_POST['password']      ?? '');
$konfirmasi   = trim($_POST['konfirmasi']    ?? '');
$nik          = trim($_POST['nik']           ?? '');
$noTelepon    = trim($_POST['no_telepon']    ?? '');
$tempatLahir  = trim($_POST['tempat_lahir']  ?? '');
$tanggalLahir = trim($_POST['tanggal_lahir'] ?? '');
$alamat       = trim($_POST['alamat']        ?? '');
$namaKelompok = trim($_POST['nama_kelompok'] ?? '');
$namaDesa     = trim($_POST['nama_desa']     ?? '');

// Validasi (urutan sama persis dengan versi lama)
if (empty($nama) || empty($username) || empty($password)
    || empty($nik) || empty($noTelepon) || empty($tempatLahir)
    || empty($tanggalLahir) || empty($alamat)) {
    sendResponse(false, 'Semua kolom wajib diisi');
}
if (strlen($password) < 6) {
    sendResponse(false, 'Password minimal 6 karakter');
}
if ($password !== $konfirmasi) {
    sendResponse(false, 'Konfirmasi password tidak cocok');
}
if (!preg_match('/^\d{16}$/', $nik)) {
    sendResponse(false, 'NIK harus 16 digit angka');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalLahir)) {
    sendResponse(false, 'Format tanggal lahir tidak valid');
}

// ── VALIDASI BARU: foto KTP wajib ──
if (!isset($_FILES['foto_ktp']) || $_FILES['foto_ktp']['error'] !== UPLOAD_ERR_OK) {
    sendResponse(false, 'Foto KTP wajib diunggah');
}

$conn = getConnection();

// Cek username duplikat
$cek = $conn->prepare("SELECT id FROM users WHERE username = ?");
$cek->bind_param('s', $username);
$cek->execute();
if ($cek->get_result()->num_rows > 0) {
    sendResponse(false, 'Username sudah digunakan');
}

// Cek NIK duplikat di tabel anggota
$cekNik = $conn->prepare("SELECT id FROM anggota WHERE nik = ?");
$cekNik->bind_param('s', $nik);
$cekNik->execute();
if ($cekNik->get_result()->num_rows > 0) {
    sendResponse(false, 'NIK sudah terdaftar');
}

// ── UPLOAD FOTO KTP ──
// uploadFile(string $inputName, string $subfolder): string
// Ambil sendiri dari $_FILES[$inputName], validasi mime (jpeg/png/pdf) & MAX_FILE_SIZE
// di dalam fungsinya, return path relatif ("ktp/ktp_xxx.jpg") atau '' kalau gagal.
$fotoKtpPath = uploadFile('foto_ktp', 'ktp');
if ($fotoKtpPath === '') {
    sendResponse(false, 'Gagal mengunggah foto KTP (pastikan format JPG/PNG dan ukuran sesuai batas)');
}

$passwordHash = password_hash($password, PASSWORD_BCRYPT);

// ── Deteksi kolom yang ada di tabel anggota ──────────────
$kolomAnggota = [];
$showCols = $conn->query("SHOW COLUMNS FROM anggota");
while ($col = $showCols->fetch_assoc()) {
    $kolomAnggota[] = $col['Field'];
}

$conn->begin_transaction();
try {
    // Simpan ke tabel users
    $stmtUser = $conn->prepare(
        "INSERT INTO users (username, nama, no_telepon, password, role)
         VALUES (?, ?, ?, ?, 'anggota')"
    );
    $stmtUser->bind_param('ssss',
        $username, $nama, $noTelepon, $passwordHash);
    $stmtUser->execute();
    $userId = $conn->insert_id;

    // Bangun query INSERT anggota sesuai kolom yang tersedia
    $fields = ['user_id'];
    $values = [$userId];
    $types  = 'i';

    if (in_array('nik', $kolomAnggota)) {
        $fields[] = 'nik'; $values[] = $nik; $types .= 's';
    }
    if (in_array('nama_lengkap', $kolomAnggota)) {
        $fields[] = 'nama_lengkap'; $values[] = $nama; $types .= 's';
    } elseif (in_array('nama', $kolomAnggota)) {
        $fields[] = 'nama'; $values[] = $nama; $types .= 's';
    }
    if (in_array('tempat_lahir', $kolomAnggota)) {
        $fields[] = 'tempat_lahir'; $values[] = $tempatLahir; $types .= 's';
    }
    if (in_array('tanggal_lahir', $kolomAnggota)) {
        $fields[] = 'tanggal_lahir'; $values[] = $tanggalLahir; $types .= 's';
    }
    if (in_array('no_telepon', $kolomAnggota)) {
        $fields[] = 'no_telepon'; $values[] = $noTelepon; $types .= 's';
    }
    if (in_array('alamat', $kolomAnggota)) {
        $fields[] = 'alamat'; $values[] = $alamat; $types .= 's';
    }
    // ── KOLOM BARU: foto_ktp, ikut pola deteksi dinamis yang sama ──
    if (in_array('foto_ktp', $kolomAnggota)) {
        $fields[] = 'foto_ktp'; $values[] = $fotoKtpPath; $types .= 's';
    }
    if (in_array('nama_kelompok', $kolomAnggota) && $namaKelompok !== '') {
        $fields[] = 'nama_kelompok'; $values[] = $namaKelompok; $types .= 's';
    }
    if (in_array('nama_desa', $kolomAnggota) && $namaDesa !== '') {
        $fields[] = 'nama_desa'; $values[] = $namaDesa; $types .= 's';
    }
    if (in_array('status_aktif', $kolomAnggota)) {
        $fields[] = 'status_aktif'; $values[] = 1; $types .= 'i';
    } elseif (in_array('status', $kolomAnggota)) {
        $fields[] = 'status'; $values[] = 'aktif'; $types .= 's';
    }

    $sqlAnggota = "INSERT INTO anggota ("
        . implode(', ', $fields) . ") VALUES ("
        . implode(', ', array_fill(0, count($fields), '?')) . ")";

    $stmtAnggota = $conn->prepare($sqlAnggota);
    $stmtAnggota->bind_param($types, ...$values);
    $stmtAnggota->execute();

    $conn->commit();
    sendResponse(true, 'Pendaftaran berhasil! Silakan login.', [
        'nama' => $nama
    ]);

} catch (Exception $e) {
    $conn->rollback();
    sendResponse(false, 'Pendaftaran gagal: ' . $e->getMessage());
}
$conn->close();