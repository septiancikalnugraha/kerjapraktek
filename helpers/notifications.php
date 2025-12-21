<?php

if (!function_exists('getDashboardNotifications')) {
    /**
     * Get dashboard notifications sourced from stock mutations and critical stock status.
     *
     * @param mysqli $conn
     * @param int $limit Total number of notifications to return after merge & sort
     * @return array<array<string,mixed>>
     */
    function getDashboardNotifications(mysqli $conn, int $limit = 8): array
    {
        $notifications = [];
        $warning_threshold = 2000;

        // Recent incoming stock mutations
        $incoming_sql = "SELECT sm.id, sm.quantity, sm.created_at, p.kode_produk, p.nama_produk, p.satuan\n                          FROM stock_mutations sm\n                          JOIN products p ON sm.product_id = p.id\n                          WHERE sm.type = 'in'\n                          ORDER BY sm.created_at DESC\n                          LIMIT 6";
        if ($incoming_result = $conn->query($incoming_sql)) {
            while ($row = $incoming_result->fetch_assoc()) {
                $notifications[] = [
                    'type' => 'incoming',
                    'title' => 'Barang Masuk',
                    'message' => sprintf('%s +%s %s', $row['kode_produk'], number_format((int)$row['quantity']), $row['satuan']),
                    'detail' => $row['nama_produk'],
                    'time' => $row['created_at']
                ];
            }
        }

        // Critical & warning stock levels
        $stock_sql = "
            SELECT 
                p.kode_produk,
                p.nama_produk,
                p.satuan,
                COALESCE(SUM(CASE WHEN sm.type = 'in' THEN sm.quantity ELSE -sm.quantity END), 0) AS stok,
                MAX(sm.created_at) AS last_update
            FROM products p
            LEFT JOIN stock_mutations sm ON sm.product_id = p.id
            GROUP BY p.id, p.kode_produk, p.nama_produk, p.satuan, p.stok_minimal
            HAVING stok <= GREATEST(p.stok_minimal, ?)
            ORDER BY stok ASC
            LIMIT 6";
        $stmt_stock = $conn->prepare($stock_sql);
        if ($stmt_stock) {
            $stmt_stock->bind_param('i', $warning_threshold);
            if ($stmt_stock->execute()) {
                $result_stock = $stmt_stock->get_result();
                while ($row = $result_stock->fetch_assoc()) {
                    $status = ((int)$row['stok'] <= 1000) ? 'critical' : 'warning';
                    $notifications[] = [
                        'type' => $status,
                        'title' => $status === 'critical' ? 'Stok Kritis' : 'Stok Hati-hati',
                        'message' => sprintf('%s (%s)', $row['kode_produk'], $row['nama_produk']),
                        'detail' => sprintf('Sisa %s %s', number_format((int)$row['stok']), $row['satuan']),
                        'time' => $row['last_update'] ?? date('Y-m-d H:i:s')
                    ];
                }
            }
            $stmt_stock->close();
        }

        // Sort notifications by time desc
        usort($notifications, function ($a, $b) {
            $timeA = strtotime($a['time']);
            $timeB = strtotime($b['time']);
            return $timeB <=> $timeA;
        });

        if (count($notifications) > $limit) {
            $notifications = array_slice($notifications, 0, $limit);
        }

        return $notifications;
    }
}

if (!function_exists('formatNotificationTime')) {
    function formatNotificationTime(string $timestamp): string
    {
        $time = strtotime($timestamp);
        if (!$time) {
            return '';
        }
        $diff = time() - $time;
        if ($diff < 60) {
            return $diff . ' detik lalu';
        }
        if ($diff < 3600) {
            return floor($diff / 60) . ' menit lalu';
        }
        if ($diff < 86400) {
            return floor($diff / 3600) . ' jam lalu';
        }
        return date('d M Y H:i', $time);
    }
}
