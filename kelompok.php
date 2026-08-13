<?php
require_once 'config.php';
$porsiId = intval($data['porsi_id'] ?? 0);
$status = strtoupper(trim($data['status_bayar'] ?? ''));
$catatan = trim($data['catatan_admin'] ?? '');
$statusValid = [
    'LUNAS',
    'DITOLAK'
];
if ($porsiId <= 0 || !in_array($status, $statusValid)) {
    sendResponse(false, 'Data verifikasi tidak valid');
    exit;
}
$stmt = $conn->prepare(
    "SELECT id, status_bayar
     FROM angsuran_porsi
     WHERE id = ?"
);
$stmt->bind_param("i", $porsiId);
$stmt->execute();
$porsi = $stmt->get_result()->fetch_assoc();
if (!$porsi) {
    sendResponse(false, 'Data porsi tidak ditemukan');
    exit;
}
if ($porsi['status_bayar'] !== 'MENUNGGU') {
    sendResponse(false, 'Porsi belum menunggu verifikasi');
    exit;
}
$stmtUpdate = $conn->prepare(
    "UPDATE angsuran_porsi
     SET status_bayar = ?,
         tanggal_verifikasi = NOW(),
         catatan_admin = ?
     WHERE id = ?"
);
$stmtUpdate->bind_param(
    "ssi",
    $status,
    $catatan,
    $porsiId
);
$stmtUpdate->execute();
if ($stmtUpdate->affected_rows > 0) {
    sendResponse(true, 'Verifikasi bukti setor berhasil', [
        'status_bayar' => $status
    ]);
} else {
    sendResponse(false, 'Gagal memperbarui status verifikasi');
}
?>