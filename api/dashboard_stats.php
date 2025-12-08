<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

header('Content-Type: application/json');

try {
    $conn = getConnection();
    
    // Get total sales (last 30 days)
    $salesQuery = "SELECT COALESCE(SUM(total), 0) as total_sales 
                  FROM transaksi 
                  WHERE status = 'selesai' 
                  AND tgl_transaksi >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    $salesResult = $conn->query($salesQuery);
    $totalSales = $salesResult->fetch_assoc()['total_sales'];
    
    // Get total orders (last 30 days)
    $ordersQuery = "SELECT COUNT(*) as total_orders 
                   FROM transaksi 
                   WHERE tgl_transaksi >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    $ordersResult = $conn->query($ordersQuery);
    $totalOrders = $ordersResult->fetch_assoc()['total_orders'];
    
    // Get low stock count - using a default value of 10 for stok_minimal
    $stockQuery = "SELECT COUNT(*) as low_stock_count 
                  FROM barang 
                  WHERE stok <= 10";  // Default value of 10 for stok_minimal
    $stockResult = $conn->query($stockQuery);
    $lowStockCount = $stockResult->fetch_assoc()['low_stock_count'];
    
    // Get active users (last 30 days)
    $usersQuery = "SELECT COUNT(DISTINCT user_id) as active_users 
                  FROM user_logins 
                  WHERE login_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    $usersResult = $conn->query($usersQuery);
    $activeUsers = $usersResult->fetch_assoc()['active_users'];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'total_sales' => (float)$totalSales,
            'total_orders' => (int)$totalOrders,
            'low_stock_count' => (int)$lowStockCount,
            'active_users' => (int)$activeUsers
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
