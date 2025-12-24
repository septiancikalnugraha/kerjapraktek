<?php
require_once 'config/config.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/helpers/notifications.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

requireLogin();

/**
 * Helper untuk mengambil data stok produk dengan filter yang sama
 * digunakan oleh tabel, AJAX refresh, dan ekspor.
 *
 * @param mysqli     $conn
 * @param string     $search
 * @param string     $kategori
 * @param string     $orderDirection 'ASC' atau 'DESC'
 * @param int|null   $limit
 * @param int|null   $offset
 * @return array
 * @throws Exception
 */
function getStockProducts(mysqli $conn, string $search = '', string $kategori = 'semua', string $orderDirection = 'ASC', ?int $limit = null, ?int $offset = null): array
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
        WHERE 1=1
    ";

    $params = [];
    $types = '';

    if (!empty($search)) {
        $searchTerm = "%{$search}%";
        $query .= " AND (p.nama_produk LIKE ? OR p.kode_produk LIKE ?)";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= 'ss';
    }

    if (!empty($kategori) && $kategori !== 'semua') {
        $query .= " AND p.kategori = ?";
        $params[] = $kategori;
        $types .= 's';
    }

    $query .= "
        GROUP BY p.id, p.kode_produk, p.nama_produk, p.kategori, p.satuan, p.harga_jual, p.stok_minimal
    ";

    $orderDirection = strtoupper($orderDirection) === 'DESC' ? 'DESC' : 'ASC';
    $query .= " ORDER BY CAST(SUBSTRING(p.kode_produk, 5) AS UNSIGNED) {$orderDirection}, p.nama_produk ASC";

    if ($limit !== null && $offset !== null) {
        $query .= " LIMIT ? OFFSET ?";
        $types .= 'ii';
        $params[] = $limit;
        $params[] = $offset;
    }

    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        throw new Exception("Gagal mempersiapkan data stok: " . $conn->error);
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        throw new Exception("Gagal mengeksekusi data stok: " . $stmt->error);
    }

    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Hitung total produk yang sesuai filter.
 */
function countStockProducts(mysqli $conn, string $search = '', string $kategori = 'semua'): int
{
    $query = "SELECT COUNT(DISTINCT p.id) as total FROM products p WHERE 1=1";
    $params = [];
    $types = '';

    if (!empty($search)) {
        $searchTerm = "%{$search}%";
        $query .= " AND (p.nama_produk LIKE ? OR p.kode_produk LIKE ?)";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= 'ss';
    }

    if (!empty($kategori) && $kategori !== 'semua') {
        $query .= " AND p.kategori = ?";
        $params[] = $kategori;
        $types .= 's';
    }

    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        throw new Exception("Gagal menghitung data stok: " . $conn->error);
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        throw new Exception("Gagal mengeksekusi hitung data stok: " . $stmt->error);
    }

    $result = $stmt->get_result()->fetch_assoc();
    return (int)($result['total'] ?? 0);
}

function parseIntegerValue($value): int
{
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }

    $value = preg_replace('/[^\d\-]/', '', $value);
    if ($value === '' || $value === '-' || $value === '--') {
        return 0;
    }

    return (int)$value;
}

function parseCurrencyValue($value): float
{
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }

    $value = preg_replace('/[^0-9,.\-]/', '', $value);
    if ($value === '' || $value === '-' || $value === '--') {
        return 0;
    }

    $value = str_replace('.', '', $value);
    $value = str_replace(',', '.', $value);

    return (float)$value;
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'import_excel'
) {
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File tidak ditemukan atau gagal diupload');
        }

        $file = $_FILES['excel_file'];
        $allowed_extensions = ['xls', 'xlsx', 'csv'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($file_extension, $allowed_extensions)) {
            throw new Exception('Format file tidak didukung. Gunakan file Excel (.xls, .xlsx) atau CSV');
        }

        $conn = getConnection();

        $success_count = 0;
        $error_count = 0;
        $errors = [];

        $conn->begin_transaction();

        $processRow = function(array $row, int $row_number) use ($conn, &$success_count, &$error_count, &$errors) {
            try {
                $kode_produk = trim($row[0] ?? '');
                $nama_produk = trim($row[1] ?? '');
                $satuan = trim($row[2] ?? '');
                $kategori = trim($row[3] ?? '');
                $stok_awal = parseIntegerValue($row[4] ?? '0');
                $harga_jual = parseCurrencyValue($row[5] ?? '0');

                if (empty($kode_produk) || empty($nama_produk) || empty($kategori) || empty($satuan)) {
                    throw new Exception("Data tidak lengkap pada baris $row_number");
                }

                if ($harga_jual <= 0) {
                    throw new Exception("Harga jual tidak valid pada baris $row_number");
                }

                if ($stok_awal < 0) {
                    throw new Exception("Stok awal tidak boleh negatif pada baris $row_number");
                }

                $check = $conn->prepare("SELECT id FROM products WHERE kode_produk = ?");
                $check->bind_param("s", $kode_produk);
                $check->execute();
                $result = $check->get_result();

                if ($result->num_rows > 0) {
                    $product = $result->fetch_assoc();
                    $product_id = $product['id'];

                    $stmt = $conn->prepare("UPDATE products SET nama_produk = ?, kategori = ?, satuan = ?, harga_jual = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->bind_param("sssdi", $nama_produk, $kategori, $satuan, $harga_jual, $product_id);

                    if (!$stmt->execute()) {
                        throw new Exception("Gagal update produk pada baris $row_number");
                    }
                } else {
                    $stmt = $conn->prepare("INSERT INTO products (kode_produk, nama_produk, kategori, satuan, harga_jual, stok_minimal, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1000, NOW(), NOW())");
                    $stmt->bind_param("ssssd", $kode_produk, $nama_produk, $kategori, $satuan, $harga_jual);

                    if (!$stmt->execute()) {
                        throw new Exception("Gagal menambahkan produk pada baris $row_number");
                    }

                    $product_id = $conn->insert_id;

                    if ($stok_awal > 0) {
                        $mutation = $conn->prepare("INSERT INTO stock_mutations (product_id, type, quantity, keterangan, created_at) VALUES (?, 'in', ?, 'Stok Awal - Import Excel', NOW())");
                        $mutation->bind_param("ii", $product_id, $stok_awal);

                        if (!$mutation->execute()) {
                            throw new Exception("Gagal menambahkan mutasi stok pada baris $row_number");
                        }
                    }
                }

                $success_count++;
            } catch (Exception $e) {
                $error_count++;
                $errors[] = $e->getMessage();
            }
        };

        if ($file_extension === 'csv') {
            $handle = fopen($file['tmp_name'], 'r');
            if (!$handle) {
                throw new Exception('Gagal membaca file CSV');
            }

            fgetcsv($handle); // skip header

            $row_number = 2;
            while (($row = fgetcsv($handle)) !== false) {
                if (empty(array_filter($row))) {
                    $row_number++;
                    continue;
                }

                $processRow($row, $row_number);
                $row_number++;
            }

            fclose($handle);
        } else {
            $spreadsheet = IOFactory::load($file['tmp_name']);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray(null, true, true, false);

            foreach ($rows as $index => $row) {
                // Skip header row
                if ($index === 0) {
                    continue;
                }

                $row_number = $index + 1;

                if (empty(array_filter($row, fn($value) => $value !== null && $value !== ''))) {
                    continue;
                }

                $processRow($row, $row_number);
            }
        }

        $conn->commit();

        if (ob_get_length()) {
            ob_clean();
        }
        echo json_encode([
            'success' => true,
            'message' => "Import berhasil! $success_count data diproses" . ($error_count > 0 ? ", $error_count gagal" : ""),
            'success_count' => $success_count,
            'error_count' => $error_count,
            'errors' => $errors
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        if (isset($conn) && $conn->errno) {
            $conn->rollback();
        }
        if (ob_get_length()) {
            ob_clean();
        }
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

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
        'last_seen' => $_SESSION['notifications_last_seen'],
    ]);
    exit;
}

