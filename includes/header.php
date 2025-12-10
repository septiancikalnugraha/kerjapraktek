<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>CV. Panca Indra Kemasan</title>
    
    <!-- Favicon -->
    <link rel="shortcut icon" href="assets/img/favicon.ico" type="image/x-icon">
    
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- DataTables CSS -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/buttons/2.2.2/css/buttons.bootstrap4.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/responsive/2.2.9/css/responsive.bootstrap4.min.css">
    
    <!-- Custom CSS -->
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

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--gray-100);
            color: var(--gray-700);
            line-height: 1.5;
        }

        .navbar {
            background: white;
            box-shadow: var(--shadow);
        }

        .sidebar {
            min-height: 100vh;
            background: white;
            box-shadow: var(--shadow-md);
        }

        .sidebar .nav-link {
            color: var(--gray-700);
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            margin: 0.25rem 1rem;
        }

        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            background-color: var(--primary-light);
            color: white;
        }

        .sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
            text-align: center;
        }

        .card {
            border: none;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            border-radius: 0.5rem;
        }

        .card-header {
            background: white;
            border-bottom: 1px solid var(--gray-200);
            padding: 1.25rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .card-title {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-800);
        }

        .table th {
            border-top: none;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--gray-500);
            border-bottom-width: 1px;
        }

        .badge {
            font-weight: 500;
            padding: 0.35em 0.65em;
            font-size: 0.75em;
        }

        .badge-warning {
            background-color: #fef3c7;
            color: #9a3412;
        }

        .badge-success {
            background-color: #dcfce7;
            color: #166534;
        }

        .btn-icon {
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }

        @media (max-width: 991.98px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: -280px;
                z-index: 1050;
                transition: all 0.3s;
            }

            .sidebar.show {
                left: 0;
            }

            .main-content {
                margin-left: 0;
            }

            .navbar-toggler {
                display: block;
            }
        }
    </style>
