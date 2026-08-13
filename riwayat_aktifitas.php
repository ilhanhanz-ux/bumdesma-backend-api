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
requireRole($user, 'admin');

$limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 40;

// Deteksi kolom dinamis (konsisten dengan pola proposal.php)
$kolomProposal = [];
$res = $conn->query("SHOW COLUMNS FROM pengajuan_proposal");
while ($k = $res->fetch_assoc()) $kolomProposal[] = $k['Field'];

$kolomStatus     = in_array('status', $kolomProposal) ? 'status' : 'status_pengajuan';
$kolomTanggalAju = in_array('created_at', $kolomProposal) ? 'created_at' : 'tanggal_pengajuan';
$adaVerifikasi   = in_array('tanggal_diproses', $kolomProposal);

$queries = [];

// 1. Proposal baru diajukan
$queries[] = "
    SELECT 'proposal_baru' AS tipe, pp.$kolomTanggalAju AS waktu,
           a.nama_lengkap AS nama, pp.jumlah_pinjaman AS nominal, NULL AS keterangan
    FROM pengajuan_proposal pp
    JOIN anggota a ON a.id = pp.anggota_id
    WHERE pp.$kolomTanggalAju IS NOT NULL
";

// 2. Proposal diverifikasi (disetujui/ditolak/revisi) — hanya kalau kolomnya tersedia
if ($adaVerifikasi) {
    $queries[] = "
        SELECT CONCAT('proposal_', pp.$kolomStatus) AS tipe, pp.tanggal_diproses AS waktu,
               a.nama_lengkap AS nama, pp.jumlah_pinjaman AS nominal, pp.catatan_admin AS keterangan
        FROM pengajuan_proposal pp
        JOIN anggota a ON a.id = pp.anggota_id
        WHERE pp.tanggal_diproses IS NOT NULL
    ";
}

// 3. Kredit dicairkan
$queries[] = "
    SELECT 'kredit_cair' AS tipe, k.tanggal_cair AS waktu,
           a.nama_lengkap AS nama, k.pokok_pinjaman AS nominal, k.no_kredit AS keterangan
    FROM kredit_aktif k
    JOIN anggota a ON a.id = k.anggota_id
    WHERE k.tanggal_cair IS NOT NULL
";

// 4. Pembayaran diterima
$queries[] = "
    SELECT 'pembayaran' AS tipe, ang.tanggal_bayar AS waktu,
           a.nama_lengkap AS nama, ang.total_bayar AS nominal,
           CONCAT('Angsuran ke-', ang.no_angsuran) AS keterangan
    FROM angsuran ang
    JOIN anggota a ON a.id = ang.anggota_id
    WHERE ang.status_bayar = 'sudah_bayar' AND ang.tanggal_bayar IS NOT NULL
";

// 5. Pengumuman diterbitkan
$queries[] = "
    SELECT 'pengumuman' AS tipe, p.created_at AS waktu,
           NULL AS nama, NULL AS nominal, p.judul AS keterangan
    FROM pengumuman p
    WHERE p.created_at IS NOT NULL
";

// 6. BARU: Verifikasi anggota (diterima/ditolak)
// Dibaca dari log audit generik riwayat_aktivitas (diisi catatAktivitas() di verifikasi_anggota.php).
// detail formatnya "Anggota ID: X - {nama}" (+ " - {keterangan}" kalau ada) — sisa.teks
// motong prefix "Anggota ID: X - " itu dulu, baru nama & keterangan diambil dari sisanya.
// CATATAN: entri lama (sebelum nama ikut disimpan) bisa salah baca "nama"-nya sebagai
// keterangan lama, karena formatnya beda. Cuma mempengaruhi data historis, bukan yang baru.
$queries[] = "
    SELECT
        CASE WHEN ra.aksi = 'Verifikasi diterima' THEN 'anggota_diterima'
             WHEN ra.aksi = 'Verifikasi ditolak'  THEN 'anggota_ditolak'
             ELSE 'anggota_lainnya' END AS tipe,
        ra.created_at AS waktu,
        SUBSTRING_INDEX(sisa.teks, ' - ', 1) AS nama,
        NULL AS nominal,
        NULLIF(
            SUBSTRING(sisa.teks, LENGTH(SUBSTRING_INDEX(sisa.teks, ' - ', 1)) + 4),
            ''
        ) AS keterangan
    FROM riwayat_aktivitas ra
    JOIN (
        SELECT id,
               SUBSTRING(detail, LENGTH(SUBSTRING_INDEX(detail, ' - ', 1)) + 4) AS teks
        FROM riwayat_aktivitas
        WHERE modul = 'Verifikasi Anggota'
    ) sisa ON sisa.id = ra.id
    WHERE ra.modul = 'Verifikasi Anggota'
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