// Set default timezone
date_default_timezone_set('Asia/Jakarta');
// Get database connection
$conn = getConnection();
// Initialize variables
$error_message = '';

$success_message = '';
$products = [];
$categories = [];
$total_pages = 1;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;
$search = $_GET['search'] ?? '';
$kategori_filter = $_GET['kategori'] ?? 'semua';

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

// Generate auto code for new product (continue sequentially from highest PRD number)
$auto_code = 'PRD-0001'; // Default value
$result = $conn->query("SELECT MAX(CAST(SUBSTRING(kode_produk, 5) AS UNSIGNED)) AS max_num FROM products WHERE kode_produk LIKE 'PRD-%'");
if ($result && ($row = $result->fetch_assoc())) {
    $next_num = ((int)($row['max_num'] ?? 0)) + 1;
    $auto_code = 'PRD-' . str_pad($next_num, 4, '0', STR_PAD_LEFT);
}

// Check database connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
// Handle AJAX request for stock data
if (isset($_GET['action']) && $_GET['action'] === 'get_updated_stock') {
    header('Content-Type: application/json');
    
    try {
        $search = $_GET['search'] ?? '';
        $kategori = $_GET['kategori'] ?? 'semua';
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $per_page = 10;
        $offset = ($page - 1) * $per_page;
        
        // Build the query
        $query = "SELECT p.*, 
                 COALESCE(SUM(CASE WHEN sm.type = 'in' THEN sm.quantity ELSE -sm.quantity END), 0) as stok_akhir 
                 FROM products p 
                 LEFT JOIN stock_mutations sm ON p.id = sm.product_id 
                 WHERE 1=1";
        
        $params = [];
        $types = '';
        
        if (!empty($search)) {
            $query .= " AND (p.nama_produk LIKE ? OR p.kode_produk LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types .= 'ss';
        }
        
        if (!empty($kategori) && $kategori !== 'semua') {
            $query .= " AND p.kategori = ?";
            $params[] = $kategori;
            $types .= 's';
        }
        
        $query .= " GROUP BY p.id, p.kode_produk, p.nama_produk, p.kategori, p.satuan, p.harga_jual, p.stok_minimal
                   ORDER BY CAST(SUBSTRING(p.kode_produk, 5) AS UNSIGNED) ASC, p.nama_produk ASC
                   LIMIT ? OFFSET ?";
        
        $params[] = $per_page;
        $params[] = $offset;
        $types .= 'ii';
        
        $stmt = $conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Format the products for the response
        $formattedProducts = [];
        foreach ($products as $product) {
            $formattedProducts[] = [
                'id' => $product['id'],
                'kode_produk' => $product['kode_produk'],
                'nama_produk' => $product['nama_produk'],
                'kategori' => $product['kategori'],
                'satuan' => $product['satuan'],
                'harga_jual' => $product['harga_jual'],
                'stok_akhir' => $product['stok_akhir'],
                'stok_minimal' => $product['stok_minimal'],
                'stok_sisa' => $product['stok_akhir'] - $product['stok_minimal']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'products' => $formattedProducts,
            'timestamp' => time()
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'export_excel') {
    try {
        $export_search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $export_category = isset($_GET['kategori']) ? trim($_GET['kategori']) : 'semua';

        $export_query = "
            SELECT 
                p.id,
                p.kode_produk,
                p.nama_produk,
                p.kategori,
                p.satuan,
                p.harga_jual,
                COALESCE(SUM(CASE WHEN sm.type = 'in' THEN sm.quantity ELSE -sm.quantity END), 0) AS stok_akhir
            FROM products p
            LEFT JOIN stock_mutations sm ON p.id = sm.product_id
            WHERE 1=1
        ";

        $params = [];
        $types = '';

        if (!empty($export_search)) {
            $like = "%{$export_search}%";
            $export_query .= " AND (p.nama_produk LIKE ? OR p.kode_produk LIKE ?)";
            $params[] = $like;
            $params[] = $like;
            $types .= 'ss';
        }

        if (!empty($export_category) && $export_category !== 'semua') {
            $export_query .= " AND p.kategori = ?";
            $params[] = $export_category;
            $types .= 's';
        }

        $export_query .= "
            GROUP BY p.id, p.kode_produk, p.nama_produk, p.kategori, p.satuan, p.harga_jual
            ORDER BY CAST(SUBSTRING(p.kode_produk, 5) AS UNSIGNED) DESC, p.nama_produk ASC
        ";

        $stmt = $conn->prepare($export_query);
        if ($stmt === false) {
            throw new Exception("Gagal mempersiapkan data ekspor: " . $conn->error);
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            throw new Exception("Gagal mengeksekusi data ekspor: " . $stmt->error);
        }

        $result = $stmt->get_result();

        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="stok_barang_' . date('Ymd_His') . '.xls"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo "<table border='1'>";
        echo "<tr>
                <th>Kode</th>
                <th>Nama Barang</th>
                <th>Satuan</th>
                <th>Kategori</th>
                <th>Stok Akhir</th>
                <th>Harga Jual</th>
                <th>Total Nilai</th>
                <th>Status</th>
            </tr>";

        while ($row = $result->fetch_assoc()) {
            $stok_akhir = (int)$row['stok_akhir'];
            $status = 'Aman';
            if ($stok_akhir <= 1000) {
                $status = 'Kritis';
            } elseif ($stok_akhir <= 2000) {
                $status = 'Hati-hati';
            }

            $total_nilai = $stok_akhir * (float)$row['harga_jual'];

            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['kode_produk']) . "</td>";
            echo "<td>" . htmlspecialchars($row['nama_produk']) . "</td>";
            echo "<td>" . htmlspecialchars($row['satuan']) . "</td>";
            echo "<td>" . htmlspecialchars($row['kategori']) . "</td>";
            echo "<td>'" . number_format($stok_akhir, 0, ',', '.') . "</td>";
            echo "<td>'Rp " . number_format((float)$row['harga_jual'], 0, ',', '.') . "</td>";
            echo "<td>'Rp " . number_format($total_nilai, 0, ',', '.') . "</td>";
            echo "<td>" . $status . "</td>";
            echo "</tr>";
        }

        echo "</table>";
        exit;
    } catch (Exception $e) {
        die("Terjadi kesalahan saat menyiapkan ekspor: " . $e->getMessage());
    }
}

// Handle AJAX request for notifications update
if (isset($_GET['action']) && $_GET['action'] === 'get_notifications') {
    header('Content-Type: application/json');

    try {
        $notifications = getDashboardNotifications($conn);
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

        $formatted_notifications = array_map(function($notif) {
            return [
                'type' => $notif['type'],
                'title' => $notif['title'],
                'message' => $notif['message'],
                'detail' => $notif['detail'] ?? '',
                'time' => $notif['time'],
                'time_formatted' => formatNotificationTime($notif['time']),
                'read' => $notif['time'] <= $last_seen_notifications ? true : false
            ];
        }, $notifications);

        echo json_encode([
            'notifications' => $formatted_notifications,
            'unread_count' => $unread_notification_count,
            'latest_notification_time' => $latest_notification_time
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'tambah_stok') {
    header('Content-Type: application/json');
    
    try {
        // Validate input
        if (!isset($_POST['id']) || !isset($_POST['jumlah']) || !is_numeric($_POST['jumlah']) || $_POST['jumlah'] <= 0) {
            throw new Exception('Jumlah stok tidak valid');
        }
        
        $product_id = (int)$_POST['id'];
        $quantity = (int)$_POST['jumlah'];
        $keterangan = $_POST['keterangan'] ?? 'Penambahan stok';
        
        // Start transaction
        $conn->begin_transaction();
        
        // Get current stock by calculating from stock_mutations
        $stmt = $conn->prepare("
            SELECT p.id, 
                   COALESCE(SUM(CASE WHEN sm.type = 'in' THEN sm.quantity ELSE -sm.quantity END), 0) as stok_akhir 
            FROM products p 
            LEFT JOIN stock_mutations sm ON p.id = sm.product_id 
            WHERE p.id = ?
            GROUP BY p.id
        ");
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception("Produk tidak ditemukan");
        }
        
        $product = $result->fetch_assoc();
        $new_stock = $product['stok_akhir'] + $quantity;
        
        // Add stock mutation record
        $mutation = $conn->prepare("
            INSERT INTO stock_mutations (product_id, type, quantity, keterangan, created_at) 
            VALUES (?, 'in', ?, ?, NOW())
        ");
        $keterangan = $keterangan ?: 'Penambahan stok manual';
        $mutation->bind_param("iis", $product_id, $quantity, $keterangan);
        
        if (!$mutation->execute()) {
            throw new Exception("Gagal mencatat mutasi stok: " . $mutation->error);
        }
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Stok berhasil ditambahkan',
            'new_stock' => $new_stock
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($conn) $conn->rollback();
        
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Handle delete product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    header('Content-Type: application/json');
    
    try {
        // Validate input
        if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
            throw new Exception('ID produk tidak valid');
        }
        
        $product_id = (int)$_POST['id'];
        
        // Start transaction
        $conn->begin_transaction();
        
        // First, delete related stock mutations
        $deleteMutations = $conn->prepare("DELETE FROM stock_mutations WHERE product_id = ?");
        if (!$deleteMutations) {
            throw new Exception("Error preparing statement: " . $conn->error);
        }
        
        $deleteMutations->bind_param("i", $product_id);
        if (!$deleteMutations->execute()) {
            throw new Exception("Gagal menghapus data mutasi stok: " . $deleteMutations->error);
        }
        
        // Then delete the product
        $deleteProduct = $conn->prepare("DELETE FROM products WHERE id = ?");
        if (!$deleteProduct) {
            throw new Exception("Error preparing statement: " . $conn->error);
        }
        
        $deleteProduct->bind_param("i", $product_id);
        if (!$deleteProduct->execute()) {
            throw new Exception("Gagal menghapus produk: " . $deleteProduct->error);
        }
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Produk berhasil dihapus'
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($conn) $conn->rollback();
        
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

try {
    // Check columns in products table
    $result = $conn->query("SHOW COLUMNS FROM products");
    $columns = [];
    $has_stock_mutations = $conn->query("SHOW TABLES LIKE 'stock_mutations'")->num_rows > 0;
    
    while($row = $result->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
    
    // Get unique categories
    if (in_array('kategori', $columns)) {
        $categories_result = $conn->query("SELECT DISTINCT kategori FROM products WHERE kategori IS NOT NULL AND kategori != '' ORDER BY kategori");
        if ($categories_result) {
            $categories = $categories_result->fetch_all(MYSQLI_NUM);
            $categories = array_column($categories, 0);
        }
    }
    
    // Build main query
    $query = "SELECT p.*";
    
    // Always calculate stock from stock_mutations
    $query .= ", COALESCE(SUM(CASE WHEN sm.type = 'in' THEN sm.quantity ELSE -sm.quantity END), 0) as stok_akhir 
              FROM products p 
              LEFT JOIN stock_mutations sm ON p.id = sm.product_id 
              WHERE 1=1";
    
    $params = [];
    $types = '';
    
    // Add search condition
    if (!empty($search)) {
        $search_term = "%$search%";
        $query .= " AND (p.nama_produk LIKE ? OR p.kode_produk LIKE ?)";
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= 'ss';
    }
    
    // Add category filter
    if (!empty($kategori_filter) && $kategori_filter !== 'semua') {
        $query .= " AND p.kategori = ?";
        $params[] = $kategori_filter;
        $types .= 's';
    }
    
    // Group by for stock calculation
    if ($has_stock_mutations) {
        $query .= " GROUP BY p.id, p.kode_produk, p.nama_produk, p.kategori, p.satuan, p.harga_jual, p.stok_minimal";
    }
    
    // Count total records for pagination
    $count_query = "SELECT COUNT(DISTINCT p.id) as total " . substr($query, strpos($query, "FROM"));
    
    // Remove GROUP BY from count query
    if (($pos = strpos($count_query, "GROUP BY")) !== false) {
        $count_query = substr($count_query, 0, $pos);
    }
    
    $count_stmt = $conn->prepare($count_query);
    if (!empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }
    
    $count_stmt->execute();
    $total_result = $count_stmt->get_result();
    $total_rows = $total_result->fetch_assoc()['total'];
    $total_pages = ceil($total_rows / $per_page);
    
    // Add sorting and pagination
    $query .= " ORDER BY CAST(SUBSTRING(p.kode_produk, 5) AS UNSIGNED) ASC, p.nama_produk ASC LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = $offset;
    $types .= 'ii';
    
    // Execute main query
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Calculate stock statistics
    if ($has_stock_mutations) {
        $stok_min_expr = in_array('stok_minimal', $columns) ? 'p.stok_minimal' : '0';
        $harga_expr = in_array('harga_jual', $columns) ? 'p.harga_jual' : '0';
        
        $stats_query = "
            SELECT 
                COUNT(CASE WHEN stok <= 1000 THEN 1 END) as kritis,
                COUNT(CASE WHEN stok > 1000 AND stok <= 2000 THEN 1 END) as peringatan,
                COUNT(CASE WHEN stok > 2000 THEN 1 END) as aman,
                SUM(stok) as total_stok
            FROM (
                SELECT 
                    p.id,
                    COALESCE(SUM(CASE WHEN sm.type = 'in' THEN sm.quantity ELSE -sm.quantity END), 0) as stok
                FROM products p
                LEFT JOIN stock_mutations sm ON p.id = sm.product_id
                GROUP BY p.id
            ) as subquery";
        
        $stats = $conn->query($stats_query);
        if ($stats) {
            $stats_data = $stats->fetch_assoc();
            $critical_stock = (int)($stats_data['kritis'] ?? 0);
            $warning_stock = (int)($stats_data['peringatan'] ?? 0);
            $safe_stock = (int)($stats_data['aman'] ?? 0);
            $total_stock = (int)($stats_data['total_stok'] ?? 0);
        }
    }
    
} catch (Exception $e) {
    $error_message = "Terjadi kesalahan: " . $e->getMessage();
    error_log($error_message);
}
// Function to format currency
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
            top: -6px;
            right: -2px;
            background-color: #f44336;
            color: white;
            border-radius: 999px;
            min-width: 22px;
            height: 22px;
            padding: 0 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 700;
            box-shadow: 0 0 0 2px #fff, 0 5px 10px rgba(244, 67, 54, 0.3);
            transform-origin: 50% 50%;
            transition: opacity 0.2s ease, transform 0.2s ease;
        }

        .notification-badge.hidden {
            display: none;
        }

        .notification-bell.active .notification-badge {
            opacity: 0;
            transform: scale(0.6);
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
            padding: 0.85rem 1rem;
            border-bottom: 1px solid var(--gray-100);
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.15rem;
        }

        .notification-message {
            font-size: 0.85rem;
            color: var(--gray-600);
        }

        .notification-detail {
            font-size: 0.78rem;
            color: var(--gray-500);
            margin-top: 0.15rem;
        }

        .notification-critical .notification-icon {
            background-color: rgba(239, 68, 68, 0.15);
            color: var(--danger);
        }

        .notification-warning .notification-icon {
            background-color: rgba(245, 158, 11, 0.15);
            color: var(--warning);
        }

        .notification-incoming .notification-icon,
        .notification-in .notification-icon {
            background-color: rgba(59, 130, 246, 0.15);
            color: var(--info);
        }

        .notification-empty {
            padding: 2rem 1rem;
            text-align: center;
            color: var(--gray-500);
        }

        .notification-empty i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
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
        
        .summary-stats-row {
            display: flex;
            justify-content: center;
            gap: 1.25rem;
            flex-wrap: wrap;
            margin-bottom: 2rem;
        }
        
        .summary-card {
            flex: 0 1 280px;
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

        /* Modal Styles */
        .modal-content {
            border: none;
            border-radius: 1rem;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .modal-header {
            border-bottom: 1px solid var(--gray-200);
            padding: 1.5rem;
        }

        .modal-title {
            font-weight: 700;
            color: var(--dark);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            border-top: 1px solid var(--gray-200);
            padding: 1rem 1.5rem;
        }

        .form-label {
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
        }

        .form-control, .form-select {
            border: 1px solid var(--gray-300);
            border-radius: 0.5rem;
            padding: 0.625rem 0.875rem;
            transition: all 0.2s;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .alert {
            border-radius: 0.75rem;
            border: none;
            padding: 1rem 1.25rem;
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
        /* Add these styles to your existing stylesheet */
@keyframes highlight-update {
    0% { background-color: rgba(79, 70, 229, 0.1); }
    100% { background-color: transparent; }
}

.table-updated {
    animation: highlight-update 2s ease-out;
}

#lastUpdateTime {
    font-size: 0.875rem;
    vertical-align: middle;
    color: var(--gray-600);
}

#refreshStockBtn {
    transition: transform 0.3s ease;
}

#refreshStockBtn.refreshing {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
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
                    <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Navbar -->
            <div class="navbar">
                <div class="navbar-left">
                    <h2 class="navbar-title">Stok Barang</h2>
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
                            <?php echo strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)); ?>
                        </div>
                        <div class="user-info">
                            <span class="user-name"><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></span>
                            <span class="user-role"><?php echo htmlspecialchars($_SESSION['role'] ?? 'Admin'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Content Area -->
            <div class="content-area">
                <!-- Alert Messages -->
                <div id="alertContainer"></div>

                <div class="page-header">
                    <div class="header-left">
                        <h1 class="page-title">Manajemen Stok Barang</h1>
                        <p class="page-subtitle">Kelola data stok barang dengan mudah dan efisien</p>
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
                                        <h3 class="mb-0"><?php echo $total_rows; ?></h3>
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
                                        <h6 class="text-muted mb-1">Total Stok</h6>
                                        <h3 class="text-success mb-0"><?php echo number_format($total_stock, 0, ',', '.'); ?></h3>
                                    </div>
                                    <div class="bg-success bg-opacity-10 p-3 rounded-circle">
                                        <i class="fas fa-chart-pie text-success"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabel Daftar Barang -->
                <div class="card shadow-sm">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0">Daftar Stok Barang</h5>
                        <div class="d-flex gap-2">
                            <a href="?action=download_template" class="btn btn-outline-info btn-sm" title="Download Template Import">
                                <i class="fas fa-download me-1"></i> Template
                            </a>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#importModal">
                                <i class="fas fa-file-import me-1"></i> Import Excel
                            </button>
                            <form method="GET" class="d-inline">
                                <input type="hidden" name="action" value="export_excel">
                                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                                <input type="hidden" name="kategori" value="<?php echo htmlspecialchars($kategori_filter); ?>">
                                <button type="submit" class="btn btn-outline-success btn-sm" title="Export ke Excel">
                                    <i class="fas fa-file-excel me-1"></i> Export
                                </button>
                            </form>
                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addProductModal">
                                <i class="fas fa-plus me-1"></i> Tambah Barang
                            </button>
                        </div>
                    </div>

                    <div class="card-body">
                        <!-- Filter dan Pencarian -->
                        <div class="row mb-4">
                            <div class="col-md-8">
                                <form method="GET" class="d-flex gap-2">
                                    <div class="input-group">
                                        <span class="input-group-text bg-white"><i class="fas fa-search"></i></span>
                                        <input type="text" name="search" class="form-control" 
                                               placeholder="Cari barang (kode/nama)..." 
                                               value="<?php echo htmlspecialchars($search); ?>">
                                    </div>
                                    <select name="kategori" class="form-select" onchange="this.form.submit()">
                                        <option value="semua" <?= (empty($_GET['kategori']) || $_GET['kategori'] === 'semua') ? 'selected' : '' ?>>Semua Kategori</option>
                                        <?php 
                                        $kategori_query = "SELECT DISTINCT kategori FROM products WHERE kategori IS NOT NULL AND kategori != '' ORDER BY kategori";
                                        $kategori_result = $conn->query($kategori_query);
                                        while ($kategori = $kategori_result->fetch_assoc()): 
                                        ?>
                                            <option value="<?= htmlspecialchars($kategori['kategori']) ?>" 
                                                    <?= (isset($_GET['kategori']) && $_GET['kategori'] === $kategori['kategori']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($kategori['kategori']) ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                    <button type="submit" class="btn btn-primary">Filter</button>
                                    <?php if (!empty($search) || !empty($kategori_filter)): ?>
                                        <a href="stok_barang.php" class="btn btn-outline-secondary">Reset</a>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>

                        <?php if (!empty($search)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Menampilkan hasil pencarian untuk: <strong>"<?php echo htmlspecialchars($search); ?>"</strong>
                            (Menggunakan algoritma <strong>Fuzzy Matching</strong> dengan <strong>Levenshtein Distance</strong>)
                        </div>
                        <?php endif; ?>

                        <!-- Tabel -->
                        <div class="table-responsive">
                            <table class="table table-hover" id="stockTable">
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
                                                // Batas stok kritis tetap 1000 dan tidak bisa diubah
                                                $batas_kritis = 1000;
                                                if ($product['stok_akhir'] <= $batas_kritis) {
                                                    $status = 'Kritis';
                                                    $status_class = 'badge-danger';
                                                } elseif ($product['stok_akhir'] <= 2000) {
                                                    $status = 'Hati-hati';
                                                    $status_class = 'badge-warning';
                                                } else {
                                                    $status = 'Aman';
                                                    $status_class = 'badge-success';
                                                }
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($product['kode_produk']); ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="bg-light rounded d-flex align-items-center justify-content-center me-2" 
                                                             style="width: 40px; height: 40px;">
                                                            <i class="fas fa-box text-muted"></i>
                                                        </div>
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
                                                    <span class="badge <?php echo $status_class; ?>">
                                                        <?php echo $status; ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <div class="btn-group">
                                                        <?php if ($status === 'Kritis'): ?>
                                                            <button type="button"
                                                                    class="btn btn-sm btn-outline-primary"
                                                                    title="Tambah Stok"
                                                                    onclick="tambahStok(<?php echo (int)$product['id']; ?>, '<?php echo htmlspecialchars(addslashes($product['kode_produk'])); ?>', '<?php echo htmlspecialchars(addslashes($product['nama_produk'])); ?>', <?php echo (int)$product['stok_akhir']; ?>)">
                                                                <i class="fas fa-plus"></i>
                                                            </button>
                                                        <?php endif; ?>
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
    </div>

    <!-- Modal Import Excel -->
    <div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="importModalLabel">
                        <i class="fas fa-file-import me-2"></i>Import Data dari Excel
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="importForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_excel">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Petunjuk:</strong>
                            <ol class="mb-0 mt-2">
                                <li>Download template terlebih dahulu</li>
                                <li>Isi data sesuai format template</li>
                                <li>Upload file Excel/CSV Anda</li>
                            </ol>
                        </div>

                        <div class="mb-3">
                            <label for="excel_file" class="form-label">Pilih File Excel/CSV <span class="text-danger">*</span></label>
                            <input type="file" class="form-control" id="excel_file" name="excel_file" accept=".xls,.xlsx,.csv" required>
                            <small class="text-muted">Format: .xls, .xlsx, atau .csv (Max: 5MB)</small>
                        </div>

                        <div id="importProgress" class="progress d-none" style="height: 25px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                        </div>

                        <div id="importResult" class="mt-3"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary" id="importBtn">
                            <i class="fas fa-upload me-1"></i>Upload & Import
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Tambah Barang -->
    <div class="modal fade" id="addProductModal" tabindex="-1" aria-labelledby="addProductModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addProductModalLabel">
                        <i class="fas fa-plus-circle me-2"></i>Tambah Barang Baru
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="addProductForm" method="POST">
                    <input type="hidden" name="action" value="tambah_barang">
                    <div class="modal-body">
                        <div id="formAlert" class="alert d-none"></div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="kode_produk" class="form-label">Kode Produk <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="kode_produk" name="kode_produk" 
                                       value="<?php echo htmlspecialchars($auto_code); ?>" required readonly>
                                <small class="text-muted">Format: PRD-XXXX (auto-generated)</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="nama_produk" class="form-label">Nama Produk <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="nama_produk" name="nama_produk" required 
                                       pattern=".{3,}" title="Nama produk minimal 3 karakter">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="kategori" class="form-label">Kategori <span class="text-danger">*</span></label>
                                <select class="form-select" id="kategori" name="kategori" required>
                                    <option value="">Pilih Kategori</option>
                                    <?php foreach ($categories as $kategori): ?>
                                        <option value="<?php echo htmlspecialchars($kategori); ?>">
                                            <?php echo htmlspecialchars($kategori); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="other">+ Tambah Kategori Baru</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3" id="newCategoryGroup" style="display: none;">
                                <label for="new_kategori" class="form-label">Kategori Baru</label>
                                <input type="text" class="form-control" id="new_kategori" name="new_kategori">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="satuan" class="form-label">Satuan <span class="text-danger">*</span></label>
                                <select class="form-select" id="satuan" name="satuan" required>
                                    <option value="">Pilih Satuan</option>
                                    <option value="Pcs">Pcs (Pieces)</option>
                                    <option value="Box">Box</option>
                                    <option value="Kg">Kg (Kilogram)</option>
                                    <option value="Liter">Liter</option>
                                    <option value="Meter">Meter</option>
                                    <option value="Unit">Unit</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="harga_jual" class="form-label">Harga Jual <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">Rp</span>
                                    <input type="text" class="form-control" id="harga_jual" name="harga_jual" 
                                           pattern="[0-9.,]+" title="Masukkan angka yang valid" required>
                                </div>
                                <small class="text-muted">Contoh: 10000 atau 10.000</small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="stok_awal" class="form-label">Stok Awal <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="stok_awal" name="stok_awal" 
                                       min="0" value="0" required>
                                <small class="text-muted">Masukkan 0 jika tidak ada stok awal</small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="stok_minimal" class="form-label">Stok Minimal <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="stok_minimal" name="stok_minimal" 
                                       value="1000" readonly>
                                <small class="text-muted">Batas stok kritis (tidak bisa diubah)</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Simpan Produk
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Tambah Stok -->
    <div class="modal fade" id="tambahStokModal" tabindex="-1" aria-labelledby="tambahStokModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="tambahStokModalLabel">
                        <i class="fas fa-plus-circle me-2"></i>Tambah Stok Barang
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formTambahStok">
                    <input type="hidden" name="action" value="tambah_stok">
                    <input type="hidden" name="id" id="tambahStokId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <p class="mb-1">Kode: <strong id="tambahStokKode"></strong></p>
                            <p class="mb-3">Nama: <strong id="tambahStokNama"></strong></p>
                            <p class="mb-3">Stok Sekarang: <strong id="stokSekarang"></strong></p>
                            
                            <div class="mb-3">
                                <label for="jumlah_tambahan" class="form-label">Jumlah Tambahan <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="jumlah_tambahan" name="jumlah" 
                                       min="1" required>
                            </div>
                            <div class="mb-3">
                                <label for="keterangan" class="form-label">Keterangan</label>
                                <textarea class="form-control" id="keterangan" name="keterangan" rows="2" 
                                          placeholder="Contoh: Pembelian stok baru"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Simpan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Konfirmasi Hapus -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">
                        <i class="fas fa-exclamation-triangle text-danger me-2"></i>Konfirmasi Hapus
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Apakah Anda yakin ingin menghapus produk:</p>
                    <p class="fw-bold text-center fs-5" id="productName"></p>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <strong>Peringatan:</strong> Data yang dihapus tidak dapat dikembalikan!
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" id="confirmDeleteBtn" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>Ya, Hapus
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const importForm = document.getElementById('importForm');
        const importBtn = document.getElementById('importBtn');
        const importProgress = document.getElementById('importProgress');
        const importResult = document.getElementById('importResult');

        if (importForm) {
            importForm.addEventListener('submit', async function(e) {
                e.preventDefault();

                const formData = new FormData(this);
                const file = document.getElementById('excel_file').files[0];

                if (!file) {
                    showImportAlert('Silakan pilih file terlebih dahulu', 'danger');
                    return;
                }

                if (file.size > 5 * 1024 * 1024) {
                    showImportAlert('Ukuran file terlalu besar. Maksimal 5MB', 'danger');
                    return;
                }

                importBtn.disabled = true;
                importBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Importing...';
                importProgress.classList.remove('d-none');
                importProgress.querySelector('.progress-bar').style.width = '100%';
                importResult.innerHTML = '';

                try {
                    const response = await fetch('stok_barang.php', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'Accept': 'application/json'
                        }
                    });

                    const rawResponse = await response.text();
                    let result;
                    try {
                        result = JSON.parse(rawResponse);
                    } catch (parseError) {
                        console.error('Import response (raw):', rawResponse);
                        throw new Error('Respon server tidak valid. Pastikan sudah login dan coba lagi.');
                    }

                    if (result.success) {
                        let message = `<strong>Import Berhasil!</strong><br>✓ ${result.success_count} data berhasil diproses`;

                        if (result.error_count > 0) {
                            message += `<br>✗ ${result.error_count} data gagal`;
                            if (result.errors && result.errors.length > 0) {
                                message += `<br><br><strong>Detail Error:</strong><ul class="mb-0">`;
                                result.errors.slice(0, 5).forEach(err => {
                                    message += `<li>${err}</li>`;
                                });
                                if (result.errors.length > 5) {
                                    message += `<li>... dan ${result.errors.length - 5} error lainnya</li>`;
                                }
                                message += `</ul>`;
                            }
                        }

                        showImportAlert(message, result.error_count > 0 ? 'warning' : 'success');

                        setTimeout(() => {
                            const modal = bootstrap.Modal.getInstance(document.getElementById('importModal'));
                            if (modal) modal.hide();

                            if (typeof updateStockTable === 'function') {
                                updateStockTable();
                            } else {
                                window.location.reload();
                            }
                        }, 2000);
                    } else {
                        showImportAlert(result.message || 'Import gagal', 'danger');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    showImportAlert('Terjadi kesalahan saat import: ' + error.message, 'danger');
                } finally {
                    importBtn.disabled = false;
                    importBtn.innerHTML = '<i class="fas fa-upload me-1"></i>Upload & Import';
                    importProgress.classList.add('d-none');
                    importProgress.querySelector('.progress-bar').style.width = '0%';
                }
            });
        }

        function showImportAlert(message, type) {
            if (!importResult) return;
            importResult.innerHTML = `
                <div class="alert alert-${type} alert-dismissible fade show">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
        }

        // Toggle form kategori baru
        const kategoriSelect = document.getElementById('kategori');
        const newCategoryGroup = document.getElementById('newCategoryGroup');
        const newKategoriInput = document.getElementById('new_kategori');
        
        if (kategoriSelect && newCategoryGroup) {
            kategoriSelect.addEventListener('change', function() {
                if (this.value === 'other') {
                    newCategoryGroup.style.display = 'block';
                    newKategoriInput.required = true;
                } else {
                    newCategoryGroup.style.display = 'none';
                    newKategoriInput.required = false;
                }
            });
        }

        // Format input harga
        const hargaJualInput = document.getElementById('harga_jual');
        if (hargaJualInput) {
            hargaJualInput.addEventListener('input', function(e) {
                // Hapus semua karakter selain angka
                let value = this.value.replace(/[^\d]/g, '');
                
                // Format dengan titik sebagai pemisah ribuan
                if (value.length > 3) {
                    value = value.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                }
                
                this.value = value;
            });
        }

        // Handle form submission
        const addProductForm = document.getElementById('addProductForm');
        if (addProductForm) {
            addProductForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalBtnText = submitBtn.innerHTML;
                const formAlert = document.getElementById('formAlert');
                
                // Validasi form
                if (!formData.get('kategori') || formData.get('kategori') === '') {
                    showAlert('Silakan pilih atau buat kategori baru', 'danger', formAlert);
                    return;
                }
                
                if (formData.get('kategori') === 'other' && !formData.get('new_kategori')) {
                    showAlert('Silakan isi kategori baru', 'danger', formAlert);
                    return;
                }
                
                // Tampilkan loading state
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Menyimpan...';
                
                try {
                    // Jika memilih kategori baru, gunakan nilai dari input kategori baru
                    if (formData.get('kategori') === 'other') {
                        formData.set('kategori', formData.get('new_kategori'));
                    }
                    
                    // Format harga jual (hapus titik)
                    const hargaJual = formData.get('harga_jual').replace(/\./g, '');
                    formData.set('harga_jual', hargaJual);
                    
                    // Kirim data ke server
                    const response = await fetch('stok_barang.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        // Tampilkan pesan sukses
                        showAlert('Produk berhasil ditambahkan', 'success', formAlert);
                        
                        // Reset form
                        this.reset();
                        
                        // Tutup modal setelah 1,5 detik
                        setTimeout(() => {
                            const modal = bootstrap.Modal.getInstance(document.getElementById('addProductModal'));
                            if (modal) modal.hide();
                            
                            // Refresh tabel
                            if (typeof updateStockTable === 'function') {
                                updateStockTable();
                            } else {
                                window.location.reload();
                            }
                        }, 1500);
                    } else {
                        // Tampilkan pesan error
                        showAlert(result.message || 'Gagal menambahkan produk', 'danger', formAlert);
                    }
                } catch (error) {
                    console.error('Error:', error);
                    showAlert('Terjadi kesalahan saat mengirim data', 'danger', formAlert);
                } finally {
                    // Reset tombol submit
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnText;
                }
            });
        }
        
        // Fungsi untuk menampilkan alert
        function showAlert(message, type = 'success', container = null) {
            if (!container) {
                container = document.body;
                
                // Buat alert element jika belum ada
                let alertDiv = document.createElement('div');
                alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed top-0 end-0 m-3`;
                alertDiv.style.zIndex = '1100';
                alertDiv.role = 'alert';
                alertDiv.innerHTML = `
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                `;
                
                document.body.appendChild(alertDiv);
                
                // Hapus alert setelah 5 detik
                setTimeout(() => {
                    alertDiv.remove();
                }, 5000);
            } else {
                // Jika container disediakan, update alert di dalam container
                container.className = `alert alert-${type} alert-dismissible fade show`;
                container.innerHTML = `
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                `;
                container.style.display = 'block';
            }
        }
    });
    </script>
    <script>
// Auto-update functionality
let lastUpdateTime = 0;
let autoUpdateInterval;

function startAutoUpdate() {
    // Update immediately
    updateStockTable();
    
    // Then update every 30 seconds
    autoUpdateInterval = setInterval(updateStockTable, 30000);
    
    // Add refresh button
    const refreshButton = `
        <button id="refreshStockBtn" class="btn btn-sm btn-outline-secondary ms-2" title="Perbarui Sekarang">
            <i class="fas fa-sync-alt"></i>
        </button>
        <span id="lastUpdateTime" class="ms-2 small text-muted"></span>
    `;
    
    const headerTitle = document.querySelector('.card-header h5');
    if (headerTitle && !document.getElementById('refreshStockBtn')) {
        headerTitle.insertAdjacentHTML('beforeend', refreshButton);
        document.getElementById('refreshStockBtn').addEventListener('click', function() {
            const btn = this;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            updateStockTable().finally(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-sync-alt"></i>';
            });
        });
    }
}

async function updateStockTable() {
    try {
        const search = document.querySelector('input[name="search"]')?.value || '';
        const kategori = document.querySelector('select[name="kategori"]')?.value || 'semua';
        const page = <?php echo $page; ?>;
        
        const response = await fetch(`stok_barang.php?action=get_updated_stock&search=${encodeURIComponent(search)}&kategori=${encodeURIComponent(kategori)}&page=${page}&_=${Date.now()}`);
        const data = await response.json();
        
        if (data.success) {
            updateTableRows(data.products);
            lastUpdateTime = data.timestamp;
            updateLastUpdateTime();
        }
    } catch (error) {
        console.error('Error updating stock:', error);
    }
}

function updateTableRows(products) {
    console.log('Updating table rows with products:', products);
    const tbody = document.querySelector('#stockTable tbody');
    if (!tbody) {
        console.error('Table body not found');
        return;
    }

    // If no products, clear the table
    if (!products || products.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" class="text-center">Tidak ada data produk</td></tr>';
        return;
    }
    
    // Create a map of existing rows for quick lookup
    const existingRows = {};
    Array.from(tbody.rows).forEach(row => {
        const productId = row.dataset.productId;
        if (productId) {
            existingRows[productId] = row;
        }
    });
    
    // Clear the table if we're doing a full refresh
    if (Object.keys(existingRows).length === 0) {
        tbody.innerHTML = '';
    }
    
    // Update or add rows
    products.forEach(product => {
        if (existingRows[product.id]) {
            // Update existing row
            updateRow(existingRows[product.id], product);
            delete existingRows[product.id]; // Remove from map to track which rows were not updated
        } else {
            // Add new row
            const newRow = document.createElement('tr');
            newRow.dataset.productId = product.id;
            newRow.className = 'table-updated';
            updateRow(newRow, product);
            tbody.prepend(newRow); // Add new rows at the top
            
            // Add highlight animation
            newRow.classList.add('highlight-update');
            setTimeout(() => {
                newRow.classList.remove('highlight-update');
            }, 3000);
        }
    });
    
    // Remove rows that no longer exist
    Object.values(existingRows).forEach(row => {
        row.remove();
    });
    
    // Update last update time
    updateLastUpdateTime();
}

function updateRow(row, product) {
    console.log('Updating row for product:', product);
    
    // Escape special characters in product name for HTML
    const escapeHtml = (text) => {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    };
    
    const escapeForAttr = (text) => String(text).replace(/'/g, "\\'");
    
    const productName = escapeHtml(product.nama_produk);
    const satuan = escapeHtml(product.satuan || '-');
    const kategori = escapeHtml(product.kategori || '-');
    const stokAkhir = parseInt(product.stok_akhir, 10) || 0;
    const hargaJual = parseFloat(product.harga_jual) || 0;
    const totalNilai = stokAkhir * hargaJual;
    
    let status = 'Aman';
    let statusClass = 'badge badge-success';
    if (stokAkhir <= 1000) {
        status = 'Kritis';
        statusClass = 'badge badge-danger';
    } else if (stokAkhir <= 2000) {
        status = 'Hati-hati';
        statusClass = 'badge badge-warning';
    }
    
    row.innerHTML = `
        <td>${escapeHtml(product.kode_produk)}</td>
        <td>
            <div class="d-flex align-items-center">
                <div class="bg-light rounded d-flex align-items-center justify-content-center me-2" style="width: 40px; height: 40px;">
                    <i class="fas fa-box text-muted"></i>
                </div>
                <div>
                    <div class="fw-medium">${productName}</div>
                    <small class="text-muted">${satuan}</small>
                </div>
            </div>
        </td>
        <td>${kategori}</td>
        <td class="text-end">${formatNumber(stokAkhir)}</td>
        <td class="text-end">${formatRupiah(hargaJual)}</td>
        <td class="text-end fw-bold">${formatRupiah(totalNilai)}</td>
        <td class="text-center"><span class="${statusClass}">${status}</span></td>
        <td class="text-center">
            <div class="btn-group">
                ${status === 'Kritis' ? `
                    <button type="button"
                        class="btn btn-sm btn-outline-primary"
                        title="Tambah Stok"
                        onclick="tambahStok(${product.id}, '${escapeForAttr(product.kode_produk)}', '${escapeForAttr(product.nama_produk)}', ${stokAkhir})">
                        <i class="fas fa-plus"></i>
                    </button>
                ` : ''}
                <button type="button"
                    class="btn btn-sm btn-outline-danger"
                    title="Hapus"
                    onclick="confirmDelete(${product.id}, '${escapeForAttr(product.nama_produk)}')">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </td>
    `;
    
    // Add event listeners for the new buttons
    const addStockBtn = row.querySelector('.btn-tambah-stok');
    if (addStockBtn) {
        addStockBtn.addEventListener('click', (e) => {
            e.preventDefault();
            tambahStok(product.id, product.kode_produk, product.nama_produk, product.stok_akhir);
        });
    }
    
    const deleteBtn = row.querySelector('.btn-danger');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', (e) => {
            e.preventDefault();
            confirmDelete(product.id, product.nama_produk);
        });
    }
}

function updateLastUpdateTime() {
    const now = new Date();
    const timeString = now.toLocaleTimeString('id-ID', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });
    
    const updateElement = document.getElementById('lastUpdateTime');
    if (updateElement) {
        updateElement.textContent = `Diperbarui: ${timeString}`;
    }
}

// Helper functions
function formatNumber(num) {
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
}

function formatRupiah(amount) {
    return `Rp ${formatNumber(amount)}`;
}

// Handle Tambah Barang form submission
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM fully loaded');
    
    // Start auto-update
    startAutoUpdate();
    
    // Add event listeners for manual refresh
    const refreshBtn = document.getElementById('refreshBtn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function() {
            console.log('Manual refresh triggered');
            updateStockTable();
        });
    }
    
    const addProductForm = document.getElementById('addProductForm');
    const kategoriSelect = document.getElementById('kategori');
    const newCategoryGroup = document.getElementById('newCategoryGroup');
    const newKategoriInput = document.getElementById('new_kategori');

    // Toggle new category input
    if (kategoriSelect && newCategoryGroup) {
        kategoriSelect.addEventListener('change', function() {
            newCategoryGroup.style.display = this.value === 'other' ? 'block' : 'none';
            if (this.value !== 'other') {
                newKategoriInput.required = false;
            } else {
                newKategoriInput.required = true;
            }
        });
    }

    // Form submission
    if (addProductForm) {
        addProductForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;
            
            // Show loading state
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Menyimpan...';
            
            // Log form data for debugging
            const formDataObj = {};
            formData.forEach((value, key) => {
                formDataObj[key] = value;
            });
            console.log('Form data:', formDataObj);
            
            try {
                // If new category is selected, use that value
                if (kategoriSelect && kategoriSelect.value === 'other' && newKategoriInput) {
                    formData.set('kategori', newKategoriInput.value.trim());
                }
                
                // Add action parameter
                formData.append('action', 'tambah_barang');
                
                const response = await fetch('stok_barang.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                
                console.log('Response status:', response.status);
                const responseText = await response.text();
                console.log('Raw response:', responseText);
                
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (e) {
                    console.error('Error parsing JSON response:', e);
                    throw new Error('Invalid response from server');
                }
                
                if (result.success) {
                    console.log('Product added successfully:', result);
                    
                    // Show success message
                    showAlert(result.message, 'success');
                    
                    // Close the modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('addProductModal'));
                    if (modal) {
                        modal.hide();
                    }
                    
                    // Reset form
                    if (addProductForm) {
                        addProductForm.reset();
                    }
                    
                    // Update the table with the new product
                    try {
                        // If we have auto-update enabled, it will handle the refresh
                        if (typeof updateStockTable === 'function') {
                            console.log('Refreshing stock table...');
                            updateStockTable();
                        } else {
                            console.log('updateStockTable function not found, reloading page...');
                            window.location.reload();
                        }
                    } catch (e) {
                        console.error('Error updating table:', e);
                        window.location.reload();
                    }
                } else {
                    // Show error message
                    showAlert(result.message || 'Gagal menambahkan produk', 'danger');
                }
            } catch (error) {
                console.error('Error:', error);
                showAlert('Terjadi kesalahan: ' + error.message, 'danger');
            } finally {
                // Reset button state
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
            }
        });
    }
});

