<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

setCORSHeaders();

$conn = getConnection();

// ── GET: List pengumuman (semua role bisa baca) ──────
// Query optional: ?limit=3  → dipakai dashboard anggota untuk preview singkat
// Query optional: ?id=5     → detail 1 pengumuman
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = validateToken($conn);

    if (isset($_GET['id'])) {
        $id = intval($_GET['id']);

        $stmt = $conn->prepare(
            "SELECT p.*, a.username AS nama_admin
             FROM pengumuman p
             LEFT JOIN admin a ON a.id = p.admin_id
             WHERE p.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            sendResponse(false, 'Pengumuman tidak ditemukan');
            exit;
        }

        $data = $result->fetch_assoc();
        sendResponse(true, 'OK', [
            'id'         => $data['id'],
            'judul'      => $data['judul'],
            'isi'        => $data['isi'],
            'tanggal'    => $data['tanggal'],
            'admin_id'   => $data['admin_id'],
            'created_at' => $data['created_at'],
        ]);
        exit;
    }

    $sql = "SELECT * FROM pengumuman ORDER BY tanggal DESC, id DESC";

    if (isset($_GET['limit'])) {
        $limit = intval($_GET['limit']);
        if ($limit > 0) {
            $sql .= " LIMIT " . $limit;
        }
    }

    $result = $conn->query($sql);

    if ($result === false) {
        sendResponse(false, 'Query error: ' . $conn->error);
        exit;
    }

    $list = [];
    while ($row = $result->fetch_assoc()) {
        $list[] = [
            'id'         => $row['id'],
            'judul'      => $row['judul'],
            'isi'        => $row['isi'],
            'tanggal'    => $row['tanggal'],
            'admin_id'   => $row['admin_id'],
            'created_at' => $row['created_at'],
        ];
    }

    sendResponse(true, 'OK', $list);
    exit;
}

// ── POST: Buat pengumuman baru (Admin) ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = validateToken($conn);
    requireRole($user, 'admin');

    $data = json_decode(file_get_contents('php://input'), true);

    $judul   = trim($data['judul']   ?? '');
    $isi     = trim($data['isi']     ?? '');
    $tanggal = trim($data['tanggal'] ?? date('Y-m-d'));

    if (empty($judul)) {
        sendResponse(false, 'Judul wajib diisi');
        exit;
    }
    if (empty($isi)) {
        sendResponse(false, 'Isi pengumuman wajib diisi');
        exit;
    }

    $stmt = $conn->prepare(
        "INSERT INTO pengumuman (judul, isi, tanggal, admin_id) VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param('sssi', $judul, $isi, $tanggal, $user['id']);

    if (!$stmt->execute()) {
        sendResponse(false, 'Gagal simpan: ' . $stmt->error);
        exit;
    }

    sendResponse(true, 'Pengumuman berhasil dibuat', ['id' => $conn->insert_id]);
    exit;
}

// ── PUT: Edit pengumuman (Admin) ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $user = validateToken($conn);
    requireRole($user, 'admin');

    $id   = intval($_GET['id'] ?? 0);
    $data = json_decode(file_get_contents('php://input'), true);

    $judul   = trim($data['judul']   ?? '');
    $isi     = trim($data['isi']     ?? '');
    $tanggal = trim($data['tanggal'] ?? '');

    if (!$id || empty($judul) || empty($isi) || empty($tanggal)) {
        sendResponse(false, 'Data tidak lengkap');
        exit;
    }

    $stmt = $conn->prepare(
        "UPDATE pengumuman SET judul = ?, isi = ?, tanggal = ? WHERE id = ?"
    );
    $stmt->bind_param('sssi', $judul, $isi, $tanggal, $id);

    if (!$stmt->execute()) {
        sendResponse(false, 'Gagal update: ' . $stmt->error);
        exit;
    }

    sendResponse(true, 'Pengumuman berhasil diperbarui');
    exit;
}

// ── DELETE: Hapus pengumuman (Admin) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $user = validateToken($conn);
    requireRole($user, 'admin');

    $id = intval($_GET['id'] ?? 0);
    if (!$id) {
        sendResponse(false, 'ID tidak valid');
        exit;
    }

    $stmt = $conn->prepare("DELETE FROM pengumuman WHERE id = ?");
    $stmt->bind_param('i', $id);

    if (!$stmt->execute()) {
        sendResponse(false, 'Gagal hapus: ' . $stmt->error);
        exit;
    }

    sendResponse(true, 'Pengumuman berhasil dihapus');
    exit;
}

sendResponse(false, 'Method tidak diizinkan', null, 405);
$conn->close();