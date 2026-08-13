<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

setCORSHeaders();
$conn = getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = validateToken($conn); // semua role yang login boleh lihat

    $result = $conn->query(
        "SELECT nama_bank, no_rekening, atas_nama, keterangan
         FROM rekening_setoran ORDER BY updated_at DESC"
    );

    if (!$result || $result->num_rows === 0) {
        sendResponse(false, 'Rekening setoran belum diatur admin');
        exit;
    }

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    sendResponse(true, 'OK', $data);
    exit;
}

sendResponse(false, 'Method tidak diizinkan', null, 405);
$conn->close();