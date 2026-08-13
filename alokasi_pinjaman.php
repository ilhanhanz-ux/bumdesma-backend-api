<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

setCORSHeaders();
$conn = getConnection();

$user = validateToken($conn);
if (!$user) {
    sendResponse(false, 'Token tidak valid', null, 401);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Ketua & admin boleh lihat
    $kreditId = isset($_GET['kredit_id']) ? (int)$_GET['kredit_id'] : 0;
    if ($kreditId <= 0) {
        sendResponse(false, 'kredit_id wajib diisi', null, 400);
        exit;
    }

    // Ambil info kredit + nama kelompok pemilik kredit (ketua pengajunya)
    $stmt = $conn->prepare("
        SELECT k.id AS kredit_id, k.no_kredit, k.pokok_pinjaman, k.anggota_id AS ketua_anggota_id,
               a.nama_kelompok
        FROM kredit_aktif k
        JOIN anggota a ON a.id = k.anggota_id
        WHERE k.id = ?
    ");
    $stmt->bind_param('i', $kreditId);
    $stmt->execute();
    $kredit = $stmt->get_result()->fetch_assoc();

    if (!$kredit) {
        sendResponse(false, 'Kredit tidak ditemukan', null, 404);
        exit;
    }

    // Kalau yang akses ketua, pastikan dia memang ketua kelompok ini
    if ($user['role'] === 'anggota') {
        $cek = $conn->prepare("SELECT is_ketua, nama_kelompok FROM anggota WHERE user_id = ?");
        $cek->bind_param('i', $user['id']);
        $cek->execute();
        $me = $cek->get_result()->fetch_assoc();
        if (!$me || !$me['is_ketua'] || $me['nama_kelompok'] !== $kredit['nama_kelompok']) {
            sendResponse(false, 'Anda bukan ketua kelompok ini', null, 403);
            exit;
        }
    }

    // Terkunci kalau sudah ada porsi bulan manapun yang mulai disetor/diverifikasi
    // untuk kredit ini -- alokasi pokok tidak boleh berubah di tengah jalan
    $lock = $conn->prepare("
        SELECT COUNT(*) AS jml FROM angsuran_porsi ap
        JOIN angsuran an ON an.id = ap.angsuran_id
        WHERE an.kredit_id = ? AND ap.status_bayar != 'belum_bayar'
    ");
    $lock->bind_param('i', $kreditId);
    $lock->execute();
    $sudahFinal = $lock->get_result()->fetch_assoc()['jml'] > 0;

    // Semua anggota aktif di kelompok ini (termasuk ketua sendiri), plus alokasi yg sudah ada
    $anggota = $conn->prepare("
        SELECT a.id AS anggota_id, a.nama_lengkap,
               COALESCE(al.jumlah_pokok, 0) AS jumlah_pokok
        FROM anggota a
        LEFT JOIN alokasi_pinjaman_anggota al
               ON al.anggota_id = a.id AND al.kredit_id = ?
        WHERE a.nama_kelompok = ? AND a.status_aktif = 1
        ORDER BY a.is_ketua DESC, a.nama_lengkap ASC
    ");
    $anggota->bind_param('is', $kreditId, $kredit['nama_kelompok']);
    $anggota->execute();
    $daftarAnggota = $anggota->get_result()->fetch_all(MYSQLI_ASSOC);

    sendResponse(true, 'OK', [
        'kredit_id'       => (int)$kredit['kredit_id'],
        'no_kredit'       => $kredit['no_kredit'],
        'nama_kelompok'   => $kredit['nama_kelompok'],
        'pokok_pinjaman'  => (float)$kredit['pokok_pinjaman'],
        'sudah_final'     => $sudahFinal,
        'anggota'         => $daftarAnggota,
    ]);
    exit;
}

if ($method === 'POST') {
    if ($user['role'] !== 'anggota') {
        sendResponse(false, 'Hanya ketua yang boleh mengisi alokasi', null, 403);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    $kreditId = (int)($body['kredit_id'] ?? 0);
    $alokasi  = $body['alokasi'] ?? [];

    if ($kreditId <= 0 || empty($alokasi)) {
        sendResponse(false, 'Data tidak lengkap', null, 400);
        exit;
    }

    // Verifikasi ketua & ambil pokok_pinjaman kredit
    $cek = $conn->prepare("
        SELECT k.pokok_pinjaman, a.nama_kelompok
        FROM kredit_aktif k JOIN anggota a ON a.id = k.anggota_id
        WHERE k.id = ?
    ");
    $cek->bind_param('i', $kreditId);
    $cek->execute();
    $kredit = $cek->get_result()->fetch_assoc();
    if (!$kredit) { sendResponse(false, 'Kredit tidak ditemukan', null, 404); exit; }

    $meStmt = $conn->prepare("SELECT id, is_ketua, nama_kelompok FROM anggota WHERE user_id = ?");
    $meStmt->bind_param('i', $user['id']);
    $meStmt->execute();
    $me = $meStmt->get_result()->fetch_assoc();
    if (!$me || !$me['is_ketua'] || $me['nama_kelompok'] !== $kredit['nama_kelompok']) {
        sendResponse(false, 'Anda bukan ketua kelompok ini', null, 403);
        exit;
    }

    // Lock check sama seperti di GET
    $lock = $conn->prepare("
        SELECT COUNT(*) AS jml FROM angsuran_porsi ap
        JOIN angsuran an ON an.id = ap.angsuran_id
        WHERE an.kredit_id = ? AND ap.status_bayar != 'belum_bayar'
    ");
    $lock->bind_param('i', $kreditId);
    $lock->execute();
    if ($lock->get_result()->fetch_assoc()['jml'] > 0) {
        sendResponse(false, 'Alokasi sudah dikunci karena sudah ada setoran berjalan', null, 409);
        exit;
    }

    // Validasi total harus pas dengan pokok_pinjaman
    $total = 0;
    foreach ($alokasi as $a) {
        if (($a['jumlah_pokok'] ?? 0) <= 0) {
            sendResponse(false, 'Nominal tiap anggota harus lebih dari 0', null, 400);
            exit;
        }
        $total += (float)$a['jumlah_pokok'];
    }
    if (abs($total - (float)$kredit['pokok_pinjaman']) >= 1) {
        sendResponse(false, 'Total alokasi (' . $total . ') harus sama dengan pokok pinjaman (' . $kredit['pokok_pinjaman'] . ')', null, 400);
        exit;
    }

    $conn->begin_transaction();
    try {
        $del = $conn->prepare("DELETE FROM alokasi_pinjaman_anggota WHERE kredit_id = ?");
        $del->bind_param('i', $kreditId);
        $del->execute();

        $ins = $conn->prepare("
            INSERT INTO alokasi_pinjaman_anggota (kredit_id, anggota_id, jumlah_pokok, dibuat_oleh)
            VALUES (?, ?, ?, ?)
        ");
        foreach ($alokasi as $a) {
            $anggotaId = (int)$a['anggota_id'];
            $jumlah = (float)$a['jumlah_pokok'];
            $ins->bind_param('iidi', $kreditId, $anggotaId, $jumlah, $me['id']);
            $ins->execute();
        }

        // ── Sinkronisasi angsuran_porsi dengan alokasi pokok yang baru ──
        // Cari semua angsuran milik kredit ini yang SEMUA porsinya masih
        // 'belum_bayar' (belum ada setoran/verifikasi berjalan) -- aman dihitung ulang.
        // Angsuran yang sudah mulai diproses (ada porsi menunggu_verifikasi/
        // sudah_bayar/ditolak) TIDAK disentuh, sesuai aturan kunci yang sudah ada.
        $angsuranAmanStmt = $conn->prepare("
            SELECT an.id, an.total_bayar
            FROM angsuran an
            WHERE an.kredit_id = ?
              AND NOT EXISTS (
                  SELECT 1 FROM angsuran_porsi ap
                  WHERE ap.angsuran_id = an.id AND ap.status_bayar != 'belum_bayar'
              )
        ");
        $angsuranAmanStmt->bind_param('i', $kreditId);
        $angsuranAmanStmt->execute();
        $daftarAngsuranAman = $angsuranAmanStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $delPorsi = $conn->prepare("DELETE FROM angsuran_porsi WHERE angsuran_id = ?");
        $insPorsi = $conn->prepare("
            INSERT INTO angsuran_porsi (angsuran_id, anggota_id, jumlah_porsi, status_bayar)
            VALUES (?, ?, ?, 'belum_bayar')
        ");

        foreach ($daftarAngsuranAman as $ang) {
            $angsuranId = (int)$ang['id'];
            $totalBayar = (float)$ang['total_bayar'];

            $delPorsi->bind_param('i', $angsuranId);
            $delPorsi->execute();

            // Bagi rata sesuai rasio alokasi pokok yang baru; anggota terakhir
            // menampung sisa pembulatan supaya totalnya presisi ke total_bayar.
            $sisaBayar = $totalBayar;
            $n = count($alokasi);
            $i = 0;
            foreach ($alokasi as $a) {
                $i++;
                $anggotaId = (int)$a['anggota_id'];
                $rasio = (float)$a['jumlah_pokok'] / $total;
                $jumlahPorsi = ($i === $n) ? round($sisaBayar, 2) : round($totalBayar * $rasio, 2);
                $sisaBayar -= $jumlahPorsi;

                $insPorsi->bind_param('iid', $angsuranId, $anggotaId, $jumlahPorsi);
                $insPorsi->execute();
            }
        }
        // ── akhir sinkronisasi angsuran_porsi ──

        $conn->commit();
        try {
            if (function_exists('catatAktivitas')) {
                catatAktivitas($conn, $user['id'], 'alokasi_pinjaman',
                    "Ketua mengalokasikan pokok pinjaman kredit #$kreditId ke " . count($alokasi) . " anggota");
            }
        } catch (\Throwable $e) {
            // Logging aktivitas gagal tidak boleh menggagalkan response utama
        }

        sendResponse(true, 'Alokasi pinjaman berhasil disimpan', null);
    } catch (Exception $e) {
        $conn->rollback();
        sendResponse(false, 'Gagal menyimpan: ' . $e->getMessage(), null, 500);
    }
    exit;
}

sendResponse(false, 'Method tidak didukung', null, 405);