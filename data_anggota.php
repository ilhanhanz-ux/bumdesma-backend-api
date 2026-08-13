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

// Helper query buat "Riwayat Transaksi Kelompok" ──
function getRiwayatTransaksiKelompok($conn, $anggotaId) {
    $stmtG = $conn->prepare("
        SELECT nama_kelompok FROM anggota
        WHERE id = ? AND status_verifikasi = 'diterima'
    ");
    $stmtG->bind_param('i', $anggotaId);
    $stmtG->execute();
    $rowG = $stmtG->get_result()->fetch_assoc();

    if (!$rowG || empty($rowG['nama_kelompok'])) {
        return null;
    }
    $namaKelompok = $rowG['nama_kelompok'];

    $stmtK = $conn->prepare("
        SELECT a.id AS anggota_id, a.nama_lengkap,
               k.id AS kredit_id, k.no_kredit, k.sisa_pokok,
               k.jangka_waktu_bulan, k.status_kredit
        FROM anggota a
        LEFT JOIN kredit_aktif k ON k.anggota_id = a.id
        WHERE a.nama_kelompok = ? AND a.status_verifikasi = 'diterima'
        ORDER BY a.nama_lengkap ASC, k.id DESC
    ");
    $stmtK->bind_param('s', $namaKelompok);
    $stmtK->execute();
    $result = $stmtK->get_result();

    $anggotaMap = [];
    while ($row = $result->fetch_assoc()) {
        $aid = (int)$row['anggota_id'];

        if (!isset($anggotaMap[$aid])) {
            $anggotaMap[$aid] = [
                'id'          => $aid,
                'namaLengkap' => $row['nama_lengkap'],
                'isPengaju'   => ($aid === $anggotaId),
                'jumlahLunas' => 0,
                'jumlahAktif' => 0,
                'jumlahMacet' => 0,
                'transaksi'   => [],
            ];
        }

        if ($row['kredit_id'] === null) {
            continue;
        }

        $status = $row['status_kredit'];
        if ($status === 'lunas') {
            $anggotaMap[$aid]['jumlahLunas']++;
        } elseif ($status === 'aktif') {
            $anggotaMap[$aid]['jumlahAktif']++;
        } elseif ($status === 'macet' || $status === 'dalam_perhatian') {
            $anggotaMap[$aid]['jumlahMacet']++;
        }

        $anggotaMap[$aid]['transaksi'][] = [
            'id'               => (int)$row['kredit_id'],
            'noKredit'         => $row['no_kredit'],
            'sisaPokok'        => (float)$row['sisa_pokok'],
            'jangkaWaktuBulan' => (int)$row['jangka_waktu_bulan'],
            'statusKredit'     => $status,
        ];
    }

    return array_values($anggotaMap);
}

$anggotaId = isset($_GET['id']) ? intval($_GET['id']) : 0;

// MODE RIWAYAT KELOMPOK: ?id=xx&riwayat_kelompok=1
if ($anggotaId > 0 && isset($_GET['riwayat_kelompok'])) {
    $riwayat = getRiwayatTransaksiKelompok($conn, $anggotaId);

    if ($riwayat === null) {
        sendResponse(false, 'Anggota tidak ditemukan', null, 404);
        exit;
    }

    sendResponse(true, 'OK', $riwayat);
    exit;
}

// MODE DETAIL: kalau ada ?id=xx, kembalikan 1 anggota
if ($anggotaId > 0) {
    // ── BARU: a.is_ketua ditambahkan supaya Android tahu status jabatan anggota ini ──
    $stmt = $conn->prepare("
        SELECT id, nik, nama_lengkap, tempat_lahir, tanggal_lahir,
               jenis_kelamin, no_telepon, alamat, status_aktif,
               nama_kelompok, nama_desa, is_ketua
        FROM anggota
        WHERE id = ? AND status_verifikasi = 'diterima'
    ");
    $stmt->bind_param('i', $anggotaId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        sendResponse(false, 'Anggota tidak ditemukan', null, 404);
        exit;
    }

    $data = [
        'id'              => (int)$row['id'],
        'nik'             => $row['nik'],
        'namaLengkap'     => $row['nama_lengkap'],
        'tempatLahir'     => $row['tempat_lahir'],
        'tanggalLahir'    => $row['tanggal_lahir'],
        'jenisKelamin'    => $row['jenis_kelamin'],
        'noTelepon'       => $row['no_telepon'],
        'alamat'          => $row['alamat'],
        'namaKelompok'    => $row['nama_kelompok'],
        'namaDesa'        => $row['nama_desa'],
        'statusAktif'     => (bool)$row['status_aktif'],
        // ── BARU ──
        'isKetua'         => (bool)$row['is_ketua'],
    ];

    sendResponse(true, 'OK', $data);
    exit;
}

// MODE DAFTAR: tampilkan semua anggota, bisa difilter
$search = isset($_GET['search']) ? trim($_GET['search']) : null;
$status = isset($_GET['status']) ? trim($_GET['status']) : null;

$sql = "
    SELECT id, nik, nama_lengkap, no_telepon, status_aktif,
           nama_kelompok, nama_desa
    FROM anggota
    WHERE status_verifikasi = 'diterima'
";
$types = '';
$params = [];

if ($search) {
    $sql .= " AND (nama_lengkap LIKE ? OR nik LIKE ?)";
    $types .= 'ss';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

if ($status === 'aktif') {
    $sql .= " AND status_aktif = 1";
} elseif ($status === 'nonaktif') {
    $sql .= " AND status_aktif = 0";
}

$sql .= " ORDER BY nama_lengkap ASC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    sendResponse(false, 'Query error: ' . $conn->error, null, 500);
    exit;
}
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$list = [];
while ($row = $result->fetch_assoc()) {
    $list[] = [
        'id'           => (int)$row['id'],
        'nik'          => $row['nik'],
        'namaLengkap'  => $row['nama_lengkap'],
        'noTelepon'    => $row['no_telepon'],
        'namaKelompok' => $row['nama_kelompok'],
        'namaDesa'     => $row['nama_desa'],
        'statusAktif'  => (bool)$row['status_aktif'],
    ];
}

sendResponse(true, 'OK', $list);
$conn->close();