// Show alert function
function showAlert(message, type = 'success') {
    const alertContainer = document.getElementById('alertContainer');
    if (!alertContainer) return;
    
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.role = 'alert';
    alertDiv.innerHTML = `
        <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'} me-2"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;
    
    alertContainer.appendChild(alertDiv);
    
    // Auto-remove alert after 5 seconds
    setTimeout(() => {
        alertDiv.classList.remove('show');
        setTimeout(() => alertDiv.remove(), 150);
    }, 5000);
}

// Function to show the tambah stok modal
function tambahStok(id, kode, nama, stok) {
    document.getElementById('tambahStokId').value = id;
    document.getElementById('tambahStokKode').textContent = kode;
    document.getElementById('tambahStokNama').textContent = nama;
    document.getElementById('stokSekarang').textContent = stok;
    document.getElementById('jumlah_tambahan').value = '';
    
    // Show the modal
    const modal = new bootstrap.Modal(document.getElementById('tambahStokModal'));
    modal.show();
}

// Function to handle delete confirmation with better user feedback
function confirmDelete(id, name) {
    // Update the modal content
    document.getElementById('productName').textContent = name;
    
    // Get the modal and confirm button
    const modalElement = document.getElementById('deleteModal');
    const confirmBtn = document.getElementById('confirmDeleteBtn');
    
    // Store the original button text for restoring later
    const originalBtnText = confirmBtn.innerHTML;
    
    // Remove any existing event listeners
    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
    
    // Add new click event to the confirm button
    newConfirmBtn.onclick = async function() {
        try {
            // Show loading state
            newConfirmBtn.disabled = true;
            newConfirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Menghapus...';
            
            // Send delete request
            const response = await fetch('stok_barang.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=delete&id=${id}`
            });
            
            const result = await response.json();
            
            if (result.success) {
                showAlert('Produk berhasil dihapus', 'success');
                
                // Close the modal
                const modal = bootstrap.Modal.getInstance(modalElement);
                if (modal) modal.hide();
                
                // Update the table if updateStockTable function exists, otherwise reload the page
                if (typeof updateStockTable === 'function') {
                    updateStockTable();
                } else {
                    window.location.reload();
                }
            } else {
                throw new Error(result.message || 'Gagal menghapus produk');
            }
        } catch (error) {
            console.error('Error:', error);
            showAlert(error.message || 'Terjadi kesalahan saat menghapus produk', 'danger');
        } finally {
            // Restore the button state
            newConfirmBtn.disabled = false;
            newConfirmBtn.innerHTML = originalBtnText;
        }
    };
    
    // Show the modal
    const modal = new bootstrap.Modal(modalElement);
    modal.show();
    
    // Handle modal hidden event to clean up
    modalElement.addEventListener('hidden.bs.modal', function() {
        // Restore the button state when modal is closed
        const currentBtn = document.getElementById('confirmDeleteBtn');
        if (currentBtn) {
            currentBtn.disabled = false;
            currentBtn.innerHTML = 'Hapus';
        }
    }, { once: true });
}

