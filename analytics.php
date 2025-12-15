<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$conn = getConnection();

// Get current year and month
$current_year = date('Y');
$current_month = date('n');

// Initialize data arrays
$months = [];
$sales_data = [];
$orders_data = [];
$products_data = [];

// Get monthly sales data
$query = "SELECT 
            MONTH(tanggal_order) as month_num,
            MONTHNAME(tanggal_order) as month_name,
            COALESCE(SUM(total_harga), 0) as total_sales,
            COUNT(DISTINCT o.id) as order_count
          FROM orders o
          WHERE YEAR(tanggal_order) = ?
          GROUP BY MONTH(tanggal_order), MONTHNAME(tanggal_order)
          ORDER BY month_num";

$stmt = $conn->prepare($query);
$stmt->bind_param('i', $current_year);
$stmt->execute();
$result = $stmt->get_result();

// Initialize all months with zero values
for ($i = 1; $i <= 12; $i++) {
    $month_name = date('M', mktime(0, 0, 0, $i, 1));
    $months[] = $month_name;
    $sales_data[$i] = 0;
    $orders_data[$i] = 0;
}

// Fill in actual data
while ($row = $result->fetch_assoc()) {
    $month_num = $row['month_num'];
    $sales_data[$month_num] = (float)$row['total_sales'];
    $orders_data[$month_num] = (int)$row['order_count'];
}

// Get top selling products
try {
    $query = "SELECT 
                p.nama_produk as product_name,
                COALESCE(SUM(oi.jumlah), 0) as total_quantity,
                COALESCE(SUM(oi.subtotal), 0) as total_revenue
              FROM products p
              LEFT JOIN order_items oi ON p.id = oi.produk_id
              LEFT JOIN orders o ON oi.order_id = o.id
              WHERE YEAR(o.tanggal_order) = ?
              GROUP BY p.id, p.nama_produk
              ORDER BY total_quantity DESC
              LIMIT 5";

    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param('i', $current_year);
    $executed = $stmt->execute();
    
    if ($executed === false) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    if ($result === false) {
        throw new Exception("Get result failed: " . $stmt->error);
    }
    
    $top_products = $result->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching top products: " . $e->getMessage());
    $top_products = [];
}

// Get order status distribution
$query = "SELECT 
            status,
            COUNT(*) as count,
            ROUND((COUNT(*) * 100.0) / (SELECT COUNT(*) FROM orders WHERE YEAR(tanggal_order) = ?), 1) as percentage
          FROM orders
          WHERE YEAR(tanggal_order) = ?
          GROUP BY status";

$stmt = $conn->prepare($query);
$stmt->bind_param('ii', $current_year, $current_year);
$stmt->execute();
$order_status = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get monthly sales growth
$query = "SELECT 
            MONTH(tanggal_order) as month_num,
            COALESCE(SUM(total_harga), 0) as monthly_sales
          FROM orders
          WHERE YEAR(tanggal_order) = ? OR YEAR(tanggal_order) = ?
          GROUP BY YEAR(tanggal_order), MONTH(tanggal_order)
          ORDER BY YEAR(tanggal_order), MONTH(tanggal_order)";

$current_year_minus_1 = $current_year - 1;
$stmt = $conn->prepare($query);
$stmt->bind_param('ii', $current_year, $current_year_minus_1);
$stmt->execute();
$sales_growth_result = $stmt->get_result();

$sales_growth = [
    'current_year' => array_fill(1, 12, 0),
    'previous_year' => array_fill(1, 12, 0)
];

while ($row = $sales_growth_result->fetch_assoc()) {
    $year = (date('Y', strtotime($row['tanggal_order'])) == $current_year) ? 'current_year' : 'previous_year';
    $month = (int)$row['month_num'];
    $sales_growth[$year][$month] = (float)$row['monthly_sales'];
}

// Calculate growth percentage
$growth_percentage = [];
for ($i = 1; $i <= 12; $i++) {
    $previous = $sales_growth['previous_year'][$i] ?: 1;
    $growth = (($sales_growth['current_year'][$i] - $sales_growth['previous_year'][$i]) / $previous) * 100;
    $growth_percentage[$i] = round($growth, 1);
}

// Initialize recent activities as empty array
$recent_activities = [];

// Check if activities table exists
$table_check = $conn->query("SHOW TABLES LIKE 'activities'");
$activities_table_exists = $table_check && $table_check->num_rows > 0;

