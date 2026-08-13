<?php
require_once '../config/database.php';
header('Content-Type: application/json');

$conn = getConnection();

// Cek semua tabel
$tables = [];
$result = $conn->query("SHOW TABLES");
while ($row = $result->fetch_row()) {
    $tables[] = $row[0];
}

// Cari tabel yang berkaitan proposal
$proposalTable = null;
foreach ($tables as $t) {
    if (strpos($t, 'proposal') !== false) {
        $proposalTable = $t;
    }
}

// Ambil data dari tabel proposal
$data = [];
if ($proposalTable) {
    $rows = $conn->query("SELECT * FROM `$proposalTable` ORDER BY id DESC LIMIT 5");
    while ($row = $rows->fetch_assoc()) {
        $data[] = $row;
    }
}

// Cek kolom tabel anggota
$kolomAnggota = [];
$ka = $conn->query("SHOW COLUMNS FROM anggota");
while ($k = $ka->fetch_assoc()) {
    $kolomAnggota[] = $k['Field'];
}

echo json_encode([
    'semua_tabel'     => $tables,
    'tabel_proposal'  => $proposalTable,
    'isi_proposal'    => $data,
    'kolom_anggota'   => $kolomAnggota,
], JSON_PRETTY_PRINT);