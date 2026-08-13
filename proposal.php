<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

setCORSHeaders();

$conn = getConnection();

// ── BARU: Riwayat transaksi (jumlah kredit per kategori status) untuk SETIAP
// anggota dalam satu kelompok yang sama — dipakai admin buat menilai rekam
// jejak keseluruhan kelompok sebelum menyetujui proposal, bukan cuma rekam
// jejak si pengaju/ketua saja.
// Kategori mengikuti enum kredit_aktif.status_kredit:
//   - lunas -> kredit yang sudah lunas
//   - aktif -> kredit yang masih berjalan normal (belum lunas)
//   - macet -> gabungan status 'macet' dan 'dalam_perhatian'
function hitungRiwayatKelompok($conn, $namaKelompok, $anggotaPengajuId) {
    $stmt = $conn->prepare(
        "SELECT a.id AS anggota_id, a.nama_lengkap, a.is_ketua,
                SUM(CASE WHEN ka.status_kredit = 'lunas' THEN 1 ELSE 0 END) AS jumlah_lunas,
                SUM(CASE WHEN ka.status_kredit = 'aktif' THEN 1 ELSE 0 END) AS jumlah_aktif,
                SUM(CASE WHEN ka.status_kredit IN ('macet','dalam_perhatian') THEN 1 ELSE 0 END) AS jumlah_macet
         FROM anggota a
         LEFT JOIN kredit_aktif ka ON ka.anggota_id = a.id
         WHERE a.nama_kelompok = ?
         GROUP BY a.id, a.nama_lengkap, a.is_ketua
         ORDER BY a.nama_lengkap ASC"
    );
    $stmt->bind_param('s', $namaKelompok);
    $stmt->execute();
    $result = $stmt->get_result();

    $daftar = [];
    while ($row = $result->fetch_assoc()) {
        $daftar[] = [
            'anggota_id'   => (int)$row['anggota_id'],
            'nama_lengkap' => $row['nama_lengkap'],
            'is_ketua'     => (int)$row['is_ketua'] === 1,
            'is_pengaju'   => (int)$row['anggota_id'] === (int)$anggotaPengajuId,
            'jumlah_lunas' => (int)$row['jumlah_lunas'],
            'jumlah_aktif' => (int)$row['jumlah_aktif'],
            'jumlah_macet' => (int)$row['jumlah_macet'],
        ];
    }
    return $daftar;
}

