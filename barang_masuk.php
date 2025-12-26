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
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;
// Inisialisasi variabel untuk view
$total_items = 0;
$total_qty = 0;
$diterima_count = 0;
$proses_count = 0;
$incoming_items = [];
$success_message = '';
$error_message = '';
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

// Handle delete incoming stock
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_incoming') {
    $delete_id = isset($_POST['incoming_id']) ? (int)$_POST['incoming_id'] : 0;

    if ($delete_id > 0) {
        $stmt_delete = $conn->prepare("DELETE FROM stock_mutations WHERE id = ? AND type = 'in'");
        if ($stmt_delete) {
            $stmt_delete->bind_param('i', $delete_id);
            if ($stmt_delete->execute()) {
                header("Location: barang_masuk.php?status=deleted");
                exit;
            }
            $error_message = "Gagal menghapus data: " . $stmt_delete->error;
        } else {
            $error_message = "Gagal menyiapkan penghapusan: " . $conn->error;
        }
    } else {
        $error_message = "Data tidak valid untuk dihapus.";
    }
}

// Export Excel
if (isset($_GET['action']) && $_GET['action'] === 'export_excel') {
    try {
        $export_start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
        $export_end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
        $export_search = isset($_GET['search']) ? trim($_GET['search']) : '';

        $export_query = "SELECT 
                sm.id,
                sm.created_at AS tanggal_masuk,
                sm.quantity AS qty,
                sm.keterangan,
                p.kode_produk AS kode,
                p.nama_produk AS nama,
                p.kategori,
                p.satuan
            FROM stock_mutations sm
            JOIN products p ON sm.product_id = p.id
            WHERE sm.type = 'in'
            AND DATE(sm.created_at) BETWEEN ? AND ?";

        $params = [$export_start_date, $export_end_date];
        $types = 'ss';

        if (!empty($export_search)) {
            $export_query .= " AND (p.nama_produk LIKE ? OR p.kategori LIKE ? OR p.kode_produk LIKE ?)";
            $like = "%{$export_search}%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $types .= 'sss';
        }

        $export_query .= " ORDER BY sm.created_at DESC";

        $stmt_export = $conn->prepare($export_query);
        if ($stmt_export === false) {
            throw new Exception("Gagal menyiapkan data ekspor: " . $conn->error);
        }

        $stmt_export->bind_param($types, ...$params);

        if (!$stmt_export->execute()) {
            throw new Exception("Gagal mengeksekusi data ekspor: " . $stmt_export->error);
        }

        $result_export = $stmt_export->get_result();

        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="barang_masuk_' . date('Ymd_His') . '.xls"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo "<table border='1'>";
        echo "<tr>
                <th>Tanggal Masuk</th>
                <th>Kode Barang</th>
                <th>Nama Barang</th>
                <th>Kategori</th>
                <th>Kuantitas</th>
                <th>Satuan</th>
                <th>Keterangan</th>
            </tr>";

        while ($row = $result_export->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['tanggal_masuk']) . "</td>";
            echo "<td>" . htmlspecialchars($row['kode']) . "</td>";
            echo "<td>" . htmlspecialchars($row['nama']) . "</td>";
            echo "<td>" . htmlspecialchars($row['kategori']) . "</td>";
            echo "<td>" . number_format((int)$row['qty'], 0, ',', '.') . "</td>";
            echo "<td>" . htmlspecialchars($row['satuan']) . "</td>";
            echo "<td>" . htmlspecialchars($row['keterangan'] ?? '-') . "</td>";
            echo "</tr>";
        }

        echo "</table>";
        exit;
    } catch (Exception $e) {
        die("Terjadi kesalahan saat menyiapkan ekspor: " . $e->getMessage());
    }
}

// Base queries menggunakan tabel mutasi stok (stock_mutations)
$main_query = "SELECT 
        sm.id,
        sm.product_id,
        sm.quantity as qty,
        sm.keterangan,
        sm.created_at as tanggal_masuk,
        p.kode_produk as kode,
        p.nama_produk as nama,
        p.kategori,
        p.satuan,
        p.harga_jual,
        (sm.quantity * p.harga_jual) as total_value
    FROM stock_mutations sm
    JOIN products p ON sm.product_id = p.id
    WHERE sm.type = 'in'
    AND DATE(sm.created_at) BETWEEN ? AND ?";

$count_query = "SELECT COUNT(*) as total
    FROM stock_mutations sm
    JOIN products p ON sm.product_id = p.id
    WHERE sm.type = 'in'
    AND DATE(sm.created_at) BETWEEN ? AND ?";

$stats_query = "SELECT 
        COUNT(DISTINCT sm.id) as total_transaksi,
        COALESCE(SUM(sm.quantity), 0) as total_qty,
        COALESCE(SUM(sm.quantity * p.harga_jual), 0) as total_value
    FROM stock_mutations sm
    JOIN products p ON sm.product_id = p.id
    WHERE sm.type = 'in'
    AND DATE(sm.created_at) BETWEEN ? AND ?";

