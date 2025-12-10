<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$conn = getConnection();
$page_title = "Data Konsumen";
$page_subtitle = "Kelola data pelanggan dan perusahaan";

// Handle delete action
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM konsumen WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['success'] = "Data konsumen berhasil dihapus";
    } else {
        $_SESSION['error'] = "Gagal menghapus data konsumen: " . $conn->error;
    }
    header("Location: data_konsumen.php");
    exit();
}

// Get all customers
$query = "SELECT * FROM konsumen ORDER BY nama_konsumen ASC";
$result = $conn->query($query);
?>

<!-- Include header -->
<?php include 'includes/header.php'; ?>

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

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Daftar Konsumen</h3>
        <div class="card-actions">
            <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addCustomerModal">
                <i class="fas fa-plus"></i> Tambah Konsumen
            </button>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="customersTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nama Konsumen</th>
                        <th>Perusahaan</th>
                        <th>No. HP</th>
                        <th>Email</th>
                        <th>Total Order</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php $no = 1; ?>
                        <?php while ($row = $result->fetch_assoc()): 
                            // Get total orders for this customer
                            $orderCount = $conn->query("SELECT COUNT(*) as total FROM orders WHERE konsumen_id = " . $row['id'])->fetch_assoc()['total'];
                        ?>
                            <tr>
                                <td><?php echo $no++; ?></td>
                                <td><?php echo htmlspecialchars($row['nama_konsumen']); ?></td>
                                <td><?php echo htmlspecialchars($row['perusahaan'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['no_hp'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['email'] ?? '-'); ?></td>
                                <td><?php echo $orderCount; ?> Order</td>
                                <td>
                                    <button class="btn btn-sm btn-icon btn-edit" 
                                            data-id="<?php echo $row['id']; ?>"
                                            data-nama="<?php echo htmlspecialchars($row['nama_konsumen']); ?>"
                                            data-perusahaan="<?php echo htmlspecialchars($row['perusahaan'] ?? ''); ?>"
                                            data-alamat="<?php echo htmlspecialchars($row['alamat'] ?? ''); ?>"
                                            data-nohp="<?php echo htmlspecialchars($row['no_hp'] ?? ''); ?>"
                                            data-email="<?php echo htmlspecialchars($row['email'] ?? ''); ?>"
                                            title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="#" class="btn btn-sm btn-icon btn-delete" 
                                       data-id="<?php echo $row['id']; ?>" 
                                       data-nama="<?php echo htmlspecialchars($row['nama_konsumen']); ?>"
                                       title="Hapus">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center">Belum ada data konsumen</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Customer Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1" role="dialog" aria-labelledby="addCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="addCustomerForm" action="actions/save_customer.php" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="addCustomerModalLabel">Tambah Konsumen Baru</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="nama_konsumen">Nama Konsumen <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="nama_konsumen" name="nama_konsumen" required>
                    </div>
                    <div class="form-group">
                        <label for="perusahaan">Nama Perusahaan</label>
                        <input type="text" class="form-control" id="perusahaan" name="perusahaan">
                    </div>
                    <div class="form-group">
                        <label for="alamat">Alamat</label>
                        <textarea class="form-control" id="alamat" name="alamat" rows="2"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="no_hp">No. HP</label>
                                <input type="text" class="form-control" id="no_hp" name="no_hp">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="email">Email</label>
                                <input type="email" class="form-control" id="email" name="email">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Customer Modal -->
<div class="modal fade" id="editCustomerModal" tabindex="-1" role="dialog" aria-labelledby="editCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="editCustomerForm" action="actions/update_customer.php" method="POST">
                <input type="hidden" id="edit_id" name="id">
                <div class="modal-header">
                    <h5 class="modal-title" id="editCustomerModalLabel">Edit Data Konsumen</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="edit_nama_konsumen">Nama Konsumen <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_nama_konsumen" name="nama_konsumen" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_perusahaan">Nama Perusahaan</label>
                        <input type="text" class="form-control" id="edit_perusahaan" name="perusahaan">
                    </div>
                    <div class="form-group">
                        <label for="edit_alamat">Alamat</label>
                        <textarea class="form-control" id="edit_alamat" name="alamat" rows="2"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_no_hp">No. HP</label>
                                <input type="text" class="form-control" id="edit_no_hp" name="no_hp">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_email">Email</label>
                                <input type="email" class="form-control" id="edit_email" name="email">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" role="dialog" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteModalLabel">Konfirmasi Hapus</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Apakah Anda yakin ingin menghapus data konsumen <strong id="deleteCustomerName"></strong>?</p>
                <p class="text-danger">Data yang dihapus tidak dapat dikembalikan!</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                <a href="#" id="confirmDelete" class="btn btn-danger">Hapus</a>
            </div>
        </div>
    </div>
</div>

<!-- Include footer -->
<?php include 'includes/footer.php'; ?>

<!-- Include scripts -->
<?php include 'includes/scripts.php'; ?>

<script>
    $(document).ready(function() {
        // Initialize DataTable
        $('#customersTable').DataTable({
            responsive: true,
            language: {
                url: '//cdn.datatables.net/plug-ins/1.10.25/i18n/Indonesian.json'
            },
            columnDefs: [
                { orderable: false, targets: [0, 6] } // Disable sorting on first and last column
            ]
        });

        // Handle edit button click
        $('.btn-edit').on('click', function() {
            var id = $(this).data('id');
            var nama = $(this).data('nama');
            var perusahaan = $(this).data('perusahaan');
            var alamat = $(this).data('alamat');
            var nohp = $(this).data('nohp');
            var email = $(this).data('email');
            
            $('#edit_id').val(id);
            $('#edit_nama_konsumen').val(nama);
            $('#edit_perusahaan').val(perusahaan);
            $('#edit_alamat').val(alamat);
            $('#edit_no_hp').val(nohp);
            $('#edit_email').val(email);
            
            $('#editCustomerModal').modal('show');
        });

        // Handle delete button click
        $('.btn-delete').on('click', function(e) {
            e.preventDefault();
            var id = $(this).data('id');
            var nama = $(this).data('nama');
            
            $('#deleteCustomerName').text(nama);
            $('#confirmDelete').attr('href', 'data_konsumen.php?delete=' + id);
            $('#deleteModal').modal('show');
        });

        // Form validation
        $('#addCustomerForm, #editCustomerForm').validate({
            rules: {
                nama_konsumen: {
                    required: true,
                    minlength: 3
                },
                email: {
                    email: true
                }
            },
            messages: {
                nama_konsumen: {
                    required: "Nama konsumen harus diisi",
                    minlength: "Nama minimal 3 karakter"
                },
                email: {
                    email: "Masukkan alamat email yang valid"
                }
            },
            errorElement: 'div',
            errorPlacement: function (error, element) {
                error.addClass('invalid-feedback');
                element.closest('.form-group').append(error);
            },
            highlight: function (element, errorClass, validClass) {
                $(element).addClass('is-invalid');
            },
            unhighlight: function (element, errorClass, validClass) {
                $(element).removeClass('is-invalid');
            }
        });
    });
</script>

<?php $conn->close(); ?>
