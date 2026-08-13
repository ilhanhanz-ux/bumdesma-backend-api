<?php
// ============================================================
//  backfill_kredit.php  — SCRIPT SEKALI PAKAI
//
//  Tujuan: membuat kredit_aktif + jadwal angsuran untuk proposal
//  yang statusnya SUDAH "disetujui" dari SEBELUM fix ini dipasang
//  (jadi tidak pernah otomatis ter-generate).
//
//  Cara pakai:
//  1. Taruh file ini di folder api/ (sebaris dengan proposal.php)
//  2. Buka di browser: http://localhost/bumdesma/api/backfill_kredit.php
//  3. Setelah selesai jalan dan hasilnya sukses, HAPUS file ini
//     dari server (supaya tidak bisa diakses ulang / disalahgunakan)
// ============================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$conn = getConnection();

// --- Salin persis fungsi generateKreditDariProposal dari proposal.php ---
function generateKreditDariProposal($conn, $proposalId) {
    $cek = $conn->prepare("SELECT id FROM kredit_aktif WHERE proposal_id = ? LIMIT 1");
    $cek->bind_param('i', $proposalId);
    $cek->execute();
    if ($cek->get_result()->num_rows > 0) {
        return false; // sudah ada, dilewati
    }

    $stmtP = $conn->prepare(
        "SELECT anggota_id, jumlah_pinjaman, jangka_waktu_bulan, bunga_persen
         FROM pengajuan_proposal WHERE id = ? LIMIT 1"
    );
    $stmtP->bind_param('i', $proposalId);
    $stmtP->execute();
    $proposal = $stmtP->get_result()->fetch_assoc();
    if (!$proposal) return false;

    $anggotaId = (int)$proposal['anggota_id'];
    $pokok     = (float)$proposal['jumlah_pinjaman'];
    $jangka    = (int)$proposal['jangka_waktu_bulan'];
    $bunga     = (float)($proposal['bunga_persen'] ?? 1.5);

    if ($jangka <= 0) $jangka = 1;

    $pokokPerBulan    = round($pokok / $jangka, 2);
    $bungaPerBulan    = round($pokok * ($bunga / 100), 2);
    $angsuranPerBulan = round($pokokPerBulan + $bungaPerBulan, 2);
    $totalKewajiban   = round($angsuranPerBulan * $jangka, 2);

    $tanggalCair = date('Y-m-d');
    $tempoAkhir  = date('Y-m-d', strtotime($tanggalCair . " +$jangka months"));
    $tempNoKredit = 'TEMP-' . uniqid();

    $stmtIns = $conn->prepare(
        "INSERT INTO kredit_aktif
            (proposal_id, anggota_id, no_kredit, pokok_pinjaman, bunga_persen,
             jangka_waktu_bulan, angsuran_per_bulan, total_kewajiban, sisa_pokok,
             tanggal_cair, tanggal_jatuh_tempo, status_kredit)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'aktif')"
    );
    $stmtIns->bind_param('iisddidddss',
        $proposalId, $anggotaId, $tempNoKredit, $pokok, $bunga, $jangka,
        $angsuranPerBulan, $totalKewajiban, $pokok, $tanggalCair, $tempoAkhir
    );

    if (!$stmtIns->execute()) {
        return 'ERROR: ' . $stmtIns->error;
    }

    $kreditId = $conn->insert_id;
    $noKredit = 'KRD-' . date('Ymd') . '-' . str_pad($kreditId, 4, '0', STR_PAD_LEFT);
    $conn->query("UPDATE kredit_aktif SET no_kredit = '$noKredit' WHERE id = $kreditId");

    $stmtA = $conn->prepare(
        "INSERT INTO angsuran
            (kredit_id, anggota_id, no_angsuran, tanggal_jatuh_tempo,
             jumlah_pokok, jumlah_bunga, jumlah_denda, total_bayar, status_bayar)
         VALUES (?, ?, ?, ?, ?, ?, 0.00, ?, 'belum_bayar')"
    );

    $sisaPokokSementara = $pokok;
    for ($i = 1; $i <= $jangka; $i++) {
        $tanggalJatuhTempo = date('Y-m-d', strtotime($tanggalCair . " +$i months"));

        $pokokBulanIni = ($i === $jangka)
            ? round($sisaPokokSementara, 2)
            : $pokokPerBulan;
        $sisaPokokSementara -= $pokokBulanIni;

        $totalBayarBulanIni = round($pokokBulanIni + $bungaPerBulan, 2);

        $stmtA->bind_param('iiisddd',
            $kreditId, $anggotaId, $i, $tanggalJatuhTempo,
            $pokokBulanIni, $bungaPerBulan, $totalBayarBulanIni);
        $stmtA->execute();
    }

    return $noKredit; // sukses
}

// --- Cari semua proposal berstatus 'disetujui' yang belum punya kredit_aktif ---
$kolomCek = $conn->query("SHOW COLUMNS FROM pengajuan_proposal");
$kolomAda = [];
while ($k = $kolomCek->fetch_assoc()) $kolomAda[] = $k['Field'];
$kolomStatus = in_array('status', $kolomAda) ? 'status' : 'status_pengajuan';

$result = $conn->query("
    SELECT pp.id, pp.no_proposal
    FROM pengajuan_proposal pp
    LEFT JOIN kredit_aktif k ON k.proposal_id = pp.id
    WHERE pp.$kolomStatus = 'disetujui' AND k.id IS NULL
");

header('Content-Type: text/plain');
echo "=== Backfill Kredit Aktif ===\n\n";

$jumlahDiproses = 0;
while ($row = $result->fetch_assoc()) {
    $hasil = generateKreditDariProposal($conn, (int)$row['id']);
    if ($hasil === false) {
        echo "[LEWAT] {$row['no_proposal']} — sudah punya kredit_aktif\n";
    } elseif (str_starts_with((string)$hasil, 'ERROR')) {
        echo "[GAGAL] {$row['no_proposal']} — {$hasil}\n";
    } else {
        echo "[SUKSES] {$row['no_proposal']} → kredit {$hasil}\n";
        $jumlahDiproses++;
    }
}

if ($jumlahDiproses === 0) {
    echo "\nTidak ada proposal yang perlu di-backfill (semua sudah punya kredit_aktif, atau belum ada yang disetujui).\n";
} else {
    echo "\nSelesai. $jumlahDiproses proposal berhasil dikonversi jadi kredit aktif.\n";
}

echo "\n⚠️  Setelah ini berhasil, HAPUS file backfill_kredit.php dari server ya.\n";

$conn->close();