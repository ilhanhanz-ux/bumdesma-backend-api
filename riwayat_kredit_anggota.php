<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

setCORSHeaders();
$conn = getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method tidak diizinkan', null, 405);
    exit;
}

$user = validateToken($conn);

if ($user['role'] !== 'admin') {
    sendResponse(false, 'Hanya admin yang boleh mengakses data ini', null, 403);
    exit;
}

$anggotaId = isset($_GET['anggota_id']) ? intval($_GET['anggota_id']) : 0;
if ($anggotaId <= 0) {
    sendResponse(false, 'ID anggota tidak valid', null, 400);
    exit;
}

$stmt = $conn->prepare("
    SELECT id, kredit_id, no_angsuran, tanggal_jatuh_tempo, tanggal_bayar,
           jumlah_pokok, jumlah_bunga, jumlah_denda, total_bayar,
           status_bayar, keterangan
    FROM angsuran
    WHERE anggota_id = ?
    ORDER BY tanggal_jatuh_tempo DESC
");
$stmt->bind_param('i', $anggotaId);
$stmt->execute();
$result = $stmt->get_result();

$riwayat = [];
$totalJatuhTempo = 0;
$totalTepatWaktu = 0;
$today = date('Y-m-d');

while ($row = $result->fetch_assoc()) {
    $jatuhTempo = $row['tanggal_jatuh_tempo'];
    $tglBayar   = $row['tanggal_bayar'];
    $sudahJatuhTempo = $jatuhTempo <= $today;

    // Status per baris, dihitung dari tanggal aktual — bukan sekadar baca status_bayar
    if ($tglBayar !== null) {
        $statusBaris = ($tglBayar <= $jatuhTempo) ? 'tepat_waktu' : 'terlambat';
    } else {
        $statusBaris = $sudahJatuhTempo ? 'menunggak' : 'belum_jatuh_tempo';
    }

    // Cuma angsuran yang sudah jatuh tempo yang masuk hitungan skor
    if ($sudahJatuhTempo) {
        $totalJatuhTempo++;
        if ($statusBaris === 'tepat_waktu') {
            $totalTepatWaktu++;
        }
    }

    $riwayat[] = [
        'id'                => (int)$row['id'],
        'noAngsuran'        => (int)$row['no_angsuran'],
        'tanggalJatuhTempo' => $jatuhTempo,
        'tanggalBayar'      => $tglBayar,
        'jumlahPokok'       => (float)$row['jumlah_pokok'],
        'jumlahBunga'       => (float)$row['jumlah_bunga'],
        'jumlahDenda'       => (float)$row['jumlah_denda'],
        'totalBayar'        => (float)$row['total_bayar'],
        'statusBayar'       => $row['status_bayar'],
        'statusBaris'       => $statusBaris,
        'keterangan'        => $row['keterangan'],
    ];
}

$skorKepatuhan = $totalJatuhTempo > 0
    ? round(($totalTepatWaktu / $totalJatuhTempo) * 100, 1)
    : null;

$statusKredit = 'belum_ada_riwayat';
if ($skorKepatuhan !== null) {
    if ($skorKepatuhan >= 80) {
        $statusKredit = 'lancar';
    } elseif ($skorKepatuhan >= 60) {
        $statusKredit = 'perlu_perhatian';
    } else {
        $statusKredit = 'macet';
    }
}

$data = [
    'skorKepatuhan'   => $skorKepatuhan,
    'statusKredit'    => $statusKredit,
    'totalJatuhTempo' => $totalJatuhTempo,
    'totalTepatWaktu' => $totalTepatWaktu,
    'riwayat'         => $riwayat,
];

sendResponse(true, 'OK', $data);
$conn->close();