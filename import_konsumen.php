<?php
// Disable HTML error output
ini_set('display_errors', 0);
// Enable error reporting
error_reporting(E_ALL);

// Set output buffering to prevent any unwanted output
ob_start();

// Naikkan batas waktu eksekusi dan memori agar impor file besar tidak gagal di tengah jalan
@set_time_limit(300);
@ini_set('memory_limit', '512M');

// Set content type to JSON first thing
header('Content-Type: application/json');

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    // Ensure no output has been sent
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonError($message, $code = 400) {
    jsonResponse([
        'success' => false,
        'message' => $message
    ], $code);
}

// Tangani error PHP yang tidak tertangkap agar tetap mengirim JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Error yang disilent dengan @ akan di-skip
    if (!(error_reporting() & $errno)) {
        return false;
    }
    jsonError('Error PHP: ' . $errstr . ' di ' . basename($errfile) . ':' . $errline, 500);
});

// Tangani error fatal pada saat shutdown
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(500);
        }
        echo json_encode([
            'success' => false,
            'message' => 'Error fatal: ' . $error['message'] . ' di ' . basename($error['file']) . ':' . $error['line']
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
});

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Invalid request method', 405);
}

// Check if file was uploaded
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    jsonError('Tidak ada file yang diunggah atau terjadi kesalahan saat mengunggah');
}

// Verify file type
$fileType = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
$allowedTypes = ['xlsx', 'xls', 'csv'];
if (!in_array($fileType, $allowedTypes)) {
    jsonError('Format file tidak didukung. Harap unggah file Excel (.xlsx, .xls) atau CSV');
}

try {
    // Include required files
    require_once __DIR__ . '/config/config.php';
    
    // Check if vendor/autoload.php exists
    $autoloadPath = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new Exception('Vendor autoloader not found. Please run "composer install"');
    }
    require_once $autoloadPath;
    
    // Check if required classes exist
    if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
        throw new Exception('PhpSpreadsheet library not found. Please install with "composer require phpoffice/phpspreadsheet"');
    }

    // Get database connection
    $conn = getConnection();
    if ($conn->connect_error) {
        throw new Exception('Database connection failed: ' . $conn->connect_error);
    }

    // Load the uploaded file
    $spreadsheet = PhpOffice\PhpSpreadsheet\IOFactory::load($_FILES['file']['tmp_name']);
    $worksheet = $spreadsheet->getActiveSheet();
    $rows = $worksheet->toArray();

    // Remove header row
    $header = array_shift($rows);

    // Validate header
    $expectedHeader = ['Nama Konsumen', 'Perusahaan', 'Alamat', 'No. HP', 'Email'];
    if ($header !== $expectedHeader) {
        throw new Exception('Format file tidak valid. Harap gunakan template yang disediakan.');
    }

    // Start transaction
    $conn->begin_transaction();

    $successCount = 0;
    $errorMessages = [];

    foreach ($rows as $index => $row) {
        $nama_konsumen = trim($row[0] ?? '');
        $perusahaan = trim($row[1] ?? '');
        $alamat = trim($row[2] ?? '');
        $no_hp = trim($row[3] ?? '');
        $email = trim($row[4] ?? '');

        // Skip empty rows
        if (empty($nama_konsumen)) {
            continue;
        }

        try {
            // Check if customer already exists
            $stmt = $conn->prepare("SELECT id FROM konsumen WHERE nama_konsumen = ?");
            if ($stmt === false) {
                throw new Exception('Failed to prepare statement: ' . $conn->error);
            }
            
            $stmt->bind_param('s', $nama_konsumen);
            if (!$stmt->execute()) {
                throw new Exception('Database error: ' . $stmt->error);
            }
            
            $result = $stmt->get_result();
            $stmt->close();

            if ($result->num_rows > 0) {
                // Update existing customer
                $customer = $result->fetch_assoc();
                $stmt = $conn->prepare("
                    UPDATE konsumen 
                    SET perusahaan = ?, alamat = ?, no_hp = ?, email = ?
                    WHERE id = ?
                ");
                if ($stmt === false) {
                    throw new Exception('Failed to prepare update statement: ' . $conn->error);
                }
                $stmt->bind_param('ssssi', 
                    $perusahaan, 
                    $alamat, 
                    $no_hp, 
                    $email,
                    $customer['id']
                );
            } else {
                // Insert new customer
                $stmt = $conn->prepare("
                    INSERT INTO konsumen (nama_konsumen, perusahaan, alamat, no_hp, email)
                    VALUES (?, ?, ?, ?, ?)
                ");
                if ($stmt === false) {
                    throw new Exception('Failed to prepare insert statement: ' . $conn->error);
                }
                $stmt->bind_param('sssss', 
                    $nama_konsumen, 
                    $perusahaan, 
                    $alamat, 
                    $no_hp, 
                    $email
                );
            }

            if (!$stmt->execute()) {
                throw new Exception('Failed to execute statement: ' . $stmt->error);
            }

            $stmt->close();

            $successCount++;
        } catch (Exception $e) {
            $errorMessages[] = "Baris " . ($index + 2) . ": " . $e->getMessage();
        }
    }

    // Commit transaction
    $conn->commit();
    $conn->close();

    jsonResponse([
        'success' => true,
        'message' => "Berhasil mengimpor $successCount data konsumen" . 
                    (count($errorMessages) > 0 ? 
                     "\nBeberapa data gagal diimpor: " . implode(', ', $errorMessages) : ''),
        'imported' => $successCount,
        'failed' => count($errorMessages)
    ]);

} catch (Exception $e) {
    // Ensure any open connections are closed
    if (isset($conn) && $conn instanceof mysqli) {
        if ($conn->ping()) {
            $conn->rollback();
        }
        $conn->close();
    }
    // Log the error (you might want to log this to a file)
    error_log('Import Error: ' . $e->getMessage());
    // Return a clean error message
    jsonError('Terjadi kesalahan saat memproses file: ' . $e->getMessage());
}
