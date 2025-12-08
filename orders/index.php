<?php
require_once '../config/config.php';
requireLogin();

$conn = getConnection();
$page_title = "Manajemen Order";
$active_menu = "orders";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <?php include '../includes/head.php'; ?>
    <title><?= $page_title; ?> - <?= SITE_NAME; ?></title>
    <style>
        .card-hover {
            transition: transform 0.2s, box-shadow 0.2s;
            text-decoration: none !important;
        }
        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .card-hover .card-body {
            padding: 1.5rem;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main">
            <?php include '../includes/navbar.php'; ?>
            
            <main class="content">
                <div class="container-fluid p-0">
                    <h1 class="h3 mb-4"><?= $page_title; ?></h1>
                    
                    <div class="row">
                        <div class="col-12 col-md-6 col-lg-3 mb-4">
                            <a href="masuk.php" class="card card-hover border-primary">
                                <div class="card-body text-center">
                                    <div class="bg-primary bg-opacity-10 p-3 rounded-circle d-inline-block mb-3">
                                        <i class="bi bi-inbox text-primary" style="font-size: 1.8rem;"></i>
                                    </div>
                                    <h5 class="card-title mb-1">Order Masuk</h5>
                                    <p class="text-muted mb-0">Kelola order baru</p>
                                </div>
                            </a>
                        </div>
                        
                        <div class="col-12 col-md-6 col-lg-3 mb-4">
                            <a href="pending.php" class="card card-hover border-warning">
                                <div class="card-body text-center">
                                    <div class="bg-warning bg-opacity-10 p-3 rounded-circle d-inline-block mb-3">
                                        <i class="bi bi-hourglass-split text-warning" style="font-size: 1.8rem;"></i>
                                    </div>
                                    <h5 class="card-title mb-1">Order Pending</h5>
                                    <p class="text-muted mb-0">Proses order menunggu</p>
                                </div>
                            </a>
                        </div>
                        
                        <div class="col-12 col-md-6 col-lg-3 mb-4">
                            <a href="selesai.php" class="card card-hover border-success">
                                <div class="card-body text-center">
                                    <div class="bg-success bg-opacity-10 p-3 rounded-circle d-inline-block mb-3">
                                        <i class="bi bi-check-circle text-success" style="font-size: 1.8rem;"></i>
                                    </div>
                                    <h5 class="card-title mb-1">Order Selesai</h5>
                                    <p class="text-muted mb-0">Riwayat order</p>
                                </div>
                            </a>
                        </div>
                        
                        <div class="col-12 col-md-6 col-lg-3 mb-4">
                            <a href="konsumen.php" class="card card-hover border-info">
                                <div class="card-body text-center">
                                    <div class="bg-info bg-opacity-10 p-3 rounded-circle d-inline-block mb-3">
                                        <i class="bi bi-people text-info" style="font-size: 1.8rem;"></i>
                                    </div>
                                    <h5 class="card-title mb-1">Data Konsumen</h5>
                                    <p class="text-muted mb-0">Kelola pelanggan</p>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
            </main>
            
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>

    <script src="../assets/js/app.js"></script>
    <script>
        // Set active menu
        document.addEventListener('DOMContentLoaded', function() {
            const menuItems = document.querySelectorAll('.sidebar-nav .nav-link');
            menuItems.forEach(item => {
                if (item.getAttribute('href').includes('orders')) {
                    item.classList.add('active');
                    const parent = item.closest('.collapse');
                    if (parent) {
                        parent.classList.add('show');
                        const parentLink = parent.previousElementSibling;
                        if (parentLink) {
                            parentLink.classList.add('active');
                            parentLink.setAttribute('aria-expanded', 'true');
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>