// ── Helper: Konversi proposal yang disetujui menjadi kredit aktif + jadwal angsuran ──
// Dipanggil otomatis saat admin mengubah status_pengajuan menjadi 'disetujui'.
// Idempotent: kalau kredit_aktif untuk proposal ini sudah pernah dibuat, fungsi ini
// tidak akan membuat duplikat (dicek lewat kolom proposal_id yang UNIQUE).
function generateKreditDariProposal($conn, $proposalId) {
    $cek = $conn->prepare("SELECT id FROM kredit_aktif WHERE proposal_id = ? LIMIT 1");
    $cek->bind_param('i', $proposalId);
    $cek->execute();
    if ($cek->get_result()->num_rows > 0) {
        return;
    }

    $stmtP = $conn->prepare(
        "SELECT anggota_id, jumlah_pinjaman, jumlah_disetujui, jangka_waktu_bulan, bunga_persen
         FROM pengajuan_proposal WHERE id = ? LIMIT 1"
    );
    $stmtP->bind_param('i', $proposalId);
    $stmtP->execute();
    $proposal = $stmtP->get_result()->fetch_assoc();
    if (!$proposal) return;

    $anggotaId = (int)$proposal['anggota_id']; // ID ketua
    $pokok     = (float)($proposal['jumlah_disetujui'] ?? $proposal['jumlah_pinjaman']);
    $jangka    = (int)$proposal['jangka_waktu_bulan'];
    $bunga     = (float)($proposal['bunga_persen'] ?? 1.5);

    if ($jangka <= 0) $jangka = 1;

    // ── BARU: ambil nama_kelompok dari ketua, lalu hitung rasio porsi
    // tiap anggota aktif di kelompok itu, proporsional ke limit_pinjaman.
    // Rasio ini dihitung SEKALI di sini dan dibekukan untuk seluruh masa
    // pinjaman -- tidak berubah walau limit_pinjaman anggota naik nanti
    // lewat prosesKenaikanLimit(). ──
    $stmtKlp = $conn->prepare("SELECT nama_kelompok FROM anggota WHERE id = ? LIMIT 1");
    $stmtKlp->bind_param('i', $anggotaId);
    $stmtKlp->execute();
    $namaKelompok = $stmtKlp->get_result()->fetch_assoc()['nama_kelompok'] ?? null;

    $rasioPorsi = []; // [anggota_id => rasio 0.0 - 1.0]
    if ($namaKelompok) {
        $stmtAnggotaKlp = $conn->prepare(
            "SELECT id, limit_pinjaman FROM anggota
             WHERE nama_kelompok = ? AND status_aktif = 1"
        );
        $stmtAnggotaKlp->bind_param('s', $namaKelompok);
        $stmtAnggotaKlp->execute();
        $resAnggotaKlp = $stmtAnggotaKlp->get_result();

        $daftarAnggotaKlp = [];
        $totalLimitKlp    = 0.0;
        while ($row = $resAnggotaKlp->fetch_assoc()) {
            $lim = (float)$row['limit_pinjaman'];
            $daftarAnggotaKlp[(int)$row['id']] = $lim;
            $totalLimitKlp += $lim;
        }

        if ($totalLimitKlp > 0) {
            foreach ($daftarAnggotaKlp as $idAnggota => $lim) {
                $rasioPorsi[$idAnggota] = $lim / $totalLimitKlp;
            }
        } elseif (count($daftarAnggotaKlp) > 0) {
            // Fallback: kalau semua limit_pinjaman kebetulan 0, bagi rata
            $rasioSama = 1 / count($daftarAnggotaKlp);
            foreach ($daftarAnggotaKlp as $idAnggota => $lim) {
                $rasioPorsi[$idAnggota] = $rasioSama;
            }
        }
    }
    // ── akhir bagian BARU ──

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
        error_log('Gagal buat kredit_aktif: ' . $stmtIns->error);
        return;
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

    // ── BARU: statement buat insert porsi tiap anggota per baris angsuran ──
    $stmtPorsi = $conn->prepare(
        "INSERT INTO angsuran_porsi (angsuran_id, anggota_id, jumlah_porsi, status_bayar)
         VALUES (?, ?, ?, 'belum_bayar')"
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

        // ── BARU: begitu 1 baris angsuran dibuat, langsung pecah jadi
        // porsi tiap anggota kelompok, proporsional ke limit_pinjaman ──
        $angsuranId = $conn->insert_id;
        foreach ($rasioPorsi as $idAnggotaPorsi => $rasio) {
            $jumlahPorsi = round($rasio * $totalBayarBulanIni, 2);
            $stmtPorsi->bind_param('iid', $angsuranId, $idAnggotaPorsi, $jumlahPorsi);
            $stmtPorsi->execute();
        }
    }
}

