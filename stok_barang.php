<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$conn = getConnection();

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Inisialisasi variabel
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$kategori_filter = isset($_GET['kategori']) ? $_GET['kategori'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Inisialisasi variabel untuk menghindari undefined
$total_items = 0;
$total_pages = 1;
$critical_stock = 0;
$warning_stock = 0;
$total_stock_value = 0;
$products = [];
$categories = [];

// Query utama untuk mendapatkan data produk
$query = "SELECT 
            p.id,
            p.kode_produk,
            p.nama_produk,
            p.kategori,
            p.satuan,
            p.harga_jual,
            COALESCE(sm.stok_akhir, 0) as stok_akhir,
            p.stok_minimal
          FROM products p
          LEFT JOIN (
              SELECT 
                  product_id,
                  SUM(CASE WHEN type = 'in' THEN quantity ELSE -quantity END) as stok_akhir
              FROM stock_mutations
              GROUP BY product_id
          ) sm ON p.id = sm.product_id
          WHERE 1=1";

$count_query = "SELECT COUNT(*) as total 
                FROM products p
                WHERE 1=1";

// Tambahkan filter pencarian jika ada
if (!empty($search)) {
    $search_param = "%$search%";
    $query .= " AND (p.nama_produk LIKE ? OR p.kode_produk LIKE ?)";
    $count_query .= " AND (p.nama_produk LIKE ? OR p.kode_produk LIKE ?)";
}

// Tambahkan filter kategori jika ada
if (!empty($kategori_filter)) {
    $query .= " AND p.kategori = ?";
    $count_query .= " AND p.kategori = ?";
}

$query .= " ORDER BY p.nama_produk ASC LIMIT ? OFFSET ?";

// Get total rows for pagination
try {
    // Prepare count query
    $stmt = $conn->prepare($count_query);
    if ($stmt === false) {
        throw new Exception("Failed to prepare count query: " . $conn->error);
    }

    // Bind parameters for count query
    $types = '';
    $params = [];
    
    if (!empty($search)) {
        $types .= 'ss';
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    if (!empty($kategori_filter)) {
        $types .= 's';
        $params[] = $kategori_filter;
    }
    
    // Bind parameters if there are any
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    // Execute count query
    if (!$stmt->execute()) {
        throw new Exception("Failed to execute count query: " . $stmt->error);
    }
    
    // Get total rows
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $total_rows = $row['total'];
    $total_pages = ceil($total_rows / $per_page);
    
    // Prepare main query
    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        throw new Exception("Failed to prepare main query: " . $conn->error);
    }
    
    // Bind parameters for main query
    $types = '';
    $params = [];
    
    if (!empty($search)) {
        $types .= 'ss';
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    if (!empty($kategori_filter)) {
        $types .= 's';
        $params[] = $kategori_filter;
    }
    
    // Add pagination parameters
    $types .= 'ii';
    $params[] = $per_page;
    $params[] = $offset;
    
    // Bind all parameters
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    // Execute main query
    if (!$stmt->execute()) {
        throw new Exception("Failed to execute main query: " . $stmt->error);
    }
    
    // Get results
    $result = $stmt->get_result();
    $products = $result->fetch_all(MYSQLI_ASSOC);
    
    // Calculate statistics
    $critical_stock = 0;
    $warning_stock = 0;
    $total_stock_value = 0;
    
    foreach ($products as $product) {
        $stok = $product['stok_akhir'];
        $min_stok = $product['stok_minimal'];
        $total_stock_value += $stok * $product['harga_jual'];
        
        if ($stok <= $min_stok) {
            $critical_stock++;
        } elseif ($stok <= ($min_stok * 1.5)) {
            $warning_stock++;
        }
    }
    
    // Get categories for filter
    $categories_query = "SELECT DISTINCT kategori FROM products WHERE kategori IS NOT NULL AND kategori != '' ORDER BY kategori";
    $categories_result = $conn->query($categories_query);
    $categories = [];
    while ($row = $categories_result->fetch_assoc()) {
        $categories[] = $row['kategori'];
    }
    
} catch (Exception $e) {
    // Log the error
    error_log("Error in stok_barang.php: " . $e->getMessage());
    error_log("Query: " . $query);
    
    // Initialize empty results
    $products = [];
    $total_rows = 0;
    $total_pages = 1;
    $critical_stock = 0;
    $warning_stock = 0;
    $total_stock_value = 0;
    $categories = [];
    
    // Show error message
    $error_message = "Terjadi kesalahan saat mengambil data. Silakan coba lagi nanti.";
}

// Fungsi untuk format rupiah
function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stok Barang - CV. Panca Indra Kemasan</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-light: #818cf8;
            --primary-dark: #4338ca;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .header-left {
            flex: 1;
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

        .header-right {
            display: flex;
            gap: 1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--gray-600);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
        }

        .stat-icon.blue {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .stat-icon.red {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        .stat-icon.yellow {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }

        .stat-icon.green {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
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
            background-color: var(--primary-dark);
        }

        .btn-outline {
            background-color: white;
            border: 1px solid var(--gray-300);
            color: var(--gray-700);
        }

        .btn-outline:hover {
            background-color: var(--gray-100);
        }

        .btn-icon {
            padding: 0.5rem;
            background-color: var(--gray-100);
            color: var(--gray-700);
            border: none;
        }

        .btn-icon:hover {
            background-color: var(--gray-200);
        }

        .btn-sm {
            padding: 0.35rem 0.75rem;
            font-size: 0.8rem;
        }

        .btn-danger {
            background-color: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background-color: #dc2626;
        }

        .card {
            background: white;
            border-radius: 1rem;
            padding: 1.75rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--gray-100);
        }

        .card-title {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--dark);
        }

        .filter-group {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        .filter-group input,
        .filter-group select {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--gray-300);
            border-radius: 0.5rem;
            font-size: 0.9rem;
            transition: all 0.2s;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
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
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--gray-200);
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-200);
            color: var(--gray-700);
            font-size: 0.9rem;
        }

        tr:hover {
            background-color: var(--gray-50);
        }

        tr:last-child td {
            border-bottom: none;
        }

        .text-right {
            text-align: right;
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

        .badge-success {
            background-color: #d1fae5;
            color: #065f46;
        }

        .badge-warning {
            background-color: #fef3c7;
            color: #92400e;
        }

        .badge-danger {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .pagination a,
        .pagination span {
            padding: 0.5rem 1rem;
            border: 1px solid var(--gray-300);
            border-radius: 0.5rem;
            text-decoration: none;
            color: var(--gray-700);
            transition: all 0.2s;
        }

        .pagination a:hover {
            background-color: var(--gray-100);
        }

        .pagination .active {
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

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .header-right {
                width: 100%;
                justify-content: space-between;
            }
        }

        @media (max-width: 768px) {
            .search-box input {
                width: 150px;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filter-group {
                flex-direction: column;
                align-items: stretch;
            }

            table {
                font-size: 0.8rem;
            }

            td, th {
                padding: 0.75rem;
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
                    <li><a href="dashboard_clean.php"><i class="fas fa-home"></i> Dashboard</a></li>
                    <li><a href="analytics.php"><i class="fas fa-chart-bar"></i> Analytics</a></li>
                    <li><a href="laporan_bulanan.php"><i class="fas fa-calendar-alt"></i> Laporan Bulanan</a></li>
                </ul>
            </div>

            <div class="sidebar-section">
                <div class="sidebar-title">Inventory</div>
                <ul class="sidebar-menu">
                    <li><a href="stok_barang.php" class="active"><i class="fas fa-boxes"></i> Stok Barang</a></li>
                    <li><a href="barang_masuk.php"><i class="fas fa-arrow-down"></i> Barang Masuk</a></li>
                    <li><a href="barang_keluar.php"><i class="fas fa-arrow-up"></i> Barang Keluar</a></li>
                    <li><a href="stok_kritis.php"><i class="fas fa-exclamation-triangle"></i> Stok Kritis</a></li>
                </ul>
            </div>

            <div class="sidebar-section">
                <div class="sidebar-title">Penjualan</div>
                <ul class="sidebar-menu">
                    <li><a href="order_masuk.php"><i class="fas fa-shopping-cart"></i> Order Masuk</a></li>
                    <li><a href="order_pending.php"><i class="fas fa-clock"></i> Order Pending</a></li>
                    <li><a href="order_selesai.php"><i class="fas fa-check-circle"></i> Order Selesai</a></li>
                    <li><a href="data_konsumen.php"><i class="fas fa-users"></i> Data Konsumen</a></li>
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
                    <h2 class="navbar-title">Stok Barang</h2>
                </div>
                <div class="navbar-right">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <form method="GET" class="d-inline">
                            <input type="text" name="search" placeholder="Cari barang..." value="<?php echo htmlspecialchars($search); ?>">
                        </form>
                    </div>
                    <div class="user-profile">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)); ?>
                        </div>
                        <div class="user-info">
                            <span class="user-name"><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></span>
                            <span class="user-role"><?php echo htmlspecialchars($_SESSION['role'] ?? 'Admin'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-area">
                <div class="page-header">
                    <div class="header-left">
                        <h1 class="page-title">Manajemen Stok Barang</h1>
                        <p class="page-subtitle">Kelola data stok barang dengan mudah dan efisien</p>
                    </div>
                    <div class="header-actions">
                        <a href="tambah_barang.php" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i>Tambah Barang
                        </a>
                    </div>
                </div>
                <!-- Statistik Ringkas -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card bg-white rounded-lg shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Barang</h6>
                                        <h3 class="mb-0"><?php echo $total_items; ?></h3>
                                    </div>
                                    <div class="bg-primary bg-opacity-10 p-3 rounded-circle">
                                        <i class="fas fa-boxes text-primary"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-white rounded-lg shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <h6 class="text-muted mb-1">Stok Kritis</h6>
                                        <h3 class="text-danger mb-0"><?php echo $critical_stock; ?></h3>
                                    </div>
                                    <div class="bg-danger bg-opacity-10 p-3 rounded-circle">
                                        <i class="fas fa-exclamation-triangle text-danger"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-white rounded-lg shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <h6 class="text-muted mb-1">Total Nilai Stok</h6>
                                        <h3 class="text-success mb-0"><?php echo formatRupiah($total_stock_value); ?></h3>
                                    </div>
                                    <div class="bg-success bg-opacity-10 p-3 rounded-circle">
                                        <i class="fas fa-money-bill-wave text-success"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabel Daftar Barang -->
                <div class="card shadow-sm">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Daftar Stok Barang</h5>
                        <div>
                            <a href="tambah_barang.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus me-1"></i> Tambah Barang
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Filter dan Pencarian -->
                        <div class="row mb-4">
                            <div class="col-md-8">
                                <form method="GET" class="d-flex gap-2">
                                    <div class="input-group">
                                        <span class="input-group-text bg-white"><i class="fas fa-search"></i></span>
                                        <input type="text" name="search" class="form-control" placeholder="Cari barang..." value="<?php echo htmlspecialchars($search); ?>">
                                    </div>
                                    <select name="kategori" class="form-select" style="width: 200px;">
                                        <option value="">Semua Kategori</option>
                                        <?php foreach ($categories as $kategori): ?>
                                            <option value="<?php echo htmlspecialchars($kategori); ?>" <?php echo ($kategori_filter == $kategori) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($kategori); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-primary">Filter</button>
                                    <?php if (!empty($search) || !empty($kategori_filter)): ?>
                                        <a href="stok_barang.php" class="btn btn-outline-secondary">Reset</a>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>

                        <!-- Tabel -->
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Kode</th>
                                        <th>Nama Barang</th>
                                        <th>Kategori</th>
                                        <th class="text-end">Stok</th>
                                        <th class="text-end">Harga Jual</th>
                                        <th class="text-end">Total Nilai</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($products) > 0): ?>
                                        <?php foreach ($products as $product): ?>
                                            <?php 
                                                $status = '';
                                                $status_class = '';
                                                if ($product['stok_akhir'] <= $product['stok_minimal']) {
                                                    $status = 'Kritis';
                                                    $status_class = 'bg-danger';
                                                } elseif ($product['stok_akhir'] <= ($product['stok_minimal'] * 1.5)) {
                                                    $status = 'Hati-hati';
                                                    $status_class = 'bg-warning';
                                                } else {
                                                    $status = 'Aman';
                                                    $status_class = 'bg-success';
                                                }
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($product['kode_produk']); ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <?php if (!empty($product['image'])): ?>
                                                            <img src="<?php echo htmlspecialchars($product['image']); ?>" 
                                                                 class="rounded me-2" width="40" height="40" 
                                                                 style="object-fit: cover;">
                                                        <?php else: ?>
                                                            <div class="bg-light rounded d-flex align-items-center justify-content-center me-2" 
                                                                 style="width: 40px; height: 40px;">
                                                                <i class="fas fa-box text-muted"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div>
                                                            <div class="fw-medium"><?php echo htmlspecialchars($product['nama_produk']); ?></div>
                                                            <small class="text-muted"><?php echo htmlspecialchars($product['satuan']); ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($product['kategori']); ?></td>
                                                <td class="text-end"><?php echo number_format($product['stok_akhir'], 0, ',', '.'); ?></td>
                                                <td class="text-end"><?php echo formatRupiah($product['harga_jual']); ?></td>
                                                <td class="text-end fw-bold">
                                                    <?php echo formatRupiah($product['stok_akhir'] * $product['harga_jual']); ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge <?php echo $status_class; ?> text-white">
                                                        <?php echo $status; ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <div class="btn-group">
                                                        <a href="detail_barang.php?id=<?php echo $product['id']; ?>" 
                                                           class="btn btn-sm btn-outline-primary" 
                                                           title="Detail">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="edit_barang.php?id=<?php echo $product['id']; ?>" 
                                                           class="btn btn-sm btn-outline-secondary" 
                                                           title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <button type="button" 
                                                                class="btn btn-sm btn-outline-danger" 
                                                                title="Hapus"
                                                                onclick="confirmDelete(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars(addslashes($product['nama_produk'])); ?>')">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4">
                                                <div class="text-muted">
                                                    <i class="fas fa-inbox fa-3x mb-3"></i>
                                                    <p class="mb-0">Tidak ada data barang</p>
                                                    <?php if (!empty($search) || !empty($kategori_filter)): ?>
                                                        <p class="small">Coba hapus filter atau kata kunci pencarian</p>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <nav class="mt-4">
                                <ul class="pagination justify-content-center">
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=1&search=<?php echo urlencode($search); ?>&kategori=<?php echo urlencode($kategori_filter); ?>">
                                                <i class="fas fa-angle-double-left"></i>
                                            </a>
                                        </li>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo ($page - 1); ?>&search=<?php echo urlencode($search); ?>&kategori=<?php echo urlencode($kategori_filter); ?>">
                                                <i class="fas fa-angle-left"></i>
                                            </a>
                                        </li>
                                    <?php endif; ?>

                                    <?php
                                    $start = max(1, $page - 2);
                                    $end = min($total_pages, $start + 4);
                                    $start = max(1, $end - 4);
                                    
                                    for ($i = $start; $i <= $end; $i++): 
                                    ?>
                                        <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&kategori=<?php echo urlencode($kategori_filter); ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>

                                    <?php if ($page < $total_pages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo ($page + 1); ?>&search=<?php echo urlencode($search); ?>&kategori=<?php echo urlencode($kategori_filter); ?>">
                                                <i class="fas fa-angle-right"></i>
                                            </a>
                                        </li>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo $total_pages; ?>&search=<?php echo urlencode($search); ?>&kategori=<?php echo urlencode($kategori_filter); ?>">
                                                <i class="fas fa-angle-double-right"></i>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Konfirmasi Hapus -->
        <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteModalLabel">Konfirmasi Hapus</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        Apakah Anda yakin ingin menghapus <span id="productName" class="fw-bold"></span>?
                        <p class="text-danger mt-2">Data yang dihapus tidak dapat dikembalikan!</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <a href="#" id="confirmDeleteBtn" class="btn btn-danger">Hapus</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- JavaScript -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            // Fungsi untuk konfirmasi hapus
            function confirmDelete(id, name) {
                document.getElementById('productName').textContent = name;
                document.getElementById('confirmDeleteBtn').href = 'hapus_barang.php?id=' + id;
                var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
                deleteModal.show();
            }

            // Inisialisasi tooltip
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });

            // Auto-hide alert setelah 5 detik
            var alertList = document.querySelectorAll('.alert');
            alertList.forEach(function (alert) {
                setTimeout(function() {
                    var bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
        </script>
    </body>
</html>
<?php $conn->close(); ?>