<?php
require_once '../config.php';
require_once '../auth.php';

$anggota = verifyToken($_POST['token'] ?? '');
if (!$anggota) {
    echo json_encode(['status' => 'error', 'message' => 'Token tidak valid']);
    exit;
}

$jumlah    = intval($_POST['jumlah_pinjaman']);
$tenor     = $_POST['tenor'];
$keperluan = trim($_POST['keperluan']);

// Upload PDF
$uploadDir = '../uploads/proposal/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$file = $_FILES['dokumen_proposal'];
$ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if ($ext !== 'pdf') {
    echo json_encode(['status' => 'error', 'message' => 'Hanya PDF yang diizinkan']);
    exit;
}

if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['status' => 'error', 'message' => 'File melebihi 5MB']);
    exit;
}

$namaFile = 'proposal_' . $anggota['id'] . '_' . time() . '.pdf';
$targetPath = $uploadDir . $namaFile;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan file']);
    exit;
}

// Simpan ke database
$stmt = $conn->prepare("
    INSERT INTO pengajuan_proposal 
        (anggota_id, jumlah_pinjaman, tenor, keperluan, dokumen_pdf, status, created_at)
    VALUES (?, ?, ?, ?, ?, 'menunggu', NOW())
");
$stmt->bind_param("iisss",
    $anggota['id'], $jumlah, $tenor, $keperluan, $namaFile);

if ($stmt->execute()) {
    echo json_encode([
        'status'  => 'success',
        'message' => 'Proposal berhasil dikirim, menunggu verifikasi admin'
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan data']);
}
$stmt->close();
$conn->close();