// ── GET: Ambil list atau detail proposal ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = validateToken($conn);

    // ── FIX: proposal selalu diajukan atas nama KETUA (pp.anggota_id = id
    // ketua), jadi anggota biasa (bukan pengaju) tidak akan pernah cocok
    // kalau difilter persis ke user_id-nya sendiri. Di sini kita ambil dulu
    // nama_kelompok milik user yang login (baik dia ketua atau bukan),
    // supaya proposal yang ditampilkan adalah proposal SATU KELOMPOK, bukan
    // cuma proposal yang pengajunya persis user ini.
    $namaKelompokUser = null;
    if ($user['role'] === 'anggota') {
        $stmtKlp = $conn->prepare("SELECT nama_kelompok FROM anggota WHERE user_id = ? LIMIT 1");
        $stmtKlp->bind_param('i', $user['id']);
        $stmtKlp->execute();
        $rowKlp = $stmtKlp->get_result()->fetch_assoc();
        $namaKelompokUser = $rowKlp['nama_kelompok'] ?? null;
    }

    if (isset($_GET['id'])) {
        $id = intval($_GET['id']);

        $sql = "SELECT pp.*,
                    a.nama_lengkap AS nama_pengaju,
                    a.no_telepon,
                    a.nama_kelompok,
                    a.nama_desa,
                    a.limit_pinjaman AS anggota_limit_pinjaman,
                    a.is_ketua,
                    a.jumlah_kredit_lunas
                FROM pengajuan_proposal pp
                JOIN anggota a ON a.id = pp.anggota_id
                WHERE pp.id = ?";

        if ($user['role'] === 'anggota') {
            $sql .= " AND a.nama_kelompok = ?";
        }

        $stmt = $conn->prepare($sql);
        if ($user['role'] === 'anggota') {
            $stmt->bind_param('is', $id, $namaKelompokUser);
        } else {
            $stmt->bind_param('i', $id);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            sendResponse(false, 'Proposal tidak ditemukan');
        }

        $data = $result->fetch_assoc();

        $anggotaUntukLimit = [
            'id'                  => $data['anggota_id'],
            'is_ketua'            => $data['is_ketua'],
            'nama_kelompok'       => $data['nama_kelompok'],
            'limit_pinjaman'      => $data['anggota_limit_pinjaman'],
            'jumlah_kredit_lunas' => $data['jumlah_kredit_lunas'],
        ];
        $infoLimit = hitungLimitPengajuan($conn, $anggotaUntukLimit);

        $proposal = [
            'id'                     => $data['id'],
            'no_proposal'            => $data['no_proposal'] ?? 'PRO-' . str_pad($data['id'], 4, '0', STR_PAD_LEFT),
            'nama_pengaju'           => $data['nama_pengaju']  ?? '-',
            'nama_kelompok'          => $data['nama_kelompok'] ?? '-',
            'nama_desa'              => $data['nama_desa']     ?? '-',
            'no_telepon'             => $data['no_telepon']    ?? '-',
            'jumlah_pinjaman'        => floatval($data['jumlah_pinjaman'] ?? 0),
            'jumlah_disetujui'       => isset($data['jumlah_disetujui']) ? floatval($data['jumlah_disetujui']) : null,
            'anggota_limit_pinjaman' => floatval($data['anggota_limit_pinjaman'] ?? 0),
            'is_ketua'               => (int)($data['is_ketua'] ?? 0) === 1,
            'limit_berlaku'          => $infoLimit['limit_final'],
            'riwayat_bagus'          => $infoLimit['riwayat_bagus'],
            'jangka_waktu_bulan'     => intval($data['jangka_waktu'] ?? $data['jangka_waktu_bulan'] ?? 0),
            'bunga_persen'           => floatval($data['bunga_persen'] ?? 1.5),
            'tujuan_pinjaman'        => $data['keperluan'] ?? $data['tujuan_pinjaman'] ?? '-',
            'deskripsi_usaha'        => $data['deskripsi_usaha'] ?? '-',
            'status_pengajuan'       => $data['status'] ?? $data['status_pengajuan'] ?? 'menunggu',
            'catatan_admin'          => $data['catatan_admin'] ?? '',
            'tanggal_pengajuan'      => $data['created_at']   ?? $data['tanggal_pengajuan'] ?? '-',
            'dok_proposal'           => $data['dok_proposal'] ?? $data['dokumen_proposal'] ?? null,
            'dok_ktp'                => $data['dok_ktp']      ?? null,
            'dok_jaminan'            => $data['dok_jaminan']  ?? null,
        ];

        if ($user['role'] === 'admin') {
            $proposal['dana_tersedia'] = hitungDanaTersedia($conn);
            // ── BARU: riwayat transaksi seluruh anggota kelompok (bukan cuma pengaju) ──
            $proposal['riwayat_kelompok'] = hitungRiwayatKelompok(
                $conn, $data['nama_kelompok'], $data['anggota_id']
            );
        }

        sendResponse(true, 'OK', $proposal);
        exit;
    }

    $sql = "SELECT pp.*,
                a.nama_lengkap AS nama_pengaju,
                a.no_telepon,
                a.nama_kelompok,
                a.nama_desa
            FROM pengajuan_proposal pp
            JOIN anggota a ON a.id = pp.anggota_id";

    if ($user['role'] === 'anggota') {
        $sql .= " WHERE a.nama_kelompok = ?";
    }

    $sql .= " ORDER BY pp.id DESC";

    if ($user['role'] === 'anggota') {
        $stmtList = $conn->prepare($sql);
        $stmtList->bind_param('s', $namaKelompokUser);
        $stmtList->execute();
        $result = $stmtList->get_result();
    } else {
        $result = $conn->query($sql);
    }

    if ($result === false) {
        sendResponse(false, 'Query error: ' . $conn->error);
        exit;
    }

    $list = [];

    while ($row = $result->fetch_assoc()) {
        $statusKolom = $row['status'] ?? $row['status_pengajuan'] ?? 'menunggu';
        $list[] = [
            'id'                 => $row['id'],
            'no_proposal'        => $row['no_proposal'] ?? 'PRO-' . str_pad($row['id'], 4, '0', STR_PAD_LEFT),
            'nama_pengaju'       => $row['nama_pengaju']  ?? '-',
            'nama_kelompok'      => $row['nama_kelompok'] ?? '-',
            'nama_desa'          => $row['nama_desa']     ?? '-',
            'jumlah_pinjaman'    => floatval($row['jumlah_pinjaman'] ?? 0),
            'jumlah_disetujui'   => isset($row['jumlah_disetujui']) ? floatval($row['jumlah_disetujui']) : null,
            'jangka_waktu_bulan' => intval($row['jangka_waktu'] ?? $row['jangka_waktu_bulan'] ?? 0),
            'bunga_persen'       => floatval($row['bunga_persen'] ?? 1.5),
            'tujuan_pinjaman'    => $row['keperluan'] ?? $row['tujuan_pinjaman'] ?? '-',
            'status_pengajuan'   => $statusKolom,
            'catatan_admin'      => $row['catatan_admin'] ?? '',
            'tanggal_pengajuan'  => $row['created_at']   ?? $row['tanggal_pengajuan'] ?? '-',
        ];
    }

    sendResponse(true, 'OK', $list);
    exit;
}

