<?php
// process_order.php - Helper file untuk memproses order
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/config.php';
requireLogin();

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['order_id']) || !isset($input['action'])) {
        throw new Exception('Parameter tidak lengkap');
    }
    
    $orderId = intval($input['order_id']);
    $action = $input['action'];
    
    // Get database connection
    $conn = getConnection();
    
    if ($conn->connect_error) {
        throw new Exception("Koneksi database gagal: " . $conn->connect_error);
    }
    
    // Update order status based on action
    if ($action === 'complete') {
        $newStatus = 'selesai';
    } elseif ($action === 'cancel') {
        $newStatus = 'dibatalkan';
    } else {
        throw new Exception('Action tidak valid');
    }
    
    // Prepare statement
    $stmt = $conn->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?");
    
    if (!$stmt) {
        throw new Exception("Gagal menyiapkan statement: " . $conn->error);
    }
    
    $stmt->bind_param("si", $newStatus, $orderId);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $response['success'] = true;
            $response['message'] = "Order berhasil diupdate menjadi status: $newStatus";
        } else {
            throw new Exception("Order tidak ditemukan atau tidak ada perubahan");
        }
    } else {
        throw new Exception("Gagal mengeksekusi query: " . $stmt->error);
    }
    
    $stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log("Error in process_order.php: " . $e->getMessage());
}

echo json_encode($response);
?>