<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

setCORSHeaders();
$conn = getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method tidak diizinkan', null, 405);
    exit;
}

$user = validateToken($conn);

if ($user['role'] !== 'admin') {
    sendResponse(false, 'Hanya admin yang bisa mencatat setoran kolektif', null, 403);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$namaKelompok  = isset($data['nama_kelompok']) ? trim($data['nama_kelompok']) : '';
$namaPenyetor  = isset($data['nama_penyetor']) ? trim($data['nama_penyetor']) : '';
$tanggalSetor  = isset($data['tanggal_setor']) ? trim($data['tanggal_setor']) : date('Y-m-d');
$keterangan    = isset($data['keterangan']) && trim($data['keterangan']) !== '' ? trim($data['keterangan']) : null;
$buktiSetorB64 = $data['bukti_setor'] ?? null;
$angsuranIds   = isset($data['angsuran_ids']) && is_array($data['angsuran_ids']) ? $data['angsuran_ids'] : [];

if ($namaKelompok === '') {
    sendResponse(false, 'nama_kelompok wajib diisi', null, 400);
    exit;
}
if ($namaPenyetor === '') {
    sendResponse(false, 'Nama ketua/penyetor wajib diisi', null, 400);
    exit;
}
if (empty($buktiSetorB64)) {
    sendResponse(false, 'Foto bukti setor wajib diisi', null, 400);
    exit;
}
if (empty($angsuranIds)) {
    sendResponse(false, 'Pilih minimal 1 angsuran yang akan disetorkan', null, 400);
    exit;
}

$angsuranIds = array_values(array_unique(array_map('intval', $angsuranIds)));
$angsuranIds = array_values(array_filter($angsuranIds, fn($id) => $id > 0));

if (empty($angsuranIds)) {
    sendResponse(false, 'Daftar angsuran tidak valid', null, 400);
    exit;
}

$placeholders = implode(',', array_fill(0, count($angsuranIds), '?'));
$types = str_repeat('i', count($angsuranIds));

// ── Guard baru: angsuran yang sudah dipecah porsi tidak boleh dilunasi
//    lewat jalur kolektif -- harus lewat verifikasi porsi masing-masing,
//    biar baris angsuran_porsi gak nyangkut belum_bayar selamanya ──
$stmtPorsiCek = $conn->prepare(
    "SELECT DISTINCT angsuran_id FROM angsuran_porsi WHERE angsuran_id IN ($placeholders)"
);
$stmtPorsiCek->bind_param($types, ...$angsuranIds);
$stmtPorsiCek->execute();
$resPorsiCek = $stmtPorsiCek->get_result();
if ($resPorsiCek->num_rows > 0) {
    $rowP = $resPorsiCek->fetch_assoc();
    sendResponse(false, "Angsuran ID {$rowP['angsuran_id']} sudah menggunakan sistem porsi, gunakan menu verifikasi porsi untuk melunasinya.", null, 409);
    exit;
}

// ── Validasi: semua angsuran ada, belum lunas, dan benar milik kelompok ini ──
$stmtCek = $conn->prepare("
    SELECT ang.id, ang.total_bayar, ang.status_bayar, a.nama_kelompok
    FROM angsuran ang
    JOIN anggota a ON a.id = ang.anggota_id
    WHERE ang.id IN ($placeholders)
");
$stmtCek->bind_param($types, ...$angsuranIds);
$stmtCek->execute();
$resCek = $stmtCek->get_result();

$ditemukan = [];
$totalNominal = 0;

while ($row = $resCek->fetch_assoc()) {
    if ($row['status_bayar'] === 'sudah_bayar') {
        sendResponse(false, "Angsuran ID {$row['id']} sudah tercatat lunas sebelumnya", null, 400);
        exit;
    }
    if ($row['nama_kelompok'] !== $namaKelompok) {
        sendResponse(false, "Angsuran ID {$row['id']} bukan milik anggota kelompok $namaKelompok", null, 400);
        exit;
    }
    $ditemukan[$row['id']] = (float)$row['total_bayar'];
    $totalNominal += (float)$row['total_bayar'];
}

if (count($ditemukan) !== count($angsuranIds)) {
    sendResponse(false, 'Sebagian angsuran tidak ditemukan', null, 404);
    exit;
}

if (strpos($buktiSetorB64, ',') !== false) {
    $buktiSetorB64 = explode(',', $buktiSetorB64)[1];
}
$imageData = base64_decode($buktiSetorB64);
if ($imageData === false) {
    sendResponse(false, 'Format foto tidak valid', null, 400);
    exit;
}

$uploadDir = '../uploads/bukti_setor_kolektif/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
$fileName = 'setoran_' . time() . '_' . uniqid() . '.jpg';
$filePath = $uploadDir . $fileName;

if (!file_put_contents($filePath, $imageData)) {
    sendResponse(false, 'Gagal menyimpan foto bukti setor', null, 500);
    exit;
}
$relativePath = 'uploads/bukti_setor_kolektif/' . $fileName;

$conn->begin_transaction();
try {
    $stmtInsert = $conn->prepare("
        INSERT INTO setoran_kolektif
            (nama_kelompok, nama_penyetor, tanggal_setor, total_nominal, bukti_setor, keterangan, admin_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtInsert->bind_param(
        'sssdssi',
        $namaKelompok, $namaPenyetor, $tanggalSetor, $totalNominal,
        $relativePath, $keterangan, $user['id']
    );
    $stmtInsert->execute();
    $setoranId = $stmtInsert->insert_id;

    $stmtDetail = $conn->prepare("
        INSERT INTO setoran_kolektif_detail (setoran_kolektif_id, angsuran_id, jumlah_dialokasikan)
        VALUES (?, ?, ?)
    ");

    foreach ($angsuranIds as $id) {
        $jumlah = $ditemukan[$id];
        $stmtDetail->bind_param('iid', $setoranId, $id, $jumlah);
        $stmtDetail->execute();

        finalisasiPembayaranLunas($conn, $id, $user['id'], $tanggalSetor, $relativePath, $keterangan);
    }

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    sendResponse(false, 'Gagal menyimpan setoran kolektif: ' . $e->getMessage(), null, 500);
    exit;
}

sendResponse(true, 'Setoran kolektif berhasil dicatat', [
    'setoran_kolektif_id' => $setoranId,
    'jumlah_angsuran'     => count($angsuranIds),
    'total_nominal'       => $totalNominal,
    'bukti_setor'         => $relativePath,
]);
$conn->close();