// ── POST: Ajukan proposal baru ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $user = validateToken($conn);

    if ($user['role'] !== 'anggota') {
        sendResponse(false, 'Hanya anggota yang bisa mengajukan proposal');
    }

    $jumlah    = floatval($_POST['jumlah_pinjaman'] ?? 0);
    $tenorStr  = trim($_POST['tenor']               ?? '');
    $keperluan = trim($_POST['keperluan']            ?? '');
    $deskripsi = trim($_POST['deskripsi_usaha']      ?? '-');

    $jangka = intval(preg_replace('/[^0-9]/', '', $tenorStr));

    if ($jumlah <= 0) {
        sendResponse(false, 'Jumlah pinjaman tidak valid');
        exit;
    }
    if ($jangka <= 0) {
        sendResponse(false, 'Tenor/jangka waktu tidak valid: ' . $tenorStr);
        exit;
    }
    if (empty($keperluan)) {
        sendResponse(false, 'Keperluan pinjaman wajib diisi');
        exit;
    }

    $stmtA = $conn->prepare(
        "SELECT id, is_ketua, nama_kelompok, limit_pinjaman, jumlah_kredit_lunas
         FROM anggota WHERE user_id = ? LIMIT 1"
    );
    $stmtA->bind_param('i', $user['id']);
    $stmtA->execute();
    $resA = $stmtA->get_result();

    if ($resA->num_rows === 0) {
        sendResponse(false, 'Data anggota tidak ditemukan untuk user ini');
        exit;
    }
    $anggota     = $resA->fetch_assoc();
    $anggotaId   = $anggota['id'];
    $bungaPersen = 1.5;

        // ── BARU: hanya ketua kelompok yang boleh mengajukan proposal ──
    if ((int)($anggota['is_ketua'] ?? 0) !== 1) {
        sendResponse(false, 'Hanya ketua kelompok yang dapat mengajukan proposal pinjaman. Hubungi ketua kelompok Anda untuk mengajukan pinjaman baru.', null, 403);
        exit;
    }

    $hasilLimit    = hitungLimitPengajuan($conn, $anggota);
    $limitPinjaman = $hasilLimit['limit_final'];

    if ($jumlah > $limitPinjaman) {
        $labelLimit = (int)$anggota['is_ketua'] === 1
            ? 'Jumlah pinjaman melebihi limit kelompok kamu saat ini (Rp '
            : 'Jumlah pinjaman melebihi limit kamu saat ini (Rp ';
        sendResponse(false, $labelLimit .
            number_format($limitPinjaman, 0, ',', '.') . ')', null, 422);
        exit;
    }

    $kolomInfo = $conn->query("SHOW COLUMNS FROM pengajuan_proposal");
    $kolomAda  = [];
    while ($k = $kolomInfo->fetch_assoc()) $kolomAda[] = $k['Field'];

    $kolomTujuan = in_array('keperluan', $kolomAda)       ? 'keperluan'        : 'tujuan_pinjaman';
    $kolomJangka = in_array('jangka_waktu', $kolomAda)    ? 'jangka_waktu'     : 'jangka_waktu_bulan';
    $kolomStatus = in_array('status', $kolomAda)          ? 'status'           : 'status_pengajuan';

    $dokPath = null;
    if (!empty($_FILES['dokumen_proposal']['name'])) {
        $hasilUpload = uploadFile('dokumen_proposal', 'proposal');
        if ($hasilUpload !== '') {
            $dokPath = $hasilUpload;
        }
    }

    $kolomDok = in_array('dokumen_proposal', $kolomAda) ? 'dokumen_proposal'
              : (in_array('dok_proposal', $kolomAda)    ? 'dok_proposal' : null);

    if ($kolomDok && $dokPath) {
        $sql = "INSERT INTO pengajuan_proposal
                    (anggota_id, jumlah_pinjaman, $kolomJangka,
                     bunga_persen, $kolomTujuan, deskripsi_usaha,
                     $kolomDok, $kolomStatus)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'menunggu')";
        $stmt = $conn->prepare($sql);
        if (!$stmt) sendResponse(false, 'Query error: ' . $conn->error);
        $stmt->bind_param('ididsss',
            $anggotaId, $jumlah, $jangka,
            $bungaPersen, $keperluan, $deskripsi, $dokPath);
    } else {
        $sql = "INSERT INTO pengajuan_proposal
                    (anggota_id, jumlah_pinjaman, $kolomJangka,
                     bunga_persen, $kolomTujuan, deskripsi_usaha,
                     $kolomStatus)
                VALUES (?, ?, ?, ?, ?, ?, 'menunggu')";
        $stmt = $conn->prepare($sql);
        if (!$stmt) sendResponse(false, 'Query error: ' . $conn->error);
        $stmt->bind_param('ididss',
            $anggotaId, $jumlah, $jangka,
            $bungaPersen, $keperluan, $deskripsi);
    }

    if (!$stmt->execute()) {
        sendResponse(false, 'Gagal simpan: ' . $stmt->error);
    }

    $newId      = $conn->insert_id;
    $noProposal = 'PRO-' . date('Ymd') . '-' . str_pad($newId, 4, '0', STR_PAD_LEFT);

    if (in_array('no_proposal', $kolomAda)) {
        $conn->query("UPDATE pengajuan_proposal
                      SET no_proposal = '$noProposal'
                      WHERE id = $newId");
    }

    sendResponse(true, 'Proposal berhasil dikirim! Menunggu verifikasi admin.', [
        'proposal_id' => $newId,
        'no_proposal' => $noProposal
    ]);
    exit;
}

