<?php
// database_setup.php
require_once __DIR__ . '/config/config.php';

$conn = getConnection();

// Drop existing tables in the correct order to avoid foreign key constraint errors
$dropQueries = [
    "SET FOREIGN_KEY_CHECKS = 0;",
    "DROP TABLE IF EXISTS transaksi_detail;",
    "DROP TABLE IF EXISTS transaksi;",
    "DROP TABLE IF EXISTS barang;",
    "DROP TABLE IF EXISTS kategori_barang;",
    "DROP TABLE IF EXISTS user_logins;",
    "DROP TABLE IF EXISTS activity_log;",
    "DROP TABLE IF EXISTS users;",
    "DROP TABLE IF EXISTS pelanggan;"
];

// Execute drop queries
foreach ($dropQueries as $query) {
    if ($conn->query($query) === TRUE) {
        echo "Drop query executed successfully<br>";
    } else {
        echo "Error dropping tables: " . $conn->error . "<br>";
    }
}

// Re-enable foreign key checks
$conn->query("SET FOREIGN_KEY_CHECKS = 1;");

// Create tables with proper structure
$queries = [
    "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        nama_lengkap VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL UNIQUE,
        role ENUM('admin', 'user') DEFAULT 'user',
        last_login DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "CREATE TABLE IF NOT EXISTS pelanggan (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kode_pelanggan VARCHAR(20) NOT NULL UNIQUE,
        nama VARCHAR(100) NOT NULL,
        perusahaan VARCHAR(100),
        alamat TEXT,
        telepon VARCHAR(20),
        email VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "CREATE TABLE IF NOT EXISTS kategori_barang (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama_kategori VARCHAR(50) NOT NULL,
        deskripsi TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "CREATE TABLE IF NOT EXISTS barang (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kode_barang VARCHAR(20) NOT NULL UNIQUE,
        nama_barang VARCHAR(100) NOT NULL,
        kategori_id INT,
        satuan VARCHAR(20),
        harga_beli DECIMAL(15,2) NOT NULL,
        harga_jual DECIMAL(15,2) NOT NULL,
        stok INT NOT NULL DEFAULT 0,
        deskripsi TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (kategori_id) REFERENCES kategori_barang(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "CREATE TABLE IF NOT EXISTS transaksi (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kode_transaksi VARCHAR(20) NOT NULL UNIQUE,
        pelanggan_id INT,
        tgl_transaksi DATETIME NOT NULL,
        total DECIMAL(15,2) NOT NULL,
        status ENUM('pending', 'diproses', 'selesai', 'dibatalkan') DEFAULT 'pending',
        catatan TEXT,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (pelanggan_id) REFERENCES pelanggan(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "CREATE TABLE IF NOT EXISTS transaksi_detail (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transaksi_id INT NOT NULL,
        barang_id INT NOT NULL,
        jumlah INT NOT NULL,
        harga_satuan DECIMAL(15,2) NOT NULL,
        subtotal DECIMAL(15,2) NOT NULL,
        FOREIGN KEY (transaksi_id) REFERENCES transaksi(id) ON DELETE CASCADE,
        FOREIGN KEY (barang_id) REFERENCES barang(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "CREATE TABLE IF NOT EXISTS activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        action VARCHAR(100) NOT NULL,
        description TEXT,
        ip_address VARCHAR(45),
        user_agent TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "CREATE TABLE IF NOT EXISTS user_logins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        login_time DATETIME NOT NULL,
        ip_address VARCHAR(45),
        user_agent TEXT,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
];

// Execute each query
foreach ($queries as $query) {
    if ($conn->query($query) === TRUE) {
        echo "Query executed successfully<br>";
    } else {
        echo "Error creating table: " . $conn->error . "<br>";
    }
}

// Create default admin user
$default_password = password_hash('admin123', PASSWORD_DEFAULT);
$query = "
    INSERT INTO users (username, password, nama_lengkap, email, role) 
    SELECT * FROM (SELECT 'admin' as username, 
                         '$default_password' as password, 
                         'Administrator' as nama_lengkap, 
                         'admin@example.com' as email, 
                         'admin' as role) AS tmp
    WHERE NOT EXISTS (
        SELECT username FROM users WHERE username = 'admin' OR email = 'admin@example.com'
    ) LIMIT 1;";

if ($conn->query($query) === TRUE) {
    if ($conn->affected_rows > 0) {
        echo "Admin user created successfully<br>";
    } else {
        echo "Admin user already exists<br>";
    }
} else {
    echo "Error creating admin user: " . $conn->error . "<br>";
}

echo "<h3>Database setup completed successfully!</h3>";
echo "<p>You can now <a href='login.php'>login</a> with:</p>";
echo "<p>Username: <strong>admin</strong><br>Password: <strong>admin123</strong></p>";
$conn->close();
?>