<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

setCORSHeaders();
$conn = getConnection();

// ── GET: Jadwal angsuran ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = validateToken($conn);

    $anggotaId = null;
    if ($user['role'] === 'anggota') {
        $stmtA = $conn->prepare("SELECT id FROM anggota WHERE user_id = ? LIMIT 1");
        $stmtA->bind_param('i', $user['id']);
        $stmtA->execute();
        $resA = $stmtA->get_result();

        if ($resA->num_rows === 0) {
            sendResponse(false, 'Data anggota tidak ditemukan untuk user ini');
            exit;
        }
        $anggotaId = $resA->fetch_assoc()['id'];
    }

    $kreditIdParam    = isset($_GET['kredit_id']) ? intval($_GET['kredit_id']) : 0;
    $anggotaIdParam   = isset($_GET['anggota_id']) ? intval($_GET['anggota_id']) : 0;
    $namaKelompokParam = isset($_GET['nama_kelompok']) ? trim($_GET['nama_kelompok']) : null; // BARU
    $filterStatus     = isset($_GET['status']) ? trim($_GET['status']) : null;
    $searchParam      = isset($_GET['search']) ? trim($_GET['search']) : null;

    $sql = "SELECT ang.*, k.no_kredit, a.nama_lengkap
            FROM angsuran ang
            JOIN kredit_aktif k ON k.id = ang.kredit_id
            JOIN anggota a ON a.id = ang.anggota_id
            WHERE 1=1";
    $types  = '';
    $params = [];

    if ($user['role'] === 'anggota') {
        $sql   .= " AND ang.anggota_id = ?";
        $types .= 'i';
        $params[] = $anggotaId;
    } elseif ($anggotaIdParam > 0) {
        $sql   .= " AND ang.anggota_id = ?";
        $types .= 'i';
        $params[] = $anggotaIdParam;
    } elseif ($namaKelompokParam) {
        // BARU: admin ambil tagihan 1 kelompok sekaligus, dipakai
        // layar Setoran Kolektif (ketua bayar borongan)
        $sql   .= " AND a.nama_kelompok = ?";
        $types .= 's';
        $params[] = $namaKelompokParam;
    } elseif ($kreditIdParam > 0) {
        $sql   .= " AND ang.kredit_id = ?";
        $types .= 'i';
        $params[] = $kreditIdParam;
    }

    if ($filterStatus === 'belum_bayar' || $filterStatus === 'sudah_bayar') {
        $sql   .= " AND ang.status_bayar = ?";
        $types .= 's';
        $params[] = $filterStatus;
    } elseif ($filterStatus === 'terlambat') {
        $sql .= " AND ang.status_bayar != 'sudah_bayar' AND ang.tanggal_jatuh_tempo < CURDATE()";
    } elseif ($filterStatus === 'belum_lunas') {
        // BARU: semua yang belum lunas (belum jatuh tempo maupun sudah menunggak)
        $sql .= " AND ang.status_bayar != 'sudah_bayar'";
    }

    if ($searchParam) {
        $sql   .= " AND (a.nama_lengkap LIKE ? OR k.no_kredit LIKE ?)";
        $types .= 'ss';
        $like = '%' . $searchParam . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= " ORDER BY ang.tanggal_jatuh_tempo ASC, ang.no_angsuran ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        sendResponse(false, 'Query error: ' . $conn->error);
        exit;
    }
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $today = date('Y-m-d');
    $list  = [];

    while ($row = $result->fetch_assoc()) {
        $hariTerlambat = 0;
        if ($row['status_bayar'] !== 'sudah_bayar' && $row['tanggal_jatuh_tempo'] < $today) {
            $tempo = new DateTime($row['tanggal_jatuh_tempo']);
            $now   = new DateTime($today);
            $hariTerlambat = $tempo->diff($now)->days;
        }

        $statusTampil = $row['status_bayar'];
        if ($statusTampil === 'belum_bayar' && $hariTerlambat > 0) {
            $statusTampil = 'terlambat';
        }

        $list[] = [
            'id'                  => (int)$row['id'],
            'kredit_id'           => (int)$row['kredit_id'],
            'no_kredit'           => $row['no_kredit'],
            'nama_lengkap'        => $row['nama_lengkap'],
            'no_angsuran'         => (int)$row['no_angsuran'],
            'total_bayar'         => (float)$row['total_bayar'],
            'tanggal_jatuh_tempo' => $row['tanggal_jatuh_tempo'],
            'tanggal_bayar'       => $row['tanggal_bayar'],
            'status_bayar'        => $statusTampil,
            'hari_terlambat'      => $hariTerlambat,
        ];
    }

    sendResponse(true, 'OK', $list);
    exit;
}