function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// Start auto-update when the page loads
document.addEventListener('DOMContentLoaded', function() {
    startAutoUpdate();
    
    // Handle form tambah stok
    const formTambahStok = document.getElementById('formTambahStok');
    if (formTambahStok) {
        formTambahStok.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;
            
            try {
                // Show loading state
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Menyimpan...';
                
                // Add action parameter
                formData.append('action', 'tambah_stok');
                
                const response = await fetch('stok_barang.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert('Stok berhasil ditambahkan', 'success');
                    
                    // Close the modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('tambahStokModal'));
                    if (modal) {
                        modal.hide();
                    }
                    
                    // Reset form
                    this.reset();
                    
                    // Update the table
                    if (typeof updateStockTable === 'function') {
                        updateStockTable();
                    } else {
                        window.location.reload();
                    }
                } else {
                    throw new Error(result.message || 'Gagal menambahkan stok');
                }
            } catch (error) {
                console.error('Error:', error);
                showAlert(error.message || 'Terjadi kesalahan saat menambahkan stok', 'danger');
            } finally {
                // Reset button state
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
            }
        });
    }
});

// Function to handle delete product
async function deleteProduct(id) {
    try {
        const response = await fetch('stok_barang.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=delete&id=${id}`
        });
        
        const result = await response.json();
        
        if (result.success) {
            showAlert('Produk berhasil dihapus', 'success');
            // Close the modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('deleteModal'));
            if (modal) modal.hide();
            // Refresh the table
            if (typeof updateStockTable === 'function') {
                updateStockTable();
            } else {
                window.location.reload();
            }
        } else {
            throw new Error(result.message || 'Gagal menghapus produk');
        }
    } catch (error) {
        console.error('Error:', error);
        showAlert(error.message || 'Terjadi kesalahan saat menghapus produk', 'danger');
    }
}

