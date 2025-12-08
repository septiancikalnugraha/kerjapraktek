<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$conn = getConnection();
$page_title = "Order Pending";
$active_menu = "pending";

// Get pending orders statistics
$pendingOrdersQuery = "SELECT COUNT(*) as total_pending 
                      FROM transaksi 
                      WHERE status IN ('diproses', 'dikirim')";
$pendingResult = $conn->query($pendingOrdersQuery);
$totalPending = $pendingResult->fetch_assoc()['total_pending'];

// Get pending orders for processing
$processingQuery = "SELECT COUNT(*) as total_processing 
                  FROM transaksi 
                  WHERE status = 'diproses'";
$processingResult = $conn->query($processingQuery);
$totalProcessing = $processingResult->fetch_assoc()['total_processing'];

// Get pending orders for shipping
$shippingQuery = "SELECT COUNT(*) as total_shipping 
                FROM transaksi 
                WHERE status = 'dikirim'";
$shippingResult = $conn->query($shippingQuery);
$totalShipping = $shippingResult->fetch_assoc()['total_shipping'];

// Get recent pending orders
$pendingOrdersQuery = "SELECT t.*, p.nama as nama_pelanggan 
                      FROM transaksi t
                      LEFT JOIN pelanggan p ON t.pelanggan_id = p.id
                      WHERE t.status IN ('diproses', 'dikirim')
                      ORDER BY t.tgl_transaksi DESC";
