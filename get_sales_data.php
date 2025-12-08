<?php
require_once __DIR__ . '/config/config.php';

header('Content-Type: application/json');

try {
    $conn = getConnection();
    
    // Get sales data for the last 7 days
    $query = "
        SELECT 
            DATE(tgl_transaksi) as date,
            COALESCE(SUM(total), 0) as total_sales
        FROM transaksi
        WHERE tgl_transaksi >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        AND status = 'selesai'
        GROUP BY DATE(tgl_transaksi)
        ORDER BY date ASC
    ";
    
    $result = $conn->query($query);
    $data = [
        'labels' => [],
        'values' => []
    ];
    
    // Initialize last 7 days
    $dates = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $dates[$date] = 0;
    }
    
    // Fill in sales data
    while ($row = $result->fetch_assoc()) {
        $date = date('Y-m-d', strtotime($row['date']));
        if (isset($dates[$date])) {
            $dates[$date] = (float)$row['total_sales'];
        }
    }
    
    // Format response
    foreach ($dates as $date => $sales) {
        $data['labels'][] = date('D, M j', strtotime($date));
        $data['values'][] = $sales;
    }
    
    echo json_encode($data);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
