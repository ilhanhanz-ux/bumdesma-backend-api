<?php
// ============================================================
//  api/kredit.php
//
//  GET /api/kredit.php   → ringkasan kredit aktif milik KELOMPOK
//                           anggota yang sedang login (dipakai
//                           kartu "Info Kredit Aktif" di dashboard)
// ============================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

setCORSHeaders();
$conn = getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method tidak diizinkan', null, 405);
    exit;
}

$user = validateToken($conn);

if ($user['role'] !== 'anggota') {
    sendResponse(false, 'Endpoint ini khusus untuk anggota');
    exit;
}

// Ambil data anggota yang sedang login. Diambil nama_kelompok-nya juga
// (BUKAN cuma id), karena kredit kelompok tercatat atas nama ketua --
// anggota biasa perlu dicari lewat kelompoknya, bukan anggota_id sendiri.
$stmtA = $conn->prepare("SELECT id, nama_kelompok FROM anggota WHERE user_id = ? LIMIT 1");
$stmtA->bind_param('i', $user['id']);
$stmtA->execute();
$resA = $stmtA->get_result();

if ($resA->num_rows === 0) {
    sendResponse(false, 'Data anggota tidak ditemukan');
    exit;
}
$anggota = $resA->fetch_assoc();
$namaKelompok = $anggota['nama_kelompok'];

// Ambil kredit aktif terbaru milik KELOMPOK ini -- dicari lewat ketua
// kelompoknya (kredit_aktif.anggota_id selalu = id ketua yang mengajukan),
// bukan anggota_id milik anggota yang sedang login. Dengan begini anggota
// biasa maupun ketua sama-sama lihat kredit kelompok yang sama.
$stmtK = $conn->prepare("
    SELECT k.id, k.no_kredit, k.sisa_pokok, k.jangka_waktu_bulan, k.status_kredit
    FROM kredit_aktif k
    JOIN anggota a ON a.id = k.anggota_id
    WHERE a.nama_kelompok = ? AND a.is_ketua = 1 AND k.status_kredit = 'aktif'
    ORDER BY k.id DESC LIMIT 1
");
$stmtK->bind_param('s', $namaKelompok);
$stmtK->execute();
$kredit = $stmtK->get_result()->fetch_assoc();

if (!$kredit) {
    sendResponse(true, 'Tidak ada kredit aktif', [
        'ada_kredit_aktif' => false,
    ]);
    exit;
}

// Cari angsuran berikutnya yang belum lunas
$stmtN = $conn->prepare("
    SELECT no_angsuran, tanggal_jatuh_tempo, total_bayar
    FROM angsuran
    WHERE kredit_id = ? AND status_bayar != 'sudah_bayar'
    ORDER BY no_angsuran ASC LIMIT 1
");
$stmtN->bind_param('i', $kredit['id']);
$stmtN->execute();
$next = $stmtN->get_result()->fetch_assoc();

// Cek apakah angsuran berikutnya jatuh tempo di bulan berjalan
$tagihanBulanIni = null;
if ($next) {
    $bulanTempo = date('Y-m', strtotime($next['tanggal_jatuh_tempo']));
    $bulanIni   = date('Y-m');
    if ($bulanTempo === $bulanIni) {
        $tagihanBulanIni = (float)$next['total_bayar'];
    }
}
// Sudah pernah diisi Alokasi Pinjaman ke Anggota apa belum, buat tombol
// banner di dashboard ketua. Anggota biasa tetap dapat field ini juga
// (harmless, Android cuma pakai kalau session.isKetua()).
$stmtAlokasi = $conn->prepare(
    "SELECT COUNT(*) AS jml FROM alokasi_pinjaman_anggota WHERE kredit_id = ?"
);
$stmtAlokasi->bind_param('i', $kredit['id']);
$stmtAlokasi->execute();
$alokasiSudahDiisi = (int)($stmtAlokasi->get_result()->fetch_assoc()['jml'] ?? 0) > 0;

// BARU: terkunci kalau sudah ada setoran/verifikasi berjalan untuk kredit
// ini -- alokasi tidak boleh diubah lagi (aturan sama persis dengan
// alokasi_pinjaman.php). Dashboard pakai ini buat sembunyikan tombol
// total, beda dengan alokasi_sudah_diisi yang cuma ganti teks tombol.
$stmtTerkunci = $conn->prepare("
    SELECT COUNT(*) AS jml FROM angsuran_porsi ap
    JOIN angsuran an ON an.id = ap.angsuran_id
    WHERE an.kredit_id = ? AND ap.status_bayar != 'belum_bayar'
");
$stmtTerkunci->bind_param('i', $kredit['id']);
$stmtTerkunci->execute();
$alokasiTerkunci = (int)($stmtTerkunci->get_result()->fetch_assoc()['jml'] ?? 0) > 0;

sendResponse(true, 'OK', [
    'ada_kredit_aktif'     => true,
    'kredit_id'            => (int)$kredit['id'],
    'no_kredit'            => $kredit['no_kredit'],
    'sisa_pokok'           => (float)$kredit['sisa_pokok'],
    'angsuran_ke'          => $next ? (int)$next['no_angsuran'] : null,
    'jangka_waktu_bulan'   => (int)$kredit['jangka_waktu_bulan'],
    'tanggal_jatuh_tempo'  => $next ? $next['tanggal_jatuh_tempo'] : null,
    'tagihan_bulan_ini'    => $tagihanBulanIni,
    'alokasi_sudah_diisi'  => $alokasiSudahDiisi,
    'alokasi_terkunci'     => $alokasiTerkunci,
]);


$conn->close();