<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

setCORSHeaders();

$conn = getConnection();

// ── Auth ─────────────────────────────────────────────
$user = validateToken($conn);
requireRole($user, 'admin');

// ── Parameter ────────────────────────────────────────
$type  = isset($_GET['type'])  ? trim($_GET['type'])  : 'ringkasan';
$bulan = isset($_GET['bulan']) ? trim($_GET['bulan']) : date('Y-m');
$bulan = preg_replace('/[^0-9\-]/', '', $bulan);

// ── Switch ───────────────────────────────────────────
switch ($type) {

    case 'ringkasan':
        // ── BARU: gabung status_aktif DAN status_verifikasi = 'diterima' ──
        // Sebelumnya cuma cek status_aktif, padahal itu di-set 1 buat SEMUA
        // akun baru daftar (termasuk yang masih pending/ditolak) — jadi
        // "Total Anggota" ikut ngitung yang belum resmi jadi anggota.
        $totalAnggota = (int)$conn->query(
            "SELECT COUNT(*) AS c FROM anggota
             WHERE status_aktif = 1 AND status_verifikasi = 'diterima'"
        )->fetch_assoc()['c'];

        $rKredit = $conn->query(
            "SELECT COUNT(*) AS c, COALESCE(SUM(sisa_pokok), 0) AS b
             FROM kredit_aktif WHERE status_kredit = 'aktif'"
        )->fetch_assoc();
        $kreditAktif = (int)$rKredit['c'];
        $danaBeredar = (float)$rKredit['b'];

        $penerimaan = (float)$conn->query(
            "SELECT COALESCE(SUM(total_bayar), 0) AS c
             FROM angsuran
             WHERE status_bayar = 'sudah_bayar'
               AND DATE_FORMAT(tanggal_bayar, '%Y-%m') = '$bulan'"
        )->fetch_assoc()['c'];

        $rTagihan = $conn->query(
            "SELECT COUNT(*) AS c, COALESCE(SUM(total_bayar), 0) AS n
             FROM angsuran
             WHERE status_bayar != 'sudah_bayar'
               AND DATE_FORMAT(tanggal_jatuh_tempo, '%Y-%m') = '$bulan'"
        )->fetch_assoc();

        $cekKolom    = $conn->query("SHOW COLUMNS FROM pengajuan_proposal LIKE 'status'");
        $kolomStatus = $cekKolom->num_rows > 0 ? 'status' : 'status_pengajuan';
        $menunggu    = (int)$conn->query(
            "SELECT COUNT(*) AS c FROM pengajuan_proposal WHERE $kolomStatus = 'menunggu'"
        )->fetch_assoc()['c'];

        $macet = (int)$conn->query(
            "SELECT COUNT(*) AS c FROM kredit_aktif WHERE status_kredit = 'macet'"
        )->fetch_assoc()['c'];

        sendResponse(true, 'Ringkasan dashboard.', [
            'total_anggota_aktif'  => $totalAnggota,
            'kredit_aktif'         => $kreditAktif,
            'dana_beredar'         => $danaBeredar,
            'penerimaan_bulan_ini' => $penerimaan,
            'tagihan_jatuh_tempo'  => (int)$rTagihan['c'],
            'nominal_jatuh_tempo'  => (float)$rTagihan['n'],
            'proposal_menunggu'    => $menunggu,
            'kredit_macet'         => $macet,
            'periode_bulan'        => $bulan,
        ]);
        break;

    case 'kredit_aktif':
        $stmt = $conn->prepare("
            SELECT k.no_kredit, k.pokok_pinjaman, k.sisa_pokok,
                   k.angsuran_per_bulan, k.jangka_waktu_bulan,
                   k.tanggal_cair, k.tanggal_jatuh_tempo,
                   a.nama_lengkap, a.nama_kelompok, a.nama_desa, a.no_telepon,
                   (SELECT COUNT(*) FROM angsuran ang
                    WHERE ang.kredit_id = k.id
                      AND ang.status_bayar = 'sudah_bayar') AS cicilan_terbayar
            FROM kredit_aktif k
            JOIN anggota a ON a.id = k.anggota_id
            WHERE k.status_kredit = 'aktif'
            ORDER BY a.nama_desa, a.nama_lengkap
        ");
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        sendResponse(true, count($rows) . ' kredit aktif.', $rows);
        break;

    case 'tunggakan':
        $stmt = $conn->prepare("
            SELECT ang.no_angsuran, ang.tanggal_jatuh_tempo,
                   ang.total_bayar, ang.status_bayar,
                   k.no_kredit, k.pokok_pinjaman,
                   a.nama_lengkap, a.nama_kelompok, a.nama_desa, a.no_telepon,
                   DATEDIFF(CURDATE(), ang.tanggal_jatuh_tempo) AS hari_tunggak
            FROM angsuran ang
            JOIN kredit_aktif k ON k.id = ang.kredit_id
            JOIN anggota a ON a.id = ang.anggota_id
            WHERE ang.status_bayar IN ('belum_bayar', 'terlambat')
              AND ang.tanggal_jatuh_tempo < CURDATE()
            ORDER BY hari_tunggak DESC
        ");
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        sendResponse(true, count($rows) . ' tunggakan.', [
            'list'          => $rows,
            'total_nominal' => array_sum(array_column($rows, 'total_bayar'))
        ]);
        break;

    case 'bulanan':
        $stmt = $conn->prepare("
            SELECT ang.tanggal_bayar, ang.jumlah_pokok, ang.jumlah_bunga,
                   ang.jumlah_denda, ang.total_bayar,
                   ang.no_angsuran, k.no_kredit,
                   a.nama_lengkap, a.nama_desa
            FROM angsuran ang
            JOIN kredit_aktif k ON k.id = ang.kredit_id
            JOIN anggota a ON a.id = ang.anggota_id
            WHERE ang.status_bayar = 'sudah_bayar'
              AND DATE_FORMAT(ang.tanggal_bayar, '%Y-%m') = ?
            ORDER BY ang.tanggal_bayar ASC
        ");
        $stmt->bind_param('s', $bulan);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        sendResponse(true, 'Rekap bulan ' . $bulan, [
            'periode'          => $bulan,
            'jumlah_transaksi' => count($rows),
            'total_pokok'      => array_sum(array_column($rows, 'jumlah_pokok')),
            'total_bunga'      => array_sum(array_column($rows, 'jumlah_bunga')),
            'total_denda'      => array_sum(array_column($rows, 'jumlah_denda')),
            'total_bayar'      => array_sum(array_column($rows, 'total_bayar')),
            'detail'           => $rows,
        ]);
        break;

    default:
        sendResponse(false, "Tipe laporan '$type' tidak dikenali.", null, 400);
        break;
}

$conn->close();