// ── PUT: Verifikasi proposal (Admin) ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $user = validateToken($conn);
    requireRole($user, 'admin');

    $id   = intval($_GET['id'] ?? 0);
    $data = json_decode(file_get_contents('php://input'), true);

    $statusBaru      = trim($data['status_pengajuan'] ?? '');
    $catatanAdmin    = trim($data['catatan_admin']    ?? '');
    $jumlahDisetujui = isset($data['jumlah_disetujui']) && $data['jumlah_disetujui'] !== ''
        ? floatval($data['jumlah_disetujui']) : null;

    $statusValid = ['menunggu', 'disetujui', 'ditolak', 'revisi'];
    if (!in_array($statusBaru, $statusValid)) {
        sendResponse(false, 'Status tidak valid');
    }

    if ($statusBaru === 'disetujui') {
        if ($jumlahDisetujui === null || $jumlahDisetujui <= 0) {
            sendResponse(false, 'Jumlah disetujui wajib diisi untuk menyetujui proposal');
        }

        $stmtCek = $conn->prepare(
            "SELECT pp.jumlah_pinjaman, a.id AS anggota_id, a.is_ketua,
                    a.nama_kelompok, a.limit_pinjaman, a.jumlah_kredit_lunas
             FROM pengajuan_proposal pp
             JOIN anggota a ON a.id = pp.anggota_id
             WHERE pp.id = ? LIMIT 1"
        );
        $stmtCek->bind_param('i', $id);
        $stmtCek->execute();
        $cekData = $stmtCek->get_result()->fetch_assoc();

        if (!$cekData) {
            sendResponse(false, 'Proposal tidak ditemukan');
        }

        if ($jumlahDisetujui > (float)$cekData['jumlah_pinjaman']) {
            sendResponse(false, 'Jumlah disetujui tidak boleh lebih besar dari jumlah yang diajukan');
        }

        $anggotaUntukLimit = [
            'id'                  => $cekData['anggota_id'],
            'is_ketua'            => $cekData['is_ketua'],
            'nama_kelompok'       => $cekData['nama_kelompok'],
            'limit_pinjaman'      => $cekData['limit_pinjaman'],
            'jumlah_kredit_lunas' => $cekData['jumlah_kredit_lunas'],
        ];
        $hasilLimitCek        = hitungLimitPengajuan($conn, $anggotaUntukLimit);
        $limitUntukVerifikasi = $hasilLimitCek['limit_final'];

        if ($jumlahDisetujui > $limitUntukVerifikasi) {
            sendResponse(false, 'Jumlah disetujui melebihi limit pinjaman anggota (Rp ' .
                number_format($limitUntukVerifikasi, 0, ',', '.') . ')');
        }

        $danaTersedia = hitungDanaTersedia($conn);
        if ($jumlahDisetujui > $danaTersedia) {
            sendResponse(false, 'Jumlah disetujui melebihi dana kas yang tersedia (Rp ' .
                number_format($danaTersedia, 0, ',', '.') . ')');
        }

        if ($jumlahDisetujui < (float)$cekData['jumlah_pinjaman']) {
            $infoSebagian = 'Disetujui sebagian: Rp ' .
                number_format($jumlahDisetujui, 0, ',', '.') . ' dari Rp ' .
                number_format($cekData['jumlah_pinjaman'], 0, ',', '.') .
                ' yang diajukan (dana kas BUMDesma sedang terbatas).';
            $catatanAdmin = trim($catatanAdmin . ' ' . $infoSebagian);
        }
    }

    $kolomCek = $conn->query("SHOW COLUMNS FROM pengajuan_proposal");
    $kolomAda = [];
    while ($k = $kolomCek->fetch_assoc()) $kolomAda[] = $k['Field'];
    $kolomStatus = in_array('status', $kolomAda) ? 'status' : 'status_pengajuan';

    $jumlahDisetujuiUntukDb = ($statusBaru === 'disetujui') ? $jumlahDisetujui : null;

    if (in_array('diverifikasi_oleh', $kolomAda) && in_array('tanggal_diproses', $kolomAda)) {
        $stmt = $conn->prepare("
            UPDATE pengajuan_proposal
            SET $kolomStatus = ?,
                catatan_admin = ?,
                jumlah_disetujui = ?,
                diverifikasi_oleh = ?,
                tanggal_diproses = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param('ssdii', $statusBaru, $catatanAdmin, $jumlahDisetujuiUntukDb, $user['id'], $id);
    } else {
        $stmt = $conn->prepare("
            UPDATE pengajuan_proposal
            SET $kolomStatus = ?,
                catatan_admin = ?,
                jumlah_disetujui = ?
            WHERE id = ?
        ");
        $stmt->bind_param('ssdi', $statusBaru, $catatanAdmin, $jumlahDisetujuiUntukDb, $id);
    }

    if (!$stmt->execute()) {
        sendResponse(false, 'Gagal update: ' . $stmt->error);
    }

    if ($statusBaru === 'disetujui') {
        generateKreditDariProposal($conn, $id);
    }

    $pesan = match($statusBaru) {
        'disetujui' => 'Proposal berhasil disetujui',
        'ditolak'   => 'Proposal telah ditolak',
        'revisi'    => 'Proposal diminta revisi',
        default     => 'Status diperbarui'
    };

    sendResponse(true, $pesan);
    exit;
}

sendResponse(false, 'Method tidak diizinkan', null, 405);
$conn->close();