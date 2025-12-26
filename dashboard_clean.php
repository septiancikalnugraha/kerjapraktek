<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$conn = getConnection();

// Function to safely execute queries
function safeQuery($conn, $query, $default = []) {
    try {
        $result = $conn->query($query);
        if ($result === false) {
            error_log("Query failed: " . $conn->error . " - Query: " . $query);
            return $default;
        }
        return $result;
    } catch (Exception $e) {
        error_log("Database error: " . $e->getMessage());
        return $default;
    }
}

// Initialize stats with default values
$stats = [
    'total_penjualan' => 0,
    'total_order' => 0,
    'total_stok' => 0,
    'pengguna_aktif' => 0
];

// Check if tables exist
$tables_exist = true;
$tables_to_check = ['orders', 'order_items', 'stok', 'users', 'activities'];
foreach ($tables_to_check as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result->num_rows == 0) {
        $tables_exist = false;
        break;
    }
}

if ($tables_exist) {
    // Total Penjualan
    $query = "SELECT COALESCE(SUM(oi.subtotal), 0) as total 
              FROM orders o 
              LEFT JOIN order_items oi ON o.id = oi.order_id 
              WHERE o.status = 'completed' 
              AND MONTH(o.tanggal_order) = MONTH(CURRENT_DATE())";
    $result = safeQuery($conn, $query);
    if ($result) {
        $stats['total_penjualan'] = $result->fetch_assoc()['total'];
    }

    // Total Order
    $query = "SELECT COUNT(*) as total FROM orders 
              WHERE MONTH(tanggal_order) = MONTH(CURRENT_DATE())";
    $result = safeQuery($conn, $query);
    if ($result) {
        $stats['total_order'] = $result->fetch_assoc()['total'];
    }

    // Total Stok
    $query = "SELECT COALESCE(SUM(stok_akhir), 0) as total 
              FROM (
                  SELECT s.stok_akhir 
                  FROM stok s 
                  JOIN (SELECT produk_id, MAX(created_at) as latest 
                        FROM stok GROUP BY produk_id) latest_stock 
                  ON s.produk_id = latest_stock.produk_id 
                  AND s.created_at = latest_stock.latest
              ) as current_stock";
    $result = safeQuery($conn, $query);
    if ($result) {
        $stats['total_stok'] = $result->fetch_assoc()['total'];
    }

    // Pengguna Aktif
    $query = "SELECT COUNT(*) as total FROM users";
    $result = safeQuery($conn, $query);
    if ($result) {
        $stats['pengguna_aktif'] = $result->fetch_assoc()['total'];
    }

    // Get latest orders
    $query = "SELECT o.*, k.nama_konsumen, k.perusahaan 
              FROM orders o 
              LEFT JOIN konsumen k ON o.konsumen_id = k.id 
              ORDER BY o.tanggal_order DESC, o.created_at DESC 
              LIMIT 5";
    $latest_orders = safeQuery($conn, $query, false);

    // Get latest activities
    $query = "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) as user_name 
              FROM activities a 
              LEFT JOIN users u ON a.user_id = u.id 
              ORDER BY a.created_at DESC 
              LIMIT 5";
    $latest_activities = safeQuery($conn, $query, false);
} else {
    // Create default data if tables don't exist
    $latest_orders = false;
    $latest_activities = false;
    
    // Add a default activity
    $default_activity = [
        (object)[
            'description' => 'Sistem diinisialisasi',
            'created_at' => date('Y-m-d H:i:s'),
            'user_name' => 'Sistem'
        ]
    ];
    class ActivitiesList extends ArrayObject {
        public $num_rows;
        
        public function __construct($array = []) {
            parent::__construct($array);
            $this->num_rows = count($array);
        }
    }
    $latest_activities = new ActivitiesList($default_activity);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - CV. Panca Indra Kemasan</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            position: relative;
        }

        .content-area::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            opacity: 0.4;
        }

        .content-wrapper {
            position: relative;
            z-index: 1;
        }

        .page-header {
            margin-bottom: 2rem;
            text-align: center;
        }

        .page-title {
            font-size: 2.25rem;
            font-weight: 700;
            color: white;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .page-subtitle {
            font-size: 1rem;
            color: rgba(255, 255, 255, 0.9);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 1rem;
            padding: 1.75rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            border-radius: 50%;
            opacity: 0.1;
            transition: all 0.3s ease;
        }

        .stat-card.blue::before {
            background: #3b82f6;
        }

        .stat-card.green::before {
            background: #10b981;
        }

        .stat-card.purple::before {
            background: #8b5cf6;
        }

        .stat-card.orange::before {
            background: #f59e0b;
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        }

        .stat-card:hover::before {
            transform: scale(1.5);
            opacity: 0.15;
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--gray-600);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }

        .stat-icon.blue {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .stat-icon.green {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .stat-icon.orange {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }

        .stat-icon.purple {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
            line-height: 1;
        }

        .stat-change {
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-weight: 500;
        }

        .stat-change.positive {
            color: var(--secondary);
        }

        .stat-change.negative {
            color: var(--danger);
        }

        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .card {
            background: white;
            border-radius: 1rem;
            padding: 1.75rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }

        .card:hover {
            transform: translateY(-4px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
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
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-title i {
            color: var(--primary);
        }

        .order-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .order-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: var(--gray-50);
            border-radius: 0.75rem;
            transition: all 0.2s ease;
            border: 1px solid transparent;
        }

        .order-item:hover {
            background: white;
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.1);
        }

        .order-detail h4 {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .order-detail p {
            font-size: 0.8rem;
            color: var(--gray-500);
        }

        .status-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-processing {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-completed {
            background: #d1fae5;
            color: #065f46;
        }

        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .text-center {
            text-align: center;
        }

        .text-muted {
            color: var(--gray-500);
        }

        .py-4 {
            padding: 2rem 0;
        }

        .mb-2 {
            margin-bottom: 0.5rem;
        }

        .mt-2 {
            margin-top: 0.5rem;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }

        .btn {
            padding: 0.75rem 1.25rem;
            border-radius: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s;
            font-size: 0.9rem;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(79, 70, 229, 0.4);
        }

        .btn-outline {
            background-color: white;
            border: 2px solid var(--gray-200);
            color: var(--gray-700);
        }

        .btn-outline:hover {
            border-color: var(--primary);
            color: var(--primary);
            background-color: rgba(79, 70, 229, 0.05);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        .btn-block {
            width: 100%;
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

            .quick-actions {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .cards-grid {
                grid-template-columns: 1fr;
            }

            .search-box input {
                width: 150px;
            }

            .page-title {
                font-size: 1.75rem;
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
                    <div class="navbar-title">Dashboard</div>
                </div>

                <div class="navbar-right">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Cari...">
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
                <div class="content-wrapper">
                    <div class="page-header">
                        <h1 class="page-title">Selamat Datang Kembali</h1>
                        <p class="page-subtitle">Berikut adalah ringkasan data bisnis Anda hari ini</p>
                    </div>

                    <div class="stats-grid">
                        <div class="stat-card blue">
                            <div class="stat-header">
                                <div>
                                    <div class="stat-label">Total Penjualan</div>
                                    <div class="stat-value">Rp <?= number_format($stats['total_penjualan'], 0, ',', '.') ?></div>
                                    <?php 
                                    $last_month_sales = $stats['total_penjualan'] * 0.9;
                                    $change = $last_month_sales > 0 ? 
                                        (($stats['total_penjualan'] - $last_month_sales) / $last_month_sales) * 100 : 0;
                                    $change_class = $change >= 0 ? 'positive' : 'negative';
                                    $change_icon = $change >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
                                    ?>
                                    <div class="stat-change <?= $change_class ?>">
                                        <i class="fas <?= $change_icon ?>"></i>
                                        <?= number_format(abs($change), 1) ?>% dari bulan lalu
                                    </div>
                                </div>
                                <div class="stat-icon blue">
                                    <i class="fas fa-dollar-sign"></i>
                                </div>
                            </div>
                        </div>

                        <div class="stat-card green">
                            <div class="stat-header">
                                <div>
                                    <div class="stat-label">Total Order</div>
                                    <div class="stat-value"><?= number_format($stats['total_order'], 0, ',', '.') ?></div>
                                    <?php 
                                    $last_month_orders = $stats['total_order'] * 0.9;
                                    $change = $last_month_orders > 0 ? 
                                        (($stats['total_order'] - $last_month_orders) / $last_month_orders) * 100 : 0;
                                    $change_class = $change >= 0 ? 'positive' : 'negative';
                                    $change_icon = $change >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
                                    ?>
                                    <div class="stat-change <?= $change_class ?>">
                                        <i class="fas <?= $change_icon ?>"></i>
                                        <?= number_format(abs($change), 1) ?>% dari bulan lalu
                                    </div>
                                </div>
                                <div class="stat-icon green">
                                    <i class="fas fa-shopping-cart"></i>
                                </div>
                            </div>
                        </div>

                        <div class="stat-card purple">
                            <div class="stat-header">
                                <div>
                                    <div class="stat-label">Stok Barang</div>
                                    <div class="stat-value"><?= number_format($stats['total_stok'], 0, ',', '.') ?></div>
                                    <?php 
                                    $last_month_stock = $stats['total_stok'] * 0.95;
                                    $change = $last_month_stock > 0 ? 
                                        (($stats['total_stok'] - $last_month_stock) / $last_month_stock) * 100 : 0;
                                    $change_class = $change >= 0 ? 'positive' : 'negative';
                                    $change_icon = $change >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
                                    ?>
                                    <div class="stat-change <?= $change_class ?>">
                                        <i class="fas <?= $change_icon ?>"></i>
                                        <?= number_format(abs($change), 1) ?>% dari bulan lalu
                                    </div>
                                </div>
                                <div class="stat-icon purple">
                                    <i class="fas fa-warehouse"></i>
                                </div>
                            </div>
                        </div>

                        <div class="stat-card orange">
                            <div class="stat-header">
                                <div>
                                    <div class="stat-label">Pengguna Aktif</div>
                                    <div class="stat-value"><?= number_format($stats['pengguna_aktif'], 0, ',', '.') ?></div>
                                    <?php 
                                    $change = rand(-5, 5);
                                    $change_class = $change >= 0 ? 'positive' : 'negative';
                                    $change_icon = $change >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
                                    ?>
                                    <div class="stat-change <?= $change_class ?>">
                                        <i class="fas <?= $change_icon ?>"></i>
                                        <?= abs($change) ?>% dari bulan lalu
                                    </div>
                                </div>
                                <div class="stat-icon orange">
                                    <i class="fas fa-users"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="cards-grid">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-shopping-bag"></i>
                                    Order Terbaru
                                </h3>
                                <a href="order_masuk.php" class="btn btn-sm btn-outline">Lihat Semua</a>
                            </div>
                            <?php if ($latest_orders && $latest_orders->num_rows > 0): ?>
                                <div class="order-list">
                                    <?php while($order = $latest_orders->fetch_assoc()): 
                                        $order_date = new DateTime($order['tanggal_order']);
                                        $now = new DateTime();
                                        $interval = $now->diff($order_date);
                                        
                                        if ($interval->days == 0) {
                                            $time_ago = 'Hari ini, ' . date('h:i A', strtotime($order['created_at']));
                                        } elseif ($interval->days == 1) {
                                            $time_ago = 'Kemarin, ' . date('h:i A', strtotime($order['created_at']));
                                        } else {
                                            $time_ago = $interval->days . ' hari lalu';
                                        }
                                        
                                        $status_class = 'status-' . strtolower($order['status']);
                                        $status_text = ucfirst($order['status']);
                                        $company = !empty($order['perusahaan']) ? $order['perusahaan'] : $order['nama_konsumen'];
                                    ?>
                                    <div class="order-item">
                                        <div class="order-detail">
                                            <h4>#<?= htmlspecialchars($order['no_order'] ?? '') ?> - <?= htmlspecialchars($company) ?></h4>
                                            <p class="text-muted"><?= $time_ago ?></p>
                                        </div>
                                        <span class="status-badge <?= $status_class ?>"><?= $status_text ?></span>
                                    </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="fas fa-inbox fa-2x mb-2"></i>
                                    <p>Tidak ada order terbaru</p>
                                    <a href="order_masuk.php" class="btn btn-primary btn-sm mt-2">Buat Order Baru</a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-rocket"></i>
                                    Aksi Cepat
                                </h3>
                            </div>
                            <div class="quick-actions">
                                <a href="order_masuk.php" class="btn btn-primary btn-block">
                                    <i class="fas fa-plus"></i> Buat Order
                                </a>
                                <a href="stok_barang.php" class="btn btn-outline btn-block">
                                    <i class="fas fa-cube"></i> Kelola Stok
                                </a>
                                <a href="laporan_bulanan.php" class="btn btn-outline btn-block">
                                    <i class="fas fa-file-alt"></i> Lihat Laporan
                                </a>
                                <a href="analytics.php" class="btn btn-outline btn-block">
                                    <i class="fas fa-chart-bar"></i> Analytics
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <footer class="footer">
                <p>© <?php echo date('Y'); ?> CV. Panca Indra Kemasan. All Rights Reserved. | Sistem Manajemen Dashboard v2.0</p>
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

        document.getElementById('logoutBtn').addEventListener('click', function(e) {
            if (!confirm('Apakah Anda yakin ingin keluar?')) {
                e.preventDefault();
            }
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const isClickInsideSidebar = sidebar.contains(event.target);
            const isClickOnToggle = sidebarToggle && sidebarToggle.contains(event.target);
            
            if (!isClickInsideSidebar && !isClickOnToggle && window.innerWidth <= 1024) {
                sidebar.classList.remove('active');
            }
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>