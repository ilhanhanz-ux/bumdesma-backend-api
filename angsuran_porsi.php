<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

setCORSHeaders();
$conn = getConnection();

// Resolve baris anggota (id, is_ketua, nama_kelompok, status_aktif) dari user_id
// yang sedang login. Dipisah jadi fungsi lokal di file ini (bukan ditaruh di
// helpers.php) biar tidak bentrok kalau ternyata sudah ada fungsi serupa di sana
// dengan nama/signature beda -- kasih tau kalau memang sudah ada, biar dipakai itu saja.
function resolveAnggotaDariUser($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT id, is_ketua, nama_kelompok, status_aktif
         FROM anggota WHERE user_id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res->num_rows > 0 ? $res->fetch_assoc() : null;
}

// Simpan bukti transfer dari base64 -> file. Pola sama persis seperti
// setoran_angsuran.php biar konsisten (PUT + JSON base64, bukan multipart).
function simpanBuktiBase64($base64String, $prefix) {
    if (strpos($base64String, ',') !== false) {
        $base64String = explode(',', $base64String)[1];
    }
    $imageData = base64_decode($base64String);
    if ($imageData === false) return null;

    $uploadDir = '../uploads/bukti_bayar/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $fileName = $prefix . '_' . time() . '_' . uniqid() . '.jpg';
    $target   = $uploadDir . $fileName;

    if (!file_put_contents($target, $imageData)) return null;
    return 'uploads/bukti_bayar/' . $fileName;
}

$user = validateToken($conn);

