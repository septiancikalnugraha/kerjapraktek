<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/helpers/notifications.php';
requireLogin();
$conn = getConnection();
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_notifications_read') {
    $timestamp = isset($_POST['timestamp']) ? (int)$_POST['timestamp'] : time();
    if ($timestamp <= 0) {
        $timestamp = time();
    }
    $current_last_seen = isset($_SESSION['notifications_last_seen']) ? (int)$_SESSION['notifications_last_seen'] : 0;
    $_SESSION['notifications_last_seen'] = max($current_last_seen, $timestamp);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'last_seen' => $_SESSION['notifications_last_seen']
    ]);
    exit;
}

// Inisialisasi variabel
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Inisialisasi variabel untuk view
$total_items = 0;
$total_pages = 1;
$critical_items = [];
$kritis_count = 0;
$peringatan_count = 0;
$total_stok = 0;
$notifications = getDashboardNotifications($conn);
$notification_count = count($notifications);
$latest_notification_time = 0;
$last_seen_notifications = isset($_SESSION['notifications_last_seen']) ? (int)$_SESSION['notifications_last_seen'] : 0;
$unread_notification_count = 0;

foreach ($notifications as $notif) {
    $notification_time = strtotime($notif['time']) ?: 0;
    if ($notification_time > $latest_notification_time) {
        $latest_notification_time = $notification_time;
    }
    if ($notification_time > $last_seen_notifications) {
        $unread_notification_count++;
    }
}

if ($latest_notification_time === 0) {
    $latest_notification_time = $last_seen_notifications;
}

$status_filter = isset($_GET['status']) ? strtolower($_GET['status']) : 'semua';
$kategori_filter = isset($_GET['kategori']) ? trim($_GET['kategori']) : 'semua';
$kategori_options = [];

// Ambil daftar kategori untuk filter
$kategori_query = $conn->query("SELECT DISTINCT kategori FROM products WHERE kategori IS NOT NULL AND kategori != '' ORDER BY kategori");
if ($kategori_query) {
    while ($row = $kategori_query->fetch_assoc()) {
        $kategori_options[] = $row['kategori'];
    }
}

$search_param = null;
$filter_sql = '';
$base_param_types = '';
$base_param_values = [];

if (!empty($search)) {
    $search_param = "%$search%";
    $filter_sql .= " AND (p.nama_produk LIKE ? OR p.kode_produk LIKE ? OR p.kategori LIKE ?)";
    $base_param_types .= 'sss';
    $base_param_values = array_merge($base_param_values, [$search_param, $search_param, $search_param]);
}

if (!empty($kategori_filter) && $kategori_filter !== 'semua') {
    $filter_sql .= " AND p.kategori = ?";
    $base_param_types .= 's';
    $base_param_values[] = $kategori_filter;
}

$warning_threshold = 2000;

$status_condition = '';
if ($status_filter === 'kritis') {
    $status_condition = " AND stock_data.stok <= 1000";
} elseif ($status_filter === 'peringatan') {
    $status_condition = " AND stock_data.stok > 1000 AND stock_data.stok <= GREATEST(stock_data.min_stok, {$warning_threshold})";
}

$stock_summary_query = "
    SELECT 
        p.id,
        p.kode_produk AS kode,
        p.nama_produk AS nama,
        p.kategori,
        p.satuan,
        p.stok_minimal AS min_stok,
        COALESCE(SUM(CASE WHEN sm.type = 'in' THEN sm.quantity ELSE -sm.quantity END), 0) AS stok,
        CASE 
            WHEN p.stok_minimal > 0 
                THEN ROUND((COALESCE(SUM(CASE WHEN sm.type = 'in' THEN sm.quantity ELSE -sm.quantity END), 0) / p.stok_minimal) * 100, 1)
            ELSE 0
        END AS persentase
    FROM products p
    LEFT JOIN stock_mutations sm ON sm.product_id = p.id
    WHERE 1=1
    {$filter_sql}
    GROUP BY p.id, p.kode_produk, p.nama_produk, p.kategori, p.satuan, p.stok_minimal
";

$base_query = "
    SELECT 
        stock_data.*,
        CASE 
            WHEN stock_data.stok <= 1000 THEN 'Kritis'
            WHEN stock_data.stok <= GREATEST(stock_data.min_stok, {$warning_threshold}) THEN 'Hati-hati'
            ELSE 'Aman'
        END AS status
    FROM ({$stock_summary_query}) AS stock_data
    WHERE stock_data.stok <= GREATEST(stock_data.min_stok, {$warning_threshold})
    {$status_condition}
