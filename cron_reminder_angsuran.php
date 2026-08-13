<?php
// api/cron_reminder_angsuran.php
//
// Reminder WA H-3 sebelum jatuh tempo angsuran:
//   - Ketua kelompok dapat 1 pesan ringkasan (total tagihan + rincian
//     status porsi tiap anggota).
//   - Tiap anggota yang porsinya belum lunas dapat 1 pesan personal
//     (nominal porsi dia sendiri).
//
// Bisa dipicu dua cara:
//   1) CLI, kalau ternyata ada akses Cron Jobs di panel hosting:
//        php /path/lengkap/ke/api/cron_reminder_angsuran.php
//   2) HTTP, lewat layanan cron eksternal (cron-job.org dst) -- WAJIB
//      sertakan ?key=SECRET yang cocok dengan REMINDER_CRON_SECRET
//      di config/database.php, contoh:
//        https://domainkamu.com/api/cron_reminder_angsuran.php?key=xxxxx
//
// Aman dipicu berkali-kali dalam sehari -- kolom reminder_terkirim_at
// di tabel angsuran & angsuran_porsi mencegah pesan yang sama
// terkirim dua kali.

$isCli = (php_sapi_name() === 'cli');
$secretKey = $isCli ? null : ($_GET['key'] ?? '');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if (!$isCli) {
    header('Content-Type: application/json');
    if (!defined('REMINDER_CRON_SECRET') || $secretKey !== REMINDER_CRON_SECRET) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

$conn = getConnection();

$ringkasan = [
    'angsuran_diproses'   => 0,
    'wa_ketua_terkirim'   => 0,
    'wa_ketua_gagal'      => 0,
    'wa_anggota_terkirim' => 0,
    'wa_anggota_gagal'    => 0,
];

function fmtRupiah(float $n): string {
    return 'Rp ' . number_format($n, 0, ',', '.');
}

function fmtTanggalIndo(string $tgl): string {
    $bulanIndo = [
        '01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr',
        '05' => 'Mei', '06' => 'Jun', '07' => 'Jul', '08' => 'Agu',
        '09' => 'Sep', '10' => 'Okt', '11' => 'Nov', '12' => 'Des',
    ];
    $bagian = explode('-', $tgl);
    if (count($bagian) !== 3) return $tgl;
    [$y, $m, $d] = $bagian;
    return ((int)$d) . ' ' . ($bulanIndo[$m] ?? $m) . ' ' . $y;
}

// ── Ambil semua angsuran yang jatuh tempo persis 3 hari lagi,
//    belum lunas, dan belum pernah direminder ──
$stmtDue = $conn->prepare(
    "SELECT ang.id AS angsuran_id, ang.kredit_id, ang.total_bayar,
            ang.tanggal_jatuh_tempo, k.no_kredit, k.anggota_id AS ketua_anggota_id
     FROM angsuran ang
     JOIN kredit_aktif k ON k.id = ang.kredit_id
     WHERE ang.status_bayar != 'sudah_bayar'
       AND ang.tanggal_jatuh_tempo = DATE_ADD(CURDATE(), INTERVAL 3 DAY)
       AND ang.reminder_terkirim_at IS NULL"
);
$stmtDue->execute();
$resDue = $stmtDue->get_result();

$daftarDue = [];
while ($row = $resDue->fetch_assoc()) {
    $daftarDue[] = $row;
}

foreach ($daftarDue as $due) {
    $ringkasan['angsuran_diproses']++;

    $angsuranId = (int)$due['angsuran_id'];
    $ketuaId    = (int)$due['ketua_anggota_id'];
    $tanggalJT  = fmtTanggalIndo($due['tanggal_jatuh_tempo']);
    $totalBayar = (float)$due['total_bayar'];

    // Data ketua kelompok
    $stmtKetua = $conn->prepare(
        "SELECT a.nama_lengkap, a.nama_kelompok, u.no_telepon
         FROM anggota a JOIN users u ON u.id = a.user_id
         WHERE a.id = ? LIMIT 1"
    );
    $stmtKetua->bind_param('i', $ketuaId);
    $stmtKetua->execute();
    $ketua = $stmtKetua->get_result()->fetch_assoc();
    if (!$ketua) {
        continue; // data ketua tidak ditemukan/rusak, lewati baris ini
    }

    // Semua porsi untuk angsuran ini (termasuk yang sudah lunas,
    // supaya ringkasan ke ketua menampilkan status lengkap)
    $stmtPorsi = $conn->prepare(
        "SELECT ap.id AS porsi_id, ap.anggota_id, ap.jumlah_porsi,
                ap.status_bayar, ap.reminder_terkirim_at,
                a.nama_lengkap, u.no_telepon
         FROM angsuran_porsi ap
         JOIN anggota a ON a.id = ap.anggota_id
         JOIN users u ON u.id = a.user_id
         WHERE ap.angsuran_id = ?
         ORDER BY a.nama_lengkap ASC"
    );
    $stmtPorsi->bind_param('i', $angsuranId);
    $stmtPorsi->execute();
    $resPorsi = $stmtPorsi->get_result();

    $daftarPorsi = [];
    while ($p = $resPorsi->fetch_assoc()) {
        $daftarPorsi[] = $p;
    }

    // ── Susun & kirim pesan ke KETUA (ringkasan semua anggota) ──
    $rincian = '';
    $no = 1;
    foreach ($daftarPorsi as $p) {
        $status = $p['status_bayar'] === 'sudah_bayar' ? '✅ Sudah' : '⏳ Belum';
        $rincian .= $no . '. ' . $p['nama_lengkap'] . ' - '
            . fmtRupiah((float)$p['jumlah_porsi']) . ' - ' . $status . "\n";
        $no++;
    }

    $pesanKetua =
        "⚠️ *Pengingat Setoran SPP BUMDesma*\n\n" .
        "Halo {$ketua['nama_lengkap']},\n" .
        "Tagihan angsuran kelompok *{$ketua['nama_kelompok']}* (No. Kredit: {$due['no_kredit']}) " .
        "akan jatuh tempo pada *{$tanggalJT}* (3 hari lagi).\n\n" .
        "Total tagihan: *" . fmtRupiah($totalBayar) . "*\n\n" .
        "Rincian porsi tiap anggota:\n{$rincian}\n" .
        "Mohon segera kumpulkan setoran dari anggota yang belum menyetor, " .
        "lalu lakukan setoran kolektif ke admin sebelum tanggal jatuh tempo. Terima kasih 🙏";

    if (!empty($ketua['no_telepon'])) {
        $hasilKetua = kirimWhatsapp($ketua['no_telepon'], $pesanKetua);
        if ($hasilKetua['success']) {
            $ringkasan['wa_ketua_terkirim']++;
        } else {
            $ringkasan['wa_ketua_gagal']++;
            error_log("Gagal kirim WA ketua (angsuran_id={$angsuranId}): " . $hasilKetua['raw']);
        }
    }

    // Tandai reminder level-ketua untuk angsuran ini sudah diproses, apapun
    // hasil kirimnya -- supaya cron besok tidak coba kirim ulang terus kalau
    // gagal (misal device Fonnte terputus). Kalau mau retry otomatis besok,
    // pindahkan baris UPDATE ini ke dalam blok "if ($hasilKetua['success'])".
    $stmtTandaiAngsuran = $conn->prepare(
        "UPDATE angsuran SET reminder_terkirim_at = NOW() WHERE id = ?"
    );
    $stmtTandaiAngsuran->bind_param('i', $angsuranId);
    $stmtTandaiAngsuran->execute();

    // ── Kirim pesan personal ke tiap ANGGOTA yang porsinya belum lunas
    //    dan belum pernah direminder ──
    foreach ($daftarPorsi as $p) {
        if ($p['status_bayar'] === 'sudah_bayar') continue;
        if ($p['reminder_terkirim_at'] !== null) continue;
        if (empty($p['no_telepon'])) continue;

        $pesanAnggota =
            "⚠️ *Pengingat Setoran SPP BUMDesma*\n\n" .
            "Halo {$p['nama_lengkap']},\n" .
            "Tagihan angsuran kelompok *{$ketua['nama_kelompok']}* akan jatuh tempo " .
            "pada *{$tanggalJT}* (3 hari lagi).\n\n" .
            "Porsi kamu: *" . fmtRupiah((float)$p['jumlah_porsi']) . "*\n\n" .
            "Segera kumpulkan setoran ke Ketua Kelompok ({$ketua['nama_lengkap']}) " .
            "sebelum tanggal jatuh tempo ya. Terima kasih 🙏";

        $hasilAnggota = kirimWhatsapp($p['no_telepon'], $pesanAnggota);

        $porsiId = (int)$p['porsi_id'];
        $stmtTandaiPorsi = $conn->prepare(
            "UPDATE angsuran_porsi SET reminder_terkirim_at = NOW() WHERE id = ?"
        );
        $stmtTandaiPorsi->bind_param('i', $porsiId);
        $stmtTandaiPorsi->execute();

        if ($hasilAnggota['success']) {
            $ringkasan['wa_anggota_terkirim']++;
        } else {
            $ringkasan['wa_anggota_gagal']++;
            error_log("Gagal kirim WA anggota id={$p['anggota_id']} (angsuran_id={$angsuranId}): " . $hasilAnggota['raw']);
        }

        // Delay kecil antar pesan supaya tidak terkesan spam ke Fonnte/WhatsApp
        usleep(1500000); // 1.5 detik
    }
}

$conn->close();

$output = json_encode(['success' => true, 'message' => 'Selesai', 'data' => $ringkasan], JSON_PRETTY_PRINT);

if ($isCli) {
    echo $output . PHP_EOL;
} else {
    echo $output;
}