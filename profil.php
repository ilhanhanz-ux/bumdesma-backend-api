<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

setCORSHeaders();
$conn = getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = validateToken($conn);

    if ($user['role'] !== 'anggota') {
        sendResponse(false, 'Endpoint ini khusus untuk anggota', null, 403);
        exit;
    }

    $stmt = $conn->prepare(
        "SELECT nik, nama_lengkap, tempat_lahir, tanggal_lahir, jenis_kelamin,
                alamat, no_telepon, nama_kelompok, nama_desa, status_aktif
         FROM anggota WHERE user_id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        sendResponse(false, 'Data anggota tidak ditemukan');
        exit;
    }

    $row = $result->fetch_assoc();
    $data = [
        'nik'           => $row['nik'],
        'nama_lengkap'  => $row['nama_lengkap'],
        'tempat_lahir'  => $row['tempat_lahir'],
        'tanggal_lahir' => $row['tanggal_lahir'],
        'jenis_kelamin' => $row['jenis_kelamin'] === 'L' ? 'Laki-laki' : 'Perempuan',
        'alamat'        => $row['alamat'],
        'no_telepon'    => $row['no_telepon'],
        'nama_kelompok' => $row['nama_kelompok'],
        'nama_desa'     => $row['nama_desa'],
        'status_aktif'  => (bool)$row['status_aktif'],
    ];

    sendResponse(true, 'OK', $data);
    exit;
}

sendResponse(false, 'Method tidak diizinkan', null, 405);
$conn->close();