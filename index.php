<?php require_once __DIR__ . '/config/config.php'; ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Executive Dashboard - Pantau Bisnis Anda</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            line-height: 1.6;
            color: #1a1a1a;
            background: #ffffff;
            overflow-x: hidden;
        }

        /* Navbar */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(0,0,0,0.06);
            transition: all 0.3s ease;
        }

        .navbar.scrolled {
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .nav-container {
            max-width: 1280px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 32px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: #1a1a1a;
            font-weight: 700;
            font-size: 1.1rem;
            letter-spacing: -0.5px;
        }

        .brand-logo {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .brand-logo svg {
            width: 22px;
            height: 22px;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-links a {
            color: #4a5568;
            text-decoration: none;
            font-weight: 500;
            padding: 10px 18px;
            border-radius: 10px;
            transition: all 0.2s ease;
            font-size: 0.95rem;
        }

        .nav-links a:hover {
            background: #f7fafc;
            color: #1a1a1a;
        }

        .btn-nav {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff !important;
            padding: 11px 24px !important;
            border-radius: 12px;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
            margin-left: 8px;
        }

        .btn-nav:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
            background: linear-gradient(135deg, #5568d3 0%, #6a3f8f 100%);
        }

        /* Hero Section */
        .hero {
            position: relative;
            padding: 140px 32px 120px;
            background: linear-gradient(180deg, #f7fafc 0%, #ffffff 100%);
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 50%, rgba(102, 126, 234, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(118, 75, 162, 0.1) 0%, transparent 50%);
            pointer-events: none;
        }

        .hero-content {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 80px;
            align-items: center;
            position: relative;
            z-index: 1;
        }

        .hero-text h1 {
            font-size: 4rem;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 24px;
            background: linear-gradient(135deg, #1a1a1a 0%, #4a5568 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -2px;
        }

        .hero-text .gradient-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-text p {
            font-size: 1.25rem;
            color: #4a5568;
            margin-bottom: 40px;
            line-height: 1.8;
            font-weight: 400;
        }

        .cta-buttons {
            display: flex;
            gap: 16px;
        }

        .btn {
            padding: 16px 36px;
            font-size: 1rem;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.35);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 32px rgba(102, 126, 234, 0.45);
        }

        .btn-secondary {
            background: #ffffff;
            color: #667eea;
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
            transform: translateY(-2px);
        }

        /* Hero Visual */
        .hero-visual {
            position: relative;
            height: 500px;
        }

        .dashboard-mockup {
            position: relative;
            width: 100%;
            height: 100%;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 
                0 20px 60px rgba(0,0,0,0.15),
                0 0 0 1px rgba(0,0,0,0.05);
            overflow: hidden;
            transform: perspective(1000px) rotateY(-5deg);
            transition: transform 0.3s ease;
        }

        .dashboard-mockup:hover {
            transform: perspective(1000px) rotateY(0deg);
        }

        .mockup-header {
            height: 50px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            padding: 0 20px;
            gap: 8px;
        }

        .mockup-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: rgba(255,255,255,0.3);
        }

        .mockup-content {
            padding: 24px;
            background: #f7fafc;
        }

        .mockup-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        .mockup-bar {
            height: 10px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            border-radius: 5px;
            margin-bottom: 12px;
        }

        .mockup-line {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            margin-bottom: 8px;
        }

        .mockup-line.short { width: 60%; }
        .mockup-line.medium { width: 80%; }

        /* Stats Section */
        .stats {
            padding: 80px 32px;
            background: #ffffff;
        }

        .stats-container {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 40px;
        }

        .stat-card {
            text-align: center;
            padding: 40px 20px;
            background: linear-gradient(135deg, #f7fafc 0%, #ffffff 100%);
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 28px rgba(0,0,0,0.1);
            border-color: #cbd5e0;
        }

        .stat-number {
            font-size: 3rem;
            font-weight: 800;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 8px;
        }

        .stat-label {
            color: #4a5568;
            font-weight: 500;
            font-size: 0.95rem;
        }

        /* Dashboard Section */
        .dashboard-section {
            padding: 120px 32px;
            background: linear-gradient(180deg, #ffffff 0%, #f7fafc 100%);
        }

        .dashboard-header {
            text-align: center;
            max-width: 700px;
            margin: 0 auto 80px;
        }

        .dashboard-header h2 {
            font-size: 3rem;
            font-weight: 800;
            margin-bottom: 20px;
            color: #1a1a1a;
            letter-spacing: -1px;
        }

        .dashboard-header p {
            font-size: 1.15rem;
            color: #4a5568;
            line-height: 1.8;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 32px;
            max-width: 1280px;
            margin: 0 auto;
        }

        .dashboard-card {
            background: white;
            padding: 40px;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .dashboard-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .dashboard-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 16px 40px rgba(0,0,0,0.12);
            border-color: #cbd5e0;
        }

        .dashboard-card:hover::before {
            transform: scaleX(1);
        }

        .dashboard-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin-bottom: 24px;
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
        }

        .dashboard-card h3 {
            font-size: 1.4rem;
            margin-bottom: 16px;
            color: #1a1a1a;
            font-weight: 700;
        }

        .dashboard-card p {
            color: #4a5568;
            line-height: 1.8;
            font-size: 0.95rem;
            margin-bottom: 20px;
        }

        .dashboard-card .btn-demo {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }

        .dashboard-card .btn-demo:hover {
            gap: 12px;
            color: #764ba2;
        }

        /* Footer */
        footer {
            background: #1a1a1a;
            color: #ffffff;
            padding: 60px 32px 30px;
        }

        .footer-content {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 60px;
            margin-bottom: 40px;
        }

        .footer-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            font-weight: 700;
            font-size: 1.1rem;
        }

        .footer-brand-logo {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .footer-desc {
            color: #a0aec0;
            line-height: 1.8;
            margin-bottom: 20px;
        }

        .footer-section h4 {
            margin-bottom: 20px;
            font-size: 0.95rem;
            color: #ffffff;
            font-weight: 600;
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 12px;
        }

        .footer-links a {
            color: #a0aec0;
            text-decoration: none;
            transition: color 0.2s ease;
            font-size: 0.9rem;
        }

        .footer-links a:hover {
            color: #667eea;
        }

        .footer-bottom {
            max-width: 1280px;
            margin: 0 auto;
            padding-top: 30px;
            border-top: 1px solid #2d3748;
            text-align: center;
            color: #a0aec0;
            font-size: 0.9rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .hero-content {
                grid-template-columns: 1fr;
                gap: 60px;
            }

            .hero-visual {
                height: 400px;
            }

            .dashboard-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }

            .footer-content {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 768px) {
            .nav-links a:not(.btn-nav) {
                display: none;
            }

            .hero-text h1 {
                font-size: 2.5rem;
            }

            .hero-text p {
                font-size: 1.1rem;
            }

            .cta-buttons {
                flex-direction: column;
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .stats-container {
                grid-template-columns: 1fr;
            }

            .dashboard-header h2 {
                font-size: 2rem;
            }

            .footer-content {
                grid-template-columns: 1fr;
                gap: 40px;
            }
        }

        /* Animations */
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

        .fade-in {
            animation: fadeInUp 0.8s ease forwards;
        }

        /* Swipe Component */
        .swipe-component {
            position: fixed;
            right: 24px;
            bottom: 24px;
            z-index: 1100;
        }
        .swipe-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            border: none;
            border-radius: 999px;
            padding: 12px 16px;
            font-weight: 600;
            box-shadow: 0 10px 24px rgba(102,126,234,0.35);
            cursor: pointer;
        }
        .swipe-btn svg {
            width: 18px; height: 18px;
        }
        .swipe-btn:active { transform: translateY(1px); }

    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar">
        <div class="nav-container">
            <a class="brand" href="#top">
                <div class="brand-logo">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M9 11l3 3L22 4" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <span>Executive Dashboard</span>
            </a>
            <div class="nav-links">
                <a href="#dashboard">Home</a>
                <a href="tentang.php">Tentang</a>
                <a href="login.php">Login</a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-content">
            <div class="hero-text fade-in">
                <h1>Kelola Bisnis Anda dengan <span class="gradient-text">Dashboard Profesional</span></h1>
                <p>Platform analitik bisnis yang powerful untuk memantau performa, menganalisis data, dan membuat keputusan bisnis yang lebih baik secara real-time.</p>
                <div class="cta-buttons">
                    <a href="dashboard.php" class="btn btn-primary">
                        Mulai Sekarang
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                            <path d="M5 12h14M12 5l7 7-7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    <a href="login.php" class="btn btn-secondary">Login</a>
                </div>
            </div>
            <div class="hero-visual fade-in">
                <div class="dashboard-mockup">
                    <div class="mockup-header">
                        <div class="mockup-dot"></div>
                        <div class="mockup-dot"></div>
                        <div class="mockup-dot"></div>
                    </div>
                    <div class="mockup-content">
                        <div class="mockup-card">
                            <div class="mockup-bar" style="width: 75%"></div>
                            <div class="mockup-line short"></div>
                            <div class="mockup-line medium"></div>
                        </div>
                        <div class="mockup-card">
                            <div class="mockup-bar" style="width: 90%"></div>
                            <div class="mockup-line medium"></div>
                            <div class="mockup-line short"></div>
                        </div>
                        <div class="mockup-card">
                            <div class="mockup-bar" style="width: 60%"></div>
                            <div class="mockup-line short"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Dashboard Features Section -->
    <section id="dashboard" class="dashboard-section">
        <div class="dashboard-header">
            <h2>Dashboard Fitur Lengkap</h2>
            <p>Semua tools yang Anda butuhkan untuk monitoring, analisis, dan pengambilan keputusan bisnis yang lebih cerdas</p>
        </div>
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="dashboard-icon">📊</div>
                <h3>Analisis Real-Time</h3>
                <p>Monitor data bisnis Anda secara langsung dengan visualisasi interaktif yang mudah dipahami dan actionable insights.</p>
                <a href="dashboard.php" class="btn-demo">
                    Lihat Dashboard →
                </a>
            </div>
            <div class="dashboard-card">
                <div class="dashboard-icon">💹</div>
                <h3>Laporan Keuangan</h3>
                <p>Lacak revenue, profit margin, cash flow, dan metrik keuangan penting lainnya dalam satu dashboard terpadu.</p>
                <a href="dashboard.php" class="btn-demo">
                    Lihat Dashboard →
                </a>
            </div>
            <div class="dashboard-card">
                <div class="dashboard-icon">📈</div>
                <h3>Grafik Interaktif</h3>
                <p>Visualisasi data dengan berbagai jenis chart yang dinamis, customizable, dan mudah untuk dipresentasikan.</p>
                <a href="dashboard.php" class="btn-demo">
                    Lihat Dashboard →
                </a>
            </div>
            <div class="dashboard-card">
                <div class="dashboard-icon">🎯</div>
                <h3>Target & KPI</h3>
                <p>Set dan track target bisnis Anda dengan sistem KPI monitoring yang komprehensif dan real-time alerts.</p>
                <a href="dashboard.php" class="btn-demo">
                    Lihat Dashboard →
                </a>
            </div>
            <div class="dashboard-card">
                <div class="dashboard-icon">👥</div>
                <h3>Tim Management</h3>
                <p>Kelola tim, assign tasks, dan monitor produktivitas dengan collaboration tools yang powerful.</p>
                <a href="dashboard.php" class="btn-demo">
                    Lihat Dashboard →
                </a>
            </div>
            <div class="dashboard-card">
                <div class="dashboard-icon">📱</div>
                <h3>Mobile Responsive</h3>
                <p>Akses dashboard Anda dari mana saja, kapan saja dengan tampilan yang optimal di semua device.</p>
                <a href="dashboard.php" class="btn-demo">
                    Lihat Dashboard →
                </a>
            </div>
            <div class="dashboard-card">
                <div class="dashboard-icon">🔒</div>
                <h3>Keamanan Enterprise</h3>
                <p>Data Anda terlindungi dengan enkripsi end-to-end, two-factor authentication, dan compliance standar industri.</p>
                <a href="dashboard.php" class="btn-demo">
                    Lihat Dashboard →
                </a>
            </div>
            <div class="dashboard-card">
                <div class="dashboard-icon">⚡</div>
                <h3>Performa Tinggi</h3>
                <p>Akses data dengan kecepatan tinggi, loading time minimal, dan pengalaman yang responsif di semua perangkat.</p>
                <a href="dashboard.php" class="btn-demo">
                    Lihat Dashboard →
                </a>
            </div>
            <div class="dashboard-card">
                <div class="dashboard-icon">🔗</div>
                <h3>Integrasi Mudah</h3>
                <p>Koneksi seamless dengan tools favorit Anda seperti accounting software, CRM, dan berbagai aplikasi bisnis.</p>
                <a href="dashboard.php" class="btn-demo">
                    Lihat Dashboard →
                </a>
            </div>
        </div>
    </section>

    <!-- Floating Navigate Button -->
    <div class="swipe-component">
        <button id="swipeBtnIndex" class="swipe-btn" aria-label="Ke halaman Tentang">
            <span>Ke Tentang</span>
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M5 12h14M12 5l7 7-7 7" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </button>
    </div>

    <!-- Footer -->
    <footer>
        <div class="footer-content">
            <div>
                <div class="footer-brand">
                    <div class="footer-brand-logo">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                            <path d="M9 11l3 3L22 4" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <span>Executive Dashboard</span>
                </div>
                <p class="footer-desc">Platform analitik bisnis profesional untuk membantu Anda membuat keputusan yang lebih baik berdasarkan data.</p>
            </div>
            <div class="footer-section">
                <h4>Produk</h4>
                <ul class="footer-links">
                    <li><a href="#">Dashboard</a></li>
                    <li><a href="#">Analytics</a></li>
                    <li><a href="#">Reports</a></li>
                    <li><a href="#">Integrasi</a></li>
                </ul>
            </div>
            <div class="footer-section">
                <h4>Perusahaan</h4>
                <ul class="footer-links">
                    <li><a href="#">Tentang Kami</a></li>
                    <li><a href="#">Karir</a></li>
                    <li><a href="#">Blog</a></li>
                    <li><a href="#">Kontak</a></li>
                </ul>
            </div>
            <div class="footer-section">
                <h4>Legal</h4>
                <ul class="footer-links">
                    <li><a href="#">Privacy Policy</a></li>
                    <li><a href="#">Terms of Service</a></li>
                    <li><a href="#">Security</a></li>
                    <li><a href="#">Compliance</a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; 2024 Executive Dashboard. All rights reserved.</p>
        </div>
    </footer>

    <script>
        // Navbar scroll effect
        window.addEventListener('scroll', () => {
            const navbar = document.querySelector('.navbar');
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });

        // Smooth scroll
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });

        // Swipe and Arrow Navigation (Index)
        (function() {
            const goTentang = () => { window.location.href = 'tentang.php'; };

            // Keyboard arrows
            window.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowLeft') {
                    goTentang();
                }
            });

            // Touch swipe
            let touchStartX = null;
            let touchStartY = null;
            const threshold = 60;

            window.addEventListener('touchstart', (e) => {
                const t = e.touches[0];
                touchStartX = t.clientX;
                touchStartY = t.clientY;
            }, { passive: true });

            window.addEventListener('touchend', (e) => {
                if (touchStartX === null || touchStartY === null) return;
                const t = e.changedTouches[0];
                const dx = t.clientX - touchStartX;
                const dy = t.clientY - touchStartY;

                if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > threshold) {
                    if (dx < 0) {
                        goTentang();
                    }
                }
                touchStartX = touchStartY = null;
            }, { passive: true });
        })();

        // Click handler for floating button
        const btnIndex = document.getElementById('swipeBtnIndex');
        if (btnIndex) {
            btnIndex.addEventListener('click', () => {
                window.location.href = 'tentang.php';
            });
        }

        // Intersection Observer for fade-in animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe dashboard cards
        document.querySelectorAll('.dashboard-card, .stat-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(30px)';
            card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(card);
        });
    </script>
</body>
</html>