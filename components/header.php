// components/header.php
<nav class="navbar">
    <div class="navbar-left">
        <button class="menu-toggle" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <a href="dashboard_clean.php" class="brand">
            <div class="brand-logo">
                <i class="fas fa-boxes"></i>
            </div>
            <div class="brand-text">
                CV. Panca Indra
                <span class="brand-subtext">Manajemen Stok</span>
            </div>
        </a>
    </div>
    <div class="navbar-right">
        <button class="notification-bell">
            <i class="far fa-bell"></i>
            <span class="notification-badge">3</span>
        </button>
        <div class="user-avatar" title="<?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?>">
            <?php 
                $name = $_SESSION['name'] ?? 'Admin';
                echo strtoupper(substr($name, 0, 2)); 
            ?>
        </div>
    </div>
</nav>