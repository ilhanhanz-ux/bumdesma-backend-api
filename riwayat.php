<?php
// ============================================================
//  api/riwayat.php
//  Endpoint terpusat untuk semua riwayat (mendukung pull-to-refresh)
//
//  GET /api/riwayat?type=proposal&id=N      → timeline 1 proposal
//  GET /api/riwayat?type=angsuran&kredit_id=N → timeline pembayaran 1 kredit
//  GET /api/riwayat?type=aktivitas          → log global (Admin)
//  GET /api/riwayat?type=ringkasan          → semua riwayat milik anggota login
//  GET /api/riwayat?type=terbaru&limit=10   → 10 aktivitas terbaru (dashboard)
//
//  Parameter tambahan:
//    since=2024-06-01T10:00:00   → hanya data setelah waktu ini (polling efisien)
//    limit=N                     → maksimum N baris (default 50)
// ============================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

setCorsHeaders();

$conn     = getConnection();
$user     = validateToken($conn);
$type     = $_GET['type']     ?? 'terbaru';
$id       = isset($_GET['id'])        ? (int)$_GET['id']        : null;
$kreditId = isset($_GET['kredit_id']) ? (int)$_GET['kredit_id'] : null;
$since    = $_GET['since']    ?? '';      // ISO timestamp untuk incremental polling
$limit    = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;

// Bangun klausa WHERE untuk "since" (pull-to-refresh hanya ambil yang baru)
$sinceClause = '';
$sinceParam  = [];
$sinceTypes  = '';
if (!empty($since) && strtotime($since)) {
    $sinceClause = " AND created_at > ?";
    $sinceParam  = [$since];
    $sinceTypes  = 's';
}

