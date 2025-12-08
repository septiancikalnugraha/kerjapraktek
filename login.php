<?php require_once __DIR__ . '/config/config.php';

$error = '';

ensureUsersTable();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if ($email === '' || $password === '') {
        $error = 'Email dan password harus diisi!';
    } else {
        if (strtolower($email) === strtolower(OWNER_EMAIL) && password_verify($password, OWNER_PASSWORD_HASH)) {
            $_SESSION['user_id'] = 0;
            $_SESSION['email'] = $email;
            $_SESSION['role'] = 'owner';
            header('Location: dashboard_clean.php');
            exit();
        } else {
            $conn = getConnection();
            $stmt = $conn->prepare("SELECT id, first_name, last_name, password_hash, role FROM users WHERE email = ? LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                if ($row['role'] !== 'staff') {
                    $error = 'Akun tidak valid untuk login staf.';
                } elseif (password_verify($password, $row['password_hash'])) {
                    $_SESSION['user_id'] = (int)$row['id'];
                    $_SESSION['email'] = $email;
                    $_SESSION['name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                    $_SESSION['role'] = 'staff';
                    header('Location: dashboard_clean.php');
                    exit();
                } else {
                    $error = 'Email atau password salah!';
                }
            } else {
                $error = 'Email atau password salah!';
            }
            $stmt->close();
            $conn->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Executive Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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
            position: relative;
            overflow: auto;
        }

        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 50%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(255, 255, 255, 0.1) 0%, transparent 50%);
            pointer-events: none;
        }

        .login-container {
            width: 100%;
            max-width: 440px;
            position: relative;
            z-index: 1;
        }

        .login-card {
            background: white;
            border-radius: 24px;
            padding: 48px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            position: relative;
            animation: fadeInUp 0.6s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .logo-container {
            text-align: center;
            margin-bottom: 32px;
        }

        .logo {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.4);
        }

        .logo svg {
            width: 32px;
            height: 32px;
        }

        h1 {
            font-size: 1.75rem;
            font-weight: 800;
            color: #1a1a1a;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .subtitle {
            color: #4a5568;
            font-size: 0.95rem;
        }

        .form-group {
            margin-bottom: 24px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #1a1a1a;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .input-wrapper {
            position: relative;
        }

        input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            font-family: inherit;
            background: #f7fafc;
        }

        input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }

        input:hover {
            border-color: #cbd5e0;
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #4a5568;
            cursor: pointer;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .password-toggle:hover {
            color: #667eea;
        }

        .forgot-password {
            text-align: right;
            margin-top: -16px;
            margin-bottom: 24px;
        }

        .forgot-password a {
            color: #667eea;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 600;
            transition: color 0.2s ease;
        }

        .forgot-password a:hover {
            color: #764ba2;
        }

        .btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.4);
            margin-bottom: 16px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(102, 126, 234, 0.5);
        }

        .btn:active {
            transform: translateY(0);
        }

        .divider {
            display: flex;
            align-items: center;
            margin: 24px 0;
            color: #4a5568;
            font-size: 0.875rem;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }

        .divider span {
            padding: 0 16px;
        }

        .social-login {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 24px;
        }

        .social-btn {
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            background: white;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            color: #1a1a1a;
        }

        .social-btn:hover {
            border-color: #cbd5e0;
            background: #f7fafc;
            transform: translateY(-2px);
        }

        .register-link {
            text-align: center;
            color: #4a5568;
            font-size: 0.9rem;
        }

        .register-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 700;
            transition: color 0.2s ease;
        }

        .register-link a:hover {
            color: #764ba2;
        }

        .back-home {
            text-align: center;
            margin-top: 24px;
        }

        .back-home a {
            color: white;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .back-home a:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 0.9rem;
            font-weight: 500;
            display: none;
        }

        .alert.error {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }

        .alert.success {
            background: #efe;
            color: #3c3;
            border: 1px solid #cfc;
        }

        @media (max-width: 480px) {
            .login-card {
                padding: 32px 24px;
            }

            h1 {
                font-size: 1.5rem;
            }

            .social-login {
                grid-template-columns: 1fr;
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
                        <path d="M9 11l3 3L22 4" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <h1>Selamat Datang</h1>
                <p class="subtitle">Login untuk mengakses dashboard Anda</p>
            </div>

            <div id="alertBox" class="alert<?php echo $error ? ' error' : ''; ?>" style="<?php echo $error ? 'display:block' : 'display:none'; ?>"><?php echo htmlspecialchars($error); ?></div>

            <form id="loginForm" method="POST" action="">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" placeholder="nama@perusahaan.com" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" placeholder="Masukkan password Anda" required>
                        <button type="button" class="password-toggle" onclick="togglePassword()">
                            <svg id="eyeIcon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <circle cx="12" cy="12" r="3" stroke-width="2"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="forgot-password">
                    <a href="#">Lupa Password?</a>
                </div>

                <button type="submit" class="btn">Login</button>
            </form>

            <div class="divider">
                <span>atau login dengan</span>
            </div>

            <div class="social-login">
                <button class="social-btn" onclick="alert('Fitur Google Login akan segera hadir!')">
                    <svg width="20" height="20" viewBox="0 0 24 24">
                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                    </svg>
                    Google
                </button>
                <button class="social-btn" onclick="alert('Fitur Microsoft Login akan segera hadir!')">
                    <svg width="20" height="20" viewBox="0 0 24 24">
                        <path fill="#f25022" d="M1 1h10v10H1z"/>
                        <path fill="#00a4ef" d="M13 1h10v10H13z"/>
                        <path fill="#7fba00" d="M1 13h10v10H1z"/>
                        <path fill="#ffb900" d="M13 13h10v10H13z"/>
                    </svg>
                    Microsoft
                </button>
            </div>

            <div class="register-link">
                Belum punya akun? <a href="register.php">Daftar Sekarang</a>
            </div>
        </div>

        <div class="back-home">
            <a href="index.php">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M19 12H5M12 19l-7-7 7-7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Kembali ke Beranda
            </a>
        </div>
    </div>

    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eyeIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><line x1="1" y1="1" x2="23" y2="23" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>';
            } else {
                passwordInput.type = 'password';
                eyeIcon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke-width="2"/>';
            }
        }
    </script>
</body>
</html>