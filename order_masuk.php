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
    // Check if tables exist
    $tableCheck = $conn->query("SHOW TABLES LIKE 'orders'");
    if ($tableCheck->num_rows == 0) {
        throw new Exception("Tabel 'orders' tidak ditemukan di database.");
    }

    $tableCheck = $conn->query("SHOW TABLES LIKE 'konsumen'");
    $customerTable = 'konsumen';
    if ($tableCheck->num_rows == 0) {
        $tableCheck = $conn->query("SHOW TABLES LIKE 'customers'");
        if ($tableCheck->num_rows == 0) {
            throw new Exception("Tabel 'konsumen' atau 'customers' tidak ditemukan di database.");
        }
        $customerTable = 'customers';
    }

    // Check which columns exist in orders table
    $columnsCheck = $conn->query("SHOW COLUMNS FROM orders");
    $columns = [];
    $customer_id_column = null;
    
    while ($row = $columnsCheck->fetch_assoc()) {
        $columns[] = $row['Field'];
        // Check for common customer ID column names
        if (in_array($row['Field'], ['customer_id', 'id_customer', 'customerid', 'cust_id'])) {
            $customer_id_column = $row['Field'];
        }
    }
    
    // If no customer ID column found, use the first column that looks like an ID
    if ($customer_id_column === null) {
        foreach ($columns as $col) {
            if (preg_match('/(id|customer)/i', $col)) {
                $customer_id_column = $col;
                break;
            }
        }
    }
    
    // If still no column found, use the first column as fallback
    if ($customer_id_column === null && !empty($columns)) {
        $customer_id_column = $columns[0];
    } elseif ($customer_id_column === null) {
        throw new Exception("Tidak dapat menentukan kolom ID pelanggan di tabel orders");
    }
    
    // Debug log the detected customer ID column
    error_log('Using customer ID column: ' . $customer_id_column);
    // DEBUG: output columns for inspection
    error_log('Orders table columns: ' . implode(', ', $columns));

    $orderNumberFieldName = detectColumn($columns, ['no_order', 'order_number', 'nomor_order'], '');
    if ($orderNumberFieldName === '') {
        $orderNumberFieldName = null;
    }

    // Determine which date column to use
    if (in_array('order_date', $columns)) {
        $date_column = 'order_date';
    } elseif (in_array('created_at', $columns)) {
        $date_column = 'created_at';
    } elseif (in_array('date', $columns)) {
        $date_column = 'date';
    } else {
        // If no date column found, list available columns for debugging
        throw new Exception("Tidak ada kolom tanggal yang ditemukan. Kolom yang tersedia: " . implode(', ', $columns));
    }

    // Detect customer columns if table exists
    $customerColumns = [];
    $customerNameColumn = 'name';
    $customerPhoneColumn = 'phone';
    $customerIdColumn = 'id';

    $customerColumnsCheck = $conn->query("SHOW COLUMNS FROM {$customerTable}");
    if ($customerColumnsCheck) {
        while ($row = $customerColumnsCheck->fetch_assoc()) {
            $customerColumns[] = $row['Field'];
        }
        $customerIdColumn = detectColumn($customerColumns, ['id', 'konsumen_id', 'customer_id'], $customerIdColumn);
        $customerPkColumn = $customerIdColumn ?: $customerPkColumn;
        $customerNameColumn = detectColumn($customerColumns, ['nama_konsumen', 'nama', 'name'], $customerNameColumn);
        $customerPhoneColumn = detectColumn($customerColumns, ['no_hp', 'telepon', 'phone'], $customerPhoneColumn);
    }

    $customerListQuery = $conn->query("
        SELECT 
            {$customerIdColumn} AS id,
            {$customerNameColumn} AS customer_name,
            {$customerPhoneColumn} AS customer_phone
        FROM {$customerTable}
        ORDER BY {$customerNameColumn} ASC
    ");
    if ($customerListQuery) {
        while ($row = $customerListQuery->fetch_assoc()) {
            $customers_list[] = [
                'id' => (int)($row['id'] ?? 0),
                'name' => $row['customer_name'] ?? '',
                'phone' => $row['customer_phone'] ?? ''
            ];
        }
    }

    // Query untuk menghitung total data
    $count_query = "SELECT COUNT(*) as total 
                   FROM orders o
                   JOIN {$customerTable} c ON o.$customer_id_column = c.{$customerIdColumn}
                   WHERE DATE(o.$date_column) BETWEEN ? AND ?";
    
    // Check which columns exist in the orders table
    $has_total_amount = in_array('total_amount', $columns);
    $has_status = in_array('status', $columns);
    if ($orderNumberFieldName) {
        $order_number_column = "o.{$orderNumberFieldName} as order_number";
    } else {
        $order_number_column = 'o.id as order_number';
    }
    
    // Build the SELECT fields dynamically
    $select_fields = [
        'o.id',
        $order_number_column,
        "o.$date_column as order_date"
    ];

    // Add status if it exists
    if ($has_status) {
        $select_fields[] = 'o.status';
    }

    // Add total_amount if it exists, otherwise calculate it
    if ($has_total_amount) {
        $select_fields[] = 'o.total_amount';
    } else {
        // Check if order_items table exists and has the required columns
        $tableCheck = $conn->query("SHOW TABLES LIKE 'order_items'");
        if ($tableCheck->num_rows > 0) {
            // Get order_items columns
            $orderItemsCols = $conn->query("SHOW COLUMNS FROM order_items");
            $orderItemsColumns = [];
            while ($col = $orderItemsCols->fetch_assoc()) {
                $orderItemsColumns[] = $col['Field'];
            }
            
            // Check for common quantity and price column names
            $qtyCol = in_array('quantity', $orderItemsColumns) ? 'quantity' : 
                     (in_array('qty', $orderItemsColumns) ? 'qty' : null);
                     
            $priceCol = in_array('price', $orderItemsColumns) ? 'price' : 
                       (in_array('harga', $orderItemsColumns) ? 'harga' : null);
            
            if ($qtyCol && $priceCol) {
                $select_fields[] = "(SELECT COALESCE(SUM($qtyCol * $priceCol), 0) FROM order_items WHERE order_id = o.id) as total_amount";
            } else {
                $select_fields[] = '0 as total_amount'; // Default to 0 if we can't determine the columns
            }
        } else {
            $select_fields[] = '0 as total_amount'; // Default to 0 if order_items doesn't exist
        }
    }

    // Add customer fields
    $select_fields = array_merge($select_fields, [
        "c.{$customerNameColumn} as customer_name",
        "c.{$customerPhoneColumn} as customer_phone"
    ]);
    
    $query = "SELECT 
                " . implode(",\n                ", $select_fields) . "
              FROM orders o
              JOIN {$customerTable} c ON o.$customer_id_column = c.{$customerIdColumn}
              WHERE DATE(o.$date_column) BETWEEN ? AND ?";
    
    // Tambahkan filter pencarian jika ada
    if (!empty($search)) {
        $search_param = "%$search%";
        $order_number_condition = $orderNumberFieldName
            ? "o.{$orderNumberFieldName} LIKE ?"
            : "o.id LIKE ?";

        $query .= " AND ($order_number_condition OR c.name LIKE ? OR c.phone LIKE ?)";
        $count_query .= " AND ($order_number_condition OR c.name LIKE ? OR c.phone LIKE ?)";
    }
    
    $query .= " ORDER BY o.$date_column DESC LIMIT ? OFFSET ?";
    
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
            p.kategori,
            p.satuan,
            p.harga_jual,
            p.stok_minimal,
            COALESCE(SUM(CASE WHEN sm.type = 'in' THEN sm.quantity ELSE -sm.quantity END), 0) AS stok_akhir
        FROM products p
        LEFT JOIN stock_mutations sm ON p.id = sm.product_id
        GROUP BY p.id, p.kode_produk, p.nama_produk, p.kategori, p.satuan, p.harga_jual, p.stok_minimal
        HAVING stok_akhir > 0
        ORDER BY CAST(SUBSTRING(p.kode_produk, 5) AS UNSIGNED) ASC, p.nama_produk ASC
    ";

    $result = $conn->query($query);
    if (!$result) {
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
            --secondary: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
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
        }

        .sidebar-menu a:hover {
            .status-badge.danger { background-color: rgba(239, 68, 68, 0.1); color: var(--danger); }
            .status-badge.success { background-color: rgba(16, 185, 129, 0.1); color: var(--secondary); }
        }

        /* Modal styles */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.4);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1500;
        }

        .modal-overlay.show {
            display: flex;
        }

        .modal-card {
            background: #fff;
            width: min(960px, 95vw);
            border-radius: 16px;
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            animation: modalEnter 0.25s ease-out;
        }

        @keyframes modalEnter {
            from {
                opacity: 0;
                transform: translateY(12px) scale(0.98);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h5 {
            margin: 0;
            font-size: 1.1rem;
            color: var(--dark);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            color: var(--gray-500);
            cursor: pointer;
        }

        .modal-body {
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .modal-body .form-group label {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--gray-600);
            margin-bottom: 0.4rem;
            display: block;
        }

        .modal-body .form-group input,
        .modal-body .form-group select,
        .modal-body .form-group textarea {
            width: 100%;
            border: 1px solid var(--gray-300);
            border-radius: 10px;
            padding: 0.8rem 1rem;
            font-size: 0.95rem;
            transition: border 0.2s, box-shadow 0.2s;
            background: #fff;
        }

        .modal-body .form-group input:focus,
        .modal-body .form-group select:focus,
        .modal-body .form-group textarea:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        .order-modal-grid {
            display: grid;
            grid-template-columns: 0.9fr 1.1fr;
            gap: 1rem;
        }

        .order-info-card,
        .order-items-card,
        .order-summary-card {
            border: 1px solid var(--gray-200);
            border-radius: 14px;
            padding: 1rem;
            background: var(--gray-50);
        }

        .order-info-card h6,
        .order-items-card h6,
        .order-summary-card h6 {
            margin: 0 0 0.75rem 0;
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--gray-700);
        }

        .form-group.inline {
            display: flex;
            gap: 0.75rem;
        }

        .form-group.inline .form-group {
            flex: 1;
        }

        .items-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 0.5rem;
        }

        .items-table th {
            text-align: left;
            font-size: 0.8rem;
            color: var(--gray-500);
            font-weight: 600;
            padding-bottom: 0.25rem;
        }

        .item-row {
            background: #fff;
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            padding: 0.75rem;
            display: grid;
            grid-template-columns: 2fr 0.9fr 0.9fr 0.6fr;
            gap: 0.75rem;
            align-items: center;
        }

        .item-row select {
            width: 100%;
        }

        .qty-control {
            display: flex;
            border: 1px solid var(--gray-300);
            border-radius: 10px;
            overflow: hidden;
        }

        .qty-control button {
            background: none;
            border: none;
            width: 36px;
            height: 38px;
            font-size: 1rem;
            cursor: pointer;
            color: var(--gray-500);
        }

        .qty-control button:hover {
            background: var(--gray-100);
        }

        .qty-control input {
            border: none;
            width: 100%;
            text-align: center;
            font-weight: 600;
        }

        .remove-item-btn {
            border: none;
            background: rgba(239, 68, 68, 0.1);
            color: #b91c1c;
            border-radius: 10px;
            height: 38px;
            cursor: pointer;
            font-weight: 600;
        }

        .remove-item-btn:hover {
            background: rgba(239, 68, 68, 0.2);
        }

        .add-item-btn {
            border: 1px dashed var(--primary);
            background: rgba(79, 70, 229, 0.08);
            color: var(--primary);
            border-radius: 10px;
            padding: 0.5rem 1rem;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            margin-top: 0.5rem;
        }

        .add-item-btn:hover {
            background: rgba(79, 70, 229, 0.15);
        }

        .order-summary-card {
            margin-top: 1rem;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .summary-row.total {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
        }

        .selected-items-list {
            margin-top: 1rem;
            max-height: 150px;
            overflow-y: auto;
            border-top: 1px solid var(--gray-200);
            padding-top: 0.5rem;
        }

        .selected-item {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            padding: 0.25rem 0;
        }

        .empty-products-hint {
            padding: 1rem;
            border: 1px dashed var(--gray-300);
            border-radius: 12px;
            background: #fff;
            color: var(--gray-500);
            text-align: center;
            font-size: 0.9rem;
        }

        @media (max-width: 900px) {
            .order-modal-grid {
                grid-template-columns: 1fr;
            }
        }

        .modal-footer {
            padding: 1.25rem 1.5rem 1.5rem;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        .btn-secondary {
            background: var(--gray-200);
            color: var(--gray-700);
        }

        .btn-secondary:hover {
            background: var(--gray-300);
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
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
                   // Current button code (line ~1604-1607)
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
                                    <th>No. Order</th>
                                    <th>Tanggal</th>
                                    <th>Konsumen</th>
                                    <th>Total Item</th>
                                    <th>Total Nilai</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orders)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center">Tidak ada data order</td>
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
                                            <td><strong><?php echo htmlspecialchars($order['order_number']); ?></strong></td>
                                            <td><?php echo formatTanggal($order['order_date']); ?></td>
                                            <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                            <td><?php echo $total_items; ?> items</td>
                                            <td><strong><?php echo formatRupiah($order['total_amount']); ?></strong></td>
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
                                                    <a href="detail_order.php?id=<?php echo $order['id']; ?>" 
                                                       class="btn btn-sm btn-primary" title="Detail">
                                                        <i class="fas fa-eye"></i> Detail
                                                    </a>
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
                <div class="modal-body">
                    <?php if ($modal_disabled): ?>
                        <div class="empty-products-hint">
                            <?php if (empty($customers_list)): ?>
                                Tambah data konsumen terlebih dahulu sebelum membuat order baru.
                            <?php elseif (empty($products_list)): ?>
                                Stok barang kosong. Tambahkan stok di halaman Stok Barang agar dapat membuat order.
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="order-modal-grid">
                            <div>
                                <div class="order-info-card">
                                    <h6>Informasi Order</h6>
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
                                    <div class="form-group inline">
                                        <div class="form-group">
                                            <label>Tanggal Order</label>
                                            <input type="date" id="orderDateInput" name="order_date" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Deadline</label>
                                            <input type="date" id="deadlineInput" name="deadline">
                                        </div>
                                    </div>
                                    <div class="form-group inline">
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
                            </div>
                            <div>
                                <div class="order-items-card">
                                    <h6>Barang Dipesan</h6>
                                    <table class="items-table" id="itemsTable">
                                        <thead>
                                            <tr>
                                                <th style="min-width:180px;">Produk</th>
                                                <th>Stok</th>
                                                <th>Jumlah</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody id="itemsTableBody">
                                        </tbody>
                                    </table>
                                    <button type="button" class="add-item-btn" id="addItemBtn">
                                        <i class="fas fa-plus"></i> Tambah Barang
                                    </button>
                                    <div class="order-summary-card">
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
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type='button' class="btn btn-secondary" id="cancelOrderModal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="submitOrderBtn" <?php echo $modal_disabled ? 'disabled' : ''; ?>>
                        Simpan Order
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
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

        document.getElementById('logoutBtn').addEventListener('click', function(e) {
            if (!confirm('Apakah Anda yakin ingin keluar?')) {
                e.preventDefault();
            }
        });

        const productsData = <?php echo json_encode($products_js_data, JSON_UNESCAPED_UNICODE); ?>;
        const customersData = <?php echo json_encode($customers_js_data, JSON_UNESCAPED_UNICODE); ?>;
        const modalDisabled = <?php echo $modal_disabled ? 'true' : 'false'; ?>;

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

        const productsMap = new Map(productsData.map(product => [product.id, product]));
        const customersMap = new Map(customersData.map(customer => [customer.id, customer]));

        let itemRows = [];
        let rowCounter = 0;

        function formatRupiah(value) {
            return 'Rp ' + new Intl.NumberFormat('id-ID', { minimumFractionDigits: 0 }).format(value);
        }

        function toggleModal(show) {
            if (show) {
                orderModal.classList.add('show');
                document.body.style.overflow = 'hidden';
                if (!modalDisabled && itemRows.length === 0) {
                    addItemRow();
                }
            } else {
                orderModal.classList.remove('show');
                document.body.style.overflow = '';
            }
        }

        function updateCustomerPhone() {
            if (!customerSelect || !customerPhoneDisplay) return;
            const customerId = parseInt(customerSelect.value || '0', 10);
            const detail = customersMap.get(customerId);
            if (detail && detail.phone) {
                customerPhoneDisplay.textContent = `Telp: ${detail.phone}`;
            } else {
                customerPhoneDisplay.textContent = 'Nomor telepon akan tampil di sini';
            }
        }

        function updateSummary() {
            let totalItems = 0;
            let totalAmount = 0;
            selectedItemsList.innerHTML = '';

            itemRows.forEach(row => {
                const product = productsMap.get(row.productId);
                if (!product) {
                    return;
                }
                totalItems += row.quantity;
                totalAmount += row.quantity * product.harga_jual;

                const summaryItem = document.createElement('div');
                summaryItem.className = 'selected-item';
                summaryItem.innerHTML = `
                    <span>${product.nama_produk} (${row.quantity} ${product.satuan})</span>
                    <strong>${formatRupiah(row.quantity * product.harga_jual)}</strong>
                `;
                selectedItemsList.appendChild(summaryItem);
            });

            summaryTotalItems.textContent = totalItems;
            summaryTotalAmount.textContent = formatRupiah(totalAmount);
        }

        function bindRowEvents(rowId) {
            const selectEl = document.querySelector(`[data-row-select="${rowId}"]`);
            const stockEl = document.querySelector(`[data-row-stock="${rowId}"]`);
            const qtyInput = document.querySelector(`[data-row-qty="${rowId}"]`);
            const decreaseBtn = document.querySelector(`[data-row-decrease="${rowId}"]`);
            const increaseBtn = document.querySelector(`[data-row-increase="${rowId}"]`);
            const removeBtn = document.querySelector(`[data-row-remove="${rowId}"]`);

            function updateRowState() {
                const selectedId = parseInt(selectEl.value || '0', 10);
                const product = productsMap.get(selectedId);
                const stateRow = itemRows.find(r => r.rowId === rowId);
                if (!stateRow) return;

                stateRow.productId = selectedId;

                if (product) {
                    stockEl.textContent = `${product.stok_akhir} ${product.satuan}`;
                    const maxQty = Math.max(1, product.stok_akhir);
                    qtyInput.max = maxQty;
                    if (stateRow.quantity > maxQty) {
                        stateRow.quantity = maxQty;
                        qtyInput.value = maxQty;
                    }
                } else {
                    stockEl.textContent = '-';
                }
                updateSummary();
            }

            selectEl.addEventListener('change', () => {
                updateRowState();
            });

            const adjustQuantity = (delta) => {
                const stateRow = itemRows.find(r => r.rowId === rowId);
                if (!stateRow) return;
                const newQty = Math.max(1, stateRow.quantity + delta);
                const product = productsMap.get(stateRow.productId);
                const maxQty = product ? product.stok_akhir : 9999;
                stateRow.quantity = Math.min(newQty, maxQty);
                qtyInput.value = stateRow.quantity;
                updateSummary();
            };

            decreaseBtn.addEventListener('click', () => adjustQuantity(-1));
            increaseBtn.addEventListener('click', () => adjustQuantity(1));

            qtyInput.addEventListener('input', () => {
                const stateRow = itemRows.find(r => r.rowId === rowId);
                if (!stateRow) return;
                let value = parseInt(qtyInput.value || '1', 10);
                if (isNaN(value) || value < 1) {
                    value = 1;
                }
                const product = productsMap.get(stateRow.productId);
                const maxQty = product ? product.stok_akhir : value;
                stateRow.quantity = Math.min(value, maxQty);
                qtyInput.value = stateRow.quantity;
                updateSummary();
            });

            removeBtn.addEventListener('click', () => {
                itemsTableBody.querySelector(`[data-row="${rowId}"]`).remove();
                itemRows = itemRows.filter(row => row.rowId !== rowId);
                if (itemRows.length === 0) {
                    updateSummary();
                } else {
                    updateSummary();
                }
            });

            updateRowState();
        }

        function buildProductOptions(selectedId = '') {
            return ['<option value="">— Pilih Barang —</option>'].concat(
                productsData.map(product => {
                    const disabled = product.stok_akhir <= 0 ? 'disabled' : '';
                    const selected = product.id === selectedId ? 'selected' : '';
                    return `<option value="${product.id}" ${disabled} ${selected}>
                        ${product.nama_produk} (${product.kode_produk})
                    </option>`;
                })
            ).join('');
        }

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
            });

            tr.innerHTML = `
                <td>
                    <select data-row-select="${rowId}">
                        ${buildProductOptions(defaultProductId)}
                    </select>
                </td>
                <td>
                    <span data-row-stock="${rowId}">-</span>
                </td>
                <td>
                    <div class="qty-control">
                        <button type="button" data-row-decrease="${rowId}">-</button>
                        <input type="number" value="1" min="1" data-row-qty="${rowId}">
                        <button type="button" data-row-increase="${rowId}">+</button>
                    </div>
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

        function collectItemsPayload() {
            return itemRows
                .filter(row => row.productId && row.quantity > 0)
                .map(row => ({
                    product_id: row.productId,
                    quantity: row.quantity,
                }));
        }

        if (customerSelect) {
            customerSelect.addEventListener('change', updateCustomerPhone);
        }

        if (!modalDisabled && addItemBtn) {
            addItemBtn.addEventListener('click', () => addItemRow());
        }

        if (!modalDisabled && openOrderModal) {
            openOrderModal.addEventListener('click', () => toggleModal(true));
        } else if (openOrderModal) {
            openOrderModal.addEventListener('click', () => {
                alert('Tidak dapat membuat order. Pastikan konsumen dan stok barang tersedia.');
            });
        }

        closeOrderModal.addEventListener('click', () => toggleModal(false));
        cancelOrderModal.addEventListener('click', () => toggleModal(false));

        orderModal.addEventListener('click', (event) => {
            if (event.target === orderModal) {
                toggleModal(false);
            }
        });

        createOrderForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (modalDisabled) {
                alert('Tidak dapat membuat order. Pastikan konsumen dan stok barang tersedia.');
                return;
            }
            const itemsPayload = collectItemsPayload();
            if (itemsPayload.length === 0) {
                alert('Tambahkan minimal satu barang pada order.');
                return;
            }

            itemsInput.value = JSON.stringify(itemsPayload);

            const formData = new FormData(createOrderForm);
            try {
                submitOrderBtn.disabled = true;
                submitOrderBtn.textContent = 'Menyimpan...';
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Gagal menyimpan order.');
                }
                alert(result.message || 'Order berhasil dibuat.');
                window.location.reload();
            } catch (error) {
                alert(error.message);
            } finally {
                submitOrderBtn.disabled = false;
                submitOrderBtn.textContent = 'Simpan Order';
            }
        });

        if (openOrderModal) {
    openOrderModal.addEventListener('click', () => {
        if (modalDisabled) {
            alert('Tidak dapat membuat order. Pastikan konsumen dan stok barang tersedia.');
            return;
        }
        toggleModal(true);
    });
}

        updateCustomerPhone();
    </script>
</body>
</html>

<?php $conn->close(); ?>