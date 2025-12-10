<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = getConnection();
    
    // Sanitize input
    $nama_konsumen = $conn->real_escape_string(trim($_POST['nama_konsumen']));
    $perusahaan = isset($_POST['perusahaan']) ? $conn->real_escape_string(trim($_POST['perusahaan'])) : null;
    $alamat = isset($_POST['alamat']) ? $conn->real_escape_string(trim($_POST['alamat'])) : null;
    $no_hp = isset($_POST['no_hp']) ? $conn->real_escape_string(trim($_POST['no_hp'])) : null;
    $email = isset($_POST['email']) ? $conn->real_escape_string(trim($_POST['email'])) : null;
    
    // Basic validation
    if (empty($nama_konsumen)) {
        $_SESSION['error'] = "Nama konsumen harus diisi";
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit();
    }
    
    // Check if customer with the same name already exists
    $check = $conn->query("SELECT id FROM konsumen WHERE nama_konsumen = '$nama_konsumen'");
    if ($check->num_rows > 0) {
        $_SESSION['error'] = "Konsumen dengan nama '$nama_konsumen' sudah ada";
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit();
    }
    
    // Insert into database
    $query = "INSERT INTO konsumen (nama_konsumen, perusahaan, alamat, no_hp, email) 
              VALUES (?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sssss", $nama_konsumen, $perusahaan, $alamat, $no_hp, $email);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Data konsumen berhasil ditambahkan";
    } else {
        $_SESSION['error'] = "Gagal menambahkan data konsumen: " . $conn->error;
    }
    
    $stmt->close();
    $conn->close();
    
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit();
} else {
    header("Location: ../data_konsumen.php");
    exit();
}