// ══════════════════════════════════════════════════════
// GET: lihat porsi angsuran (beda hasil tergantung role)
// ══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    // ── Detail 1 angsuran + breakdown porsi tiap anggota ──
    // Dipakai ketua (buat bagi/edit porsi) & admin (buat lihat rincian).
    if (isset($_GET['angsuran_id'])) {
        $angsuranId = intval($_GET['angsuran_id']);

        // Ditambah: k.pokok_pinjaman (buat hitung nominal_default) dan
        // a2.nama_kelompok (buat fallback daftar anggota kalau porsi belum
        // pernah diisi sama sekali -- lihat catatan di bawah)
        $stmtAng = $conn->prepare(
            "SELECT ang.id, ang.kredit_id, ang.anggota_id AS ketua_anggota_id,
                    ang.no_angsuran, ang.total_bayar, ang.tanggal_jatuh_tempo,
                    ang.status_bayar AS status_induk, k.no_kredit, k.pokok_pinjaman,
                    a2.nama_kelompok
             FROM angsuran ang
             JOIN kredit_aktif k ON k.id = ang.kredit_id
             JOIN anggota a2 ON a2.id = ang.anggota_id
             WHERE ang.id = ? LIMIT 1"
        );
        $stmtAng->bind_param('i', $angsuranId);
        $stmtAng->execute();
        $angsuran = $stmtAng->get_result()->fetch_assoc();

        if (!$angsuran) {
            sendResponse(false, 'Angsuran tidak ditemukan', null, 404);
            exit;
        }

        if ($user['role'] === 'anggota') {
            $anggota = resolveAnggotaDariUser($conn, $user['id']);
            $isKetuaKredit = $anggota && (int)$anggota['id'] === (int)$angsuran['ketua_anggota_id'];
            if (!$isKetuaKredit) {
                sendResponse(false, 'Anda tidak berhak mengakses data ini', null, 403);
                exit;
            }
        }

        // Alokasi pokok pinjaman per anggota untuk kredit ini (hasil layar
        // "Alokasi Pinjaman ke Anggota"), dipakai buat hitung nominal_default.
        // Kalau belum pernah diisi ketua, map ini kosong -> nominal_default = 0
        // untuk semua (fallback aman, tidak error).
        $alokasiMap = [];
        $stmtAlokasi = $conn->prepare(
            "SELECT anggota_id, jumlah_pokok FROM alokasi_pinjaman_anggota WHERE kredit_id = ?"
        );
        $stmtAlokasi->bind_param('i', $angsuran['kredit_id']);
        $stmtAlokasi->execute();
        $resAlokasi = $stmtAlokasi->get_result();
        while ($rowA = $resAlokasi->fetch_assoc()) {
            $alokasiMap[(int)$rowA['anggota_id']] = (float)$rowA['jumlah_pokok'];
        }
        $pokokPinjaman = (float)$angsuran['pokok_pinjaman'];

        $stmtPorsi = $conn->prepare(
            "SELECT ap.*, a.nama_lengkap
             FROM angsuran_porsi ap
             JOIN anggota a ON a.id = ap.anggota_id
             WHERE ap.angsuran_id = ?
             ORDER BY a.nama_lengkap ASC"
        );
        $stmtPorsi->bind_param('i', $angsuranId);
        $stmtPorsi->execute();
        $resPorsi = $stmtPorsi->get_result();

        $porsiList = [];
        $totalSudahDibagi = 0.0;
        while ($row = $resPorsi->fetch_assoc()) {
            $totalSudahDibagi += (float)$row['jumlah_porsi'];
            $alokasiPokok = $alokasiMap[(int)$row['anggota_id']] ?? 0;
            $nominalDefault = $pokokPinjaman > 0
                ? round(($alokasiPokok / $pokokPinjaman) * (float)$angsuran['total_bayar'], 2)
                : 0;
            $porsiList[] = [
                'id'                 => (int)$row['id'],
                'anggota_id'         => (int)$row['anggota_id'],
                'nama_lengkap'       => $row['nama_lengkap'],
                'jumlah_porsi'       => (float)$row['jumlah_porsi'],
                'nominal_default'    => $nominalDefault,
                'status_bayar'       => $row['status_bayar'],
                'bukti_bayar'        => $row['bukti_bayar'],
                'tanggal_setor'      => $row['tanggal_setor'],
                'tanggal_verifikasi' => $row['tanggal_verifikasi'],
                'catatan_admin'      => $row['catatan_admin'],
            ];
        }

        // Kalau ketua BELUM PERNAH mengisi porsi untuk angsuran ini sama sekali,
        // angsuran_porsi belum punya baris apa pun -> $porsiList di atas kosong.
        // Tanpa fallback ini, layar Atur Porsi Angsuran bakal muncul KOSONG di
        // kunjungan pertama (ketua tidak tahu siapa saja yang harus diisi).
        // Jadi kalau kosong, bangun daftar dari anggota aktif kelompok ini,
        // jumlah_porsi = 0 (belum tersimpan), nominal_default tetap dihitung
        // supaya field-nya di Android sudah ke-pre-fill proporsional.
        if (empty($porsiList)) {
            $stmtAnggotaKelompok = $conn->prepare(
                "SELECT id AS anggota_id, nama_lengkap
                 FROM anggota
                 WHERE nama_kelompok = ? AND status_aktif = 1
                 ORDER BY is_ketua DESC, nama_lengkap ASC"
            );
            $stmtAnggotaKelompok->bind_param('s', $angsuran['nama_kelompok']);
            $stmtAnggotaKelompok->execute();
            $resAnggotaKelompok = $stmtAnggotaKelompok->get_result();

            while ($rowM = $resAnggotaKelompok->fetch_assoc()) {
                $alokasiPokok = $alokasiMap[(int)$rowM['anggota_id']] ?? 0;
                $nominalDefault = $pokokPinjaman > 0
                    ? round(($alokasiPokok / $pokokPinjaman) * (float)$angsuran['total_bayar'], 2)
                    : 0;
                $porsiList[] = [
                    'id'                 => 0,
                    'anggota_id'         => (int)$rowM['anggota_id'],
                    'nama_lengkap'       => $rowM['nama_lengkap'],
                    'jumlah_porsi'       => 0,
                    'nominal_default'    => $nominalDefault,
                    'status_bayar'       => 'belum_bayar',
                    'bukti_bayar'        => null,
                    'tanggal_setor'      => null,
                    'tanggal_verifikasi' => null,
                    'catatan_admin'      => null,
                ];
            }
        }

        sendResponse(true, 'OK', [
            'angsuran_id'         => (int)$angsuran['id'],
            'kredit_id'           => (int)$angsuran['kredit_id'],
            'no_kredit'           => $angsuran['no_kredit'],
            'no_angsuran'         => (int)$angsuran['no_angsuran'],
            'total_bayar'         => (float)$angsuran['total_bayar'],
            'tanggal_jatuh_tempo' => $angsuran['tanggal_jatuh_tempo'],
            'status_induk'        => $angsuran['status_induk'],
            'total_sudah_dibagi'  => round($totalSudahDibagi, 2),
            'sisa_belum_dibagi'   => round((float)$angsuran['total_bayar'] - $totalSudahDibagi, 2),
            'porsi'               => $porsiList,
        ]);
        exit;
    }

    if ($user['role'] === 'anggota') {
        $anggota = resolveAnggotaDariUser($conn, $user['id']);
        if (!$anggota) {
            sendResponse(false, 'Data anggota tidak ditemukan', null, 404);
            exit;
        }

        // Ketua juga anggota biasa yang punya porsi pribadi sendiri di
        // angsuran_porsi (lihat kolom anggota_id di tabel itu -- ketua
        // punya baris sendiri, terpisah dari anggota lain di kelompoknya).
        // Default (tanpa parameter) tetap balikin ringkasan kelompok untuk
        // ketua, dipakai KelolaPorsiActivity (bagi porsi ke anggota).
        // Kalau ada ?tampilan=saya, ketua eksplisit minta porsi PRIBADInya
        // sendiri -- dipakai TagihanPorsiSayaActivity & RiwayatPembayaranActivity,
        // sama seperti anggota biasa, formatnya identik.
        $tampilanSaya = isset($_GET['tampilan']) && trim($_GET['tampilan']) === 'saya';

        // ── KETUA (mode ringkasan kelompok, default) ──
        if (!$tampilanSaya && (int)($anggota['is_ketua'] ?? 0) === 1) {
            $stmt = $conn->prepare(
                "SELECT ang.id, ang.no_angsuran, ang.total_bayar, ang.tanggal_jatuh_tempo,
                        ang.status_bayar AS status_induk,
                        COALESCE(SUM(ap.jumlah_porsi), 0) AS total_dibagi
                 FROM angsuran ang
                 LEFT JOIN angsuran_porsi ap ON ap.angsuran_id = ang.id
                 WHERE ang.anggota_id = ?
                 GROUP BY ang.id
                 ORDER BY ang.tanggal_jatuh_tempo ASC"
            );
            $stmt->bind_param('i', $anggota['id']);
            $stmt->execute();
            $res = $stmt->get_result();

            $list = [];
            while ($row = $res->fetch_assoc()) {
                $list[] = [
                    'angsuran_id'         => (int)$row['id'],
                    'no_angsuran'         => (int)$row['no_angsuran'],
                    'total_bayar'         => (float)$row['total_bayar'],
                    'tanggal_jatuh_tempo' => $row['tanggal_jatuh_tempo'],
                    'status_induk'        => $row['status_induk'],
                    'total_dibagi'        => (float)$row['total_dibagi'],
                    'sudah_lengkap'       => abs((float)$row['total_dibagi'] - (float)$row['total_bayar']) < 0.01,
                ];
            }
            sendResponse(true, 'OK', $list);
            exit;
        }

        // ── PORSI PRIBADI (anggota biasa, ATAU ketua dengan ?tampilan=saya) ──
        $filterStatus = isset($_GET['status']) ? trim($_GET['status']) : null;

        $sql = "SELECT ap.*, ang.no_angsuran, ang.tanggal_jatuh_tempo, k.no_kredit
                FROM angsuran_porsi ap
                JOIN angsuran ang ON ang.id = ap.angsuran_id
                JOIN kredit_aktif k ON k.id = ang.kredit_id
                WHERE ap.anggota_id = ?";
        $types  = 'i';
        $params = [$anggota['id']];

        if ($filterStatus) {
            $sql   .= " AND ap.status_bayar = ?";
            $types .= 's';
            $params[] = $filterStatus;
        }
        $sql .= " ORDER BY ang.tanggal_jatuh_tempo ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();

        $today = date('Y-m-d');
        $list = [];
        while ($row = $res->fetch_assoc()) {
            $hariTerlambat = 0;
            if ($row['status_bayar'] !== 'sudah_bayar' && $row['tanggal_jatuh_tempo'] < $today) {
                $tempo = new DateTime($row['tanggal_jatuh_tempo']);
                $now   = new DateTime($today);
                $hariTerlambat = $tempo->diff($now)->days;
            }
            $list[] = [
                'id'                  => (int)$row['id'],
                'angsuran_id'         => (int)$row['angsuran_id'],
                'no_kredit'           => $row['no_kredit'],
                'no_angsuran'         => (int)$row['no_angsuran'],
                'jumlah_porsi'        => (float)$row['jumlah_porsi'],
                'tanggal_jatuh_tempo' => $row['tanggal_jatuh_tempo'],
                'status_bayar'        => $row['status_bayar'],
                'bukti_bayar'         => $row['bukti_bayar'],
                'tanggal_setor'       => $row['tanggal_setor'],
                'catatan_admin'       => $row['catatan_admin'],
                'hari_terlambat'      => $hariTerlambat,
            ];
        }
        sendResponse(true, 'OK', $list);
        exit;
    }

    // ── ADMIN: daftar porsi yang perlu diverifikasi / riwayat ──
    requireRole($user, 'admin');

    $filterStatus = isset($_GET['status']) ? trim($_GET['status']) : 'menunggu_verifikasi';

    $sql = "SELECT ap.*, a.nama_lengkap, a.nama_kelompok,
                   ang.no_angsuran, ang.tanggal_jatuh_tempo, k.no_kredit
            FROM angsuran_porsi ap
            JOIN anggota a ON a.id = ap.anggota_id
            JOIN angsuran ang ON ang.id = ap.angsuran_id
            JOIN kredit_aktif k ON k.id = ang.kredit_id
            WHERE 1=1";
    $types = ''; $params = [];

    if ($filterStatus === 'riwayat') {
        $sql .= " AND ap.status_bayar IN ('sudah_bayar','ditolak')";
    } elseif ($filterStatus !== 'semua') {
        $sql   .= " AND ap.status_bayar = ?";
        $types .= 's';
        $params[] = $filterStatus;
    }
    $sql .= " ORDER BY ap.tanggal_setor DESC";

    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $list = [];
    while ($row = $res->fetch_assoc()) {
        $list[] = [
            'id'                 => (int)$row['id'],
            'angsuran_id'        => (int)$row['angsuran_id'],
            'no_kredit'          => $row['no_kredit'],
            'no_angsuran'        => (int)$row['no_angsuran'],
            'nama_lengkap'       => $row['nama_lengkap'],
            'nama_kelompok'      => $row['nama_kelompok'],
            'jumlah_porsi'       => (float)$row['jumlah_porsi'],
            'status_bayar'       => $row['status_bayar'],
            'bukti_bayar'        => $row['bukti_bayar'],
            'tanggal_setor'      => $row['tanggal_setor'],
            'tanggal_verifikasi' => $row['tanggal_verifikasi'],
            'catatan_admin'      => $row['catatan_admin'],
        ];
    }
    sendResponse(true, 'OK', $list);
    exit;
}

