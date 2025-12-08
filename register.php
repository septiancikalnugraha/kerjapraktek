<?php
// Aktifkan error reporting untuk debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Definisikan base path
$basePath = __DIR__;

// Definisikan path ke config.php
$configFile = $basePath . '/config/config.php';

// Cek apakah file config.php ada
if (!file_exists($configFile)) {
    die('File konfigurasi tidak ditemukan: ' . $configFile);
}

// Load file konfigurasi
try {
    require_once $configFile;
} catch (Exception $e) {
    die('Gagal memuat konfigurasi: ' . $e->getMessage());
}

// Pastikan session sudah dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect jika sudah login
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$success = '';

try {
    // Cek koneksi database dan buat tabel jika belum ada
    $conn = getConnection();
    ensureUsersTable();
} catch (Exception $e) {
    die('Tidak dapat terhubung ke database: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = isset($_POST['firstName']) ? trim($_POST['firstName']) : '';
    $lastName = isset($_POST['lastName']) ? trim($_POST['lastName']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $company = isset($_POST['company']) ? trim($_POST['company']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $confirmPassword = isset($_POST['confirmPassword']) ? $_POST['confirmPassword'] : '';
    $terms = isset($_POST['terms']);

    // Validasi input
    if (empty($firstName) || empty($lastName) || empty($email) || empty($company) || empty($password) || empty($confirmPassword)) {
        $error = 'Semua field harus diisi!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid!';
    } elseif (strlen($password) < 8) {
        $error = 'Password minimal 8 karakter!';
    } elseif ($password !== $confirmPassword) {
        $error = 'Password dan konfirmasi password tidak cocok!';
    } elseif (!$terms) {
        $error = 'Anda harus menyetujui Syarat & Ketentuan!';
    } else {
        try {
            // Cek apakah email sudah terdaftar
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error = 'Email sudah terdaftar!';
            } else {
                // Hash password
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                
                // Simpan ke database
                $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, company, password_hash) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('sssss', $firstName, $lastName, $email, $company, $hashedPassword);
                
                if ($stmt->execute()) {
                    $success = 'Pendaftaran berhasil! Silakan login.';
                    // Reset form
                    $_POST = array();
                } else {
                    $error = 'Terjadi kesalahan. Silakan coba lagi.';
                }
            }
            
            $stmt->close();
        } catch (Exception $e) {
            $error = 'Terjadi kesalahan: ' . $e->getMessage();
        }
    }
    
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Akun - Executive Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            width: 100%;
            max-width: 480px;
            margin: 0 auto;
        }

        .login-card {
            background: white;
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .logo-container {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }

        .logo svg {
            width: 40px;
            height: 40px;
            color: white;
        }

        h1 {
            font-size: 24px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
        }

        .subtitle {
            color: #718096;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #4a5568;
            font-size: 14px;
        }

        .input-wrapper {
            position: relative;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #a0aec0;
            cursor: pointer;
            padding: 4px;
        }

        .password-toggle:hover {
            color: #4a5568;
        }

        .terms-container {
            display: flex;
            align-items: flex-start;
            margin: 20px 0;
        }

        .terms-container input[type="checkbox"] {
            margin-right: 8px;
            margin-top: 4px;
        }

        .terms-container label {
            font-size: 13px;
            color: #4a5568;
            line-height: 1.5;
            margin: 0;
        }

        .terms-container a {
            color: #667eea;
            text-decoration: none;
        }

        .terms-container a:hover {
            text-decoration: underline;
        }

        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .login-link {
            text-align: center;
            margin-top: 24px;
            color: #4a5568;
            font-size: 14px;
        }

        .login-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            margin-left: 4px;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
        }

        .alert-error {
            background-color: #fff5f5;
            color: #e53e3e;
            border-left: 4px solid #e53e3e;
        }

        .alert-success {
            background-color: #f0fff4;
            color: #38a169;
            border-left: 4px solid #38a169;
        }

        .alert i {
            margin-right: 10px;
            font-size: 18px;
        }

        @media (max-width: 480px) {
            .login-card {
                padding: 24px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
            
            h1 {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="logo-container">
                <div class="logo">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 12C14.21 12 16 10.21 16 8C16 5.79 14.21 4 12 4C9.79 4 8 5.79 8 8C8 10.21 9.79 12 12 12ZM12 14C9.33 14 4 15.34 4 18V20H20V18C20 15.34 14.67 14 12 14Z" fill="currentColor"/>
                    </svg>
                </div>
                <h1>Buat Akun Baru</h1>
                <p class="subtitle">Daftar untuk mulai menggunakan dashboard</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php else: ?>
                <form method="POST" action="" id="registerForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="firstName">Nama Depan</label>
                            <input type="text" 
                                   id="firstName" 
                                   name="firstName" 
                                   value="<?php echo isset($_POST['firstName']) ? htmlspecialchars($_POST['firstName']) : ''; ?>" 
                                   required 
                                   placeholder="Nama depan">
                        </div>
                        <div class="form-group">
                            <label for="lastName">Nama Belakang</label>
                            <input type="text" 
                                   id="lastName" 
                                   name="lastName" 
                                   value="<?php echo isset($_POST['lastName']) ? htmlspecialchars($_POST['lastName']) : ''; ?>" 
                                   required 
                                   placeholder="Nama belakang">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Alamat Email</label>
                        <input type="email" 
                               id="email" 
                               name="email" 
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                               required 
                               placeholder="contoh@perusahaan.com">
                    </div>
                    
                    <div class="form-group">
                        <label for="company">Nama Perusahaan</label>
                        <input type="text" 
                               id="company" 
                               name="company" 
                               value="<?php echo isset($_POST['company']) ? htmlspecialchars($_POST['company']) : ''; ?>" 
                               required 
                               placeholder="Nama perusahaan Anda">
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-wrapper">
                            <input type="password" 
                                   id="password" 
                                   name="password" 
                                   required 
                                   minlength="8" 
                                   placeholder="Minimal 8 karakter">
                            <button type="button" class="password-toggle" onclick="togglePassword('password', 'togglePasswordIcon')">
                                <i id="togglePasswordIcon" class="far fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirmPassword">Konfirmasi Password</label>
                        <div class="input-wrapper">
                            <input type="password" 
                                   id="confirmPassword" 
                                   name="confirmPassword" 
                                   required 
                                   minlength="8" 
                                   placeholder="Ketik ulang password Anda">
                            <button type="button" class="password-toggle" onclick="togglePassword('confirmPassword', 'toggleConfirmPasswordIcon')">
                                <i id="toggleConfirmPasswordIcon" class="far fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="terms-container">
                        <input type="checkbox" id="terms" name="terms" required>
                        <label for="terms">Saya menyetujui <a href="#" class="text-primary">Syarat & Ketentuan</a> dan <a href="#" class="text-primary">Kebijakan Privasi</a></label>
                    </div>
                    
                    <button type="submit" class="btn">
                        <i class="fas fa-user-plus"></i> Daftar Sekarang
                    </button>
                </form>
            <?php endif; ?>
            
            <div class="login-link">
                Sudah punya akun? <a href="login.php">Masuk disini</a>
            </div>
        </div>
    </div>

    <script>
        // Fungsi toggle password visibility
        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            
            if (input && icon) {
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    input.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            }
        }
        
        // Validasi form
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('registerForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const password = document.getElementById('password')?.value || '';
                    const confirmPassword = document.getElementById('confirmPassword')?.value || '';
                    const terms = document.getElementById('terms')?.checked;
                    
                    // Validasi password
                    if (password.length < 8) {
                        e.preventDefault();
                        alert('Password minimal 8 karakter');
                        return false;
                    }
                    
                    // Validasi konfirmasi password
                    if (password !== confirmPassword) {
                        e.preventDefault();
                        alert('Password dan konfirmasi password tidak cocok');
                        return false;
                    }
                    
                    // Validasi syarat dan ketentuan
                    if (!terms) {
                        e.preventDefault();
                        alert('Anda harus menyetujui Syarat & Ketentuan');
                        return false;
                    }
                    
                    return true;
                });
            }
        });
    </script>
</body>
</html>