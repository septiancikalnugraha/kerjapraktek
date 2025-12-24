<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (ob_get_level() === 0) {
    ob_start();
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'kerjapraktek_db');

define('OWNER_EMAIL', 'owner@cvpancaindrakemasan.com');
define('OWNER_PASSWORD_HASH', '$2y$10$.2u00pGb9jn1HxnKO7ZnwOFGG3XfXfSFc2yWQVBmuB.0uJynfTjia');

// Create connection
function getConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    if (!$conn->set_charset('utf8mb4')) {
        die("Error setting charset: " . $conn->error);
    }
    
    return $conn;
}

function ensureUsersTable() {
    $conn = getConnection();
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        first_name VARCHAR(100) NULL,
        last_name VARCHAR(100) NULL,
        email VARCHAR(255) NOT NULL UNIQUE,
        company VARCHAR(255) NULL,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('owner','staff') NOT NULL DEFAULT 'staff',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($sql);
    $conn->close();
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Redirect if not logged in
function requireLogin() {
    if (isLoggedIn()) {
        return;
    }

    $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
    $isJsonExpected = stripos($acceptHeader, 'application/json') !== false;
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

    if ($isJsonExpected || $isAjax || $isPost) {
        if (ob_get_length()) {
            ob_clean();
        }

        header('Content-Type: application/json; charset=utf-8', true, 401);
        echo json_encode([
            'success' => false,
            'message' => 'Sesi Anda telah berakhir. Silakan login kembali.'
        ]);

        exit();
    }

    header('Location: login.php');
    exit();
}

// config/config.php
// ... kode sebelumnya ...

function includeStyles() {
    ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/project_kerjapraktek/assets/style.css">
    <?php
}

function includeScripts() {
    ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="/project_kerjapraktek/assets/main.js"></script>
    <?php
}

?>