";

$ordered_query = $base_query . "
    ORDER BY stock_data.kode ASC
";

$count_query = "SELECT COUNT(*) as total FROM ({$base_query}) as critical_products";
$stats_query = "SELECT 
        COUNT(*) as total_items,
        SUM(CASE WHEN stok <= 1000 THEN 1 ELSE 0 END) as kritis_count,
        SUM(CASE WHEN stok > 1000 AND stok <= GREATEST(min_stok, {$warning_threshold}) THEN 1 ELSE 0 END) as peringatan_count,
        SUM(stok) as total_stok
    FROM ({$base_query}) as critical_products";
$main_query = $ordered_query . " LIMIT ? OFFSET ?";

// Export to Excel
if (isset($_GET['action']) && $_GET['action'] === 'export_excel') {
    try {
        $export_stmt = $conn->prepare($ordered_query);
        if ($export_stmt === false) {
            throw new Exception("Gagal mempersiapkan data ekspor: " . $conn->error);
        }

        if (!empty($base_param_types)) {
            $export_stmt->bind_param($base_param_types, ...$base_param_values);
        }

        if (!$export_stmt->execute()) {
            throw new Exception("Gagal mengeksekusi data ekspor: " . $export_stmt->error);
        }

        $export_data = $export_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="stok_kritis_' . date('Ymd_His') . '.xls"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo "<table border='1'>";
        echo "<tr>
                <th>Kode</th>
                <th>Nama Barang</th>
                <th>Kategori</th>
                <th>Satuan</th>
                <th>Stok</th>
                <th>Min. Stok</th>
                <th>Status</th>
                <th>Persentase</th>
            </tr>";

        foreach ($export_data as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['kode']) . "</td>";
            echo "<td>" . htmlspecialchars($row['nama']) . "</td>";
            echo "<td>" . htmlspecialchars($row['kategori']) . "</td>";
            echo "<td>" . htmlspecialchars($row['satuan']) . "</td>";
            echo "<td>" . (int)$row['stok'] . "</td>";
            echo "<td>" . (int)$row['min_stok'] . "</td>";
            echo "<td>" . htmlspecialchars($row['status']) . "</td>";
            echo "<td>" . htmlspecialchars($row['persentase']) . "%</td>";
            echo "</tr>";
        }

        echo "</table>";
        exit;
    } catch (Exception $e) {
        die("Terjadi kesalahan saat menyiapkan ekspor: " . $e->getMessage());
    }
}

