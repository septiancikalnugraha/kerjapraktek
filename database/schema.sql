-- Create database if not exists
CREATE DATABASE IF NOT EXISTS `kerjapraktek_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

USE `kerjapraktek_db`;

-- Table structure for table `konsumen`
CREATE TABLE IF NOT EXISTS `konsumen` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_konsumen` varchar(100) NOT NULL,
  `perusahaan` varchar(100) DEFAULT NULL,
  `alamat` text DEFAULT NULL,
  `no_hp` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `orders`
CREATE TABLE IF NOT EXISTS `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `no_order` varchar(50) NOT NULL,
  `konsumen_id` int(11) NOT NULL,
  `tanggal_order` date NOT NULL,
  `deadline` date DEFAULT NULL,
  `total_harga` decimal(15,2) DEFAULT 0.00,
  `status` enum('pending','processing','completed','cancelled') NOT NULL DEFAULT 'pending',
  `keterangan` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `no_order` (`no_order`),
  KEY `konsumen_id` (`konsumen_id`),
  CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`konsumen_id`) REFERENCES `konsumen` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `products`
CREATE TABLE IF NOT EXISTS `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kode_produk` varchar(50) NOT NULL,
  `nama_produk` varchar(255) NOT NULL,
  `kategori` varchar(100) DEFAULT NULL,
  `satuan` varchar(20) DEFAULT 'pcs',
  `harga_beli` decimal(15,2) DEFAULT 0.00,
  `harga_jual` decimal(15,2) DEFAULT 0.00,
  `stok_minimal` int(11) DEFAULT 10,
  `keterangan` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `kode_produk` (`kode_produk`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `stok`
CREATE TABLE IF NOT EXISTS `stok` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `produk_id` int(11) NOT NULL,
  `stok_awal` int(11) NOT NULL DEFAULT 0,
  `stok_masuk` int(11) DEFAULT 0,
  `stok_keluar` int(11) DEFAULT 0,
  `stok_akhir` int(11) DEFAULT 0,
  `keterangan` text DEFAULT NULL,
  `tanggal_update` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `produk_id` (`produk_id`),
  CONSTRAINT `stok_ibfk_1` FOREIGN KEY (`produk_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `order_items`
CREATE TABLE IF NOT EXISTS `order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `produk_id` int(11) DEFAULT NULL,
  `nama_item` varchar(255) NOT NULL,
  `jumlah` int(11) NOT NULL DEFAULT 1,
  `harga_satuan` decimal(15,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `keterangan` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `produk_id` (`produk_id`),
  CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`produk_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for table `activities`
CREATE TABLE IF NOT EXISTS `activities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `activity_type` enum('login','order','stok','pengguna','laporan') NOT NULL,
  `description` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `activities_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default admin user if not exists
INSERT IGNORE INTO `users` (`first_name`, `last_name`, `email`, `password_hash`, `role`) 
VALUES ('Admin', 'Sistem', 'admin@cvpancaindrakemasan.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'owner');

-- Create a view for critical stock
CREATE OR REPLACE VIEW `v_stok_kritis` AS
SELECT p.*, s.stok_akhir
FROM products p
JOIN (
    SELECT produk_id, stok_akhir
    FROM stok
    WHERE (produk_id, created_at) IN (
        SELECT produk_id, MAX(created_at)
        FROM stok
        GROUP BY produk_id
    )
) s ON p.id = s.produk_id
WHERE s.stok_akhir <= p.stok_minimal;

-- Create a view for monthly sales
CREATE OR REPLACE VIEW `v_penjualan_bulanan` AS
SELECT 
    DATE_FORMAT(o.tanggal_order, '%Y-%m') AS bulan,
    COUNT(DISTINCT o.id) AS total_order,
    SUM(oi.subtotal) AS total_penjualan
FROM orders o
JOIN order_items oi ON o.id = oi.order_id
WHERE o.status = 'completed'
GROUP BY DATE_FORMAT(o.tanggal_order, '%Y-%m')
ORDER BY bulan DESC;