</head>
<body>
    <div class="d-flex">
        <!-- Sidebar -->
        <div class="sidebar d-flex flex-column flex-shrink-0 p-3 bg-white" style="width: 280px;">
            <a href="dashboard_clean.php" class="d-flex align-items-center mb-3 mb-md-0 me-md-auto text-decoration-none">
                <span class="fs-4">CV. Panca Indra Kemasan</span>
            </a>
            <hr>
            <ul class="nav nav-pills flex-column mb-auto">
                <li class="nav-item">
                    <a href="dashboard_clean.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard_clean.php' ? 'active' : ''; ?>">
                        <i class="fas fa-tachometer-alt"></i>
                        Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="analytics.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'analytics.php' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-bar"></i>
                        Analytics
                    </a>
                </li>
                <li class="nav-item">
                    <a href="laporan_bulanan.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'laporan_bulanan.php' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-alt"></i>
                        Laporan Bulanan
                    </a>
                </li>
                <li class="nav-item">
                    <a href="stok_barang.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'stok_barang.php' ? 'active' : ''; ?>">
                        <i class="fas fa-boxes"></i>
                        Stok Barang
                    </a>
                </li>
                <li class="nav-item">
                    <a href="barang_masuk.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'barang_masuk.php' ? 'active' : ''; ?>">
                        <i class="fas fa-arrow-down"></i>
                        Barang Masuk
                    </a>
                </li>
                <li class="nav-item">
                    <a href="barang_keluar.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'barang_keluar.php' ? 'active' : ''; ?>">
                        <i class="fas fa-arrow-up"></i>
                        Barang Keluar
                    </a>
                </li>
                <li class="nav-item">
                    <a href="stok_kritis.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'stok_kritis.php' ? 'active' : ''; ?>">
                        <i class="fas fa-exclamation-triangle"></i>
                        Stok Kritis
                    </a>
                </li>
                <li class="nav-item">
                    <a href="order_masuk.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'order_masuk.php' ? 'active' : ''; ?>">
                        <i class="fas fa-shopping-cart"></i>
                        Order Masuk
                    </a>
                </li>
                <li class="nav-item">
                    <a href="order_pending.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'order_pending.php' ? 'active' : ''; ?>">
                        <i class="fas fa-clock"></i>
                        Order Pending
                    </a>
                </li>
                <li class="nav-item">
                    <a href="order_selesai.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'order_selesai.php' ? 'active' : ''; ?>">
                        <i class="fas fa-check-circle"></i>
                        Order Selesai
                    </a>
                </li>
                <li class="nav-item">
                    <a href="data_konsumen.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'data_konsumen.php' ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i>
                        Data Konsumen
                    </a>
                </li>
                <li class="nav-item">
                    <a href="profil.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'profil.php' ? 'active' : ''; ?>">
                        <i class="fas fa-user-cog"></i>
                        Profil
                    </a>
                </li>
                <li class="nav-item">
                    <a href="konfigurasi.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'konfigurasi.php' ? 'active' : ''; ?>">
                        <i class="fas fa-sliders-h"></i>
                        Konfigurasi
                    </a>
                </li>
            </ul>
            <hr>
            <div class="dropdown">
                <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle" id="dropdownUser1" data-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user-circle me-2" style="font-size: 1.5rem;"></i>
                    <strong><?php echo $_SESSION['user_name'] ?? 'Admin'; ?></strong>
                </a>
                <ul class="dropdown-menu dropdown-menu-dark text-small shadow" aria-labelledby="dropdownUser1">
                    <li><a class="dropdown-item" href="profil.php">Profil</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="logout.php">Keluar</a></li>
                </ul>
            </div>
        </div>

        <!-- Main content -->
        <div class="main-content flex-grow-1">
            <!-- Navbar -->
            <nav class="navbar navbar-expand-lg navbar-light bg-white">
                <button class="btn btn-link d-md-none" type="button" id="sidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="d-flex align-items-center">
                    <h4 class="mb-0"><?php echo $page_title ?? 'Dashboard'; ?></h4>
                    <?php if (isset($page_subtitle)): ?>
                        <span class="text-muted ml-2"><?php echo $page_subtitle; ?></span>
                    <?php endif; ?>
                </div>
                <div class="ml-auto d-flex">
                    <div class="dropdown mr-3">
                        <button class="btn btn-light btn-icon position-relative" type="button" id="notificationDropdown" data-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-bell"></i>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                3
                                <span class="visually-hidden">notifications</span>
                            </span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationDropdown" style="width: 300px;">
                            <h6 class="dropdown-header">Notifikasi</h6>
                            <a class="dropdown-item" href="#">5 Order baru masuk</a>
                            <a class="dropdown-item" href="#">3 Stok menipis</a>
                            <a class="dropdown-item" href="#">Pembaruan sistem tersedia</a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item text-center" href="#">Lihat Semua</a>
                        </div>
                    </div>
                    <div class="dropdown">
                        <a href="#" class="d-flex align-items-center text-decoration-none" id="userDropdown" data-toggle="dropdown" aria-expanded="false">
                            <div class="mr-2 d-none d-lg-block">
                                <div class="font-weight-bold"><?php echo $_SESSION['user_name'] ?? 'Admin'; ?></div>
                                <div class="text-muted small"><?php echo $_SESSION['user_role'] ?? 'Administrator'; ?></div>
                            </div>
                            <i class="fas fa-user-circle" style="font-size: 2rem; color: var(--gray-600);"></i>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <a class="dropdown-item" href="profil.php"><i class="fas fa-user-cog fa-fw me-2"></i> Profil</a>
                            <a class="dropdown-item" href="#"><i class="fas fa-cog fa-fw me-2"></i> Pengaturan</a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt fa-fw me-2"></i> Keluar</a>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Main content area -->
            <div class="container-fluid py-4">
                <!-- Success/Error Messages -->
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php 
                        echo $_SESSION['success'];
                        unset($_SESSION['success']);
                        ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php 
                        echo $_SESSION['error'];
                        unset($_SESSION['error']);
                        ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>