// ── POST: Catat pembayaran angsuran (admin) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = validateToken($conn);

    if ($user['role'] !== 'admin') {
        sendResponse(false, 'Hanya admin yang boleh mencatat pembayaran', null, 403);
        exit;
    }

    $angsuranId   = isset($_GET['id']) ? intval($_GET['id']) : (isset($_POST['id']) ? intval($_POST['id']) : 0);
    $jumlahBayar  = isset($_POST['jumlah_bayar']) ? floatval($_POST['jumlah_bayar']) : 0;
    $tanggalBayar = isset($_POST['tanggal_bayar']) ? trim($_POST['tanggal_bayar']) : date('Y-m-d');
    $keterangan   = isset($_POST['keterangan']) && trim($_POST['keterangan']) !== ''
                        ? trim($_POST['keterangan']) : null;

    if ($angsuranId <= 0) {
        sendResponse(false, 'ID angsuran tidak valid');
        exit;
    }
    if ($jumlahBayar <= 0) {
        sendResponse(false, 'Jumlah bayar tidak valid');
        exit;
    }

    $stmtC = $conn->prepare("SELECT * FROM angsuran WHERE id = ? LIMIT 1");
    $stmtC->bind_param('i', $angsuranId);
    $stmtC->execute();
    $resC = $stmtC->get_result();

    if ($resC->num_rows === 0) {
        sendResponse(false, 'Data angsuran tidak ditemukan', null, 404);
        exit;
    }
    $angsuran = $resC->fetch_assoc();

    if ($angsuran['status_bayar'] === 'sudah_bayar') {
        sendResponse(false, 'Angsuran ini sudah tercatat lunas sebelumnya');
        exit;
    }

    if ($jumlahBayar < (float)$angsuran['total_bayar']) {
        sendResponse(false, 'Jumlah bayar (Rp ' . number_format($jumlahBayar, 0, ',', '.') .
            ') kurang dari tagihan (Rp ' . number_format($angsuran['total_bayar'], 0, ',', '.') . ')');
        exit;
    }

    // Upload bukti bayar (opsional)
    $buktiPath = null;
    if (isset($_FILES['bukti_bayar']) && $_FILES['bukti_bayar']['error'] === UPLOAD_ERR_OK
        && $_FILES['bukti_bayar']['size'] > 0) {
        $uploadDir = '../uploads/bukti_bayar/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext      = pathinfo($_FILES['bukti_bayar']['name'], PATHINFO_EXTENSION);
        $fileName = 'bukti_' . $angsuranId . '_' . time() . '.' . $ext;
        $target   = $uploadDir . $fileName;

        if (move_uploaded_file($_FILES['bukti_bayar']['tmp_name'], $target)) {
            $buktiPath = 'uploads/bukti_bayar/' . $fileName;
        }
    }

    $stmtU = $conn->prepare(
        "UPDATE angsuran
         SET tanggal_bayar = ?, status_bayar = 'sudah_bayar',
             keterangan = ?, bukti_bayar = ?, admin_id = ?
         WHERE id = ?"
    );
    $stmtU->bind_param('sssii', $tanggalBayar, $keterangan, $buktiPath, $user['id'], $angsuranId);
    $stmtU->execute();

    if ($stmtU->affected_rows > 0) {
        // ── BARU: cek apakah pembayaran ini melunasi kredit, evaluasi kenaikan limit ──
        prosesKenaikanLimit(
            $conn,
            (int)$angsuran['kredit_id'],
            (int)$angsuran['anggota_id'],
            (int)$user['id']
        );

        sendResponse(true, 'Pembayaran berhasil dicatat', null);
    } else {
        sendResponse(false, 'Gagal menyimpan perubahan');
    }
    exit;
}

sendResponse(false, 'Method tidak diizinkan', null, 405);
$conn->close();