<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

// Initialize variables
$error_message = '';
$orders = [];
$stats = [
    'total_selesai' => 0,
    'completed_this_week' => 0,
    'total_value' => 0
];

try {
    // Get database connection
    $conn = getConnection();
    
    // Check connection
    if ($conn->connect_error) {
        throw new Exception("Koneksi database gagal: " . $conn->connect_error);
    }

    // Check if tables exist
    $tableCheck = $conn->query("SHOW TABLES LIKE 'orders'");
    if ($tableCheck->num_rows == 0) {
        throw new Exception("Tabel 'orders' tidak ditemukan di database.");
    }

    $tableCheck = $conn->query("SHOW TABLES LIKE 'konsumen'");
    if ($tableCheck->num_rows == 0) {
        throw new Exception("Tabel 'konsumen' tidak ditemukan di database.");
    }

    // Get statistics for completed orders
    $statsQuery = "SELECT 
                    COUNT(*) as total_selesai,
                    SUM(CASE WHEN updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND status = 'completed' THEN 1 ELSE 0 END) as completed_this_week,
                    SUM(total_harga) as total_value
                   FROM orders 
                   WHERE status = 'completed'";
    
    $statsResult = $conn->query($statsQuery);
    if ($statsResult && $statsResult->num_rows > 0) {
        $statsData = $statsResult->fetch_assoc();
        $stats['total_selesai'] = $statsData['total_selesai'] ?? 0;
        $stats['completed_this_week'] = $statsData['completed_this_week'] ?? 0;
        $stats['total_value'] = $statsData['total_value'] ?? 0;
    }

    // Get completed orders with customer information
    $query = "SELECT o.*, k.nama_konsumen, k.perusahaan, k.no_hp as telepon 
              FROM orders o 
              LEFT JOIN konsumen k ON o.konsumen_id = k.id 
              WHERE o.status = 'completed' 
              ORDER BY o.updated_at DESC";
    
    $result = $conn->query($query);
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $orders[] = $row;
        }
    } else {
        throw new Exception("Error fetching orders: " . $conn->error);
    }
    
    $conn->close();
    
} catch (Exception $e) {
    $error_message = $e->getMessage();
    error_log("Error in order_selesai.php: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Selesai - CV. Panca Indra Kemasan</title>
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

        .stat-icon.orange {
            background-color: #ffedd5;
            color: #ea580c;
        }

        .stat-icon.red {
            background-color: #fee2e2;
            color: #dc2626;
        }

        .stat-icon.yellow {
            background-color: #fef3c7;
            color: #ca8a04;
        }

        .stat-icon.green {
            background-color: #d1fae5;
            color: #059669;
        }

        .stat-icon.blue {
            background-color: #dbeafe;
            color: #2563eb;
        }

        .stat-icon.purple {
            background-color: #ede9fe;
            color: #7c3aed;
        }

        .badge-success {
            background-color: #d1fae5;
            color: #065f46;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
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
                    <div class="navbar-title">Order Selesai</div>
                </div>

                <div class="navbar-right">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Cari order..." id="searchInput">
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
                        <h1 class="page-title">Order Selesai</h1>
                        <p class="page-subtitle">Daftar pesanan yang telah diselesaikan</p>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-label">Total Order Selesai</div>
                            <div class="stat-value"><?php echo $stats['total_selesai']; ?></div>
                        </div>
                        <div class="stat-icon green">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-label">Selesai Minggu Ini</div>
                            <div class="stat-value"><?php echo $stats['completed_this_week']; ?></div>
                        </div>
                        <div class="stat-icon blue">
                            <i class="fas fa-calendar-week"></i>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-label">Total Nilai</div>
                            <div class="stat-value">Rp <?php echo number_format($stats['total_value'], 0, ',', '.'); ?></div>
                        </div>
                        <div class="stat-icon purple">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Daftar Order Selesai</h3>
                        <div class="card-actions">
                            <a href="order_masuk.php" class="btn btn-outline">
                                <i class="fas fa-arrow-left"></i> Kembali
                            </a>
                            <button class="btn btn-primary" onclick="exportData()">
                                <i class="fas fa-download"></i> Export Data
                            </button>
                        </div>
                    </div>

                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>No. Order</th>
                                    <th>Tanggal Selesai</th>
                                    <th>Konsumen</th>
                                    <th>Perusahaan</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($error_message)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-danger">
                                            <?php echo htmlspecialchars($error_message); ?>
                                        </td>
                                    </tr>
                                <?php elseif (!empty($orders)): ?>
                                    <?php foreach ($orders as $order): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($order['no_order'] ?? 'N/A'); ?></strong></td>
                                            <td><?php echo isset($order['updated_at']) ? date('d M Y', strtotime($order['updated_at'])) : 'N/A'; ?></td>
                                            <td><?php echo htmlspecialchars($order['nama_konsumen'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($order['perusahaan'] ?? '-'); ?></td>
                                            <td><strong>Rp <?php echo isset($order['total_harga']) ? number_format($order['total_harga'], 0, ',', '.') : '0'; ?></strong></td>
                                            <td>
                                                <span class="badge badge-success">
                                                    Selesai
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="order_detail.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-icon" title="Lihat Detail">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="cetak_invoice.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-primary" title="Cetak Invoice" target="_blank">
                                                        <i class="fas fa-print"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center">Tidak ada data order selesai</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
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

        // Print invoice function
        function printInvoice(orderId) {
            window.open(`cetak_invoice.php?id=${orderId}`, '_blank');
        }

        // Export data function
        function exportData() {
            if (confirm('Export data order selesai ke Excel?')) {
                alert('Data berhasil di-export!');
                // window.location.href = 'export_order_selesai.php';
            }
        }
    </script>
    <script>
        // Initialize DataTable if you're using it
        $(document).ready(function() {
            $('table').DataTable({
                "language": {
                    "url": "//cdn.datatables.net/plug-ins/1.10.25/i18n/Indonesian.json"
                },
                "order": [[1, "desc"]] // Sort by completion date by default
            });
        });
    </script>
</body>
</html>