// Function to show delete confirmation
function confirmDelete(id, name) {
    document.getElementById('productName').textContent = name;
    const confirmBtn = document.getElementById('confirmDeleteBtn');
    
    // Remove previous event listeners to prevent multiple bindings
    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
    
    // Store original button text
    const originalBtnText = newConfirmBtn.innerHTML;
    
    // Add new event listener
    newConfirmBtn.onclick = async function() {
        try {
            // Show loading state
            newConfirmBtn.disabled = true;
            newConfirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Menghapus...';
            
            // Call deleteProduct and wait for it to complete
            await deleteProduct(id);
            
            // Hide the modal on success
            const modal = bootstrap.Modal.getInstance(document.getElementById('deleteModal'));
            if (modal) modal.hide();
            
        } catch (error) {
            console.error('Error:', error);
            showAlert(error.message || 'Terjadi kesalahan saat menghapus produk', 'danger');
        } finally {
            // Reset button state
            newConfirmBtn.disabled = false;
            newConfirmBtn.innerHTML = originalBtnText;
        }
    };
    
    // Show the modal
    const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    modal.show();
}

// Function to handle product deletion
async function deleteProduct(id) {
    try {
        const response = await fetch('stok_barang.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=delete&id=${id}`
        });
        
        const result = await response.json();
        
        if (result.success) {
            showAlert('Data berhasil dihapus', 'success');
            
            // Update the table if updateStockTable function exists, otherwise reload the page
            if (typeof updateStockTable === 'function') {
                updateStockTable();
            } else {
                window.location.reload();
            }
        } else {
            throw new Error(result.message || 'Gagal menghapus produk');
        }
    } catch (error) {
        console.error('Error:', error);
        throw error; // Re-throw the error to be caught by the caller
    }
}

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
        const response = await fetch('stok_barang.php', {
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