switch ($type) {

    // =========================================================
    // Timeline riwayat 1 proposal (Anggota & Admin)
    // =========================================================
    case 'proposal':
        if (!$id) sendResponse(false, 'ID proposal wajib diisi.', null, 400);

        // Validasi akses
        if ($user['role'] === 'anggota') {
            $chk = $conn->prepare(
                "SELECT pp.id FROM pengajuan_proposal pp
                 JOIN anggota a ON a.id = pp.anggota_id
                 WHERE pp.id = ? AND a.user_id = ?"
            );
            $chk->bind_param('ii', $id, $user['id']);
            $chk->execute();
            if ($chk->get_result()->num_rows === 0) {
                sendResponse(false, 'Akses ditolak.', null, 403);
            }
        }

        $sql = "
            SELECT rp.id, rp.aksi, rp.status_sebelum, rp.status_sesudah,
                   rp.keterangan, rp.role_pelaku, rp.created_at,
                   u.username AS nama_pelaku,
                   CASE WHEN rp.dok_baru != '' AND rp.dok_baru IS NOT NULL
                        THEN CONCAT('" . UPLOAD_URL . "', rp.dok_baru)
                        ELSE NULL END AS dok_url
            FROM riwayat_proposal rp
            JOIN users u ON u.id = rp.dilakukan_oleh
            WHERE rp.proposal_id = ? $sinceClause
            ORDER BY rp.created_at DESC
            LIMIT $limit
        ";
        $stmt = $conn->prepare($sql);

        if (!empty($sinceParam)) {
            $stmt->bind_param('i' . $sinceTypes, $id, ...$sinceParam);
        } else {
            $stmt->bind_param('i', $id);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        sendResponse(true, count($rows) . ' riwayat proposal.', [
            'proposal_id'  => $id,
            'jumlah'       => count($rows),
            'server_time'  => date('Y-m-d\TH:i:s'),   // untuk next polling
            'riwayat'      => $rows,
        ]);
        break;

    // =========================================================
    // Timeline riwayat pembayaran 1 kredit (Anggota & Admin)
    // =========================================================
    case 'angsuran':
        if (!$kreditId) sendResponse(false, 'kredit_id wajib diisi.', null, 400);

        if ($user['role'] === 'anggota') {
            $chk = $conn->prepare(
                "SELECT k.id FROM kredit_aktif k JOIN anggota a ON a.id = k.anggota_id
                 WHERE k.id = ? AND a.user_id = ?"
            );
            $chk->bind_param('ii', $kreditId, $user['id']);
            $chk->execute();
            if ($chk->get_result()->num_rows === 0) {
                sendResponse(false, 'Akses ditolak.', null, 403);
            }
        }

        $sql = "
            SELECT ra.id, ra.aksi, ra.jumlah_bayar, ra.denda,
                   ra.status_sebelum, ra.status_sesudah, ra.keterangan,
                   ra.role_pelaku, ra.created_at,
                   u.username AS nama_pelaku,
                   CASE WHEN ra.bukti_bayar != '' AND ra.bukti_bayar IS NOT NULL
                        THEN CONCAT('" . UPLOAD_URL . "', ra.bukti_bayar)
                        ELSE NULL END AS bukti_url
            FROM riwayat_angsuran ra
            JOIN users u ON u.id = ra.dilakukan_oleh
            WHERE ra.kredit_id = ? $sinceClause
            ORDER BY ra.created_at DESC
            LIMIT $limit
        ";
        $stmt = $conn->prepare($sql);
        if (!empty($sinceParam)) {
            $stmt->bind_param('i' . $sinceTypes, $kreditId, ...$sinceParam);
        } else {
            $stmt->bind_param('i', $kreditId);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        sendResponse(true, count($rows) . ' riwayat pembayaran.', [
            'kredit_id'   => $kreditId,
            'jumlah'      => count($rows),
            'server_time' => date('Y-m-d\TH:i:s'),
            'riwayat'     => $rows,
        ]);
        break;

    // =========================================================
    // Log aktivitas global — hanya Admin
    // =========================================================
    case 'aktivitas':
        requireRole($user, 'admin');

        $modul = $_GET['modul'] ?? '';
        $sql   = "
            SELECT ra.id, ra.modul, ra.aksi, ra.detail, ra.ip_address,
                   ra.role_pelaku, ra.created_at, u.username
            FROM riwayat_aktivitas ra
            JOIN users u ON u.id = ra.user_id
            WHERE 1=1
        ";
        $params = []; $types = '';

        if (!empty($modul)) {
            $sql .= " AND ra.modul = ?"; $params[] = $modul; $types .= 's';
        }
        if (!empty($sinceParam)) {
            $sql .= $sinceClause; $params[] = $sinceParam[0]; $types .= 's';
        }

        $sql .= " ORDER BY ra.created_at DESC LIMIT $limit";
        $stmt = $conn->prepare($sql);
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        sendResponse(true, count($rows) . ' log aktivitas.', [
            'jumlah'      => count($rows),
            'server_time' => date('Y-m-d\TH:i:s'),
            'aktivitas'   => $rows,
        ]);
        break;

    // =========================================================
    // Ringkasan semua riwayat milik anggota yang login
    // =========================================================
    case 'ringkasan':
        // Ambil anggota_id
        $stmtA = $conn->prepare(
            "SELECT id FROM anggota WHERE user_id = ? LIMIT 1"
        );
        $stmtA->bind_param('i', $user['id']);
        $stmtA->execute();
        $anggota = $stmtA->get_result()->fetch_assoc();

        if (!$anggota) sendResponse(false, 'Data anggota tidak ditemukan.', null, 404);
        $aId = (int)$anggota['id'];

        // Riwayat proposal
        $stmtP = $conn->prepare("
            SELECT rp.aksi, rp.status_sesudah, rp.keterangan, rp.created_at,
                   'proposal' AS jenis, rp.role_pelaku
            FROM riwayat_proposal rp
            WHERE rp.anggota_id = ? $sinceClause
            ORDER BY rp.created_at DESC LIMIT 20
        ");
        if (!empty($sinceParam)) {
            $stmtP->bind_param('i' . $sinceTypes, $aId, ...$sinceParam);
        } else {
            $stmtP->bind_param('i', $aId);
        }
        $stmtP->execute();
        $riwayatProposal = $stmtP->get_result()->fetch_all(MYSQLI_ASSOC);

        // Riwayat angsuran
        $stmtAng = $conn->prepare("
            SELECT ra.aksi, ra.jumlah_bayar, ra.status_sesudah,
                   ra.keterangan, ra.created_at, 'angsuran' AS jenis,
                   ra.role_pelaku
            FROM riwayat_angsuran ra
            WHERE ra.anggota_id = ? $sinceClause
            ORDER BY ra.created_at DESC LIMIT 20
        ");
        if (!empty($sinceParam)) {
            $stmtAng->bind_param('i' . $sinceTypes, $aId, ...$sinceParam);
        } else {
            $stmtAng->bind_param('i', $aId);
        }
        $stmtAng->execute();
        $riwayatAngsuran = $stmtAng->get_result()->fetch_all(MYSQLI_ASSOC);

        // Gabungkan dan urutkan berdasarkan waktu
        $gabungan = array_merge($riwayatProposal, $riwayatAngsuran);
        usort($gabungan, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
        $gabungan = array_slice($gabungan, 0, $limit);

        sendResponse(true, 'Riwayat anggota berhasil diambil.', [
            'anggota_id'  => $aId,
            'jumlah'      => count($gabungan),
            'server_time' => date('Y-m-d\TH:i:s'),
            'riwayat'     => $gabungan,
        ]);
        break;

    // =========================================================
    // 10 aktivitas terbaru — untuk notif dashboard
    // =========================================================
    case 'terbaru':
        if ($user['role'] === 'admin') {
            $stmt = $conn->prepare("
                SELECT ra.modul, ra.aksi, ra.created_at, u.username
                FROM riwayat_aktivitas ra
                JOIN users u ON u.id = ra.user_id
                ORDER BY ra.created_at DESC LIMIT $limit
            ");
            $stmt->execute();
        } else {
            $stmtA = $conn->prepare("SELECT id FROM anggota WHERE user_id = ? LIMIT 1");
            $stmtA->bind_param('i', $user['id']);
            $stmtA->execute();
            $aRow = $stmtA->get_result()->fetch_assoc();
            $aId  = $aRow ? (int)$aRow['id'] : 0;

            $stmt = $conn->prepare("
                (SELECT aksi, 'proposal' AS modul, created_at FROM riwayat_proposal WHERE anggota_id = ?)
                UNION ALL
                (SELECT aksi, 'angsuran' AS modul, created_at FROM riwayat_angsuran WHERE anggota_id = ?)
                ORDER BY created_at DESC LIMIT $limit
            ");
            $stmt->bind_param('ii', $aId, $aId);
        }

        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        sendResponse(true, 'Aktivitas terbaru.', [
            'jumlah'      => count($rows),
            'server_time' => date('Y-m-d\TH:i:s'),
            'aktivitas'   => $rows,
        ]);
        break;

    default:
        sendResponse(false, "Tipe riwayat '$type' tidak dikenali.", null, 400);
}

$conn->close();
