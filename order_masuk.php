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

try {
    // Check if tables exist
    $tableCheck = $conn->query("SHOW TABLES LIKE 'orders'");
    if ($tableCheck->num_rows == 0) {
        throw new Exception("Tabel 'orders' tidak ditemukan di database.");
    }

    $tableCheck = $conn->query("SHOW TABLES LIKE 'customers'");
    if ($tableCheck->num_rows == 0) {
        throw new Exception("Tabel 'customers' tidak ditemukan di database.");
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

    // Query untuk menghitung total data
    $count_query = "SELECT COUNT(*) as total 
                   FROM orders o
                   JOIN customers c ON o.$customer_id_column = c.id
                   WHERE DATE(o.$date_column) BETWEEN ? AND ?";
    
    // Check which columns exist in the orders table
    $has_total_amount = in_array('total_amount', $columns);
    $has_status = in_array('status', $columns);
    $order_number_column = in_array('order_number', $columns) ? 'o.order_number' : 'o.id as order_number';
    
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
        'c.name as customer_name',
        'c.phone as customer_phone'
    ]);
    
    $query = "SELECT 
                " . implode(",\n                ", $select_fields) . "
              FROM orders o
              JOIN customers c ON o.$customer_id_column = c.id
              WHERE DATE(o.$date_column) BETWEEN ? AND ?";
    
    // Tambahkan filter pencarian jika ada
    if (!empty($search)) {
        $search_param = "%$search%";
        $order_number_condition = in_array('order_number', $columns) ? "o.order_number LIKE ?" : "o.id LIKE ?";
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
    
} catch (Exception $e) {
    error_log("Error in order_masuk.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    
    // Show detailed error in development, generic in production
    if (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || $_SERVER['SERVER_NAME'] === 'localhost') {
        $error_message = "Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
    } else {
        $error_message = "Terjadi kesalahan saat mengambil data. Silakan coba lagi nanti.";
    }
}

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
                    <a href="tambah_order.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Buat Order Baru
                    </a>
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
    </script>
</body>
</html>
<?php $conn->close(); ?>