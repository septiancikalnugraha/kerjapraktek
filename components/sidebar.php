// components/sidebar.php
<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<aside class="sidebar" id="sidebar">
    <nav class="sidebar-menu">
        <a href="dashboard_clean.php" class="menu-item <?= $current_page === 'dashboard_clean.php' ? 'active' : '' ?>">
            <i class="fas fa-tachometer-alt"></i>
            <span>Dashboard</span>
        </a>
        <a href="stok_barang.php" class="menu-item <?= $current_page === 'stok_barang.php' ? 'active' : '' ?>">
            <i class="fas fa-boxes"></i>
            <span>Stok Barang</span>
        </a>
        <a href="barang_masuk.php" class="menu-item <?= $current_page === 'barang_masuk.php' ? 'active' : '' ?>">
            <i class="fas fa-arrow-down"></i>
            <span>Barang Masuk</span>
        </a>
        <a href="barang_keluar.php" class="menu-item <?= $current_page === 'barang_keluar.php' ? 'active' : '' ?>">
            <i class="fas fa-arrow-up"></i>
            <span>Barang Keluar</span>
        </a>
        <a href="stok_kritis.php" class="menu-item <?= $current_page === 'stok_kritis.php' ? 'active' : '' ?>">
            <i class="fas fa-exclamation-triangle"></i>
            <span>Stok Kritis</span>
        </a>
        
        <div class="menu-divider"></div>
        
        <a href="pengaturan.php" class="menu-item <?= $current_page === 'pengaturan.php' ? 'active' : '' ?>">
            <i class="fas fa-cog"></i>
            <span>Pengaturan</span>
        </a>
        <a href="logout.php" class="menu-item">
            <i class="fas fa-sign-out-alt"></i>
            <span>Keluar</span>
        </a>
    </nav>
</aside>