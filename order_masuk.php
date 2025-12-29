<?php
// Enable detailed error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/config.php';
requireLogin();
$conn = getConnection();

// Check database connection
if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error);
}

// Handle order deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_order') {
    header('Content-Type: application/json');
    
    try {
        if (!isset($_POST['order_id']) || !is_numeric($_POST['order_id'])) {
            throw new Exception('ID order tidak valid');
        }
        
        $order_id = (int)$_POST['order_id'];
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // First, delete order items
            $delete_items = $conn->prepare("DELETE FROM order_items WHERE order_id = ?");
            if (!$delete_items) {
                throw new Exception('Gagal mempersiapkan penghapusan item order: ' . $conn->error);
            }
            $delete_items->bind_param('i', $order_id);
            if (!$delete_items->execute()) {
                throw new Exception('Gagal menghapus item order: ' . $delete_items->error);
            }
            
            // Then delete the order
            $delete_order = $conn->prepare("DELETE FROM orders WHERE id = ?");
            if (!$delete_order) {
                throw new Exception('Gagal mempersiapkan penghapusan order: ' . $conn->error);
            }
            $delete_order->bind_param('i', $order_id);
            if (!$delete_order->execute()) {
                throw new Exception('Gagal menghapus order: ' . $delete_order->error);
            }
            
            // Commit transaction
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Order berhasil dihapus'
            ]);
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// Handle order creation
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'create_order'
) {
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        $conn->begin_transaction();

        $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        $orderDate = $_POST['order_date'] ?? date('Y-m-d');
        $deadline = $_POST['deadline'] ?? null;
        $status = $_POST['status'] ?? 'pending';
        $note = trim($_POST['note'] ?? '');
        $orderNumber = trim($_POST['order_number'] ?? '');
        $itemsPayload = $_POST['items'] ?? '[]';
        $items = json_decode($itemsPayload, true);

        if ($customerId <= 0) {
            throw new Exception('Pilih konsumen untuk melanjutkan.');
        }

        if (!is_array($items) || empty($items)) {
            throw new Exception('Tambahkan minimal satu barang pada order.');
        }

        $availableProducts = [];
        foreach (getAvailableProducts($conn) as $product) {
            $product['harga_jual'] = (float)$product['harga_jual'];
            $product['stok_akhir'] = (int)$product['stok_akhir'];
            $availableProducts[(int)$product['id']] = $product;
        }

        $orderItemsData = [];
        $totalAmount = 0;

        foreach ($items as $index => $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $quantity = (int)($item['quantity'] ?? 0);

            if ($productId <= 0) {
                throw new Exception("Barang pada baris " . ($index + 1) . " belum dipilih.");
            }

            if ($quantity <= 0) {
                throw new Exception("Jumlah barang pada baris " . ($index + 1) . " tidak valid.");
            }

            if (!isset($availableProducts[$productId])) {
                throw new Exception("Barang dengan stok tersedia tidak ditemukan atau sudah habis.");
            }

            $productData = $availableProducts[$productId];

            if ($quantity > $productData['stok_akhir']) {
                throw new Exception("Jumlah untuk {$productData['nama_produk']} melebihi stok tersedia ({$productData['stok_akhir']}).");
            }

            $subtotal = $quantity * $productData['harga_jual'];
            $totalAmount += $subtotal;
            $availableProducts[$productId]['stok_akhir'] -= $quantity;

            $orderItemsData[] = [
                'product_id' => $productId,
                'product_name' => $productData['nama_produk'],
                'quantity' => $quantity,
                'price' => $productData['harga_jual'],
                'subtotal' => $subtotal,
                'satuan' => $productData['satuan'],
            ];
        }

        if ($orderNumber === '') {
            $orderNumber = generateOrderNumber($conn, 'no_order');
        } else {
            $stmtCheck = $conn->prepare("SELECT id FROM orders WHERE no_order = ? LIMIT 1");
            $stmtCheck->bind_param('s', $orderNumber);
            $stmtCheck->execute();
            $exists = $stmtCheck->get_result()->fetch_assoc();
            $stmtCheck->close();
            if ($exists) {
                throw new Exception('Nomor order sudah digunakan. Silakan muat ulang halaman untuk mendapatkan nomor baru.');
            }
        }

        $orderStmt = $conn->prepare("
            INSERT INTO orders (no_order, konsumen_id, tanggal_order, deadline, total_harga, status, keterangan)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $deadlineParam = $deadline !== '' ? $deadline : null;
        $orderStmt->bind_param(
            'sissdss',
            $orderNumber,
            $customerId,
            $orderDate,
            $deadlineParam,
            $totalAmount,
            $status,
            $note
        );

        if (!$orderStmt->execute()) {
            throw new Exception('Gagal menyimpan order baru: ' . $orderStmt->error);
        }

        $orderId = $conn->insert_id;
        $orderStmt->close();

        $itemStmt = $conn->prepare("
            INSERT INTO order_items (order_id, produk_id, nama_item, jumlah, harga_satuan, subtotal, keterangan)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $mutationStmt = $conn->prepare("
            INSERT INTO stock_mutations (product_id, type, quantity, keterangan)
            VALUES (?, 'out', ?, ?)
        ");

        foreach ($orderItemsData as $orderItem) {
            $emptyNote = null;
            $itemStmt->bind_param(
                'iisidds',
                $orderId,
                $orderItem['product_id'],
                $orderItem['product_name'],
                $orderItem['quantity'],
                $orderItem['price'],
                $orderItem['subtotal'],
                $emptyNote
            );

            if (!$itemStmt->execute()) {
                throw new Exception('Gagal menyimpan detail order: ' . $itemStmt->error);
            }

            $mutationNote = "Order {$orderNumber}";
            $mutationStmt->bind_param(
                'iis',
                $orderItem['product_id'],
                $orderItem['quantity'],
                $mutationNote
            );

            if (!$mutationStmt->execute()) {
                throw new Exception('Gagal mencatat mutasi stok: ' . $mutationStmt->error);
            }
        }

        $itemStmt->close();
        $mutationStmt->close();

        $conn->commit();
        $_SESSION['order_success'] = "Order {$orderNumber} berhasil dibuat.";

        echo json_encode([
            'success' => true,
            'message' => 'Order berhasil dibuat.',
            'order_id' => $orderId,
            'order_number' => $orderNumber
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Inisialisasi variabel
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Inisialisasi variabel untuk view
$orders = [];
$total_orders = 0;
$total_pages = 1;
$total_amount = 0;
$error_message = '';
$date_column = 'created_at'; // Default column name
$customers_list = [];
$products_list = [];
$orderNumberFieldName = '';
$orderDateFieldName = 'created_at';
$orderTotalFieldName = '';
$orderStatusFieldName = '';
$orderNoteFieldName = '';
$orderDeadlineFieldName = '';
$orderCustomerColumn = '';
$customerTableName = '';
$customerPkColumn = 'id';
$customerNameColumn = 'name';
$customerPhoneColumn = 'phone';
$suggestedOrderNumber = '';
$orderStatuses = [
    'pending' => 'Pending',
    'processing' => 'Diproses',
    'completed' => 'Selesai',
    'cancelled' => 'Dibatalkan'
];

if (!empty($_SESSION['order_success'])) {
    $success_message = $_SESSION['order_success'];
    unset($_SESSION['order_success']);
}

try {
    // Pastikan tabel orders ada
    $tableCheck = $conn->query("SHOW TABLES LIKE 'orders'");
    if ($tableCheck->num_rows == 0) {
        throw new Exception("Tabel 'orders' tidak ditemukan di database.");
    }

    // Ambil daftar konsumen untuk dropdown di modal
    $customerListQuery = $conn->query("
        SELECT 
            id,
            nama_konsumen AS customer_name,
            no_hp AS customer_phone
        FROM konsumen
        ORDER BY nama_konsumen ASC
    ");
    
    if ($customerListQuery) {
        while ($row = $customerListQuery->fetch_assoc()) {
            $customers_list[] = [
                'id' => (int)$row['id'],
                'name' => $row['customer_name'],
                'phone' => $row['customer_phone']
            ];
        }
    }

    // Query untuk menghitung total order
    $count_query = "SELECT COUNT(*) as total 
                   FROM orders o
                   JOIN konsumen k ON o.konsumen_id = k.id
                   WHERE DATE(o.tanggal_order) BETWEEN ? AND ?";
    
    // Query untuk mengambil data order
    $query = "SELECT 
                o.id,
                o.no_order AS order_number,
                o.tanggal_order AS order_date,
                o.total_harga AS total_amount,
                o.status,
                k.nama_konsumen AS customer_name,
                k.no_hp AS customer_phone
              FROM orders o
              JOIN konsumen k ON o.konsumen_id = k.id
              WHERE DATE(o.tanggal_order) BETWEEN ? AND ?";
    
    // Tambahkan filter pencarian jika ada
    if (!empty($search)) {
        $search_param = "%$search%";
        $query .= " AND (o.no_order LIKE ? OR k.nama_konsumen LIKE ? OR k.no_hp LIKE ?)";
        $count_query .= " AND (o.no_order LIKE ? OR k.nama_konsumen LIKE ? OR k.no_hp LIKE ?)";
    }
    
    $query .= " ORDER BY o.tanggal_order DESC, o.id DESC LIMIT ? OFFSET ?";
    
    // Hitung total data
    $stmt = $conn->prepare($count_query);
    if ($stmt === false) {
        throw new Exception("Gagal mempersiapkan query hitung: " . $conn->error);
    }
    
    // Binding parameter untuk count query
    $param_types = 'ss';
    $params = [$start_date, $end_date];
    
    if (!empty($search)) {
        $param_types .= 'sss';
        array_push($params, $search_param, $search_param, $search_param);
    }
    
    $stmt->bind_param($param_types, ...$params);
    
    if (!$stmt->execute()) {
        throw new Exception("Gagal mengeksekusi query hitung: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $total_orders = $result->fetch_assoc()['total'];
    $total_pages = ceil($total_orders / $per_page);
    
    // Ambil data order
    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        throw new Exception("Gagal mempersiapkan query utama: " . $conn->error);
    }
    
    // Binding parameter untuk main query
    $param_types = 'ss';
    $params = [$start_date, $end_date];
    
    if (!empty($search)) {
        $param_types .= 'sss';
        array_push($params, $search_param, $search_param, $search_param);
    }
    
    $param_types .= 'ii';
    array_push($params, $per_page, $offset);
    
    $stmt->bind_param($param_types, ...$params);
    
    if (!$stmt->execute()) {
        throw new Exception("Gagal mengeksekusi query utama: " . $stmt->error);
    }
    
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Hitung total amount
    $total_amount = 0;
    if (!empty($orders)) {
        $total_amount = array_sum(array_column($orders, 'total_amount'));
    }
    
    $products_list = getAvailableProducts($conn);
    $suggestedOrderNumber = generateOrderNumber($conn, $orderNumberFieldName ?: 'no_order');
    if ($suggestedOrderNumber === '') {
        $suggestedOrderNumber = 'ORD-' . date('Ymd') . '-001';
    }

} catch (Exception $e) {
    error_log("Error in order_masuk.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    
    // Show detailed error in development, generic in production
    if (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || $_SERVER['SERVER_NAME'] === 'localhost') {
        $error_message = "Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
    } else {
        $error_message = "Terjadi kesalahan saat mengambil data. Silakan coba lagi nanti.";
    }
}

$products_js_data = array_map(function ($product) {
    return [
        'id' => (int)($product['id'] ?? 0),
        'kode_produk' => $product['kode_produk'] ?? '',
        'nama_produk' => $product['nama_produk'] ?? '',
        'harga_jual' => (float)($product['harga_jual'] ?? 0),
        'stok_akhir' => (int)($product['stok_akhir'] ?? 0),
        'satuan' => $product['satuan'] ?? '',
    ];
}, $products_list);

$customers_js_data = array_map(function ($customer) {
    return [
        'id' => (int)($customer['id'] ?? 0),
        'name' => $customer['name'] ?? '',
        'phone' => $customer['phone'] ?? '',
    ];
}, $customers_list);

$modal_disabled = empty($customers_list) || empty($products_list);

// Fungsi untuk format rupiah
function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Fungsi untuk format tanggal
function formatTanggal($date) {
    $bulan = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
        5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
        9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
    ];
    $timestamp = strtotime($date);
    $tanggal = date('d', $timestamp);
    $bulan_idx = date('n', $timestamp);
    $tahun = date('Y', $timestamp);
    return $tanggal . ' ' . $bulan[$bulan_idx] . ' ' . $tahun;
}

function detectColumn(array $columns, array $candidates, string $default = ''): string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return $default;
}

function generateOrderNumber(mysqli $conn, string $orderNumberField): string
{
    if ($orderNumberField === 'id' || $orderNumberField === '') {
        return '';
    }

    $prefix = 'ORD-' . date('Ymd') . '-';
    $like = $prefix . '%';
    $stmt = $conn->prepare("SELECT {$orderNumberField} AS number FROM orders WHERE {$orderNumberField} LIKE ? ORDER BY {$orderNumberField} DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($result && preg_match('/(\d+)$/', $result['number'], $matches)) {
            $next = (int)$matches[1] + 1;
        } else {
            $next = 1;
        }
    } else {
        $next = 1;
    }

    return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

function bindParamsDynamic(mysqli_stmt $stmt, string $types, array &$values): void
{
    $refs = [];
    $refs[] = $types;
    foreach ($values as $key => $value) {
        $refs[] = &$values[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function getAvailableProducts(mysqli $conn): array
{
    $query = "
        SELECT 
            p.id,
            p.kode_produk,
            p.nama_produk,
            COALESCE(p.kategori, 'Umum') as kategori,
            COALESCE(p.satuan, 'pcs') as satuan,
            p.harga_jual,
            p.harga_beli,
            p.stok_minimal,
            COALESCE(SUM(CASE WHEN sm.type = 'in' THEN sm.quantity ELSE -sm.quantity END), 0) AS stok_akhir
        FROM products p
        LEFT JOIN stock_mutations sm ON p.id = sm.product_id
        GROUP BY p.id, p.kode_produk, p.nama_produk, p.kategori, p.satuan, p.harga_jual, p.harga_beli, p.stok_minimal
        ORDER BY p.kategori ASC, p.nama_produk ASC, p.kode_produk ASC
    ";

    $result = $conn->query($query);
    if (!$result) {
        error_log('Failed to fetch products: ' . $conn->error);
        return [];
    }

    return $result->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Masuk - CV. Panca Indra Kemasan</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-light: #818cf8;
            --primary-dark: #4338ca;
            --secondary: #10b981;
            --secondary-dark: #0d9f6e;
            --danger: #ef4444;
            --danger-dark: #dc2626;
            --warning: #f59e0b;
            --warning-dark: #d97706;
            --info: #3b82f6;
            --info-dark: #2563eb;
            --success: #10b981;
            --success-dark: #0d9f6e;
            --dark: #1f2937;
            --light: #f9fafb;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--gray-100);
            color: var(--gray-700);
            line-height: 1.5;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background: white;
            padding: 1.5rem 0;
            position: fixed;
            height: 100%;
            overflow-y: auto;
            border-right: 1px solid var(--gray-200);
            z-index: 1000;
            box-shadow: var(--shadow-md);
        }

        .sidebar-logo {
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .logo-text {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .sidebar-section {
            margin-bottom: 1.5rem;
            padding: 0 0.75rem;
        }

        .sidebar-title {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: var(--gray-500);
            font-weight: 600;
            margin-bottom: 0.75rem;
            padding: 0 1rem;
            letter-spacing: 0.05em;
        }

        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar-menu li {
            margin-bottom: 0.25rem;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            color: var(--gray-700);
            text-decoration: none;
            border-radius: 0.5rem;
            transition: all 0.2s;
            font-size: 0.9rem;
            position: relative;
            margin: 0 0.5rem;
        }

        .sidebar-menu a i {
            margin-right: 0.75rem;
            width: 20px;
            text-align: center;
            font-size: 1rem;
            color: var(--gray-500);
        }

        .sidebar-menu a:hover {
            background-color: rgba(79, 70, 229, 0.08);
            color: var(--primary);
        }

        .sidebar-menu a:hover i {
            color: var(--primary);
        }

        .sidebar-menu a.active {
            background: linear-gradient(90deg, rgba(79, 70, 229, 0.1), transparent);
            color: var(--primary);
            font-weight: 600;
        }

        .sidebar-menu a.active i {
            color: var(--primary);
        }

        .sidebar-menu a.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: var(--primary);
            border-radius: 0 3px 3px 0;
        }

        .main-content {
            flex: 1;
            margin-left: 250px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .navbar {
            background: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .navbar-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .navbar-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
        }

        .navbar-right {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding: 0.5rem 1rem 0.5rem 2.5rem;
            border-radius: 0.5rem;
            border: 1px solid var(--gray-300);
            background-color: var(--gray-50);
            font-size: 0.9rem;
            width: 250px;
            transition: all 0.2s;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            background-color: white;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .search-box i {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-500);
        }

        .notification-bell {
            position: relative;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 0.5rem;
            transition: background-color 0.2s;
            font-size: 1.1rem;
            color: var(--gray-700);
        }

        .notification-bell:hover {
            background-color: var(--gray-100);
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: var(--danger);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.65rem;
            font-weight: 700;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem;
            border-radius: 0.5rem;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .user-profile:hover {
            background-color: var(--gray-100);
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .user-info {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--dark);
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--gray-500);
        }

        .content-area {
            flex: 1;
            padding: 2rem;
            background-color: var(--gray-100);
        }

        .page-header {
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-title {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .page-subtitle {
            font-size: 0.95rem;
            color: var(--gray-500);
        }

        .alert {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .alert-danger {
            background-color: #fee;
            color: #c00;
            border: 1px solid #fcc;
        }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
            font-size: 0.9rem;
            text-decoration: none;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background-color: #4338ca;
        }

        .btn-success {
            background-color: var(--secondary);
            color: white;
        }

        .btn-success:hover {
            background-color: #059669;
        }

        .btn-danger {
            background-color: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background-color: #dc2626;
        }

        .btn-sm {
            padding: 0.4rem 0.75rem;
            font-size: 0.8rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 0.75rem;
            padding: 1.5rem;
            box-shadow: var(--shadow);
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            transition: all 0.2s;
        }

        .stat-card:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
        }

        .stat-content {
            flex: 1;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--gray-500);
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .stat-icon.blue {
            background-color: #dbeafe;
            color: #2563eb;
        }

        .stat-icon.green {
            background-color: #dcfce7;
            color: #16a34a;
        }

        .stat-icon.yellow {
            background-color: #fef3c7;
            color: #d97706;
        }

        .card {
            background: white;
            border-radius: 0.75rem;
            padding: 1.5rem;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .card-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--dark);
        }

        .filter-group {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .filter-group select,
        .filter-group input[type="text"] {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            border: 1px solid var(--gray-300);
            font-size: 0.9rem;
        }

        .search-filter {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background-color: var(--gray-50);
        }

        th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--gray-700);
            border-bottom: 2px solid var(--gray-200);
            font-size: 0.9rem;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-200);
            font-size: 0.9rem;
        }

        tr:hover {
            background-color: var(--gray-50);
        }

        .text-center {
            text-align: center;
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-warning {
            background-color: #fef3c7;
            color: #92400e;
        }

        .badge-info {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .badge-success {
            background-color: #dcfce7;
            color: #166534;
        }

        .badge-primary {
            background-color: #e0e7ff;
            color: #3730a3;
        }

        .badge-danger {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
            padding: 1rem 0;
        }

        .page-link {
            padding: 0.5rem 0.75rem;
            border-radius: 0.375rem;
            border: 1px solid var(--gray-300);
            background: white;
            color: var(--gray-700);
            text-decoration: none;
            transition: all 0.2s;
            font-size: 0.875rem;
        }

        .page-link:hover {
            background-color: var(--gray-50);
            border-color: var(--primary);
        }

        .page-link.active {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .footer {
            background: white;
            padding: 1.5rem 2rem;
            text-align: center;
            color: var(--gray-500);
            font-size: 0.85rem;
            border-top: 1px solid var(--gray-200);
        }

        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
                z-index: 20;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .filter-group {
                flex-direction: column;
            }

            .table-container {
                font-size: 0.8rem;
            }

            .search-filter {
                display: flex;
                gap: 1rem;
                flex-wrap: wrap;
            }

            .table-container {
                overflow-x: auto;
            }

            table {
                width: 100%;
                border-collapse: collapse;
            }

            thead {
                background-color: var(--gray-50);
            }

            th {
                padding: 1rem;
                text-align: left;
                font-weight: 600;
                color: var(--gray-700);
                border-bottom: 2px solid var(--gray-200);
                font-size: 0.9rem;
            }

            td {
                padding: 1rem;
                border-bottom: 1px solid var(--gray-200);
                font-size: 0.9rem;
            }

            tr:hover {
                background-color: var(--gray-50);
            }

            .text-center {
                text-align: center;
            }

            .badge {
                display: inline-block;
                padding: 0.25rem 0.75rem;
                border-radius: 9999px;
                font-size: 0.75rem;
                font-weight: 600;
            }

            .badge-warning {
                background-color: #fef3c7;
                color: #92400e;
            }

            .badge-info {
                background-color: #dbeafe;
                color: #1e40af;
            }

            .badge-success {
                background-color: #dcfce7;
                color: #166534;
            }

            .badge-primary {
                background-color: #e0e7ff;
                color: #3730a3;
            }

            .badge-danger {
                background-color: #fee2e2;
                color: #991b1b;
            }

            .action-buttons {
                display: flex;
                gap: 0.5rem;
            }

            .pagination {
                display: flex;
                justify-content: center;
                gap: 0.5rem;
                margin-top: 1.5rem;
                padding: 1rem 0;
            }

            .page-link {
                padding: 0.5rem 0.75rem;
                border-radius: 0.375rem;
                border: 1px solid var(--gray-300);
                background: white;
                color: var(--gray-700);
                text-decoration: none;
                transition: all 0.2s;
                font-size: 0.875rem;
            }

            .page-link:hover {
                background-color: var(--gray-50);
                border-color: var(--primary);
            }

            .page-link.active {
                background-color: var(--primary);
                color: white;
                border-color: var(--primary);
            }

            .footer {
                background: white;
                padding: 1.5rem 2rem;
                text-align: center;
                color: var(--gray-500);
                font-size: 0.85rem;
                border-top: 1px solid var(--gray-200);
            }

            @media (max-width: 1024px) {
                .sidebar {
                    transform: translateX(-100%);
                    transition: transform 0.3s ease;
                    z-index: 20;
                }

                .sidebar.active {
                    transform: translateX(0);
                }

                .main-content {
                    margin-left: 0;
                }
            }

            @media (max-width: 768px) {
                .stats-grid {
                    grid-template-columns: 1fr;
                }

                .page-header {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 1rem;
                }

                .filter-group {
                    flex-direction: column;
                }

    .sidebar-menu a:hover {
        .status-badge.danger { background-color: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .status-badge.success { background-color: rgba(16, 185, 129, 0.1); color: var(--secondary); }
    }

    /* ============================================= */
    /* MODAL OVERLAY & CARD */
    /* ============================================= */
    .modal-overlay {
        position: fixed;
        inset: 0;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(6px);
        z-index: 1500;
    }

    .modal-overlay.show {
        display: flex;
    }

    .modal-card {
        width: min(1024px, 96vw);
        max-height: 92vh;
        background: #ffffff;
        border-radius: 22px;
        box-shadow: 0 35px 60px -30px rgba(15, 23, 42, 0.45);
        border: 1px solid rgba(15, 23, 42, 0.08);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        animation: modalFadeUp 0.35s cubic-bezier(0.25, 1, 0.5, 1);
    }

    @keyframes modalFadeUp {
        from { opacity: 0; transform: translateY(20px) scale(0.97); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }

    /* ============================================= */
    /* MODAL HEADER */
    /* ============================================= */
    .modal-header {
        padding: 1.5rem 2rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-bottom: 1px solid rgba(148, 163, 184, 0.35);
        background: linear-gradient(135deg, rgba(79,70,229,0.15), rgba(79,70,229,0.03));
    }

    .modal-header h5 {
        margin: 0;
        font-size: 1.4rem;
        font-weight: 700;
        color: var(--gray-900);
        display: flex;
        align-items: center;
        gap: 0.85rem;
    }

    .modal-header h5 i {
        width: 48px;
        height: 48px;
        border-radius: 14px;
        background: linear-gradient(135deg, var(--primary), var(--primary-light));
        color: white;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        box-shadow: 0 10px 25px -12px rgba(79, 70, 229, 0.6);
    }

    .modal-close {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        border: none;
        background: white;
        color: var(--gray-500);
        font-size: 1.5rem;
        cursor: pointer;
        box-shadow: inset 0 0 0 1.5px rgba(148, 163, 184, 0.6);
        transition: all 0.2s ease;
    }

    .modal-close:hover {
        color: var(--danger);
        box-shadow: inset 0 0 0 2px var(--danger);
        transform: scale(1.05) rotate(90deg);
    }

    /* ============================================= */
    /* MODAL BODY */
    /* ============================================= */
    .modal-body {
        padding: 2rem;
        flex: 1 1 auto;
        min-height: 0;
        background: linear-gradient(180deg, #f9fafb, #ffffff);
    }

    .modal-scrollable {
        flex: 1 1 auto;
        min-height: 0;
        max-height: calc(90vh - 220px);
        overflow-y: auto;
        overflow-x: hidden;
        scrollbar-width: thin;
        scrollbar-color: #9b9b9b #ededed;
    }

    .modal-scrollable::-webkit-scrollbar {
        width: 9px;
    }

    .modal-scrollable::-webkit-scrollbar-track {
        background: #ededed;
        border-radius: 999px;
    }

    .modal-scrollable::-webkit-scrollbar-thumb {
        background: linear-gradient(180deg, #b5b5b5 0%, #9b9b9b 100%);
        border-radius: 999px;
        border: 2px solid #ededed;
    }

    .modal-scrollable::-webkit-scrollbar-thumb:hover {
        background: linear-gradient(180deg, #a3a3a3 0%, #8a8a8a 100%);
    }

    /* ============================================= */
    /* MODAL GRID */
    /* ============================================= */
    .modal-sections {
        display: grid;
        grid-template-columns: minmax(320px, 370px) 1fr;
        gap: 1.75rem;
    }

    .modal-panel {
        background: #ffffff;
        border: 1.5px solid rgba(148, 163, 184, 0.4);
        border-radius: 18px;
        padding: 1.5rem;
        box-shadow: 0 12px 25px -18px rgba(15, 23, 42, 0.4);
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .modal-panel:hover {
        border-color: var(--primary-light);
        box-shadow: 0 18px 35px -22px rgba(79, 70, 229, 0.35);
    }

    .modal-panel h6 {
        margin: 0 0 0.35rem;
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--gray-900);
    }

    .modal-panel p {
        margin: 0 0 1.25rem;
        color: var(--gray-500);
        font-size: 0.9rem;
    }

    /* ============================================= */
    /* FORM ELEMENTS */
    /* ============================================= */
    .form-group {
        margin-bottom: 1.25rem;
    }

    .form-group label {
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--gray-700);
        display: block;
        margin-bottom: 0.4rem;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 0.85rem 1rem;
        border-radius: 12px;
        border: 1.5px solid var(--gray-300);
        font-size: 0.95rem;
        background: white;
        transition: border 0.2s ease, box-shadow 0.2s ease;
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
    }

    .two-column {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
    }

    .form-group textarea {
        min-height: 90px;
        resize: vertical;
    }

    select {
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%234B5563' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 1rem center;
        padding-right: 2.5rem;
        cursor: pointer;
    }

    /* ============================================= */
    /* ITEMS TABLE */
    /* ============================================= */
    .items-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }

    .items-table thead th {
        text-align: left;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--gray-500);
        padding: 0 0.5rem 0.75rem;
    }

    .items-table tbody {
        display: block;
        max-height: 340px;
        overflow-y: auto;
    }

    .items-table tbody::-webkit-scrollbar {
        width: 8px;
    }

    .items-table tbody::-webkit-scrollbar-thumb {
        background: var(--gray-300);
        border-radius: 8px;
    }

    .items-table tbody tr {
        display: grid;
        grid-template-columns: 2.1fr 0.9fr 1.2fr 1fr 0.5fr;
        gap: 0.75rem;
        padding: 0.9rem;
        margin-bottom: 0.9rem;
        border-radius: 16px;
        border: 1.5px solid rgba(148, 163, 184, 0.35);
        background: #f9fafb;
        box-shadow: 0 6px 14px -10px rgba(15, 23, 42, 0.25);
    }

    .items-table tbody tr:hover {
        border-color: var(--primary);
        background: white;
        box-shadow: 0 14px 30px -16px rgba(79, 70, 229, 0.4);
    }

    .items-table tbody td {
        margin: 0;
        padding: 0;
        display: flex;
        align-items: center;
    }

    .qty-control {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        border: 2px solid var(--gray-200);
        border-radius: 14px;
        padding: 0.25rem;
        background: white;
    }

    .qty-control button {
        width: 38px;
        height: 38px;
        border: none;
        border-radius: 10px;
        background: var(--gray-100);
        color: var(--gray-700);
        font-size: 1.1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .qty-control button:hover {
        background: var(--primary);
        color: white;
        transform: scale(1.05);
    }

    .qty-control input {
        width: 64px;
        border: none;
        text-align: center;
        font-size: 1rem;
        font-weight: 600;
        background: transparent;
    }

    .qty-control input:focus {
        outline: none;
    }

    .remove-item-btn {
        width: 42px;
        height: 42px;
        border: none;
        border-radius: 12px;
        background: rgba(239, 68, 68, 0.1);
        color: var(--danger);
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
    }

    .remove-item-btn:hover {
        background: var(--danger);
        color: white;
        transform: scale(1.05);
    }

    .add-item-btn {
        width: 100%;
        padding: 0.85rem 1.25rem;
        border-radius: 14px;
        border: 2px dashed var(--gray-300);
        background: white;
        color: var(--primary);
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.6rem;
        cursor: pointer;
        transition: all 0.2s ease;
        margin-top: 0.5rem;
    }

    .add-item-btn:hover {
        border-color: var(--primary);
        background: rgba(79, 70, 229, 0.08);
        box-shadow: 0 8px 18px -12px rgba(79, 70, 229, 0.5);
    }

    /* ============================================= */
    /* ORDER SUMMARY */
    /* ============================================= */
    .order-summary {
        margin-top: 1.5rem;
        padding: 1.5rem;
        border-radius: 16px;
        border: 1.5px solid var(--gray-200);
        background: white;
        box-shadow: 0 12px 24px -18px rgba(15, 23, 42, 0.35);
    }

    .summary-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.75rem 0;
        border-bottom: 1px solid var(--gray-200);
        font-size: 0.95rem;
    }

    .summary-row:last-child {
        border-bottom: none;
    }

    .summary-row.total {
        font-size: 1.2rem;
        font-weight: 700;
        border-top: 2px solid var(--gray-200);
        margin-top: 0.75rem;
        padding-top: 1rem;
    }

    .summary-row.total strong {
        color: var(--primary);
        font-size: 1.5rem;
    }

    .selected-items-list {
        margin-top: 1.25rem;
        max-height: 200px;
        overflow-y: auto;
        padding-right: 0.5rem;
    }

    .selected-items-list::-webkit-scrollbar {
        width: 6px;
    }

    .selected-items-list::-webkit-scrollbar-thumb {
        background: var(--gray-300);
        border-radius: 8px;
    }

    .selected-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.85rem 1rem;
        border: 1px solid var(--gray-200);
        border-radius: 12px;
        background: var(--gray-50);
        margin-bottom: 0.6rem;
    }

    /* ============================================= */
    /* MODAL FOOTER */
    /* ============================================= */
    .modal-footer {
        padding: 1.5rem 2rem;
        border-top: 1px solid rgba(148, 163, 184, 0.35);
        background: #f8fafc;
        display: flex;
        justify-content: flex-end;
        gap: 1rem;
    }

    .btn {
        border: none;
        border-radius: 12px;
        padding: 0.85rem 1.75rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.95rem;
    }

    .btn-secondary {
        background: white;
        color: var(--gray-700);
        border: 1.5px solid var(--gray-300);
    }

    .btn-secondary:hover {
        background: var(--gray-100);
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        color: white;
        box-shadow: 0 12px 25px -15px rgba(79, 70, 229, 0.6);
    }

    .btn-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 18px 32px -18px rgba(79, 70, 229, 0.75);
    }

    .btn-primary:disabled {
        background: var(--gray-300);
        color: var(--gray-600);
        cursor: not-allowed;
        box-shadow: none;
    }

    /* ============================================= */
    /* RESPONSIVE */
    /* ============================================= */
    @media (max-width: 1024px) {
        .modal-sections {
            grid-template-columns: 1fr;
        }

        .modal-card {
            width: 95vw;
        }
    }

    @media (max-width: 768px) {
        .modal-header {
            flex-direction: column;
            gap: 1rem;
            text-align: center;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            flex-direction: column;
        }

        .modal-footer .btn {
            width: 100%;
        }

        .items-table tbody tr {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 480px) {
        .modal-card {
            width: 100vw;
            height: 100vh;
            border-radius: 0;
        }

        .modal-overlay {
            padding: 0;
        }
    }

    /* ============================================= */
    /* ACCESSIBILITY */
    /* ============================================= */
    *:focus-visible {
        outline: 3px solid var(--primary);
        outline-offset: 2px;
    }
</style>
</head>
<body>
    <div class="dashboard-container">
        <!-- ... -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-logo">
                <div class="logo-text">
                    <i class="fas fa-chart-line"></i>
                    Dashboard
                </div>
            </div>

            <div class="sidebar-section">
                <div class="sidebar-title">Menu Utama</div>
                <ul class="sidebar-menu">
                    <li><a href="dashboard_clean.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard_clean.php' ? 'active' : ''; ?>"><i class="fas fa-home"></i> Dashboard</a></li>
                    <li><a href="analytics.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'analytics.php' ? 'active' : ''; ?>"><i class="fas fa-chart-bar"></i> Analytics</a></li>
                    <li><a href="laporan_bulanan.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'laporan_bulanan.php' ? 'active' : ''; ?>"><i class="fas fa-calendar-alt"></i> Laporan Bulanan</a></li>
                </ul>
            </div>

            <div class="sidebar-section">
                <div class="sidebar-title">Inventory</div>
                <ul class="sidebar-menu">
                    <li><a href="stok_barang.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'stok_barang.php' ? 'active' : ''; ?>"><i class="fas fa-boxes"></i> Stok Barang</a></li>
                    <li><a href="barang_masuk.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'barang_masuk.php' ? 'active' : ''; ?>"><i class="fas fa-arrow-down"></i> Barang Masuk</a></li>
                    <li><a href="barang_keluar.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'barang_keluar.php' ? 'active' : ''; ?>"><i class="fas fa-arrow-up"></i> Barang Keluar</a></li>
                    <li><a href="stok_kritis.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'stok_kritis.php' ? 'active' : ''; ?>"><i class="fas fa-exclamation-triangle"></i> Stok Kritis</a></li>
                </ul>
            </div>

           <div class="sidebar-section">
                <div class="sidebar-title">Penjualan</div>
                <ul class="sidebar-menu">
                    <li><a href="order_masuk.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'order_masuk.php' ? 'active' : ''; ?>"><i class="fas fa-shopping-cart"></i> Order Masuk</a></li>
                    <li><a href="order_pending.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'order_pending.php' ? 'active' : ''; ?>"><i class="fas fa-clock"></i> Order Pending</a></li>
                    <li><a href="order_selesai.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'order_selesai.php' ? 'active' : ''; ?>"><i class="fas fa-check-circle"></i> Order Selesai</a></li>
                    <li><a href="data_konsumen.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'data_konsumen.php' ? 'active' : ''; ?>"><i class="fas fa-users"></i> Data Konsumen</a></li>
                </ul>
            </div>

            <div class="sidebar-section">
                <div class="sidebar-title">Pengaturan</div>
                <ul class="sidebar-menu">
                    <li><a href="#"><i class="fas fa-user-cog"></i> Profil</a></li>
                    <li><a href="#"><i class="fas fa-sliders-h"></i> Konfigurasi</a></li>
                    <li><a href="logout.php" id="logoutBtn"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </aside>

        <div class="main-content">
            <div class="navbar">
                <div class="navbar-left">
                    <button class="btn btn-icon" id="sidebarToggle" style="display: none;">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="navbar-title">Order Masuk</div>
                </div>

                <div class="navbar-right">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Cari order...">
                    </div>

                    <div class="notification-bell">
                        <i class="fas fa-bell"></i>
                        <span class="notification-badge">3</span>
                    </div>

                    <div class="user-profile">
                        <div class="user-avatar">EX</div>
                        <div class="user-info">
                            <span class="user-name">Eksekutif</span>
                            <span class="user-role">Admin</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-area">
                <div class="page-header">
                    <div>
                        <h1 class="page-title">Order Masuk</h1>
                        <p class="page-subtitle">Kelola semua order yang masuk dari konsumen</p>
                    </div>
                    
                <button type="button" class="btn btn-primary" id="openOrderModal" <?php echo $modal_disabled ? 'disabled' : ''; ?>>
                    <i class="fas fa-plus"></i> Buat Order Baru
                </button>
                </div>

                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-label">Total Order</div>
                            <div class="stat-value"><?php echo number_format($total_orders); ?></div>
                        </div>
                        <div class="stat-icon blue">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-label">Total Nilai Order</div>
                            <div class="stat-value"><?php echo formatRupiah($total_amount); ?></div>
                        </div>
                        <div class="stat-icon green">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-label">Rata-rata Nilai</div>
                            <div class="stat-value">
                                <?php 
                                $avg_order = $total_orders > 0 ? $total_amount / $total_orders : 0;
                                echo formatRupiah($avg_order); 
                                ?>
                            </div>
                        </div>
                        <div class="stat-icon yellow">
                            <i class="fas fa-chart-line"></i>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Daftar Order</h3>
                        <div class="filter-group">
                            <form method="GET" class="search-filter">
                                <div class="search-box">
                                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                           placeholder="Cari no. order/nama...">
                                </div>
                                <select name="status" onchange="this.form.submit()">
                                    <option value="">Semua Status</option>
                                    <option value="pending" <?php echo ($_GET['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="processing" <?php echo ($_GET['status'] ?? '') === 'processing' ? 'selected' : ''; ?>>Diproses</option>
                                    <option value="completed" <?php echo ($_GET['status'] ?? '') === 'completed' ? 'selected' : ''; ?>>Selesai</option>
                                </select>
                                <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                                <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                            </form>
                        </div>
                    </div>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID Transaksi</th>
                                    <th>Nama Konsumen</th>
                                    <th>Email</th>
                                    <th>Nomor Handphone</th>
                                    <th>Kategori & Nama Barang</th>
                                    <th>Total Order</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orders)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center">Tidak ada data order</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($orders as $order): 
                                        $items_query = "SELECT COUNT(*) as total_items FROM order_items WHERE order_id = ?";
                                        $stmt = $conn->prepare($items_query);
                                        $stmt->bind_param('i', $order['id']);
                                        $stmt->execute();
                                        $items_result = $stmt->get_result()->fetch_assoc();
                                        $total_items = $items_result['total_items'] ?? 0;
                                    ?>
                                        <tr>
                                            <td>
                                                <div><strong><?php echo htmlspecialchars($order['order_number']); ?></strong></div>
                                                <div class="text-xs text-gray-500"><?php echo date('d F Y H:i', strtotime($order['order_date'])); ?></div>
                                            </td>
                                            <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                            <td><?php echo htmlspecialchars($order['customer_email'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($order['customer_phone'] ?? '-'); ?></td>
                                            <td>
                                                <div style="max-width: 300px; overflow: hidden; text-overflow: ellipsis;">
                                                    <?php 
                                                    $items_query = "SELECT oi.nama_item, oi.jumlah, oi.satuan FROM order_items oi WHERE oi.order_id = ?";
                                                    $stmt = $conn->prepare($items_query);
                                                    $stmt->bind_param('i', $order['id']);
                                                    $stmt->execute();
                                                    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                                    $items_text = [];
                                                    foreach ($items as $item) {
                                                        $items_text[] = "{$item['nama_item']} ({$item['jumlah']} {$item['satuan']})";
                                                    }
                                                    echo htmlspecialchars(implode(', ', $items_text) ?: 'Belum ada barang');
                                                    ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge badge-primary">
                                                    <?php echo $total_items; ?> Item
                                                </span>
                                            </td>
                                            <td>
                                                <?php
                                                $status_class = [
                                                    'pending' => 'warning',
                                                    'processing' => 'info',
                                                    'shipped' => 'primary',
                                                    'completed' => 'success',
                                                    'cancelled' => 'danger'
                                                ][$order['status']] ?? 'secondary';
                                                $status_text = [
                                                    'pending' => 'Pending',
                                                    'processing' => 'Diproses',
                                                    'shipped' => 'Dikirim',
                                                    'completed' => 'Selesai',
                                                    'cancelled' => 'Dibatalkan'
                                                ][$order['status']] ?? $order['status'];
                                                ?>
                                                <span class="badge badge-<?php echo $status_class; ?>">
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-sm btn-icon" onclick="window.location.href='detail_order.php?id=<?php echo $order['id']; ?>'">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-icon" onclick="window.location.href='edit_order.php?id=<?php echo $order['id']; ?>'">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-danger" onclick="hapusOrder(<?php echo $order['id']; ?>, '<?php echo htmlspecialchars(addslashes($order['order_number'])); ?>')">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=1&search=<?php echo urlencode($search); ?>&status=<?php echo htmlspecialchars($_GET['status'] ?? ''); ?>" 
                                   class="page-link">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                                <a href="?page=<?php echo ($page - 1); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo htmlspecialchars($_GET['status'] ?? ''); ?>" 
                                   class="page-link">
                                    <i class="fas fa-angle-left"></i>
                                </a>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $start_page + 4);
                            $start_page = max(1, $end_page - 4);
                            
                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo htmlspecialchars($_GET['status'] ?? ''); ?>" 
                                   class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo ($page + 1); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo htmlspecialchars($_GET['status'] ?? ''); ?>" 
                                   class="page-link">
                                    <i class="fas fa-angle-right"></i>
                                </a>
                                <a href="?page=<?php echo $total_pages; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo htmlspecialchars($_GET['status'] ?? ''); ?>" 
                                   class="page-link">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <footer class="footer">
                <p> 2023 CV. Panca Indra Kemasan. All Rights Reserved. | Sistem Manajemen Dashboard v2.0</p>
            </footer>
        </div>
    </div>

    <div class="modal-overlay" id="orderModal">
        <div class="modal-card">
            <div class="modal-header">
                <h5><i class="fas fa-plus-circle me-2"></i>Order Baru</h5>
                <button type="button" class="modal-close" id="closeOrderModal">&times;</button>
            </div>
            <form id="createOrderForm">
                <input type="hidden" name="action" value="create_order">
                <input type="hidden" name="items" id="itemsInput">
                <div class="modal-body modal-scrollable">
                    <?php if ($modal_disabled): ?>
                        <div class="empty-products-hint">
                            <?php if (empty($customers_list)): ?>
                                Tambah data konsumen terlebih dahulu sebelum membuat order baru.
                            <?php elseif (empty($products_list)): ?>
                                Stok barang kosong. Tambahkan stok di halaman Stok Barang agar dapat membuat order.
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="modal-sections">
                            <div class="modal-panel">
                                <h6>Informasi Order</h6>
                                <p>Isi data utama untuk order baru.</p>
                                <div class="form-group">
                                    <label>Nomor Order</label>
                                    <input type="text" id="orderNumberInput" name="order_number" value="<?php echo htmlspecialchars($suggestedOrderNumber); ?>" readonly>
                                </div>
                                <div class="form-group">
                                    <label>Pilih Konsumen</label>
                                    <select id="customerSelect" name="customer_id" required>
                                        <option value="">— Pilih Konsumen —</option>
                                        <?php foreach ($customers_list as $customer): ?>
                                            <option value="<?php echo $customer['id']; ?>">
                                                <?php echo htmlspecialchars($customer['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small id="customerPhoneDisplay" style="display:block;margin-top:0.4rem;color:var(--gray-500);font-size:0.85rem;">Nomor telepon akan tampil di sini</small>
                                </div>
                                <div class="two-column">
                                    <div class="form-group">
                                        <label>Tanggal Order</label>
                                        <input type="date" id="orderDateInput" name="order_date" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Deadline</label>
                                        <input type="date" id="deadlineInput" name="deadline">
                                    </div>
                                </div>
                                <div class="two-column">
                                    <div class="form-group">
                                        <label>Status</label>
                                        <select id="statusSelect" name="status">
                                            <?php foreach ($orderStatuses as $value => $label): ?>
                                                <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Catatan</label>
                                        <textarea id="noteInput" name="note" rows="3" placeholder="Catatan internal order"></textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-panel">
                                <h6>Barang Dipesan</h6>
                                <p>Pilih barang dan atur jumlah sesuai kebutuhan.</p>
                                <table class="items-table">
                                    <thead>
                                        <tr>
                                            <th>Produk</th>
                                            <th>Stok</th>
                                            <th>Jumlah</th>
                                            <th>Total</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="itemsTableBody"></tbody>
                                </table>
                                <button type="button" class="add-item-btn" id="addItemBtn">
                                    <i class="fas fa-plus"></i> Tambah Barang
                                </button>
                                <div class="order-summary">
                                    <div class="summary-row">
                                        <span>Total Item</span>
                                        <strong id="summaryTotalItems">0</strong>
                                    </div>
                                    <div class="summary-row total">
                                        <span>Total Nilai</span>
                                        <strong id="summaryTotalAmount">Rp 0</strong>
                                    </div>
                                    <div class="selected-items-list" id="selectedItemsList"></div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancelOrderModal">Batalkan</button>
                    <button type="submit" class="btn btn-primary" id="submitOrderBtn" <?php echo $modal_disabled ? 'disabled' : ''; ?>>
                        Simpan Order
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
// =============================================================================
// SIDEBAR TOGGLE & RESPONSIVE
// =============================================================================
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebar = document.getElementById('sidebar');

if (sidebarToggle) {
    sidebarToggle.addEventListener('click', function() {
        sidebar.classList.toggle('active');
    });
}

document.addEventListener('click', function(e) {
    if (window.innerWidth <= 1024) {
        if (!sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
            sidebar.classList.remove('active');
        }
    }
});

function handleResize() {
    if (window.innerWidth <= 1024) {
        sidebarToggle.style.display = 'block';
    } else {
        sidebarToggle.style.display = 'none';
        sidebar.classList.remove('active');
    }
}

window.addEventListener('resize', handleResize);
handleResize();

// =============================================================================
// LOGOUT CONFIRMATION
// =============================================================================
document.getElementById('logoutBtn').addEventListener('click', function(e) {
    if (!confirm('Apakah Anda yakin ingin keluar?')) {
        e.preventDefault();
    }
});

// =============================================================================
// DATA INITIALIZATION
// =============================================================================
const productsData = <?php echo json_encode($products_js_data, JSON_UNESCAPED_UNICODE); ?>;
const customersData = <?php echo json_encode($customers_js_data, JSON_UNESCAPED_UNICODE); ?>;

function getProductSortValue(product) {
    const kode = (product?.kode_produk || '').toString();
    const numericPart = kode.match(/\d+/);
    if (numericPart) {
        return parseInt(numericPart[0], 10);
    }
    // fallback: keep non-numbered codes at the end but still deterministic
    return Number.MAX_SAFE_INTEGER;
}

// Ensure dropdown sequence follows PRD-0001, PRD-0002, ...
const productsDataSorted = [...productsData].sort((a, b) => {
    const valueDiff = getProductSortValue(a) - getProductSortValue(b);
    if (valueDiff !== 0) return valueDiff;

    const kodeA = (a.kode_produk || '').localeCompare(b.kode_produk || undefined);
    if (kodeA !== 0) return kodeA;

    return (a.nama_produk || '').localeCompare(b.nama_produk || '');
});

// Group products by category for better organization
const productsByCategory = {};
productsDataSorted.forEach(product => {
    const kategori = product.kategori || 'Umum';
    const satuan = product.satuan || 'pcs';
    
    if (!productsByCategory[kategori]) {
        productsByCategory[kategori] = [];
    }
    
    const productData = {
        id: product.id,
        kode_produk: product.kode_produk || '',
        nama_produk: product.nama_produk || 'Produk Tanpa Nama',
        kategori: kategori,
        satuan: satuan,
        harga_jual: parseFloat(product.harga_jual) || 0,
        stok_akhir: parseInt(product.stok_akhir) || 0
    };
    
    productsByCategory[kategori].push(productData);
});

// Create a map for quick lookup
const productsMap = new Map();
Object.values(productsByCategory).flat().forEach(product => {
    productsMap.set(product.id, product);
});

// Create customers map
const customersMap = new Map(customersData.map(customer => [customer.id, customer]));

// =============================================================================
// MODAL ELEMENTS
// =============================================================================
const orderModal = document.getElementById('orderModal');
const openOrderModal = document.getElementById('openOrderModal');
const closeOrderModal = document.getElementById('closeOrderModal');
const cancelOrderModal = document.getElementById('cancelOrderModal');
const createOrderForm = document.getElementById('createOrderForm');
const itemsTableBody = document.getElementById('itemsTableBody');
const addItemBtn = document.getElementById('addItemBtn');
const summaryTotalItems = document.getElementById('summaryTotalItems');
const summaryTotalAmount = document.getElementById('summaryTotalAmount');
const selectedItemsList = document.getElementById('selectedItemsList');
const itemsInput = document.getElementById('itemsInput');
const customerSelect = document.getElementById('customerSelect');
const customerPhoneDisplay = document.getElementById('customerPhoneDisplay');
const submitOrderBtn = document.getElementById('submitOrderBtn');

let itemRows = [];
let rowCounter = 0;

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================
function formatRupiah(value) {
    return 'Rp ' + new Intl.NumberFormat('id-ID', { minimumFractionDigits: 0 }).format(value);
}

function resetOrderForm() {
    if (createOrderForm) {
        createOrderForm.reset();
    }

    itemRows = [];
    rowCounter = 0;

    if (itemsTableBody) {
        itemsTableBody.innerHTML = '';
    }

    addItemRow();
    updateSummary();
    updateCustomerPhone();
}

function toggleModal(show) {
    console.log('Toggle modal:', show);
    
    if (show) {
        // Validasi data konsumen dan produk
        if (customersData.length === 0) {
            alert('Tidak ada data konsumen. Tambahkan konsumen terlebih dahulu di menu Data Konsumen.');
            return;
        }
        
        if (productsData.length === 0) {
            alert('Tidak ada produk dengan stok tersedia. Tambahkan stok barang terlebih dahulu.');
            return;
        }
        
        orderModal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        resetOrderForm();
    } else {
        orderModal.classList.remove('show');
        document.body.style.overflow = '';
    }
}

function updateCustomerPhone() {
    if (!customerSelect || !customerPhoneDisplay) return;
    
    const customerId = parseInt(customerSelect.value || '0', 10);
    const customer = customersMap.get(customerId);
    
    if (customer && customer.phone) {
        customerPhoneDisplay.textContent = `Telp: ${customer.phone}`;
        customerPhoneDisplay.style.color = 'var(--success)';
    } else {
        customerPhoneDisplay.textContent = 'Nomor telepon akan tampil di sini';
        customerPhoneDisplay.style.color = 'var(--gray-500)';
    }
}

function buildProductOptions(selectedId = '') {
    let options = ['<option value="">— Pilih Barang —</option>'];
    
    // Group by category
    for (const [category, products] of Object.entries(productsByCategory)) {
        options.push(`<optgroup label="${category}">`);
        
        products.forEach(product => {
            const selected = product.id == selectedId ? 'selected' : '';
            const stok = product.stok_akhir || 0;
            const satuan = product.satuan || 'pcs';
            const kode = product.kode_produk || '';
            const nama = product.nama_produk || 'Produk';
            
            const displayText = `${kode ? `[${kode}] ` : ''}${nama} - Stok: ${stok} ${satuan}`;
            
            options.push(`
                <option value="${product.id}" ${selected}>
                    ${displayText}
                </option>
            `);
        });
        
        options.push('</optgroup>');
    }
    
    return options.join('');
}

function updateSummary() {
    let totalItems = 0;
    let totalAmount = 0;
    
    if (selectedItemsList) {
        selectedItemsList.innerHTML = '';
    }

    itemRows.forEach(row => {
        const product = productsMap.get(row.productId);
        if (!product || row.quantity <= 0) return;
        
        totalItems += row.quantity;
        const subtotal = row.quantity * product.harga_jual;
        totalAmount += subtotal;

        if (selectedItemsList) {
            const summaryItem = document.createElement('div');
            summaryItem.className = 'selected-item';
            summaryItem.innerHTML = `
                <span>${product.nama_produk} (${row.quantity} ${product.satuan})</span>
                <strong>${formatRupiah(subtotal)}</strong>
            `;
            selectedItemsList.appendChild(summaryItem);
        }
    });

    if (summaryTotalItems) summaryTotalItems.textContent = totalItems;
    if (summaryTotalAmount) summaryTotalAmount.textContent = formatRupiah(totalAmount);
}

function collectItemsPayload() {
    return itemRows
        .filter(row => row.productId && row.quantity > 0)
        .map(row => ({
            product_id: row.productId,
            quantity: row.quantity
        }));
}

// =============================================================================
// ADD ITEM ROW
// =============================================================================
function addItemRow(defaultProductId = null) {
    if (!itemsTableBody) return;
    
    rowCounter += 1;
    const rowId = rowCounter;
    
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.dataset.row = rowId;
    
    itemRows.push({
        rowId,
        productId: defaultProductId ? parseInt(defaultProductId, 10) : 0,
        quantity: 1,
        price: 0,
        unit: 'pcs'
    });

    tr.innerHTML = `
        <td>
            <select data-row-select="${rowId}" style="width:100%;padding:0.5rem;border:1px solid var(--gray-300);border-radius:8px;">
                ${buildProductOptions(defaultProductId)}
            </select>
        </td>
        <td>
            <span data-row-stock="${rowId}" class="badge badge-secondary">-</span>
        </td>
        <td>
            <div class="qty-control">
                <button type="button" data-row-decrease="${rowId}">-</button>
                <input type="number" value="1" min="1" data-row-qty="${rowId}">
                <button type="button" data-row-increase="${rowId}">+</button>
            </div>
        </td>
        <td>
            <span data-row-price="${rowId}">Rp 0</span>
        </td>
        <td>
            <button type="button" class="remove-item-btn" data-row-remove="${rowId}">
                <i class="fas fa-trash"></i>
            </button>
        </td>
    `;

    itemsTableBody.appendChild(tr);
    bindRowEvents(rowId);
}

// =============================================================================
// BIND ROW EVENTS
// =============================================================================
function bindRowEvents(rowId) {
    const selectEl = document.querySelector(`[data-row-select="${rowId}"]`);
    const stockEl = document.querySelector(`[data-row-stock="${rowId}"]`);
    const qtyInput = document.querySelector(`[data-row-qty="${rowId}"]`);
    const priceDisplay = document.querySelector(`[data-row-price="${rowId}"]`);
    const decreaseBtn = document.querySelector(`[data-row-decrease="${rowId}"]`);
    const increaseBtn = document.querySelector(`[data-row-increase="${rowId}"]`);
    const removeBtn = document.querySelector(`[data-row-remove="${rowId}"]`);
    const stateRow = itemRows.find(r => r.rowId === rowId);

    if (!stateRow) return;

    function updateRowState() {
        const selectedId = parseInt(selectEl.value || '0', 10);
        const product = productsMap.get(selectedId);

        stateRow.productId = selectedId;

        if (product) {
            const stock = parseInt(product.stok_akhir) || 0;
            stockEl.textContent = stock > 0 ? `${stock} ${product.satuan}` : 'Habis';
            stockEl.className = `badge ${stock > 0 ? 'badge-success' : 'badge-danger'}`;
            
            const price = parseFloat(product.harga_jual) || 0;
            priceDisplay.textContent = formatRupiah(price * stateRow.quantity);
            stateRow.price = price;
            stateRow.unit = product.satuan || 'pcs';
            
            const maxQty = Math.max(1, stock);
            qtyInput.max = maxQty;
            qtyInput.disabled = stock <= 0;
            
            if (stateRow.quantity > maxQty) {
                stateRow.quantity = maxQty;
                qtyInput.value = maxQty;
            }
        } else {
            stockEl.textContent = '-';
            stockEl.className = 'badge badge-secondary';
            priceDisplay.textContent = 'Rp 0';
            stateRow.price = 0;
            stateRow.unit = 'pcs';
        }
        
        updateSummary();
    }

    selectEl.addEventListener('change', updateRowState);

    const adjustQuantity = (delta) => {
        const currentQty = parseInt(qtyInput.value) || 1;
        const newQty = Math.max(1, currentQty + delta);
        const product = productsMap.get(parseInt(selectEl.value) || 0);
        const maxQty = product ? (parseInt(product.stok_akhir) || 1) : 9999;
        const finalQty = Math.min(newQty, maxQty);
        
        qtyInput.value = finalQty;
        stateRow.quantity = finalQty;
        
        if (product) {
            priceDisplay.textContent = formatRupiah(product.harga_jual * finalQty);
        }
        
        updateSummary();
    };

    decreaseBtn.addEventListener('click', () => adjustQuantity(-1));
    increaseBtn.addEventListener('click', () => adjustQuantity(1));

    qtyInput.addEventListener('input', () => {
        let value = parseInt(qtyInput.value || '1', 10);
        if (isNaN(value) || value < 1) value = 1;
        
        const product = productsMap.get(parseInt(selectEl.value) || 0);
        const maxQty = product ? (parseInt(product.stok_akhir) || 1) : value;
        
        stateRow.quantity = Math.min(value, maxQty);
        qtyInput.value = stateRow.quantity;
        
        if (product) {
            priceDisplay.textContent = formatRupiah(product.harga_jual * stateRow.quantity);
        }
        
        updateSummary();
    });

    removeBtn.addEventListener('click', () => {
        const rowElement = itemsTableBody.querySelector(`[data-row="${rowId}"]`);
        if (rowElement) rowElement.remove();
        
        itemRows = itemRows.filter(row => row.rowId !== rowId);
        updateSummary();
        
        if (itemRows.length === 0) {
            addItemRow();
        }
    });

    updateRowState();
}

// =============================================================================
// EVENT LISTENERS
// =============================================================================
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM fully loaded');
    
    // Get modal elements
    const openBtn = document.getElementById('openOrderModal');
    const closeBtn = document.getElementById('closeOrderModal');
    const cancelBtn = document.getElementById('cancelOrderModal');
    const modal = document.getElementById('orderModal');
    // Add click event to open button
    if (openBtn) {
        console.log('Adding click listener to open button');
        openBtn.addEventListener('click', showOrderModal);
    } else {
        console.error('Open button not found');
    }
    // Add click event to close button
    if (closeBtn) {
        console.log('Adding click listener to close button');
        closeBtn.addEventListener('click', hideOrderModal);
    }
    // Add click event to cancel button
    if (cancelBtn) {
        console.log('Adding click listener to cancel button');
        cancelBtn.addEventListener('click', hideOrderModal);
    }
    // Close modal when clicking outside
    if (modal) {
        console.log('Adding outside click handler');
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                hideOrderModal(e);
            }
        });
    }
    
    // Add CSS for modal
    const style = document.createElement('style');
    style.textContent = `
        /* Modal Overlay */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1500;
            padding: 1rem;
            animation: fadeInOverlay 0.3s ease;
        }

        .modal-overlay.show {
            display: flex;
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Modal Card */
        .modal-card {
            width: min(1200px, 98vw);
            max-height: 94vh;
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 0 35px 60px -30px rgba(15, 23, 42, 0.45);
            border: 1px solid rgba(15, 23, 42, 0.08);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: slideUpModal 0.4s cubic-bezier(0.25, 1, 0.5, 1);
        }

        @keyframes slideUpModal {
            from { 
                transform: translateY(30px) scale(0.96);
                opacity: 0;
            }
            to { 
                transform: translateY(0) scale(1);
                opacity: 1;
            }
        }

         /* Style untuk modal body yang bisa discroll */
    .modal-body {
        flex: 1;
        overflow-y: auto;
        padding: 1.5rem;
        max-height: calc(100vh - 200px); /* Sesuaikan tinggi maksimum */
        scrollbar-width: thin;
        scrollbar-color: #c1c1c1 #f1f1f1;
    }
    /* Style untuk Webkit browsers (Chrome, Safari) */
    .modal-body::-webkit-scrollbar {
        width: 8px;
    }
    .modal-body::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }
    .modal-body::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 10px;
    }
    .modal-body::-webkit-scrollbar-thumb:hover {
        background: #a8a8a8;
    }
    /* Pastikan konten dalam modal-panel bisa discroll dengan benar */
    .modal-panel {
        display: flex;
        flex-direction: column;
        height: 100%;
    }
    /* Style untuk tabel items */
    .items-table {
        width: 100%;
        border-collapse: collapse;
    }
    .items-table thead {
        position: sticky;
        top: 0;
        background-color: #fff;
        z-index: 10;
    }
    /* Style untuk footer modal */
    .modal-footer {
        padding: 1rem 1.5rem;
        border-top: 1px solid #e5e7eb;
        background-color: #f9fafb;
    }

        /* Modal Header */
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.75rem 2rem;
            background: linear-gradient(135deg, 
                rgba(79, 70, 229, 0.12) 0%, 
                rgba(79, 70, 229, 0.03) 100%);
            border-bottom: 2px solid rgba(79, 70, 229, 0.1);
        }

        .modal-header h5 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            display: flex;
            align-items: center;
            gap: 0.875rem;
            margin: 0;
        }

        .modal-header h5 i,
        .modal-header h5 .fa-plus-circle {
            display: inline-flex;
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.25rem;
            box-shadow: 
                0 10px 25px -10px rgba(79, 70, 229, 0.6),
                0 0 0 4px rgba(79, 70, 229, 0.1);
        }

        .modal-close {
            border: none;
            background: white;
            width: 44px;
            height: 44px;
            border-radius: 12px;
            font-size: 1.35rem;
            color: var(--gray-500);
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 
                inset 0 0 0 2px var(--gray-300),
                0 2px 4px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 300;
        }

        .modal-close:hover {
            color: var(--danger);
            background: rgba(239, 68, 68, 0.05);
            box-shadow: 
                inset 0 0 0 2px var(--danger),
                0 4px 12px rgba(239, 68, 68, 0.2);
            transform: scale(1.05) rotate(90deg);
        }

        .modal-close:active {
            transform: scale(0.95) rotate(90deg);
        }
         /* Modal Body */
        .modal-body {
            padding: 2rem;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .modal-body::-webkit-scrollbar {
            width: 12px;
        }

        .modal-body::-webkit-scrollbar-track {
            background: var(--gray-50);
            border-radius: 10px;
        }

        .modal-body::-webkit-scrollbar-thumb {
            background: var(--gray-300);
            border-radius: 10px;
            border: 3px solid var(--gray-50);
        }

        .modal-body::-webkit-scrollbar-thumb:hover {
            background: var(--gray-400);
        }

        /* Modal Sections */
        .modal-sections {
            display: grid;
            grid-template-columns: 1fr 1.5fr;
            gap: 2rem;
            min-height: 0; /* Allows the grid to be smaller than its contents */
        }

        .modal-panel {
            border: 1.5px solid rgba(148, 163, 184, 0.4);
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 12px 25px -18px rgba(15, 23, 42, 0.4);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            position: relative;
            overflow: hidden;
        }

        .modal-panel:hover {
            border-color: var(--primary-light);
            box-shadow: 0 18px 35px -22px rgba(79, 70, 229, 0.35);
        }

        .modal-panel h6 {
            margin: 0 0 0.35rem;
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
        }

        .modal-panel p {
            margin: 0 0 1.25rem;
            color: var(--gray-500);
            font-size: 0.9rem;
        }

        /* Form Groups */
        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--gray-700);
            display: block;
            margin-bottom: 0.4rem;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.85rem 1rem;
            border-radius: 12px;
            border: 1.5px solid var(--gray-300);
            font-size: 0.95rem;
            background: white;
            transition: border 0.2s ease, box-shadow 0.2s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        .two-column {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        .form-group textarea {
            min-height: 90px;
            resize: vertical;
        }

        select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%234B5563' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            padding-right: 2.5rem;
            cursor: pointer;
        }

        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin: 1rem 0;
            flex: 1;
            min-height: 200px;
            display: block;
            overflow-y: auto;
        }

        .items-table thead th {
            text-align: left;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--gray-500);
            padding: 0 0.5rem 0.75rem;
        }

        .items-table tbody {
            display: block;
            max-height: 340px;
            overflow-y: auto;
        }

        .items-table tbody::-webkit-scrollbar {
            width: 8px;
        }

        .items-table tbody::-webkit-scrollbar-track {
            background: var(--gray-50);
            border-radius: 8px;
        }

        .items-table tbody::-webkit-scrollbar-thumb {
            background: var(--gray-300);
            border-radius: 8px;
        }

        .items-table tbody tr {
            display: grid;
            grid-template-columns: 2.1fr 0.9fr 1.2fr 1fr 0.5fr;
            gap: 0.75rem;
            padding: 0.9rem;
            margin-bottom: 0.9rem;
            border-radius: 16px;
            border: 1.5px solid rgba(148, 163, 184, 0.35);
            background: #f9fafb;
            box-shadow: 0 6px 14px -10px rgba(15, 23, 42, 0.25);
        }

        .items-table tbody tr:hover {
            border-color: var(--primary);
            background: white;
            box-shadow: 0 14px 30px -16px rgba(79, 70, 229, 0.4);
        }

        .items-table tbody td {
            margin: 0;
            padding: 0;
            display: flex;
            align-items: center;
        }

        .qty-control {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            border: 2px solid var(--gray-200);
            border-radius: 14px;
            padding: 0.25rem;
            background: white;
        }

        .qty-control button {
            width: 38px;
            height: 38px;
            border: none;
            border-radius: 10px;
            background: var(--gray-100);
            color: var(--gray-700);
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .qty-control button:hover {
            background: var(--primary);
            color: white;
            transform: scale(1.05);
        }

        .qty-control input {
            width: 64px;
            border: none;
            text-align: center;
            font-size: 1rem;
            font-weight: 600;
            background: transparent;
        }

        .qty-control input:focus {
            outline: none;
        }

        .remove-item-btn {
            width: 42px;
            height: 42px;
            border: none;
            border-radius: 12px;
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .remove-item-btn:hover {
            background: var(--danger);
            color: white;
            transform: scale(1.05);
        }

        .add-item-btn {
            width: 100%;
            padding: 0.85rem 1.25rem;
            border-radius: 14px;
            border: 2px dashed var(--gray-300);
            background: white;
            color: var(--primary);
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 0.5rem;
        }

        .add-item-btn:hover {
            border-color: var(--primary);
            background: rgba(79, 70, 229, 0.08);
            box-shadow: 0 8px 18px -12px rgba(79, 70, 229, 0.5);
        }

        /* Order Summary */
        .order-summary {
            margin-top: 1.5rem;
            padding: 1.5rem;
            border-radius: 16px;
            position: relative;
            z-index: 1;
            border: 1.5px solid var(--gray-200);
            background: white;
            box-shadow: 0 12px 24px -18px rgba(15, 23, 42, 0.35);
        }

        .summary-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-200);
            font-size: 0.95rem;
        }

        .summary-row:last-child {
            border-bottom: none;
        }

        .summary-row.total {
            font-size: 1.2rem;
            font-weight: 700;
            border-top: 2px solid var(--gray-200);
            margin-top: 0.75rem;
            padding-top: 1rem;
        }

        .summary-row.total strong {
            color: var(--primary);
            font-size: 1.5rem;
        }

        .selected-items-list {
            margin-top: 1.25rem;
            max-height: 200px;
            overflow-y: auto;
            padding-right: 0.5rem;
        }

        .selected-items-list::-webkit-scrollbar {
            width: 6px;
        }

        .selected-items-list::-webkit-scrollbar-thumb {
            background: var(--gray-300);
            border-radius: 8px;
        }

        .selected-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.85rem 1rem;
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            background: var(--gray-50);
            margin-bottom: 0.6rem;
        }

        /* Modal Footer */
        .modal-footer {
            padding: 1.5rem 2rem;
            border-top: 1px solid rgba(148, 163, 184, 0.35);
            background: #f8fafc;
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        .btn {
            border: none;
            border-radius: 12px;
            padding: 0.85rem 1.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.95rem;
        }

        .btn-secondary {
            background: white;
            color: var(--gray-700);
            border: 1.5px solid var(--gray-300);
        }

        .btn-secondary:hover {
            background: var(--gray-100);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 12px 25px -15px rgba(79, 70, 229, 0.6);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 18px 32px -18px rgba(79, 70, 229, 0.75);
        }

        .btn-primary:disabled {
            background: var(--gray-300);
            color: var(--gray-600);
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        .btn i {
            font-size: 1.05rem;
        }

        /* Badge Styles */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.375rem 0.875rem;
            border-radius: 9px;
            font-size: 0.8125rem;
            font-weight: 600;
        }

        .badge-secondary {
            background-color: var(--gray-200);
            color: var(--gray-700);
        }

        .badge-success {
            background-color: rgba(16, 185, 129, 0.15);
            color: #065f46;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .badge-danger {
            background-color: rgba(239, 68, 68, 0.15);
            color: #991b1b;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        /* Empty State */
        .empty-products-hint {
            padding: 3.5rem 2rem;
            text-align: center;
            color: var(--gray-600);
            background: var(--gray-50);
            border: 2px dashed var(--gray-300);
            border-radius: 20px;
            font-size: 0.95rem;
            line-height: 1.7;
        }

        .empty-products-hint::before {
            content: "⚠️";
            display: block;
            font-size: 3.5rem;
            margin-bottom: 1.25rem;
        }

        /* Loading State */
        .btn-primary.loading {
            position: relative;
            color: transparent;
            pointer-events: none;
        }

        .btn-primary.loading::after {
            content: '';
            position: absolute;
            width: 22px;
            height: 22px;
            top: 50%;
            left: 50%;
            margin-left: -11px;
            margin-top: -11px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .modal-sections {
                grid-template-columns: 1fr;
            }

            .modal-card {
                width: 96vw;
            }

            .item-row {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
        }

        @media (max-width: 768px) {
            .modal-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .modal-body {
                padding: 1.5rem;
            }

            .modal-footer {
                flex-direction: column;
            }

            .modal-footer .btn {
                width: 100%;
            }

            .two-column {
                grid-template-columns: 1fr;
            }

            .qty-control input {
                width: 55px;
            }

            .modal-panel {
                padding: 1.5rem;
            }
        }

        @media (max-width: 480px) {
            .modal-card {
                width: 100vw;
                height: 100vh;
                border-radius: 0;
            }

            .modal-overlay {
                padding: 0;
            }

            .modal-sections {
                gap: 1.5rem;
            }

            .item-row {
                padding: 1rem;
            }
        }
     /* Accessibility */
        *:focus-visible {
            outline: 3px solid var(--primary);
            outline-offset: 2px;
            border-radius: 4px;
        }
    /* ============================================= */
/* IMPROVED MODAL WITH SCROLLBAR */
/* ============================================= */

/* Modal Overlay */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(8px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1500;
    padding: 1rem;
    animation: fadeInOverlay 0.3s ease;
}

.modal-overlay.show {
    display: flex;
}

@keyframes fadeInOverlay {
    from { opacity: 0; }
    to { opacity: 1; }
}

/* Modal Card */
.modal-card {
        width: min(1200px, 96vw);
        max-height: 90vh;
        background: #ffffff;
        border-radius: 22px;
        box-shadow: 0 35px 60px -30px rgba(15, 23, 42, 0.45);
        border: 1px solid rgba(15, 23, 42, 0.08);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        animation: modalFadeUp 0.35s cubic-bezier(0.25, 1, 0.5, 1);
    }

/* Modal Header */
.modal-card {
        width: min(1200px, 96vw);
        max-height: 90vh;
        background: #ffffff;
        border-radius: 22px;
        box-shadow: 0 35px 60px -30px rgba(15, 23, 42, 0.45);
        border: 1px solid rgba(15, 23, 42, 0.08);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        animation: modalFadeUp 0.35s cubic-bezier(0.25, 1, 0.5, 1);
    }

.modal-header h5 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--gray-900);
    display: flex;
    align-items: center;
    gap: 0.875rem;
    margin: 0;
}

.modal-header h5 i,
.modal-header h5 .fa-plus-circle {
    display: inline-flex;
    width: 48px;
    height: 48px;
    border-radius: 14px;
    background: linear-gradient(135deg, var(--primary), var(--primary-light));
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.25rem;
    box-shadow: 
        0 10px 25px -10px rgba(79, 70, 229, 0.6),
        0 0 0 4px rgba(79, 70, 229, 0.1);
}

.modal-close {
    border: none;
    background: white;
    width: 44px;
    height: 44px;
    border-radius: 12px;
    font-size: 1.35rem;
    color: var(--gray-500);
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 
        inset 0 0 0 2px var(--gray-300),
        0 2px 4px rgba(0, 0, 0, 0.05);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 300;
}

.modal-close:hover {
    color: var(--danger);
    background: rgba(239, 68, 68, 0.05);
    box-shadow: 
        inset 0 0 0 2px var(--danger),
        0 4px 12px rgba(239, 68, 68, 0.2);
    transform: scale(1.05) rotate(90deg);
}

/* Modal Body with Custom Scrollbar */
.modal-body {
    padding: 2rem;
    overflow-y: auto;
    overflow-x: hidden;
    flex: 1;
    position: relative;
}

/* Custom Scrollbar for Modal Body */
.modal-body::-webkit-scrollbar {
    width: 14px;
}

.modal-body::-webkit-scrollbar-track {
    background: linear-gradient(to right, transparent, var(--gray-100));
    border-radius: 10px;
    margin: 8px 0;
}

.modal-body::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, var(--primary-light), var(--primary));
    border-radius: 10px;
    border: 3px solid var(--gray-50);
    box-shadow: inset 0 0 6px rgba(79, 70, 229, 0.3);
}

.modal-body::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(180deg, var(--primary), var(--primary-dark));
    border: 2px solid var(--gray-50);
}

.modal-body::-webkit-scrollbar-thumb:active {
    background: var(--primary-dark);
}

/* Firefox Scrollbar */
.modal-body {
    scrollbar-width: thin;
    scrollbar-color: var(--primary) var(--gray-100);
}

/* Items Table Body Scrollbar */
.items-table tbody {
    display: block;
    max-height: 400px;
    overflow-y: auto;
    overflow-x: hidden;
}

.items-table tbody::-webkit-scrollbar {
    width: 10px;
}

.items-table tbody::-webkit-scrollbar-track {
    background: var(--gray-50);
    border-radius: 8px;
    margin: 4px 0;
}

.items-table tbody::-webkit-scrollbar-thumb {
    background: var(--gray-300);
    border-radius: 8px;
    border: 2px solid var(--gray-50);
}

.items-table tbody::-webkit-scrollbar-thumb:hover {
    background: var(--gray-400);
}

/* Selected Items List Scrollbar */
.selected-items-list {
    margin-top: 1.25rem;
    max-height: 220px;
    overflow-y: auto;
    overflow-x: hidden;
}

.selected-items-list::-webkit-scrollbar {
    width: 8px;
}

.selected-items-list::-webkit-scrollbar-track {
    background: var(--gray-100);
    border-radius: 6px;
}

.selected-items-list::-webkit-scrollbar-thumb {
    background: var(--primary-light);
    border-radius: 6px;
}

.selected-items-list::-webkit-scrollbar-thumb:hover {
    background: var(--primary);
}

/* Modal Footer */
.modal-footer {
    padding: 1.75rem 2rem;
    border-top: 2px solid var(--gray-100);
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    background: linear-gradient(to top, var(--gray-50), white);
    flex-shrink: 0;
}

/* Quantity Control */
.qty-control {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    background: var(--gray-50);
    border: 2px solid var(--gray-300);
    border-radius: 12px;
    padding: 0.25rem;
}

.qty-control button {
    width: 38px;
    height: 38px;
    border: none;
    background: white;
    color: var(--gray-700);
    border-radius: 10px;
    font-size: 1.125rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.qty-control button:hover {
    background: var(--primary);
    color: white;
    transform: scale(1.15);
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
}

.qty-control button:active {
    transform: scale(0.95);
}

.qty-control input {
    width: 70px;
    text-align: center;
    border: none;
    padding: 0.5rem;
    font-weight: 600;
    font-size: 1rem;
    color: var(--gray-900);
    background: transparent;
}

.qty-control input:focus {
    outline: none;
    box-shadow: none;
}

/* Remove Item Button */
.remove-item-btn {
    width: 42px;
    height: 42px;
    border: none;
    background: var(--gray-100);
    color: var(--danger);
    border-radius: 11px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.05rem;
}

.remove-item-btn:hover {
    background: var(--danger);
    color: white;
    transform: scale(1.15) rotate(10deg);
    box-shadow: 0 6px 16px rgba(239, 68, 68, 0.3);
}

.remove-item-btn:active {
    transform: scale(0.9) rotate(10deg);
}

/* Item Row */
.item-row {
    display: grid;
    grid-template-columns: 2fr repeat(3, minmax(90px, 150px)) 60px;
    gap: 0.875rem;
    padding: 1.125rem;
    margin-bottom: 0.875rem;
    border-radius: 16px;
    border: 2px solid var(--gray-200);
    background: white;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    align-items: center;
}

.item-row:hover {
    border-color: var(--primary);
    box-shadow: 
        0 10px 30px -15px rgba(79, 70, 229, 0.3),
        0 0 0 1px var(--primary-light);
    transform: translateY(-3px);
}

/* Responsive Design */
@media (max-width: 1024px) {
    .modal-sections {
        grid-template-columns: 1fr;
    }

    .modal-card {
        width: 96vw;
    }

    .item-row {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
}

@media (max-width: 768px) {
    .modal-header {
        padding: 1.5rem 1.5rem;
    }

    .modal-header h5 {
        font-size: 1.25rem;
    }

    .modal-body {
        padding: 1.5rem;
    }

    .modal-body::-webkit-scrollbar {
        width: 10px;
    }

    .modal-footer {
        padding: 1.25rem 1.5rem;
        flex-direction: column;
    }

    .modal-footer .btn {
        width: 100%;
    }

    .two-column {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 480px) {
    .modal-card {
        width: 100vw;
        height: 100vh;
        max-height: 100vh;
        border-radius: 0;
    }

    .modal-overlay {
        padding: 0;
    }

    .modal-body::-webkit-scrollbar {
        width: 8px;
    }
}
    `;
    document.head.appendChild(style);
    
    console.log('Modal initialization complete');
});


// =============================================================================
// INITIALIZATION
// =============================================================================
// Make sure these functions are called on page load
document.addEventListener('DOMContentLoaded', function() {
    updateCustomerPhone();
    console.log('Modal script initialized');
    console.log('Available customers:', customersData.length);
    console.log('Available products:', productsData.length);
    
    // Enable the open modal button if it was disabled by PHP
    const openBtn = document.getElementById('openOrderModal');
    if (openBtn && openBtn.disabled) {
        openBtn.disabled = false;
        console.log('Enabled the "Buat Order Baru" button');
    }
});

// =============================================================================
// MODAL FUNCTIONS
// =============================================================================
// Debug: Log modal and button elements
console.log('Modal elements:', {
    orderModal: document.getElementById('orderModal'),
    openOrderModal: document.getElementById('openOrderModal'),
    closeOrderModal: document.getElementById('closeOrderModal'),
    cancelOrderModal: document.getElementById('cancelOrderModal')
});
// Show modal function
function showOrderModal(e) {
    if (e) e.preventDefault();
    console.log('showOrderModal called');
    
    // Validate data before showing modal
    if (customersData.length === 0) {
        alert('Tidak ada data konsumen. Tambahkan konsumen terlebih dahulu di menu Data Konsumen.');
        return;
    }
    
    if (productsData.length === 0) {
        alert('Tidak ada produk dengan stok tersedia. Tambahkan stok barang terlebih dahulu.');
        return;
    }
    
    const modal = document.getElementById('orderModal');
    if (modal) {
        console.log('Showing modal');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        
        // Reset form when opening modal
        const form = document.getElementById('createOrderForm');
        if (form) form.reset();
        
        // Clear any existing items
        const itemsTableBody = document.getElementById('itemsTableBody');
        if (itemsTableBody) itemsTableBody.innerHTML = '';
        
        // Add first empty row
        addItemRow();
    } else {
        console.error('Modal element not found');
    }
}
// Hide modal function
function hideOrderModal(e) {
    if (e) e.preventDefault();
    console.log('hideOrderModal called');
    const modal = document.getElementById('orderModal');
    if (modal) {
        console.log('Hiding modal');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

</script>
</body>
</html>

<?php $conn->close(); ?>