// ══════════════════════════════════════════════════════
// POST: ketua menetapkan/mengubah porsi tiap anggota untuk 1 angsuran
// ══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($user['role'] !== 'anggota') {
        sendResponse(false, 'Hanya ketua kelompok yang bisa membagi porsi angsuran', null, 403);
        exit;
    }

    $ketua = resolveAnggotaDariUser($conn, $user['id']);
    if (!$ketua || (int)($ketua['is_ketua'] ?? 0) !== 1) {
        sendResponse(false, 'Hanya ketua kelompok yang bisa membagi porsi angsuran', null, 403);
        exit;
    }

    $data       = json_decode(file_get_contents('php://input'), true);
    $angsuranId = isset($data['angsuran_id']) ? intval($data['angsuran_id']) : 0;
    $porsiInput = isset($data['porsi']) && is_array($data['porsi']) ? $data['porsi'] : [];

    if ($angsuranId <= 0) {
        sendResponse(false, 'angsuran_id wajib diisi', null, 400);
        exit;
    }
    if (empty($porsiInput)) {
        sendResponse(false, 'Daftar porsi anggota wajib diisi', null, 400);
        exit;
    }

    $stmtAng = $conn->prepare(
        "SELECT id, anggota_id, total_bayar FROM angsuran WHERE id = ? LIMIT 1"
    );
    $stmtAng->bind_param('i', $angsuranId);
    $stmtAng->execute();
    $angsuran = $stmtAng->get_result()->fetch_assoc();

    if (!$angsuran) {
        sendResponse(false, 'Angsuran tidak ditemukan', null, 404);
        exit;
    }
    if ((int)$angsuran['anggota_id'] !== (int)$ketua['id']) {
        sendResponse(false, 'Angsuran ini bukan milik kredit kelompok Anda', null, 403);
        exit;
    }

    // Kalau ada porsi yang statusnya bukan belum_bayar (sudah disetor/diverifikasi),
    // jangan izinkan diubah lagi -- cegah data pembayaran yang sedang berjalan rusak.
    $stmtCekAda = $conn->prepare(
        "SELECT COUNT(*) AS jumlah FROM angsuran_porsi
         WHERE angsuran_id = ? AND status_bayar != 'belum_bayar'"
    );
    $stmtCekAda->bind_param('i', $angsuranId);
    $stmtCekAda->execute();
    $adaYangDiproses = (int)($stmtCekAda->get_result()->fetch_assoc()['jumlah'] ?? 0);

    if ($adaYangDiproses > 0) {
        sendResponse(false, 'Sebagian porsi angsuran ini sudah disetor/diverifikasi, tidak bisa diubah lagi. Hubungi admin kalau perlu koreksi.', null, 409);
        exit;
    }

    // Validasi tiap anggota_id: harus 1 kelompok yang sama dengan ketua & aktif
    $totalPorsi = 0.0;
    $porsiValid = [];
    foreach ($porsiInput as $item) {
        $anggotaId = isset($item['anggota_id']) ? intval($item['anggota_id']) : 0;
        $jumlah    = isset($item['jumlah_porsi']) ? floatval($item['jumlah_porsi']) : 0;

        if ($anggotaId <= 0 || $jumlah <= 0) {
            sendResponse(false, 'Data porsi tidak valid (anggota_id/jumlah_porsi)', null, 400);
            exit;
        }

        $stmtCekAnggota = $conn->prepare(
            "SELECT id FROM anggota WHERE id = ? AND nama_kelompok = ? AND status_aktif = 1 LIMIT 1"
        );
        $stmtCekAnggota->bind_param('is', $anggotaId, $ketua['nama_kelompok']);
        $stmtCekAnggota->execute();
        if ($stmtCekAnggota->get_result()->num_rows === 0) {
            sendResponse(false, "Anggota ID $anggotaId bukan anggota aktif di kelompok Anda", null, 400);
            exit;
        }

        $totalPorsi += $jumlah;
        $porsiValid[] = ['anggota_id' => $anggotaId, 'jumlah_porsi' => $jumlah];
    }

    if (abs($totalPorsi - (float)$angsuran['total_bayar']) > 0.01) {
        sendResponse(false, 'Total porsi (Rp ' . number_format($totalPorsi, 0, ',', '.') .
            ') harus sama persis dengan total tagihan (Rp ' .
            number_format($angsuran['total_bayar'], 0, ',', '.') . ')', null, 422);
        exit;
    }

    $conn->begin_transaction();
    try {
        $stmtDel = $conn->prepare("DELETE FROM angsuran_porsi WHERE angsuran_id = ?");
        $stmtDel->bind_param('i', $angsuranId);
        $stmtDel->execute();

        $stmtIns = $conn->prepare(
            "INSERT INTO angsuran_porsi (angsuran_id, anggota_id, jumlah_porsi, status_bayar)
             VALUES (?, ?, ?, 'belum_bayar')"
        );
        foreach ($porsiValid as $p) {
            $stmtIns->bind_param('iid', $angsuranId, $p['anggota_id'], $p['jumlah_porsi']);
            $stmtIns->execute();
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        sendResponse(false, 'Gagal menyimpan porsi: ' . $e->getMessage(), null, 500);
        exit;
    }

    sendResponse(true, 'Porsi angsuran berhasil disimpan', [
        'angsuran_id'    => $angsuranId,
        'jumlah_anggota' => count($porsiValid),
    ]);
    exit;
}