$pendingOrdersResult = $conn->query($pendingOrdersQuery);
$pendingOrders = $pendingOrdersResult ? $pendingOrdersResult->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - CV. Panca Indra Kemasan</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
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

        /* Main Content Styles */
        .main-content {
            flex: 1;
            margin-left: 250px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Navbar Styles */
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

        .navbar-left, .navbar-right {
            display: flex;
            align-items: center;
        }

        .search-box {
            position: relative;
            margin-right: 1rem;
        }

        .search-box input {
            padding: 0.5rem 1rem 0.5rem 2.5rem;
            border-radius: 0.5rem;
            border: 1px solid var(--gray-300);
            background-color: var(--gray-50);
            width: 250px;
            font-size: 0.875rem;
        }

        .search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 0.5rem;
        }

        .user-menu:hover {
            background-color: var(--gray-100);
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .user-info {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 500;
            font-size: 0.875rem;
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--gray-500);
        }

        /* Content Area */
        .content-area {
            flex: 1;
            padding: 2rem;
            background-color: var(--gray-50);
        }

        .page-header {
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }

        .page-subtitle {
            color: var(--gray-500);
            font-size: 0.875rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background-color: white;
            border-radius: 0.75rem;
            padding: 1.5rem;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .stat-icon.blue {
            background-color: #e0e7ff;
            color: #4f46e5;
        }

        .stat-icon.green {
            background-color: #dcfce7;
            color: #10b981;
        }

        .stat-icon.orange {
            background-color: #ffedd5;
            color: #f59e0b;
        }

        .stat-icon.red {
            background-color: #fee2e2;
            color: #ef4444;
        }

        .stat-info {
            flex: 1;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--gray-900);
            line-height: 1.2;
        }

        .stat-label {
            color: var(--gray-500);
            font-size: 0.875rem;
        }

        /* Card Styles */
        .card {
            background-color: white;
            border-radius: 0.75rem;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
            margin: 0;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Table Styles */
        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background-color: var(--gray-50);
            color: var(--gray-600);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            text-align: left;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .table td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-200);
            vertical-align: middle;
        }

        .table tr:last-child td {
            border-bottom: none;
        }

        .table tr:hover {
            background-color: var(--gray-50);
        }

        /* Badge Styles */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.35em 0.65em;
            font-size: 0.75rem;
            font-weight: 500;
            border-radius: 0.375rem;
        }

        .badge.bg-primary {
            background-color: var(--primary) !important;
        }

        .badge.bg-success {
            background-color: var(--secondary) !important;
        }

        .badge.bg-warning {
            background-color: var(--warning) !important;
            color: var(--gray-900);
        }

        .badge.bg-danger {
            background-color: var(--danger) !important;
        }

        /* Button Styles */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 500;
            font-size: 0.875rem;
            line-height: 1.25rem;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid transparent;
        }

        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background-color: #4338ca;
            border-color: #4338ca;
        }

        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--gray-300);
            color: var(--gray-700);
        }

        .btn-outline:hover {
            background-color: var(--gray-100);
            border-color: var(--gray-400);
        }

        /* Grid Layout */
        .grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .col-span-8 {
            grid-column: span 8;
        }

        .col-span-4 {
            grid-column: span 4;
        }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.35em 0.65em;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: capitalize;
        }

        .status-diproses {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .status-dikirim {
            background-color: #fef3c7;
            color: #92400e;
        }

        .status-selesai {
            background-color: #dcfce7;
            color: #166534;
        }

        .status-batal {
            background-color: #fee2e2;
            color: #991b1b;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .search-box input {
                width: 200px;
            }

            .col-span-8, .col-span-4 {
                grid-column: span 12;
            }
        }

        @media (max-width: 768px) {
            .content-area {
                padding: 1.25rem;
            }
            
            .search-box {
                display: none;
            }
            
            .user-info {
                display: none;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-logo">
                <div class="logo-text">
                    <i class="fas fa-chart-line"></i>
                    <span>CV. Panca Indra</span>
                </div>
            </div>
            
            <div class="sidebar-section">
                <ul class="sidebar-menu">
                    <li>
                        <a href="index.php" class="<?= $active_menu == 'dashboard' ? 'active' : '' ?>">
                            <i class="fas fa-home"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a href="pending.php" class="<?= $active_menu == 'pending' ? 'active' : '' ?>">
                            <i class="fas fa-clock"></i>
                            <span>Order Pending</span>
                        </a>
                    </li>
                    <li>
                        <a href="transaksi.php" class="<?= $active_menu == 'transaksi' ? 'active' : '' ?>">
                            <i class="fas fa-shopping-cart"></i>
                            <span>Transaksi</span>
                        </a>
                    </li>
                </ul>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-title">Data</div>
                <ul class="sidebar-menu">
                    <li>
                        <a href="pelanggan.php">
                            <i class="fas fa-users"></i>
                            <span>Data Pelanggan</span>
                        </a>
                    </li>
                    <li>
                        <a href="#">
                            <i class="fas fa-box"></i>
                            <span>Data Produk</span>
                        </a>
                    </li>
                </ul>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-title">Laporan</div>
                <ul class="sidebar-menu">
                    <li>
                        <a href="#">
                            <i class="fas fa-file-alt"></i>
                            <span>Laporan Penjualan</span>
                        </a>
                    </li>
                </ul>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-title">Pengaturan</div>
                <ul class="sidebar-menu">
                    <li>
                        <a href="#">
                            <i class="fas fa-cog"></i>
                            <span>Pengaturan</span>
                        </a>
                    </li>
                </ul>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Navbar -->
            <div class="navbar">
                <div class="navbar-left">
                    <button class="btn btn-icon" id="sidebarToggle" style="display: none;">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Cari pesanan, pelanggan...">
                    </div>
                </div>
                <div class="navbar-right">
                    <div class="user-menu">
                        <div class="user-avatar">
                            <?= strtoupper(substr($_SESSION['user_name'] ?? 'U', 0, 1)) ?>
                        </div>
                        <div class="user-info">
                            <span class="user-name"><?= $_SESSION['user_name'] ?? 'User' ?></span>
                            <span class="user-role"><?= ucfirst($_SESSION['user_role'] ?? 'Admin') ?></span>
                        </div>
                        <i class="fas fa-chevron-down" style="font-size: 0.75rem;"></i>
                    </div>
                </div>
            </div>

            <!-- Content Area -->
            <div class="content-area">
                <div class="page-header">
                    <h1 class="page-title">Order Pending</h1>
                    <p class="page-subtitle">Daftar pesanan yang sedang dalam proses atau menunggu pengiriman</p>
                </div>

                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon blue">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-value"><?= number_format($totalPending) ?></div>
                            <div class="stat-label">Total Order Pending</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange">
                            <i class="fas fa-cog"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-value"><?= number_format($totalProcessing) ?></div>
                            <div class="stat-label">Dalam Proses</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green">
                            <i class="fas fa-truck"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-value"><?= number_format($totalShipping) ?></div>
                            <div class="stat-label">Dalam Pengiriman</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-value">0</div>
                            <div class="stat-label">Menunggu Konfirmasi</div>
                        </div>
                    </div>
                </div>

                <div class="grid">
                    <!-- Pending Orders -->
                    <div class="col-span-8">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Daftar Order Pending</h3>
                                <a href="tambah.php" class="btn btn-sm btn-primary">
                                    <i class="fas fa-plus mr-1"></i> Order Baru
                                </a>
                            </div>
                            <div class="card-body">
                                <?php if (isset($_SESSION['success'])): ?>
                                    <div class="alert alert-success alert-dismissible fade show" role="alert" style="background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; border-radius: 0.5rem; padding: 1rem; margin-bottom: 1.5rem;">
                                        <?= $_SESSION['success'] ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="position: absolute; right: 1rem; top: 1rem;"></button>
                                    </div>
                                    <?php unset($_SESSION['success']); ?>
                                <?php endif; ?>

                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>No. Order</th>
                                                <th>Pelanggan</th>
                                                <th>Tanggal</th>
                                                <th>Total</th>
                                                <th>Status</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($pendingOrders as $order): 
                                                $status_class = [
                                                    'diproses' => 'status-diproses',
                                                    'dikirim' => 'status-dikirim',
                                                    'selesai' => 'status-selesai',
                                                    'batal' => 'status-batal'
                                                ][$order['status']] ?? 'status-diproses';
                                            ?>
                                                <tr>
                                                    <td>#<?= str_pad($order['id'], 6, '0', STR_PAD_LEFT) ?></td>
                                                    <td><?= htmlspecialchars($order['nama_pelanggan'] ?? 'Tidak Diketahui') ?></td>
                                                    <td><?= date('d/m/Y', strtotime($order['tgl_transaksi'])) ?></td>
                                                    <td>Rp <?= number_format($order['total'], 0, ',', '.') ?></td>
                                                    <td>
                                                        <span class="status-badge <?= $status_class ?>">
                                                            <?= ucfirst($order['status']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="flex items-center gap-2">
                                                            <a href="detail.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline" title="Detail">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                            <?php if ($order['status'] == 'diproses'): ?>
                                                                <button class="btn btn-sm btn-warning update-status" 
                                                                        data-id="<?= $order['id'] ?>"
                                                                        data-status="dikirim"
                                                                        title="Tandai Dikirim">
                                                                    <i class="fas fa-truck"></i>
                                                                </button>
                                                            <?php elseif ($order['status'] == 'dikirim'): ?>
                                                                <button class="btn btn-sm btn-success update-status" 
                                                                        data-id="<?= $order['id'] ?>"
                                                                        data-status="selesai"
                                                                        title="Tandai Selesai">
                                                                    <i class="fas fa-check"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                            <a href="cetak.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline" target="_blank" title="Cetak">
                                                                <i class="fas fa-print"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($pendingOrders)): ?>
                                                <tr>
                                                    <td colspan="6" class="text-center py-4 text-gray-500">
                                                        Tidak ada order pending
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="col-span-4">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Aksi Cepat</h3>
                            </div>
                            <div class="card-body">
                                <div class="space-y-2">
                                    <a href="tambah.php" class="flex items-center gap-2 p-2 rounded-lg hover:bg-gray-50 transition-colors">
                                        <div class="w-8 h-8 rounded-lg bg-blue-100 text-blue-600 flex items-center justify-center">
                                            <i class="fas fa-plus text-sm"></i>
                                        </div>
                                        <span class="text-sm font-medium">Tambah Order Baru</span>
                                    </a>
                                    <a href="orders/konsumen.php?action=tambah" class="flex items-center gap-2 p-2 rounded-lg hover:bg-gray-50 transition-colors">
                                        <div class="w-8 h-8 rounded-lg bg-green-100 text-green-600 flex items-center justify-center">
                                            <i class="fas fa-user-plus text-sm"></i>
                                        </div>
                                        <span class="text-sm font-medium">Tambah Pelanggan</span>
                                    </a>
                                    <a href="#" class="flex items-center gap-2 p-2 rounded-lg hover:bg-gray-50 transition-colors">
                                        <div class="w-8 h-8 rounded-lg bg-yellow-100 text-yellow-600 flex items-center justify-center">
                                            <i class="fas fa-file-export text-sm"></i>
                                        </div>
                                        <span class="text-sm font-medium">Ekspor Laporan</span>
                                    </a>
                                    <a href="orders/masuk.php" class="flex items-center gap-2 p-2 rounded-lg hover:bg-gray-50 transition-colors">
                                        <div class="w-8 h-8 rounded-lg bg-purple-100 text-purple-600 flex items-center justify-center">
                                            <i class="fas fa-list-ul text-sm"></i>
                                        </div>
                                        <span class="text-sm font-medium">Lihat Semua Order</span>
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Status Info -->
                        <div class="card mt-6">
                            <div class="card-header">
                                <h3 class="card-title">Keterangan Status</h3>
                            </div>
                            <div class="card-body">
                                <div class="space-y-3">
                                    <div class="flex items-center gap-3">
                                        <span class="status-badge status-diproses">Diproses</span>
                                        <span class="text-sm text-gray-600">Order sedang dalam proses pengerjaan</span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="status-badge status-dikirim">Dikirim</span>
                                        <span class="text-sm text-gray-600">Order sedang dalam pengiriman</span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="status-badge status-selesai">Selesai</span>
                                        <span class="text-sm text-gray-600">Order telah selesai</span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="status-badge status-batal">Batal</span>
                                        <span class="text-sm text-gray-600">Order dibatalkan</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <footer class="footer" style="padding: 1.5rem; text-align: center; color: var(--gray-500); font-size: 0.875rem; border-top: 1px solid var(--gray-200);">
                <p> 2023 CV. Panca Indra Kemasan. All Rights Reserved.</p>
            </footer>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.min.js"></script>
    <script>
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        
        // Toggle sidebar on mobile
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('active');
            });
        }
        
        // Handle responsive sidebar
        function handleSidebar() {
            if (window.innerWidth < 1024) {
                sidebarToggle.style.display = 'flex';
                sidebar.classList.add('mobile-sidebar');
                sidebar.classList.remove('active');
            } else {
                sidebarToggle.style.display = 'none';
                sidebar.classList.remove('mobile-sidebar', 'active');
            }
        }
        
        // Initial check
        handleSidebar();
        
        // Check on resize
        window.addEventListener('resize', handleSidebar);

        // Handle status update
        $(document).on('click', '.update-status', function() {
            const orderId = $(this).data('id');
            const newStatus = $(this).data('status');
            const statusText = $(this).attr('title') || 'perbarui status';
            
            if (confirm(`Apakah Anda yakin ingin ${statusText.toLowerCase()}?`)) {
                $.post('proses_order.php', { 
                    id: orderId, 
                    action: 'update_status',
                    status: newStatus
                }, function(response) {
                    const result = JSON.parse(response);
                    if (result.success) {
                        location.reload();
                    } else {
                        alert('Terjadi kesalahan: ' + (result.message || 'Tidak dapat memperbarui status'));
                    }
                }).fail(function() {
                    alert('Terjadi kesalahan saat memperbarui status');
                });
            }
        });

        // Handle responsive sidebar
        function handleResize() {
            if (window.innerWidth < 1024) {
                document.getElementById('sidebar').classList.remove('active');
            } else {
                document.getElementById('sidebar').classList.add('active');
            }
        }

        // Initial check
        handleResize();

        // Add event listener for window resize
        window.addEventListener('resize', handleResize);
    </script>
</body>
</html>
<?php $conn->close(); ?>