if ($activities_table_exists) {
    try {
        $table_check = $conn->query("SHOW TABLES LIKE 'users'");
        $users_table_exists = $table_check && $table_check->num_rows > 0;
        
        if ($users_table_exists) {
            $query = "SELECT 
                        a.*,
                        CONCAT(u.first_name, ' ', u.last_name) as user_name,
                        u.role as user_role
                      FROM activities a
                      LEFT JOIN users u ON a.user_id = u.id
                      ORDER BY a.created_at DESC
                      LIMIT 5";
        } else {
            $query = "SELECT 
                        a.*,
                        NULL as user_name,
                        NULL as user_role
                      FROM activities a
                      ORDER BY a.created_at DESC
                      LIMIT 5";
        }
        
        $result = $conn->query($query);
        if ($result) {
            $recent_activities = $result->fetch_all(MYSQLI_ASSOC);
        } else {
            error_log("Error fetching activities: " . $conn->error);
        }
    } catch (Exception $e) {
        error_log("Error in activities query: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - CV. Panca Indra Kemasan</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            margin-bottom: 2rem;
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
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--gray-600);
            font-weight: 600;
            margin-bottom: 0.75rem;
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

        .stat-icon.purple {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }

        .stat-icon.yellow {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }

        .stat-value {
            font-size: 1.75rem;
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
        }

        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
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
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--gray-500);
            letter-spacing: 0.05em;
        }

        th.text-right {
            text-align: right;
        }

        td {
            padding: 1rem;
            border-top: 1px solid var(--gray-200);
            font-size: 0.875rem;
        }

        td.text-right {
            text-align: right;
        }

        tr:hover {
            background-color: var(--gray-50);
        }

        .activity-item {
            padding: 1rem 0;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            gap: 1rem;
            align-items: flex-start;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: rgba(79, 70, 229, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: var(--primary);
            font-size: 0.9rem;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.95rem;
            margin-bottom: 0.25rem;
        }

        .activity-time {
            font-size: 0.8rem;
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

        .footer {
            background: white;
            padding: 1.5rem 2rem;
            text-align: center;
            color: var(--gray-500);
            font-size: 0.85rem;
            border-top: 1px solid var(--gray-200);
        }

        .text-green-600 { color: #059669; }
        .text-red-600 { color: #dc2626; }

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

            .cards-grid {
                grid-template-columns: 1fr;
            }

            .search-box input {
                width: 150px;
            }

            .page-title {
                font-size: 1.5rem;
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
                    <li><a href="analytics.php" class="active"><i class="fas fa-chart-bar"></i> Analytics</a></li>
                    <li><a href="laporan_bulanan.php"><i class="fas fa-calendar-alt"></i> Laporan Bulanan</a></li>
                </ul>
            </div>

            <div class="sidebar-section">
                <div class="sidebar-title">Inventory</div>
                <ul class="sidebar-menu">
                    <li><a href="stok_barang.php"><i class="fas fa-boxes"></i> Stok Barang</a></li>
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
                    <div class="navbar-title">Analytics</div>
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
                <div class="page-header">
                    <h1 class="page-title">Analisis & Laporan</h1>
                    <p class="page-subtitle">Tinjau kinerja bisnis Anda dengan analisis mendalam</p>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div>
                                <div class="stat-label">Total Penjualan Tahun Ini</div>
                                <div class="stat-value">Rp <?= number_format(array_sum($sales_data), 0, ',', '.') ?></div>
                                <?php
                                $total_sales_prev_year = array_sum(array_slice($sales_data, 0, $current_month));
                                $total_sales_curr_year = array_sum(array_slice($sales_data, 0, $current_month));
                                $growth = $total_sales_prev_year > 0 ? (($total_sales_curr_year - $total_sales_prev_year) / $total_sales_prev_year) * 100 : 0;
                                $growth_class = $growth >= 0 ? 'positive' : 'negative';
                                $growth_icon = $growth >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
                                ?>
                                <div class="stat-change <?= $growth_class ?>">
                                    <i class="fas <?= $growth_icon ?>"></i>
                                    <?= number_format(abs($growth), 1) ?>% dari tahun lalu
                                </div>
                            </div>
                            <div class="stat-icon blue">
                                <i class="fas fa-dollar-sign"></i>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div>
                                <div class="stat-label">Total Order Tahun Ini</div>
                                <div class="stat-value"><?= number_format(array_sum($orders_data), 0, ',', '.') ?></div>
                                <?php
                                $total_orders_prev_year = array_sum(array_slice($orders_data, 0, $current_month));
                                $total_orders_curr_year = array_sum(array_slice($orders_data, 0, $current_month));
                                $order_growth = $total_orders_prev_year > 0 ? (($total_orders_curr_year - $total_orders_prev_year) / $total_orders_prev_year) * 100 : 0;
                                $order_growth_class = $order_growth >= 0 ? 'positive' : 'negative';
                                $order_growth_icon = $order_growth >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
                                ?>
                                <div class="stat-change <?= $order_growth_class ?>">
                                    <i class="fas <?= $order_growth_icon ?>"></i>
                                    <?= number_format(abs($order_growth), 1) ?>% dari tahun lalu
                                </div>
                            </div>
                            <div class="stat-icon green">
                                <i class="fas fa-shopping-cart"></i>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div>
                                <div class="stat-label">Rata-rata Nilai Order</div>
                                <div class="stat-value">Rp <?= number_format(array_sum($sales_data) / max(1, array_sum($orders_data)), 0, ',', '.') ?></div>
                                <div class="stat-change positive">
                                    <i class="fas fa-chart-line"></i>
                                    Per order
                                </div>
                            </div>
                            <div class="stat-icon purple">
                                <i class="fas fa-chart-line"></i>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div>
                                <div class="stat-label">Produk Terlaris</div>
                                <div class="stat-value" style="font-size: 1.25rem;"><?= isset($top_products[0]) ? substr($top_products[0]['product_name'], 0, 20) : 'N/A' ?></div>
                                <div class="stat-change positive">
                                    <i class="fas fa-star"></i>
                                    <?= number_format($top_products[0]['total_quantity'] ?? 0, 0, ',', '.') ?> terjual
                                </div>
                            </div>
                            <div class="stat-icon yellow">
                                <i class="fas fa-star"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="cards-grid">
                    <div class="card" style="grid-column: span 2;">
                        <div class="card-header">
                            <h3 class="card-title">Tren Penjualan <?= $current_year ?></h3>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <span style="font-size: 0.875rem; color: var(--gray-500);">vs Tahun Lalu</span>
                                <span class="stat-change <?= $growth >= 0 ? 'positive' : 'negative' ?>" style="padding: 0.25rem 0.5rem; background: <?= $growth >= 0 ? '#d1fae5' : '#fee2e2' ?>; border-radius: 0.5rem;">
                                    <?= $growth >= 0 ? '+' : '' ?><?= number_format($growth, 1) ?>%
                                </span>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="salesChart"></canvas>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Distribusi Status Order</h3>
                        </div>
                        <div class="chart-container">
                            <canvas id="orderStatusChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="cards-grid">
                    <div class="card" style="grid-column: span 2;">
                        <div class="card-header">
                            <h3 class="card-title">5 Produk Terlaris</h3>
                            <span style="font-size: 0.875rem; color: var(--gray-500);">Tahun <?= $current_year ?></span>
                        </div>
                        <div class="chart-container">
                            <canvas id="topProductsChart"></canvas>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Aktivitas Terbaru</h3>
                        </div>
                        <div>
                            <?php if (!empty($recent_activities)): ?>
                                <?php foreach ($recent_activities as $activity): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <i class="fas fa-<?= isset($activity['activity_type']) && $activity['activity_type'] === 'login' ? 'sign-in-alt' : (isset($activity['activity_type']) && $activity['activity_type'] === 'order' ? 'shopping-cart' : 'info-circle') ?>"></i>
                                        </div>
                                        <div class="activity-content">
                                            <p class="activity-title"><?= htmlspecialchars($activity['description'] ?? 'Aktivitas sistem') ?></p>
                                            <p class="activity-time">
                                                <?= date('d M Y, H:i', strtotime($activity['created_at'])) ?> • 
                                                <?= $activity['user_name'] ? htmlspecialchars($activity['user_name']) : 'Sistem' ?>
                                            </p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p style="text-align: center; color: var(--gray-500); padding: 2rem 0;">Tidak ada aktivitas terbaru</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Ringkasan Bulanan</h3>
                        <button class="btn btn-outline btn-sm">
                            <i class="fas fa-download"></i>
                            Ekspor Laporan
                        </button>
                    </div>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Bulan</th>
                                    <th class="text-right">Total Penjualan</th>
                                    <th class="text-right">Total Order</th>
                                    <th class="text-right">Rata-rata/Order</th>
                                    <th class="text-right">Pertumbuhan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $total_sales = 0;
                                $total_orders = 0;
                                $months_display = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];
                                
                                for ($i = 1; $i <= 12; $i++):
                                    $month_sales = $sales_data[$i] ?? 0;
                                    $month_orders = $orders_data[$i] ?? 0;
                                    $avg_order = $month_orders > 0 ? $month_sales / $month_orders : 0;
                                    $growth = isset($growth_percentage[$i]) ? $growth_percentage[$i] : 0;
                                    $growth_class = $growth >= 0 ? 'text-green-600' : 'text-red-600';
                                    $growth_icon = $growth >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
                                    
                                    $total_sales += $month_sales;
                                    $total_orders += $month_orders;
                                ?>
                                <tr>
                                    <td style="font-weight: 600;"><?= $months_display[$i-1] ?></td>
                                    <td class="text-right">Rp <?= number_format($month_sales, 0, ',', '.') ?></td>
                                    <td class="text-right"><?= number_format($month_orders, 0, ',', '.') ?></td>
                                    <td class="text-right">Rp <?= number_format($avg_order, 0, ',', '.') ?></td>
                                    <td class="text-right <?= $growth_class ?>">
                                        <i class="fas <?= $growth_icon ?>"></i> <?= number_format(abs($growth), 1) ?>%
                                    </td>
                                </tr>
                                <?php endfor; ?>
                                <tr style="background-color: var(--gray-50); font-weight: 700;">
                                    <td>Total</td>
                                    <td class="text-right">Rp <?= number_format($total_sales, 0, ',', '.') ?></td>
                                    <td class="text-right"><?= number_format($total_orders, 0, ',', '.') ?></td>
                                    <td class="text-right">Rp <?= number_format($total_orders > 0 ? $total_sales / $total_orders : 0, 0, ',', '.') ?></td>
                                    <td class="text-right">
                                        <?php
                                        $total_avg_growth = count(array_filter($growth_percentage)) > 0 ? array_sum($growth_percentage) / count(array_filter($growth_percentage)) : 0;
                                        $total_growth_class = $total_avg_growth >= 0 ? 'text-green-600' : 'text-red-600';
                                        $total_growth_icon = $total_avg_growth >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
                                        ?>
                                        <span class="<?= $total_growth_class ?>">
                                            <i class="fas <?= $total_growth_icon ?>"></i> 
                                            <?= number_format(abs($total_avg_growth), 1) ?>%
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <footer class="footer">
                <p>© <?php echo date('Y'); ?> CV. Panca Indra Kemasan. All Rights Reserved. | Sistem Manajemen Dashboard v2.0</p>
            </footer>
        </div>
    </div>

    <script>
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');

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

        // Sales Chart
        const salesCtx = document.getElementById('salesChart').getContext('2d');
        new Chart(salesCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($months) ?>,
                datasets: [
                    {
                        label: 'Tahun Ini',
                        data: <?= json_encode(array_values($sales_data)) ?>,
                        borderColor: '#4f46e5',
                        backgroundColor: 'rgba(79, 70, 229, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#4f46e5',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    },
                    {
                        label: 'Tahun Lalu',
                        data: <?= json_encode(array_values($sales_growth['previous_year'])) ?>,
                        borderColor: '#9ca3af',
                        backgroundColor: 'rgba(156, 163, 175, 0.05)',
                        borderWidth: 2,
                        borderDash: [5, 5],
                        tension: 0.4,
                        fill: false,
                        pointBackgroundColor: '#9ca3af',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 5
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 15,
                            font: {
                                size: 12,
                                weight: '600'
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        borderColor: 'rgba(255, 255, 255, 0.2)',
                        borderWidth: 1,
                        titleFont: {
                            size: 13,
                            weight: 'bold'
                        },
                        bodyFont: {
                            size: 12
                        },
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    label += new Intl.NumberFormat('id-ID', { 
                                        style: 'currency', 
                                        currency: 'IDR',
                                        maximumFractionDigits: 0
                                    }).format(context.parsed.y);
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            callback: function(value) {
                                if (value >= 1000000) {
                                    return 'Rp' + (value / 1000000).toFixed(1) + 'jt';
                                } else if (value >= 1000) {
                                    return 'Rp' + (value / 1000).toFixed(0) + 'rb';
                                }
                                return 'Rp' + value;
                            },
                            font: {
                                size: 11
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 11
                            }
                        }
                    }
                }
            }
        });

        // Order Status Chart
        const orderStatusCtx = document.getElementById('orderStatusChart').getContext('2d');
        new Chart(orderStatusCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_column($order_status, 'status')) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($order_status, 'count')) ?>,
                    backgroundColor: [
                        '#3b82f6',
                        '#10b981',
                        '#f59e0b',
                        '#ef4444'
                    ],
                    borderWidth: 0,
                    hoverOffset: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            font: {
                                size: 12
                            },
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = Math.round((value / total) * 100);
                                return `${label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                },
                cutout: '65%'
            }
        });

        // Top Products Chart
        const topProductsCtx = document.getElementById('topProductsChart').getContext('2d');
        new Chart(topProductsCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($top_products, 'product_name')) ?>,
                datasets: [{
                    label: 'Jumlah Terjual',
                    data: <?= json_encode(array_column($top_products, 'total_quantity')) ?>,
                    backgroundColor: 'rgba(79, 70, 229, 0.8)',
                    borderRadius: 6,
                    hoverBackgroundColor: '#4f46e5'
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString();
                            },
                            font: {
                                size: 11
                            }
                        }
                    },
                    y: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 11
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>