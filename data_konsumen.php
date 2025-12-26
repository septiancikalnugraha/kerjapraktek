<?php
require_once __DIR__ . '/config/config.php';
requireLogin();
$conn = getConnection();

// Query untuk statistik
$total_konsumen = 0;
$konsumen_aktif = 0;
$konsumen_baru = 0;

// Get total konsumen
$result_total = $conn->query("SELECT COUNT(*) as total FROM konsumen");
if ($result_total) {
    $total_konsumen = $result_total->fetch_assoc()['total'];
}

// Get konsumen aktif (those with at least one order)
$result_aktif = $conn->query("SELECT COUNT(DISTINCT id) as total FROM konsumen WHERE id IN (SELECT DISTINCT konsumen_id FROM orders)");
if ($result_aktif) {
    $konsumen_aktif = $result_aktif->fetch_assoc()['total'];
}

// Get new konsumen this month
$result_baru = $conn->query("SELECT COUNT(*) as total FROM konsumen WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
if ($result_baru) {
    $konsumen_baru = $result_baru->fetch_assoc()['total'];
}

// Debug: Check columns in all relevant tables
$debug_konsumen = $conn->query("SHOW COLUMNS FROM konsumen");
$konsumen_columns = [];
if ($debug_konsumen) {
    while($col = $debug_konsumen->fetch_assoc()) {
        $konsumen_columns[] = $col['Field'];
    }
}

$debug_barang = $conn->query("SHOW COLUMNS FROM barang");
$barang_columns = [];
if ($debug_barang) {
    while($col = $debug_barang->fetch_assoc()) {
        $barang_columns[] = $col['Field'];
    }
}

// Check orders table structure to find the correct column name for barang_id
$debug_orders = $conn->query("SHOW COLUMNS FROM orders");
$orders_columns = [];
if ($debug_orders) {
    while($col = $debug_orders->fetch_assoc()) {
        $orders_columns[] = $col['Field'];
    }
}

// Determine the correct column name for joining with barang
$barang_id_column = '';
$possible_barang_columns = ['barang_id', 'produk_id', 'id_barang', 'id_produk'];
foreach ($possible_barang_columns as $col) {
    if (in_array($col, $orders_columns)) {
        $barang_id_column = $col;
        break;
    }
}

// Build the product description part based on available columns
$produk_desc_parts = [];
if (in_array('kategori', $barang_columns)) {
    $produk_desc_parts[] = "b.kategori";
}
if (in_array('nama_barang', $barang_columns)) {
    $produk_desc_parts[] = "COALESCE(b.nama_barang, '')";
} else if (in_array('nama', $barang_columns)) {
    $produk_desc_parts[] = "COALESCE(b.nama, '')";
}

$produk_desc = !empty($produk_desc_parts) ? 
    "CONCAT_WS(' - ', " . implode(", ", $produk_desc_parts) . ")" :
    "'Produk ID: ', b.id";

// Query untuk mengambil data konsumen beserta ringkasan transaksi (jika ada)
// Basis data adalah tabel konsumen sehingga konsumen tanpa transaksi tetap muncul
$select_columns = [
    'k.id as konsumen_id',
    'k.nama_konsumen',
    'k.email',
    in_array('no_hp', $konsumen_columns) ? 'k.no_hp as telepon' : 
        (in_array('telepon', $konsumen_columns) ? 'k.telepon' : "'' as telepon"),
    'GROUP_CONCAT(' . $produk_desc . ' SEPARATOR ", ") as barang_dipesan',
    // Gunakan tanggal transaksi terakhir (jika ada)
    'DATE(MAX(o.created_at)) as tanggal_order',
    'DAY(MAX(o.created_at)) as hari',
    'MONTHNAME(MAX(o.created_at)) as bulan',
    'YEAR(MAX(o.created_at)) as tahun',
    'DATE_FORMAT(MAX(o.created_at), "%H:%i:%s") as jam_order',
    'COUNT(DISTINCT o.id) as total_order',
    'MAX(o.id) as order_id'
];

$group_by = ['k.id', 'k.nama_konsumen', 'k.email'];
if (in_array('no_hp', $konsumen_columns)) {
    $group_by[] = 'k.no_hp';
} else if (in_array('telepon', $konsumen_columns)) {
    $group_by[] = 'k.telepon';
}

// Build the join condition based on available columns
$join_condition = "";
if ($barang_id_column) {
    $join_condition = "LEFT JOIN barang b ON o.$barang_id_column = b.id";
} else {
    // If no matching column is found, try to join through order_details if it exists
    if (in_array('order_details', $conn->query("SHOW TABLES")->fetch_all(MYSQLI_NUM)[0])) {
        $join_condition = "LEFT JOIN order_details od ON o.id = od.order_id
          LEFT JOIN barang b ON od.barang_id = b.id";
    } else {
        // If no join is possible, just select NULL for product info
        $select_columns[4] = "'' as barang_dipesan";
        $join_condition = "";
    }
}

$query = "SELECT 
          " . implode(",\n          ", $select_columns) . "
          FROM konsumen k
          LEFT JOIN orders o ON o.konsumen_id = k.id
          $join_condition
          GROUP BY " . implode(", ", $group_by) . "
          ORDER BY k.id DESC";

// Uncomment for debugging
// echo "<pre>" . htmlspecialchars($query) . "</pre>";

$result = $conn->query($query);
if (!$result) {
    die("Query failed: " . $conn->error);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Konsumen - CV. Panca Indra Kemasan</title>
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

        .btn-outline {
            background-color: white;
            border: 1px solid var(--gray-300);
            color: var(--gray-700);
        }

        .btn-outline:hover {
            background-color: var(--gray-100);
        }

        .btn-sm {
            padding: 0.4rem 0.75rem;
            font-size: 0.8rem;
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
            background-color: #d1fae5;
            color: #059669;
        }

        .stat-icon.purple {
            background-color: #e9d5ff;
            color: #9333ea;
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

        .card-actions {
            display: flex;
            gap: 0.75rem;
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

        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-primary {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .badge-success {
            background-color: #d1fae5;
            color: #065f46;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .text-center {
            text-align: center;
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

            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .card-actions {
                width: 100%;
            }

            .card-actions .btn {
                flex: 1;
            }

            .table-container {
                font-size: 0.8rem;
            }

            .action-buttons {
                flex-direction: column;
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
                    <div class="navbar-title">Data Konsumen</div>
                </div>

                <div class="navbar-right">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Cari transaksi..." id="searchInput">
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
                        <h1 class="page-title">Data Konsumen</h1>
                        <p class="page-subtitle">Kelola data konsumen dan transaksi pelanggan perusahaan</p>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-label">Total Konsumen</div>
                            <div class="stat-value"><?php echo $total_konsumen; ?></div>
                        </div>
                        <div class="stat-icon blue">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-label">Konsumen Aktif</div>
                            <div class="stat-value"><?php echo $konsumen_aktif; ?></div>
                        </div>
                        <div class="stat-icon green">
                            <i class="fas fa-user-check"></i>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-label">Konsumen Baru (Bulan Ini)</div>
                            <div class="stat-value"><?php echo $konsumen_baru; ?></div>
                        </div>
                        <div class="stat-icon purple">
                            <i class="fas fa-user-plus"></i>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Daftar Transaksi Konsumen</h3>
                        <div class="card-actions">
                            <button type="button" class="btn btn-outline" onclick="downloadTemplate()">
                                <i class="fas fa-file-download"></i> Template
                            </button>
                            <button type="button" class="btn btn-outline" onclick="document.getElementById('importFile').click()">
                                <i class="fas fa-file-import"></i> Import
                            </button>
                            <input type="file" id="importFile" style="display: none;" accept=".xlsx,.xls,.csv" onchange="handleFileImport(this.files[0])">
                            <button class="btn btn-outline" onclick="exportData()">
                                <i class="fas fa-download"></i> Export
                            </button>
                            <button class="btn btn-primary" onclick="tambahKonsumen()">
                                <i class="fas fa-plus"></i> Tambah Konsumen
                            </button>
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
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($result && $result->num_rows > 0): ?>
                                    <?php while ($row = $result->fetch_assoc()): ?>
                                        <?php
                                            $hasOrder = !empty($row['order_id']);
                                            $totalOrder = (int)($row['total_order'] ?? 0);
                                        ?>
                                        <tr>
                                            <td>
                                                <?php if ($hasOrder): ?>
                                                    <div><strong>#TRX-<?php echo str_pad($row['order_id'], 5, '0', STR_PAD_LEFT); ?></strong></div>
                                                    <div class="text-xs text-gray-500"><?php echo $row['hari'] . ' ' . $row['bulan'] . ' ' . $row['tahun'] . ' ' . $row['jam_order']; ?></div>
                                                <?php else: ?>
                                                    <div><strong>-</strong></div>
                                                    <div class="text-xs text-gray-500">Belum ada transaksi</div>
                                                <?php endif; ?>
                                            </td>

                                            <td><?php echo htmlspecialchars($row['nama_konsumen']); ?></td>
                                            <td><?php echo htmlspecialchars($row['email']); ?></td>
                                            <td><?php echo htmlspecialchars($row['telepon']); ?></td>
                                            <td>
                                                <div style="max-width: 300px; overflow: hidden; text-overflow: ellipsis;">
                                                    <?php echo htmlspecialchars($row['barang_dipesan'] ?: 'Belum ada barang'); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge badge-primary">
                                                    <?php echo $totalOrder; ?> Item
                                                </span>
                                            </td>

                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-sm btn-icon" onclick="lihatDetail(<?php echo $row['order_id']; ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-icon" onclick="editKonsumen(<?php echo $row['order_id']; ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-danger" onclick="hapusKonsumen(<?php echo $row['order_id']; ?>, '<?php echo htmlspecialchars($row['nama_konsumen']); ?>')">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center">Tidak ada data transaksi</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <footer class="footer">
                <p> CV. Panca Indra Kemasan. All Rights Reserved. | Sistem Manajemen Dashboard v2.0</p>
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

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 1024) {
                if (!sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // Show sidebar toggle button on mobile
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

        // Logout confirmation
        document.getElementById('logoutBtn').addEventListener('click', function(e) {
            if (!confirm('Apakah Anda yakin ingin keluar?')) {
                e.preventDefault();
            }
        });

        // Search functionality
        const searchInput = document.getElementById('searchInput');
        searchInput.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const tableRows = document.querySelectorAll('tbody tr');
            
            tableRows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });

        // Tambah Konsumen
        function tambahKonsumen() {
            alert('Form tambah konsumen akan segera ditampilkan');
            // window.location.href = 'konsumen_create.php';
        }

        // Lihat Detail
        function lihatDetail(id) {
            alert('Menampilkan detail transaksi ID: ' + id);
            // window.location.href = 'transaksi_detail.php?id=' + id;
        }

        // Edit Konsumen
        function editKonsumen(id) {
            alert('Edit transaksi ID: ' + id);
            // window.location.href = 'transaksi_edit.php?id=' + id;
        }

        // Hapus Konsumen
        function hapusKonsumen(id, nama) {
            if (confirm('Apakah Anda yakin ingin menghapus transaksi untuk konsumen "' + nama + '"?\nSemua data transaksi terkait juga akan terhapus!')) {
                alert('Transaksi untuk konsumen "' + nama + '" berhasil dihapus!');
                // Ajax call untuk menghapus data
                // fetch('delete_transaksi.php?id=' + id, {method: 'DELETE'})
                //     .then(response => response.json())
                //     .then(data => {
                //         if(data.success) {
                //             location.reload();
                //         }
                //     });
            }
        }

        // Export Data
        function exportData() {
            // Get current search and filter values
            const search = document.querySelector('input[name="search"]')?.value || '';
            const status = document.querySelector('select[name="status"]')?.value || '';
            
            // Create export URL with current filters
            const url = `export_konsumen.php?search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}`;
            
            // Trigger download
            const link = document.createElement('a');
            link.href = url;
            link.download = `data_konsumen_${new Date().toISOString().split('T')[0]}.xlsx`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function downloadTemplate() {
            // Create a link to download the template
            const link = document.createElement('a');
            link.href = 'download_template.php?type=konsumen';
            link.download = 'template_konsumen.xlsx';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function handleFileImport(file) {
            if (!file) return;

            if (!confirm('Apakah Anda yakin ingin mengimpor data konsumen? Pastikan format file sudah sesuai dengan template.')) {
                return;
            }

            const formData = new FormData();
            formData.append('file', file);

            // Tombol import adalah tombol yang memicu input file
            const importBtn = document.querySelector('button[onclick*="importFile"]') || document.querySelector('button[onclick*="handleFileImport"]');
            const originalText = importBtn ? importBtn.innerHTML : null;
            if (importBtn) {
                importBtn.disabled = true;
                importBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mengimpor...';
            }

            fetch('import_konsumen.php', {
                method: 'POST',
                body: formData
            })
            .then(async (response) => {
                const raw = await response.text();

                if (!raw) {
                    throw new Error('Server tidak mengembalikan data (respon kosong).');
                }

                let data;
                try {
                    data = JSON.parse(raw);
                } catch (e) {
                    console.error('Respon mentah dari server (bukan JSON):', raw);
                    throw new Error('Respon server tidak valid. Detail: ' + raw.substring(0, 200));
                }

                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Gagal mengimpor data');
                }

                return data;
            })
            .then((data) => {
                alert('Data berhasil diimpor: ' + data.message);
                // Untuk saat ini, reload halaman agar tabel ikut ter‑update
                location.reload();
            })
            .catch((error) => {
                console.error('Error import konsumen:', error);
                alert('Gagal mengimpor data: ' + error.message);
            })
            .finally(() => {
                if (importBtn) {
                    importBtn.disabled = false;
                    importBtn.innerHTML = originalText;
                }
                const fileInput = document.getElementById('importFile');
                if (fileInput) fileInput.value = '';
            });
        }
    </script>
</body>
</html>
<?php $conn->close(); ?>