// Tambahkan filter pencarian jika ada
$search_term = '';
if (!empty($search)) {
    $search_term = "%$search%";
    $filter = " AND (p.nama_produk LIKE ? OR p.kategori LIKE ? OR p.kode_produk LIKE ?)";
    $main_query .= $filter;
    $count_query .= $filter;
    $stats_query .= $filter;
}

$main_query .= " ORDER BY sm.created_at DESC LIMIT ? OFFSET ?";

try {
    // Hitung total data
    $stmt = $conn->prepare($count_query);
    if ($stmt === false) {
        throw new Exception("Failed to prepare count query: " . $conn->error);
    }
    // Binding parameter untuk count query
    if (!empty($search_term)) {
        $stmt->bind_param('sssss', $start_date, $end_date, $search_term, $search_term, $search_term);
    } else {
        $stmt->bind_param('ss', $start_date, $end_date);
    }
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to execute count query: " . $stmt->error);
    }
    
    $total_rows = $stmt->get_result()->fetch_assoc()['total'];
    $total_pages = ceil($total_rows / $per_page);
    
    // Ambil data statistik
    $stmt_stats = $conn->prepare($stats_query);
    if ($stmt_stats === false) {
        throw new Exception("Failed to prepare stats query: " . $conn->error);
    }
    
    // Binding parameter untuk stats query
    if (!empty($search_term)) {
        $stmt_stats->bind_param('sssss', $start_date, $end_date, $search_term, $search_term, $search_term);
    } else {
        $stmt_stats->bind_param('ss', $start_date, $end_date);
    }
    
    if (!$stmt_stats->execute()) {
        throw new Exception("Failed to execute stats query: " . $stmt_stats->error);
    }
    
    $stats = $stmt_stats->get_result()->fetch_assoc();
    $total_items = $stats['total_transaksi'] ?? 0;
    $total_qty = $stats['total_qty'] ?? 0;
    $diterima_count = $total_items;
    
    // Ambil data dengan pagination
    $stmt = $conn->prepare($main_query);
    if ($stmt === false) {
        throw new Exception("Failed to prepare main query: " . $conn->error);
    }
    
    // Binding parameter untuk main query
    if (!empty($search_term)) {
        $stmt->bind_param('sssssii', $start_date, $end_date, $search_term, $search_term, $search_term, $per_page, $offset);
    } else {
        $stmt->bind_param('ssii', $start_date, $end_date, $per_page, $offset);
    }
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to execute main query: " . $stmt->error);
    }
    
    $incoming_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
} catch (Exception $e) {
    error_log("Error in barang_masuk.php: " . $e->getMessage());
    $error_message = "Terjadi kesalahan saat mengambil data. Silakan coba lagi nanti.";
}
// Fungsi untuk format rupiah
function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}
// Fungsi untuk format tanggal Indonesia
function formatTanggal($date) {
    $bulan = array(
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
        5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
        9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
    );
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
    <title>Barang Masuk - CV. Panca Indra Kemasan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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

        .stat-icon.purple {
            background-color: #f3e8ff;
            color: #7c3aed;
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
            align-items: center;
        }

        .form-select, .form-control {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--gray-300);
            border-radius: 0.5rem;
            background-color: white;
            font-size: 0.9rem;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .input-group {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .input-group-text {
            padding: 0.5rem 0.75rem;
            background-color: var(--gray-100);
            border: 1px solid var(--gray-300);
            border-radius: 0.5rem;
            font-size: 0.9rem;
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

        .badge-info {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .bg-primary {
            background-color: var(--primary);
            color: white;
        }

        .footer {
            background: white;
            padding: 1.5rem 2rem;
            text-align: center;
            color: var(--gray-500);
            font-size: 0.85rem;
            border-top: 1px solid var(--gray-200);
        }

        .d-flex {
            display: flex;
        }

        .gap-2 {
            gap: 0.5rem;
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
                    <div class="navbar-title">Barang Masuk</div>
                </div>

                <div class="navbar-right">

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
                                        <p>Tidak ada notifikasi terbaru</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($notifications as $notif): ?>
                                        <div class="notification-item notification-<?php echo htmlspecialchars($notif['type']); ?>"
                                            data-notif-time="<?php echo strtotime($notif['time']); ?>">
                                            <div class="notification-icon">
                                                <?php if ($notif['type'] === 'critical'): ?>
                                                    <i class="fas fa-exclamation-circle"></i>
                                                <?php elseif ($notif['type'] === 'warning'): ?>
                                                    <i class="fas fa-exclamation-triangle"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-arrow-down"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div class="notification-content">
                                                <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                                <div class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                                                <?php if (isset($notif['detail'])): ?>
                                                    <div class="notification-detail"><?php echo htmlspecialchars($notif['detail']); ?></div>
                                                <?php endif; ?>
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
                        <h1 class="page-title">Barang Masuk</h1>
                        <p class="page-subtitle">Kelola dan monitor penerimaan barang dari supplier</p>
                    </div>
                    <div class="header-right"></div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-label">Total Transaksi</div>
                            <div class="stat-value"><?php echo $total_items; ?></div>
                            <div class="stat-change">
                                <i class="fas fa-info-circle"></i> Bulan ini
                            </div>
                        </div>
                        <div class="stat-icon blue">
                            <i class="fas fa-inbox"></i>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-label">Total Kuantitas</div>
                            <div class="stat-value"><?php echo number_format($total_qty); ?></div>
                            <div class="stat-change">
                                Unit diterima
                            </div>
                        </div>
                        <div class="stat-icon green">
                            <i class="fas fa-boxes"></i>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-label">Status Diterima</div>
                            <div class="stat-value"><?php echo $diterima_count; ?></div>
                            <div class="stat-change">
                                Barang terkonfirmasi
                            </div>
                        </div>
                        <div class="stat-icon orange">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-label">Proses QC</div>
                            <div class="stat-value"><?php echo $proses_count; ?></div>
                            <div class="stat-change">
                                Sedang diperiksa
                            </div>
                        </div>
                        <div class="stat-icon purple">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Daftar Barang Masuk</h3>
                        <div class="card-filters d-flex align-items-center gap-2 flex-wrap">
                            <form method="GET" class="d-inline">
                                <input type="hidden" name="action" value="export_excel">
                                <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                                <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                                <button type="submit" class="btn btn-outline-success btn-sm" title="Export ke Excel">
                                    <i class="fas fa-file-excel me-1"></i> Export
                                </button>
                            </form>
                            <form method="GET" class="d-flex gap-2">
                                <div class="input-group" style="width: 400px;">
                                    <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                                    <span class="input-group-text">s/d</span>
                                    <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                                </div>
                                <div class="input-group">
                                    <input type="text" name="search" class="form-control" placeholder="Cari nama/kategori..." value="<?php echo htmlspecialchars($search); ?>">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                                    <?php if (!empty($search) || $start_date != date('Y-m-01') || $end_date != date('Y-m-t')): ?>
                                        <a href="barang_masuk.php" class="btn btn-outline">Reset</a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th>Kode Barang</th>
                                <th>Nama Barang</th>
                                <th>Kategori</th>
                                <th class="text-right">Qty</th>
                                <th>Tanggal Masuk</th>
                                <th>Keterangan</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($incoming_items) > 0): ?>
                                <?php foreach ($incoming_items as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['kode']); ?></td>
                                        <td><?php echo htmlspecialchars($item['nama']); ?></td>
                                        <td><?php echo htmlspecialchars($item['kategori']); ?></td>
                                        <td class="text-right"><?php echo number_format($item['qty'], 0, ',', '.') . ' ' . $item['satuan']; ?></td>
                                        <td><?php echo formatTanggal($item['tanggal_masuk']); ?></td>
                                        <td><?php echo htmlspecialchars($item['keterangan'] ?? '-'); ?></td>
                                        <td class="text-center">
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus data barang masuk ini?');">
                                                <input type="hidden" name="action" value="delete_incoming">
                                                <input type="hidden" name="incoming_id" value="<?php echo (int)$item['id']; ?>">
                                                <button class="btn-icon" style="color: var(--danger);" type="submit" title="Hapus">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center" style="padding: 3rem;">
                                        <div style="color: var(--gray-500);">
                                            <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem; display: block;"></i>
                                            <p style="margin-bottom: 0; font-size: 1rem;">Tidak ada data barang masuk</p>
                                            <?php if (!empty($search)): ?>
                                                <p style="font-size: 0.875rem; margin-top: 0.5rem;">Coba hapus kata kunci pencarian</p>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <?php if ($total_pages > 1): ?>
                    <div style="display: flex; justify-content: center; margin-top: 1.5rem; gap: 0.5rem;">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" 
                               class="btn <?php echo $page == $i ? 'btn-primary' : 'btn-outline'; ?> btn-sm">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <footer class="footer">
                <p>&copy; CV. Panca Indra Kemasan. All Rights Reserved. | Sistem Manajemen Dashboard v2.0</p>
            </footer>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Toggle sidebar

        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');

        if (window.innerWidth < 1024) {
            sidebarToggle.style.display = 'block';
            sidebar.classList.remove('active');
        }

        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
        });

        window.addEventListener('resize', () => {
            if (window.innerWidth >= 1024) {
                sidebarToggle.style.display = 'none';
                sidebar.classList.add('active');
            } else {
                sidebarToggle.style.display = 'block';
            }
        });

        // Notifications
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
                const response = await fetch('barang_masuk.php', {
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
            notificationBell.addEventListener('click', (e) => {
                e.stopPropagation();
                notificationPopup.classList.toggle('active');
                notificationBell.classList.toggle('active');
                if (notificationPopup.classList.contains('active')) {
                    markNotificationsAsRead();
                }
            });

            document.addEventListener('click', (e) => {
                if (!notificationPopup.contains(e.target) && !notificationBell.contains(e.target)) {
                    notificationPopup.classList.remove('active');
                    notificationBell.classList.remove('active');
                }
            });
        }

        // Initialize date picker
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d",
            allowInput: true
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
</body>
</html>
<?php $conn->close(); ?>