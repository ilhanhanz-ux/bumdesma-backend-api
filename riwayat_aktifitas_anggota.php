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
requireRole($user, 'anggota');

$limit  = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 40;
$userId = (int)$user['id'];

// Deteksi kolom dinamis (konsisten dengan pola proposal.php & riwayat_aktifitas.php admin)
$kolomProposal = [];
$res = $conn->query("SHOW COLUMNS FROM pengajuan_proposal");
while ($k = $res->fetch_assoc()) $kolomProposal[] = $k['Field'];

$kolomStatus     = in_array('status', $kolomProposal) ? 'status' : 'status_pengajuan';
$kolomTanggalAju = in_array('created_at', $kolomProposal) ? 'created_at' : 'tanggal_pengajuan';
$adaVerifikasi   = in_array('tanggal_diproses', $kolomProposal);

$queries = [];

// 1. Proposal yang diajukan anggota ini
$queries[] = "
    SELECT 'proposal_baru' AS tipe, pp.$kolomTanggalAju AS waktu,
           a.nama_lengkap AS nama, pp.jumlah_pinjaman AS nominal, NULL AS keterangan
    FROM pengajuan_proposal pp
    JOIN anggota a ON a.id = pp.anggota_id
    WHERE pp.$kolomTanggalAju IS NOT NULL AND a.user_id = $userId
";

// 2. Proposal anggota ini diverifikasi (disetujui/ditolak/revisi)
if ($adaVerifikasi) {
    $queries[] = "
        SELECT CONCAT('proposal_', pp.$kolomStatus) AS tipe, pp.tanggal_diproses AS waktu,
               a.nama_lengkap AS nama, pp.jumlah_pinjaman AS nominal, pp.catatan_admin AS keterangan
        FROM pengajuan_proposal pp
        JOIN anggota a ON a.id = pp.anggota_id
        WHERE pp.tanggal_diproses IS NOT NULL AND a.user_id = $userId
    ";
}

// 3. Kredit anggota ini dicairkan
$queries[] = "
    SELECT 'kredit_cair' AS tipe, k.tanggal_cair AS waktu,
           a.nama_lengkap AS nama, k.pokok_pinjaman AS nominal, k.no_kredit AS keterangan
    FROM kredit_aktif k
    JOIN anggota a ON a.id = k.anggota_id
    WHERE k.tanggal_cair IS NOT NULL AND a.user_id = $userId
";

// 4. Pembayaran angsuran anggota ini
$queries[] = "
    SELECT 'pembayaran' AS tipe, ang.tanggal_bayar AS waktu,
           a.nama_lengkap AS nama, ang.total_bayar AS nominal,
           CONCAT('Angsuran ke-', ang.no_angsuran) AS keterangan
    FROM angsuran ang
    JOIN anggota a ON a.id = ang.anggota_id
    WHERE ang.status_bayar = 'sudah_bayar' AND ang.tanggal_bayar IS NOT NULL
      AND a.user_id = $userId
";

// 5. Pengumuman - buat semua anggota, sengaja TIDAK difilter per user
$queries[] = "
    SELECT 'pengumuman' AS tipe, p.created_at AS waktu,
           NULL AS nama, NULL AS nominal, p.judul AS keterangan
    FROM pengumuman p
    WHERE p.created_at IS NOT NULL
";

$sqlUnion = implode(" UNION ALL ", $queries);
$sqlFinal = "SELECT * FROM ($sqlUnion) AS gabungan ORDER BY waktu DESC LIMIT $limit";

$result = $conn->query($sqlFinal);
if ($result === false) {
    sendResponse(false, 'Query error: ' . $conn->error, null, 500);
    exit;
}

$list = [];
while ($row = $result->fetch_assoc()) {
    $list[] = [
        'tipe'       => $row['tipe'],
        'waktu'      => $row['waktu'],
        'nama'       => $row['nama'],
        'nominal'    => $row['nominal'] !== null ? (float)$row['nominal'] : null,
        'keterangan' => $row['keterangan'],
    ];
}

sendResponse(true, count($list) . ' aktivitas.', $list);
$conn->close();