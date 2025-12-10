<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['id'])) {
    $conn = getConnection();
    
    // Sanitize input
    $id = (int)$_POST['id'];
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
    
    // Check if customer with the same name already exists (excluding current record)
    $check = $conn->query("SELECT id FROM konsumen WHERE nama_konsumen = '$nama_konsumen' AND id != $id");
    if ($check->num_rows > 0) {
        $_SESSION['error'] = "Konsumen dengan nama '$nama_konsumen' sudah ada";
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit();
    }
    
    // Update database
    $query = "UPDATE konsumen SET 
              nama_konsumen = ?, 
              perusahaan = ?, 
              alamat = ?, 
              no_hp = ?, 
              email = ?,
              updated_at = NOW()
              WHERE id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sssssi", $nama_konsumen, $perusahaan, $alamat, $no_hp, $email, $id);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Data konsumen berhasil diperbarui";
    } else {
        $_SESSION['error'] = "Gagal memperbarui data konsumen: " . $conn->error;
    }
    
    $stmt->close();
    $conn->close();
    
    header("Location: ../data_konsumen.php");
    exit();
} else {
    header("Location: ../data_konsumen.php");
    exit();
}
