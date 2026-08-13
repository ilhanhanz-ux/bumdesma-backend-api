<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

setCORSHeaders();

$conn = getConnection();
$user = validateToken($conn);
requireRole($user, 'admin');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($id > 0) {
        // Detail 1 anggota (dipakai VerifikasiAnggotaDetailActivity)
        // ── BARU: a.is_ketua ditambahkan supaya Android tahu status jabatan anggota ini ──
        $stmt = $conn->prepare(
            "SELECT a.id AS anggota_id, u.nama, u.username, u.no_telepon,
                    a.nama_kelompok, a.nama_desa, a.tempat_lahir, a.tanggal_lahir,
                    a.alamat, a.foto_ktp, a.status_verifikasi, a.is_ketua
             FROM anggota a
             JOIN users u ON u.id = a.user_id
             WHERE a.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 0) {
            sendResponse(false, 'Data anggota tidak ditemukan', null, 404);
        }

        $detailAnggota = $res->fetch_assoc();

        // ── BARU: cek apakah kelompok ini sudah punya ketua lain yang sudah diterima.
        // Dipakai Android buat nampilin info/menonaktifkan checkbox "Jadikan Ketua"
        // kalau slotnya sudah terisi, supaya admin nggak perlu coba-coba dulu baru
        // ditolak backend (walau backend tetap validasi ulang saat POST).
        $stmtKetuaCek = $conn->prepare(
            "SELECT u.nama FROM anggota a
             JOIN users u ON u.id = a.user_id
             WHERE a.nama_kelompok = ? AND a.is_ketua = 1 AND a.status_verifikasi = 'diterima'
                   AND a.id != ?
             LIMIT 1"
        );
        $stmtKetuaCek->bind_param('si', $detailAnggota['nama_kelompok'], $id);
        $stmtKetuaCek->execute();
        $resKetuaCek = $stmtKetuaCek->get_result();

        $detailAnggota['kelompok_sudah_ada_ketua'] = $resKetuaCek->num_rows > 0;
        $detailAnggota['nama_ketua_sekarang'] = $resKetuaCek->num_rows > 0
            ? $resKetuaCek->fetch_assoc()['nama'] : null;

        sendResponse(true, 'Detail anggota', $detailAnggota);
    }

    // ── BARU: filter berdasarkan tab yang dipilih di Android ──
    // ?status=riwayat -> anggota yang udah diproses (diterima/ditolak)
    // default/lainnya -> anggota yang masih pending (perilaku lama)
    $statusFilter = isset($_GET['status']) ? trim($_GET['status']) : 'pending';

    $whereStatus = ($statusFilter === 'riwayat')
        ? "a.status_verifikasi IN ('diterima', 'ditolak')"
        : "a.status_verifikasi = 'pending'";

    // Daftar anggota baru yang masih menunggu verifikasi manual
    $result = $conn->query(
        "SELECT a.id AS anggota_id, u.nama, u.username, u.no_telepon,
                a.nama_kelompok, a.nama_desa, a.tempat_lahir, a.tanggal_lahir,
                a.alamat, a.status_verifikasi, a.is_ketua
         FROM anggota a
         JOIN users u ON u.id = a.user_id
         WHERE $whereStatus
         ORDER BY a.updated_at DESC"
    );

    $daftar = [];
    while ($row = $result->fetch_assoc()) {
        $daftar[] = $row;
    }

    sendResponse(true, 'Daftar anggota menunggu verifikasi', $daftar);

} elseif ($method === 'POST') {
    $data       = getJsonBody();
    $anggotaId  = (int)($data['anggota_id'] ?? 0);
    $aksi       = trim($data['aksi'] ?? '');       // 'diterima' atau 'ditolak'
    $keterangan = trim($data['keterangan'] ?? '');
    // ── BARU: opsional, hanya berlaku saat aksi = 'diterima'. Menandai anggota
    // ini sebagai KETUA kelompoknya. Hanya ketua yang nanti bisa mengajukan
    // proposal pinjaman (lihat gerbang di proposal.php).
    $jadikanKetua = (int)($data['jadikan_ketua'] ?? 0) === 1;

    if ($anggotaId <= 0 || !in_array($aksi, ['diterima', 'ditolak'], true)) {
        sendResponse(false, 'anggota_id dan aksi (diterima/ditolak) wajib diisi dengan benar');
    }

    // Pastikan anggotanya ada dan memang masih berstatus pending
    $stmtCek = $conn->prepare(
        "SELECT status_verifikasi, nama_kelompok FROM anggota WHERE id = ? LIMIT 1"
    );
    $stmtCek->bind_param('i', $anggotaId);
    $stmtCek->execute();
    $resCek = $stmtCek->get_result();

    if ($resCek->num_rows === 0) {
        sendResponse(false, 'Data anggota tidak ditemukan', null, 404);
    }

    $dataCek       = $resCek->fetch_assoc();
    $statusSebelum = $dataCek['status_verifikasi'];
    $namaKelompok  = $dataCek['nama_kelompok'];

    if ($statusSebelum !== 'pending') {
        sendResponse(false, 'Anggota ini sudah pernah diverifikasi sebelumnya');
    }

    // ── BARU: cegah 1 kelompok punya lebih dari 1 ketua aktif ──
    if ($aksi === 'diterima' && $jadikanKetua) {
        $stmtKetua = $conn->prepare(
            "SELECT u.nama FROM anggota a
             JOIN users u ON u.id = a.user_id
             WHERE a.nama_kelompok = ? AND a.is_ketua = 1 AND a.status_verifikasi = 'diterima'
             LIMIT 1"
        );
        $stmtKetua->bind_param('s', $namaKelompok);
        $stmtKetua->execute();
        $resKetua = $stmtKetua->get_result();
        if ($resKetua->num_rows > 0) {
            $namaKetuaSekarang = $resKetua->fetch_assoc()['nama'];
            sendResponse(false,
                "Kelompok \"$namaKelompok\" sudah punya ketua: $namaKetuaSekarang. " .
                "Lepas status ketua yang lama dulu lewat menu Data Anggota sebelum " .
                "menetapkan ketua baru."
            );
        }
    }

    // Ambil nama buat disimpan di riwayat (bukan cuma ID) — dipakai kalau
    // nanti feed Riwayat Aktifitas mau nampilin kejadian ini juga.
    $stmtNama = $conn->prepare(
        "SELECT u.nama FROM anggota a
         JOIN users u ON u.id = a.user_id
         WHERE a.id = ? LIMIT 1"
    );
    $stmtNama->bind_param('i', $anggotaId);
    $stmtNama->execute();
    $resNama    = $stmtNama->get_result();
    $namaAnggota = $resNama->num_rows > 0 ? $resNama->fetch_assoc()['nama'] : 'Anggota';

    // ── BARU: is_ketua ikut di-update sekaligus. Cuma bernilai 1 kalau
    // aksi = diterima DAN admin mencentang "jadikan ketua". Kalau ditolak
    // atau tidak dicentang, tetap 0 (default).
    $isKetuaBaru = ($aksi === 'diterima' && $jadikanKetua) ? 1 : 0;

    $stmtUpdate = $conn->prepare(
        "UPDATE anggota SET status_verifikasi = ?, is_ketua = ? WHERE id = ?"
    );
    $stmtUpdate->bind_param('sii', $aksi, $isKetuaBaru, $anggotaId);
    $stmtUpdate->execute();

    // Kalau ditolak, langsung putus token akunnya juga (kalau kebetulan sedang login)
    if ($aksi === 'ditolak') {
        $stmtRevoke = $conn->prepare(
            "UPDATE users u JOIN anggota a ON a.user_id = u.id
             SET u.token = NULL WHERE a.id = ?"
        );
        $stmtRevoke->bind_param('i', $anggotaId);
        $stmtRevoke->execute();
    }

    $detail = "Anggota ID: {$anggotaId} - {$namaAnggota}" .
        ($isKetuaBaru === 1 ? " (ditetapkan sebagai KETUA kelompok)" : "") .
        ($keterangan !== '' ? " - {$keterangan}" : '');
    catatAktivitas($conn, $user['id'], $user['role'], 'Verifikasi Anggota',
        $aksi === 'diterima' ? 'Verifikasi diterima' : 'Verifikasi ditolak', $detail);

    sendResponse(true, $aksi === 'diterima'
        ? ($isKetuaBaru === 1
            ? 'Anggota berhasil diverifikasi sebagai KETUA kelompok dan bisa login'
            : 'Anggota berhasil diverifikasi dan bisa login')
        : 'Anggota ditolak dan tidak bisa login'
    );

} else {
    sendResponse(false, 'Method tidak diizinkan', null, 405);
}

$conn->close();