try {
    // Hitung total data
    $stmt = $conn->prepare($count_query);
    if ($stmt === false) {
        throw new Exception("Gagal mempersiapkan query hitung: " . $conn->error);
    }
    
    // Binding parameter untuk count query
    if (!empty($base_param_types)) {
        $stmt->bind_param($base_param_types, ...$base_param_values);
    }
    
    if (!$stmt->execute()) {
        throw new Exception("Gagal mengeksekusi query hitung: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $total_rows = $result->fetch_assoc()['total'];
    $total_pages = ceil($total_rows / $per_page);
    
    // Ambil data dengan pagination
    $stmt = $conn->prepare($main_query);
    if ($stmt === false) {
        throw new Exception("Gagal mempersiapkan query utama: " . $conn->error);
    }
    
    // Binding parameter untuk main query
    if (!empty($base_param_types)) {
        $param_types = $base_param_types . 'ii';
        $params = array_merge($base_param_values, [$per_page, $offset]);
        $stmt->bind_param($param_types, ...$params);
    } else {
        $stmt->bind_param('ii', $per_page, $offset);
    }
    
    if (!$stmt->execute()) {
        throw new Exception("Gagal mengeksekusi query utama: " . $stmt->error);
    }
    
    $critical_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Hitung statistik
    $kritis_count = 0;
    $peringatan_count = 0;
    $total_stok = 0;
    $total_items = 0;
    
    $stmt_stats = $conn->prepare($stats_query);
    if ($stmt_stats === false) {
        throw new Exception("Gagal mempersiapkan query statistik: " . $conn->error);
    }
    
    if (!empty($base_param_types)) {
        $stmt_stats->bind_param($base_param_types, ...$base_param_values);
    }
    
    if (!$stmt_stats->execute()) {
        throw new Exception("Gagal mengeksekusi query statistik: " . $stmt_stats->error);
    }
    
    $stats_result = $stmt_stats->get_result()->fetch_assoc();
    if ($stats_result) {
        $total_items = (int)($stats_result['total_items'] ?? 0);
        $kritis_count = (int)($stats_result['kritis_count'] ?? 0);
        $peringatan_count = (int)($stats_result['peringatan_count'] ?? 0);
        $total_stok = (int)($stats_result['total_stok'] ?? 0);
    }
    
} catch (Exception $e) {
    error_log("Error in stok_kritis.php: " . $e->getMessage());
    $error_message = "Terjadi kesalahan saat mengambil data. Silakan coba lagi nanti.";
}

// Fungsi untuk format rupiah
function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}
?>

<!-- Rest of your HTML code remains the same -->
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stok Kritis - CV. Panca Indra Kemasan</title>
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
            border-radius: 999px;
            border: 1px solid var(--gray-200);
            background-color: white;
            font-size: 0.95rem;
            width: 260px;
            transition: all 0.2s;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04);
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            background-color: white;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary);
        }

        .notification-wrapper {
            position: relative;
        }

        .notification-bell {
            position: relative;
            cursor: pointer;
            padding: 0.5rem 0.65rem;
            border-radius: 999px;
            transition: background-color 0.2s, color 0.2s;
            font-size: 1.1rem;
            color: var(--gray-700);
            border: none;
            background: white;
            box-shadow: 0 1px 2px rgba(0,0,0,0.08);
        }

        .notification-bell:hover,
        .notification-bell.active {
            background-color: rgba(79,70,229,0.08);
            color: var(--primary);
        }

        .notification-badge {
            position: absolute;
            top: -4px;
            right: -4px;
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

        .notification-badge.hidden {
            display: none;
        }

        .notification-popup {
            position: absolute;
            top: 120%;
            right: 0;
            width: 320px;
            background: white;
            border-radius: 0.75rem;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--gray-200);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.2s ease;
            z-index: 30;
        }

        .notification-popup.active {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .notification-header {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notification-list {
            max-height: 320px;
            overflow-y: auto;
        }

        .notification-item {
            display: flex;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--gray-100);
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-icon {
            width: 36px;
            height: 36px;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .notification-incoming .notification-icon {
            background-color: #dbeafe;
            color: #1d4ed8;
        }

        .notification-critical .notification-icon {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        .notification-warning .notification-icon {
            background-color: #fef3c7;
            color: #b45309;
        }

        .notification-content .notification-title {
            font-weight: 600;
            color: var(--gray-800);
            font-size: 0.9rem;
        }

        .notification-content .notification-message {
            font-size: 0.85rem;
            color: var(--gray-600);
            margin: 0.15rem 0;
        }

        .notification-content .notification-detail {
            font-size: 0.8rem;
            color: var(--gray-500);
        }

        .notification-content .notification-detail {
            font-size: 0.8rem;
            color: var(--gray-500);
        }

        .notification-content small {
            color: var(--gray-400);
        }

        .notification-empty {
            text-align: center;
            padding: 2rem 1rem;
            color: var(--gray-500);
        }

        .notification-empty i {
            font-size: 2.5rem;
            margin-bottom: 0.75rem;
            color: var(--gray-300);
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

        .select {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--gray-300);
            border-radius: 0.5rem;
            background-color: white;
            font-size: 0.9rem;
            cursor: pointer;
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
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .stat-change {
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            color: var(--gray-500);
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

        .stat-icon.orange {
            background-color: #ffedd5;
            color: #ea580c;
        }

        .stat-icon.red {
            background-color: #fee2e2;
            color: #dc2626;
        }

        .card {
            background: white;
            border-radius: 0.75rem;
            padding: 1.5rem;
            box-shadow: var(--shadow);
            transition: all 0.2s;
            margin-bottom: 1.5rem;
        }

        .card:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
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

        .card-filters {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
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

        tr:last-child td {
            border-bottom: none;
        }

        tbody tr:hover {
            background-color: var(--gray-50);
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .badge {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-success {
            background-color: #dcfce7;
            color: #166534;
        }

        .badge-warning {
            background-color: #fef3c7;
            color: #b45309;
        }

        .badge-danger {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .progress-bar {
            height: 6px;
            background-color: var(--gray-200);
            border-radius: 9999px;
            overflow: hidden;
            margin-top: 0.5rem;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--danger), var(--warning));
            transition: width 0.3s ease;
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

            .header-right {
                flex-direction: column;
            }

            .card-filters {
                flex-direction: column;
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
                    <div class="navbar-title">Stok Kritis</div>
                </div>

                <div class="navbar-right">
                    <div class="search-box">
                        
                    </div>

                    <div class="notification-wrapper">
                        <button class="notification-bell" id="notificationBell"
                            data-latest-notification="<?php echo $latest_notification_time; ?>"
                            data-unread-count="<?php echo $unread_notification_count; ?>">
                            <i class="fas fa-bell"></i>
                            <span class="notification-badge <?php echo $unread_notification_count > 0 ? '' : 'hidden'; ?>" id="notificationBadge" data-unread="<?php echo $unread_notification_count; ?>">
                                <?php echo $unread_notification_count; ?>
                            </span>
                        </button>
                        <div class="notification-popup" id="notificationPopup">
                            <div class="notification-header">
                                <h6>Notifikasi</h6>
                                <span class="badge bg-primary" id="notificationHeaderCount" data-unread="<?php echo $unread_notification_count; ?>">
                                    <?php echo $unread_notification_count > 0 ? $unread_notification_count . ' baru' : 'Tidak ada notifikasi baru'; ?>
                                </span>
                            </div>
                            <div class="notification-list">
                                <?php if ($notification_count === 0): ?>
                                    <div class="notification-empty">
                                        <i class="fas fa-check-circle"></i>
                                        <p>Belum ada notifikasi</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($notifications as $notif): ?>
                                        <div class="notification-item notification-<?php echo htmlspecialchars($notif['type']); ?>">
                                            <div class="notification-icon">
                                                <?php if ($notif['type'] === 'critical'): ?>
                                                    <i class="fas fa-exclamation-circle"></i>
                                                <?php elseif ($notif['type'] === 'warning'): ?>
                                                    <i class="fas fa-exclamation-triangle"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-box"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div class="notification-content">
                                                <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                                <div class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                                                <?php if (!empty($notif['detail'])): ?>
                                                    <div class="notification-detail"><?php echo htmlspecialchars($notif['detail']); ?></div>
                                                <?php endif; ?>
                                                <small><?php echo formatNotificationTime($notif['time']); ?></small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                     <div class="user-profile">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($_SESSION['username'] ?? 'CV. ', 0, 1)); ?>
                        </div>
                        <div class="user-info">
                            <span class="user-name"><?php echo htmlspecialchars($_SESSION['username'] ?? 'CV. PANCA INDRA KEMASAN'); ?></span>
                            <span class="user-role"><?php echo htmlspecialchars($_SESSION['role'] ?? 'Admin'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-area">
                <div class="page-header">
                    <div class="header-left">
                        <h1 class="page-title">Stok Kritis</h1>
                        <p class="page-subtitle">Monitor barang dengan stok di bawah minimum atau dalam status peringatan</p>
                    </div>
                    <div class="header-right"></div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-label">Total Barang Kritis</div>
                            <div class="stat-value"><?php echo $kritis_count; ?></div>
                            <div class="stat-change">
                                <i class="fas fa-alert"></i> Segera restok
                            </div>
                        </div>
                        <div class="stat-icon red">
                            <i class="fas fa-exclamation-circle"></i>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-label">Barang Peringatan</div>
                            <div class="stat-value"><?php echo $peringatan_count; ?></div>
                            <div class="stat-change">
                                Monitor stok
                            </div>
                        </div>
                        <div class="stat-icon orange">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-label">Total Item</div>
                            <div class="stat-value"><?php echo $total_items; ?></div>
                            <div class="stat-change">
                                Dalam monitoring
                            </div>
                        </div>
                        <div class="stat-icon blue">
                            <i class="fas fa-boxes"></i>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-label">Total Stok</div>
                            <div class="stat-value"><?php echo number_format($total_stok); ?></div>
                            <div class="stat-change">
                                Unit terukur
                            </div>
                        </div>
                        <div class="stat-icon green">
                            <i class="fas fa-chart-pie"></i>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Daftar Barang Stok Kritis</h3>
                        <form method="GET" class="card-filters" style="gap:0.5rem; flex-wrap:wrap;">
                            <div class="input-group" style="min-width:220px;">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" name="search" class="form-control" placeholder="Cari kode/nama/kategori..."
                                       value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <select class="select" name="status">
                                <option value="semua" <?php echo $status_filter === 'semua' ? 'selected' : ''; ?>>Semua Status</option>
                                <option value="kritis" <?php echo $status_filter === 'kritis' ? 'selected' : ''; ?>>Kritis</option>
                                <option value="peringatan" <?php echo $status_filter === 'peringatan' ? 'selected' : ''; ?>>Peringatan</option>
                            </select>
                            <select class="select" name="kategori">
                                <option value="semua" <?php echo $kategori_filter === 'semua' ? 'selected' : ''; ?>>Semua Kategori</option>
                                <?php foreach ($kategori_options as $kategori_option): ?>
                                    <option value="<?php echo htmlspecialchars($kategori_option); ?>"
                                        <?php echo $kategori_filter === $kategori_option ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($kategori_option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-outline">Terapkan</button>
                            <?php if (!empty($search) || $status_filter !== 'semua' || $kategori_filter !== 'semua'): ?>
                                <a href="stok_kritis.php" class="btn btn-outline">Reset</a>
                            <?php endif; ?>
                            <button type="submit" name="action" value="export_excel" class="btn btn-primary" formtarget="_blank">
                                <i class="fas fa-download me-1"></i> Laporan
                            </button>
                        </form>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Nama Barang</th>
                                <th>Kategori</th>
                                <th class="text-right">Stok</th>
                                <th class="text-right">Min. Stok</th>
                                <th>Status</th>
                                <th>Progress</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($critical_items as $item): ?>
                            <tr>
                                <td><strong><?php echo $item['kode']; ?></strong></td>
                                <td><?php echo $item['nama']; ?></td>
                                <td><?php echo $item['kategori']; ?></td>
                                <td class="text-right"><?php echo $item['stok']; ?> <?php echo $item['satuan']; ?></td>
                                <td class="text-right"><?php echo $item['min_stok']; ?> <?php echo $item['satuan']; ?></td>
                                <td>
                                    <?php 
                                        if ($item['status'] === 'Kritis') {
                                            echo '<span class="badge badge-danger">' . $item['status'] . '</span>';
                                        } else {
                                            echo '<span class="badge badge-warning">' . $item['status'] . '</span>';
                                        }
                                    ?>
                                </td>
                                <td>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo $item['persentase']; ?>%"></div>
                                    </div>
                                    <small style="color: var(--gray-500);"><?php echo $item['persentase']; ?>%</small>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline" title="Detail">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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

        const notificationBell = document.getElementById('notificationBell');
        const notificationPopup = document.getElementById('notificationPopup');
        const notificationBadge = document.getElementById('notificationBadge');
        const notificationHeaderCount = document.getElementById('notificationHeaderCount');

        const updateNotificationUI = (unreadCount) => {
            if (notificationBadge) {
                notificationBadge.dataset.unread = unreadCount;
                if (unreadCount > 0) {
                    notificationBadge.classList.remove('hidden');
                    notificationBadge.textContent = unreadCount;
                } else {
                    notificationBadge.classList.add('hidden');
                    notificationBadge.textContent = '';
                }
            }

            if (notificationHeaderCount) {
                notificationHeaderCount.dataset.unread = unreadCount;
                notificationHeaderCount.textContent = unreadCount > 0
                    ? `${unreadCount} baru`
                    : 'Tidak ada notifikasi baru';
            }

            if (notificationBell) {
                notificationBell.dataset.unreadCount = unreadCount;
            }
        };

        const markNotificationsAsRead = async () => {
            if (!notificationBell) return;
            const unreadCount = parseInt(notificationBell.dataset.unreadCount || '0', 10);
            if (unreadCount === 0) return;

            const latestTimestamp = parseInt(notificationBell.dataset.latestNotification || '0', 10);
            if (!latestTimestamp) return;

            try {
                const response = await fetch('stok_kritis.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'Accept': 'application/json'
                    },
                    body: new URLSearchParams({
                        action: 'mark_notifications_read',
                        timestamp: latestTimestamp
                    })
                });

                const result = await response.json();
                if (result?.success) {
                    updateNotificationUI(0);
                }
            } catch (error) {
                console.error('Failed to mark notifications as read:', error);
            }
        };

        if (notificationBell && notificationPopup) {
            notificationBell.addEventListener('click', function(e) {
                e.stopPropagation();
                notificationPopup.classList.toggle('active');
                notificationBell.classList.toggle('active');
                if (notificationPopup.classList.contains('active')) {
                    markNotificationsAsRead();
                }
            });

            document.addEventListener('click', function(e) {
                if (!notificationPopup.contains(e.target) && e.target !== notificationBell) {
                    notificationPopup.classList.remove('active');
                    notificationBell.classList.remove('active');
                }
            });
        }
    </script>
</body>
</html>
<?php $conn->close(); ?>
