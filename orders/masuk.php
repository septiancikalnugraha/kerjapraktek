<?php
require_once '../config/config.php';
requireLogin();

$conn = getConnection();
$page_title = "Order Masuk";
$active_menu = "orders";

// Get new orders (status = 'baru' or 'diproses')
$query = "SELECT t.*, p.nama as nama_pelanggan 
          FROM transaksi t
          LEFT JOIN pelanggan p ON t.pelanggan_id = p.id
          WHERE t.status IN ('baru', 'diproses')
          ORDER BY t.tgl_transaksi DESC";
$result = $conn->query($query);
$orders = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <?php include '../includes/head.php'; ?>
    <title><?= $page_title; ?> - <?= SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        .status-badge {
            font-size: 0.8rem;
            padding: 0.35em 0.65em;
        }
        .action-buttons .btn {
            margin-right: 0.25rem;
            margin-bottom: 0.25rem;
        }
        @media (max-width: 768px) {
            .action-buttons .btn {
                padding: 0.25rem 0.5rem;
                font-size: 0.8rem;
            }
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
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h1 class="h3 mb-0"><?= $page_title; ?></h1>
                        <div>
                            <a href="tambah.php" class="btn btn-primary">
                                <i class="bi bi-plus-lg me-1"></i> Order Baru
                            </a>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-body">
                            <?php if (isset($_SESSION['success'])): ?>
                                <div class="alert alert-success alert-dismissible fade show" role="alert">
                                    <?= $_SESSION['success']; ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                                <?php unset($_SESSION['success']); ?>
                            <?php endif; ?>
                            
                            <div class="table-responsive">
                                <table id="ordersTable" class="table table-hover" style="width:100%">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Tanggal</th>
                                            <th>No. Order</th>
                                            <th>Pelanggan</th>
                                            <th>Total</th>
                                            <th>Status</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($orders as $index => $order): 
                                            $status_class = [
                                                'baru' => 'bg-primary',
                                                'diproses' => 'bg-info',
                                                'dikirim' => 'bg-warning',
                                                'selesai' => 'bg-success',
                                                'batal' => 'bg-danger'
                                            ][$order['status']] ?? 'bg-secondary';
                                        ?>
                                            <tr>
                                                <td><?= $index + 1; ?></td>
                                                <td><?= date('d/m/Y H:i', strtotime($order['tgl_transaksi'])); ?></td>
                                                <td>#<?= str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                                <td><?= htmlspecialchars($order['nama_pelanggan'] ?? 'Tidak Diketahui'); ?></td>
                                                <td>Rp <?= number_format($order['total'], 0, ',', '.'); ?></td>
                                                <td>
                                                    <span class="badge <?= $status_class; ?> status-badge">
                                                        <?= ucfirst($order['status']); ?>
                                                    </span>
                                                </td>
                                                <td class="action-buttons">
                                                    <a href="detail.php?id=<?= $order['id']; ?>" class="btn btn-sm btn-outline-primary" title="Lihat Detail">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <?php if ($order['status'] == 'baru'): ?>
                                                        <button class="btn btn-sm btn-success process-order" 
                                                                data-id="<?= $order['id']; ?>"
                                                                title="Proses Order">
                                                            <i class="bi bi-check-lg"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <a href="cetak.php?id=<?= $order['id']; ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Cetak">
                                                        <i class="bi bi-printer"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
            
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>

    <script src="../assets/js/app.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize DataTable
            const table = $('#ordersTable').DataTable({
                order: [[1, 'desc']],
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.11.5/i18n/id.json'
                },
                responsive: true,
                columnDefs: [
                    { orderable: false, targets: [0, 6] },
                    { searchable: false, targets: [0, 5, 6] },
                    { width: '5%', targets: [0, 5, 6] },
                    { width: '15%', targets: [1, 3, 4] },
                    { width: '10%', targets: [2] }
                ]
            });
            
            // Process order
            $(document).on('click', '.process-order', function() {
                const orderId = $(this).data('id');
                if (confirm('Apakah Anda yakin ingin memproses order ini?')) {
                    $.post('proses_order.php', { 
                        id: orderId, 
                        action: 'process',
                        status: 'diproses'
                    }, function(response) {
                        const result = JSON.parse(response);
                        if (result.success) {
                            location.reload();
                        } else {
                            alert('Terjadi kesalahan: ' + result.message);
                        }
                    }).fail(function() {
                        alert('Terjadi kesalahan saat memproses order');
                    });
                }
            });
            
            // Set active menu
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