// ══════════════════════════════════════════════════════
// PUT: dua aksi beda tergantung role si pemanggil
//   - anggota -> submit bukti setoran mandiri
//   - admin   -> verifikasi (approve/reject)
// ══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data    = json_decode(file_get_contents('php://input'), true);
    $porsiId = isset($data['porsi_id']) ? intval($data['porsi_id']) : 0;

    if ($porsiId <= 0) {
        sendResponse(false, 'porsi_id wajib diisi', null, 400);
        exit;
    }

    $stmtP = $conn->prepare(
        "SELECT ap.*, ang.kredit_id, ang.anggota_id AS ketua_anggota_id
         FROM angsuran_porsi ap
         JOIN angsuran ang ON ang.id = ap.angsuran_id
         WHERE ap.id = ? LIMIT 1"
    );
    $stmtP->bind_param('i', $porsiId);
    $stmtP->execute();
    $porsi = $stmtP->get_result()->fetch_assoc();

    if (!$porsi) {
        sendResponse(false, 'Data porsi tidak ditemukan', null, 404);
        exit;
    }

    // ── ANGGOTA: submit bukti setoran mandiri ──
    if ($user['role'] === 'anggota') {
        $anggota = resolveAnggotaDariUser($conn, $user['id']);
        if (!$anggota || (int)$anggota['id'] !== (int)$porsi['anggota_id']) {
            sendResponse(false, 'Anda tidak berhak mengubah data ini', null, 403);
            exit;
        }
        if (!in_array($porsi['status_bayar'], ['belum_bayar', 'ditolak'])) {
            sendResponse(false, 'Porsi ini sudah disetor/lunas, tidak bisa disetor ulang', null, 409);
            exit;
        }

        $buktiB64   = $data['bukti_bayar'] ?? null;
        $keterangan = isset($data['keterangan']) ? trim($data['keterangan']) : null;

        if (empty($buktiB64)) {
            sendResponse(false, 'Foto bukti transfer wajib diisi', null, 400);
            exit;
        }

        $path = simpanBuktiBase64($buktiB64, 'porsi_' . $porsiId);
        if (!$path) {
            sendResponse(false, 'Gagal menyimpan foto bukti transfer', null, 500);
            exit;
        }

        $stmtU = $conn->prepare(
            "UPDATE angsuran_porsi
             SET status_bayar = 'menunggu_verifikasi', bukti_bayar = ?,
                 tanggal_setor = CURDATE(), catatan_admin = ?
             WHERE id = ?"
        );
        $stmtU->bind_param('ssi', $path, $keterangan, $porsiId);
        $stmtU->execute();

        sendResponse(true, 'Bukti setoran berhasil dikirim, menunggu verifikasi admin', [
            'porsi_id'    => $porsiId,
            'bukti_bayar' => $path,
        ]);
        exit;
    }

    // ── ADMIN: verifikasi (approve/reject) ──
    requireRole($user, 'admin');

    $aksi         = isset($data['aksi']) ? trim($data['aksi']) : '';
    $catatanAdmin = isset($data['catatan_admin']) ? trim($data['catatan_admin']) : null;

    if (!in_array($aksi, ['approve', 'reject'])) {
        sendResponse(false, "aksi harus 'approve' atau 'reject'", null, 400);
        exit;
    }
    if ($porsi['status_bayar'] !== 'menunggu_verifikasi') {
        sendResponse(false, 'Porsi ini tidak sedang menunggu verifikasi', null, 409);
        exit;
    }
    if ($aksi === 'reject' && empty($catatanAdmin)) {
        sendResponse(false, 'Catatan alasan penolakan wajib diisi', null, 400);
        exit;
    }

    $statusBaru = $aksi === 'approve' ? 'sudah_bayar' : 'ditolak';

    $stmtV = $conn->prepare(
        "UPDATE angsuran_porsi
         SET status_bayar = ?, tanggal_verifikasi = CURDATE(),
             catatan_admin = ?, admin_id = ?
         WHERE id = ?"
    );
    $stmtV->bind_param('ssii', $statusBaru, $catatanAdmin, $user['id'], $porsiId);
    $stmtV->execute();

    if ($aksi === 'approve') {
        $stmtCek = $conn->prepare(
            "SELECT COUNT(*) AS belum FROM angsuran_porsi
             WHERE angsuran_id = ? AND status_bayar != 'sudah_bayar'"
        );
        $stmtCek->bind_param('i', $porsi['angsuran_id']);
        $stmtCek->execute();
        $belum = (int)($stmtCek->get_result()->fetch_assoc()['belum'] ?? 1);

        if ($belum === 0) {
            finalisasiPembayaranLunas(
                $conn,
                (int)$porsi['angsuran_id'],
                (int)$user['id'],
                date('Y-m-d'),
                null,
                'Lunas otomatis - seluruh porsi anggota sudah diverifikasi'
            );
        }
    }

    sendResponse(true, $aksi === 'approve' ? 'Setoran berhasil diverifikasi' : 'Setoran ditolak', [
        'porsi_id'     => $porsiId,
        'status_bayar' => $statusBaru,
    ]);
    exit;
}

sendResponse(false, 'Method tidak diizinkan', null, 405);
$conn->close();