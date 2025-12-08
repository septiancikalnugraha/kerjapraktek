<?php require_once __DIR__ . '/config/config.php'; ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tentang Kami - Executive Dashboard</title>
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

        /* Navbar - Same as index */
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

        /* Hero About */
        .hero-about {
            padding: 140px 32px 80px;
            background: linear-gradient(180deg, #f7fafc 0%, #ffffff 100%);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .hero-about::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 30%, rgba(102, 126, 234, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(118, 75, 162, 0.08) 0%, transparent 50%);
            pointer-events: none;
        }

        .hero-about-content {
            max-width: 800px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .hero-about h1 {
            font-size: 3.5rem;
            font-weight: 800;
            margin-bottom: 24px;
            background: linear-gradient(135deg, #1a1a1a 0%, #4a5568 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -2px;
        }

        .hero-about .gradient-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-about p {
            font-size: 1.25rem;
            color: #4a5568;
            line-height: 1.8;
            margin-bottom: 20px;
        }

        /* Story Section */
        .story-section {
            padding: 100px 32px;
            background: #ffffff;
        }

        .story-container {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 80px;
            align-items: center;
        }

        .story-image {
            width: 100%;
            height: 500px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 24px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(102, 126, 234, 0.3);
        }

        .story-image::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 120px;
            height: 120px;
            background: rgba(255,255,255,0.2);
            border-radius: 24px;
            backdrop-filter: blur(10px);
        }

        .story-image::after {
            content: '📊';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 4rem;
        }

        .story-content h2 {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 24px;
            color: #1a1a1a;
            letter-spacing: -1px;
        }

        .story-content p {
            font-size: 1.1rem;
            color: #4a5568;
            line-height: 1.9;
            margin-bottom: 20px;
        }

        /* Mission Vision Section */
        .mission-vision {
            padding: 100px 32px;
            background: linear-gradient(180deg, #f7fafc 0%, #ffffff 100%);
        }

        .mv-container {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
        }

        .mv-card {
            background: white;
            padding: 60px 40px;
            border-radius: 24px;
            border: 1px solid #e2e8f0;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .mv-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        }

        .mv-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 50px rgba(0,0,0,0.12);
        }

        .mv-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            margin-bottom: 32px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }

        .mv-card h3 {
            font-size: 2rem;
            margin-bottom: 20px;
            color: #1a1a1a;
            font-weight: 700;
        }

        .mv-card p {
            color: #4a5568;
            line-height: 1.9;
            font-size: 1.05rem;
        }

        /* Values Section */
        .values-section {
            padding: 100px 32px;
            background: #ffffff;
        }

        .values-header {
            text-align: center;
            max-width: 700px;
            margin: 0 auto 80px;
        }

        .values-header h2 {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 20px;
            color: #1a1a1a;
            letter-spacing: -1px;
        }

        .values-header p {
            font-size: 1.15rem;
            color: #4a5568;
            line-height: 1.8;
        }

        .values-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 32px;
            max-width: 1280px;
            margin: 0 auto;
        }

        .value-card {
            background: linear-gradient(135deg, #f7fafc 0%, #ffffff 100%);
            padding: 40px 32px;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
            text-align: center;
            transition: all 0.3s ease;
        }

        .value-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 16px 40px rgba(0,0,0,0.1);
            border-color: #cbd5e0;
        }

        .value-icon {
            font-size: 3rem;
            margin-bottom: 20px;
        }

        .value-card h4 {
            font-size: 1.3rem;
            margin-bottom: 16px;
            color: #1a1a1a;
            font-weight: 700;
        }

        .value-card p {
            color: #4a5568;
            line-height: 1.8;
            font-size: 0.95rem;
        }

        /* Team Section */
        .team-section {
            padding: 100px 32px;
            background: linear-gradient(180deg, #f7fafc 0%, #ffffff 100%);
        }

        .team-header {
            text-align: center;
            max-width: 700px;
            margin: 0 auto 80px;
        }

        .team-header h2 {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 20px;
            color: #1a1a1a;
            letter-spacing: -1px;
        }

        .team-header p {
            font-size: 1.15rem;
            color: #4a5568;
            line-height: 1.8;
        }

        .team-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 32px;
            max-width: 1280px;
            margin: 0 auto;
        }

        .team-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }

        .team-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 50px rgba(0,0,0,0.12);
        }

        .team-photo {
            width: 100%;
            height: 280px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            position: relative;
            overflow: hidden;
        }

        .team-photo::after {
            content: '👤';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 5rem;
            opacity: 0.3;
        }

        .team-info {
            padding: 24px;
            text-align: center;
        }

        .team-info h4 {
            font-size: 1.2rem;
            margin-bottom: 8px;
            color: #1a1a1a;
            font-weight: 700;
        }

        .team-info p {
            color: #667eea;
            font-size: 0.95rem;
            font-weight: 500;
        }

        /* Stats Section */
        .about-stats {
            padding: 100px 32px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 60px;
            max-width: 1280px;
            margin: 0 auto;
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: 3.5rem;
            font-weight: 800;
            margin-bottom: 12px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .stat-label {
            font-size: 1.1rem;
            opacity: 0.9;
            font-weight: 500;
        }

        /* CTA Section */
        .cta-section {
            padding: 120px 32px;
            background: #ffffff;
            text-align: center;
        }

        .cta-content {
            max-width: 800px;
            margin: 0 auto;
        }

        .cta-content h2 {
            font-size: 3rem;
            font-weight: 800;
            margin-bottom: 24px;
            color: #1a1a1a;
            letter-spacing: -1px;
        }

        .cta-content p {
            font-size: 1.2rem;
            color: #4a5568;
            margin-bottom: 40px;
            line-height: 1.8;
        }

        .cta-buttons {
            display: flex;
            gap: 16px;
            justify-content: center;
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
            .story-container {
                grid-template-columns: 1fr;
                gap: 60px;
            }

            .mv-container {
                grid-template-columns: 1fr;
            }

            .values-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .team-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 40px;
            }

            .footer-content {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 768px) {
            .nav-links a:not(.btn-nav) {
                display: none;
            }

            .hero-about h1 {
                font-size: 2.5rem;
            }

            .hero-about p {
                font-size: 1.1rem;
            }

            .story-content h2,
            .mv-card h3,
            .values-header h2,
            .team-header h2,
            .cta-content h2 {
                font-size: 2rem;
            }

            .values-grid {
                grid-template-columns: 1fr;
            }

            .team-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 32px;
            }

            .cta-buttons {
                flex-direction: column;
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
            transition: all 0.3s ease;
        }
        .swipe-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 32px rgba(102,126,234,0.45);
        }
        .swipe-btn svg {
            width: 18px; 
            height: 18px;
        }
        .swipe-btn:active { 
            transform: translateY(1px); 
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar">
        <div class="nav-container">
            <a class="brand" href="index.php">
                <div class="brand-logo">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M9 11l3 3L22 4" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <span>Executive Dashboard</span>
            </a>
            <div class="nav-links">
                <a href="index.php#features">Home</a>
                <a href="tentang.php">Tentang</a>
                <a href="login.php">Login</a>
            </div>
        </div>
    </nav>

    <!-- Hero About -->
    <section class="hero-about">
        <div class="hero-about-content fade-in">
            <h1>Transformasi <span class="gradient-text">Data Menjadi Keputusan</span></h1>
            <p>Kami berkomitmen untuk membantu bisnis dari berbagai skala membuat keputusan yang lebih baik berdasarkan data yang akurat dan real-time.</p>
            <p>Sejak 2020, kami telah melayani lebih dari 10.000 pengguna aktif di seluruh Indonesia.</p>
        </div>
    </section>

    <!-- Story Section -->
    <section class="story-section">
        <div class="story-container">
            <div class="story-image"></div>
            <div class="story-content">
                <h2>Cerita Kami</h2>
                <p>Executive Dashboard lahir dari kebutuhan nyata para pebisnis untuk memiliki akses mudah terhadap data bisnis mereka. Kami melihat banyak perusahaan kesulitan mengintegrasikan berbagai sumber data dan menganalisisnya secara efektif.</p>
                <p>Dengan pengalaman lebih dari 10 tahun di bidang teknologi dan analitik bisnis, tim kami membangun platform yang tidak hanya powerful, tetapi juga mudah digunakan oleh siapa saja.</p>
                <p>Hari ini, kami bangga menjadi mitra terpercaya bagi ribuan bisnis dalam mengoptimalkan operasional dan meningkatkan profitabilitas mereka.</p>
            </div>
        </div>
    </section>

    <!-- Mission Vision Section -->
    <section class="mission-vision">
        <div class="mv-container">
            <div class="mv-card">
                <div class="mv-icon">🎯</div>
                <h3>Misi Kami</h3>
                <p>Memberdayakan setiap bisnis dengan tools analitik yang powerful namun mudah digunakan, sehingga mereka dapat membuat keputusan berdasarkan data yang akurat dan real-time, bukan hanya intuisi semata.</p>
            </div>
            <div class="mv-card">
                <div class="mv-icon">🔭</div>
                <h3>Visi Kami</h3>
                <p>Menjadi platform analitik bisnis terdepan di Indonesia yang mengubah cara perusahaan memahami dan menggunakan data mereka untuk pertumbuhan yang berkelanjutan dan sukses jangka panjang.</p>
            </div>
        </div>
    </section>

    <!-- Values Section -->
    <section class="values-section">
        <div class="values-header">
            <h2>Nilai-Nilai Kami</h2>
            <p>Prinsip yang memandu setiap keputusan dan tindakan kami</p>
        </div>
        <div class="values-grid">
            <div class="value-card">
                <div class="value-icon">💡</div>
                <h4>Inovasi</h4>
                <p>Kami terus berinovasi untuk menghadirkan fitur-fitur terbaru yang memenuhi kebutuhan bisnis modern.</p>
            </div>
            <div class="value-card">
                <div class="value-icon">🤝</div>
                <h4>Integritas</h4>
                <p>Transparansi dan kejujuran adalah fondasi dalam setiap hubungan dengan klien dan partner kami.</p>
            </div>
            <div class="value-card">
                <div class="value-icon">⚡</div>
                <h4>Kecepatan</h4>
                <p>Kami memahami bahwa waktu adalah aset berharga, maka kami prioritaskan efisiensi di setiap aspek.</p>
            </div>
            <div class="value-card">
                <div class="value-icon">🎓</div>
                <h4>Pembelajaran</h4>
                <p>Kami percaya pada continuous improvement dan selalu terbuka untuk feedback dari pengguna.</p>
            </div>
            <div class="value-card">
                <div class="value-icon">🌟</div>
                <h4>Keunggulan</h4>
                <p>Standar kualitas tinggi dalam setiap produk dan layanan yang kami berikan kepada pelanggan.</p>
            </div>
            <div class="value-card">
                <div class="value-icon">🔒</div>
                <h4>Keamanan</h4>
                <p>Melindungi data pelanggan adalah prioritas utama dengan standar security terbaik di industri.</p>
            </div>
        </div>
    </section>

    <!-- Team Section -->
    <section class="team-section">
        <div class="team-header">
            <h2>Tim Kami</h2>
            <p>Bertemu dengan orang-orang di balik Executive Dashboard</p>
        </div>
        <div class="team-grid">
            <div class="team-card">
                <div class="team-photo"></div>
                <div class="team-info">
                    <h4>Ahmad Rizky</h4>
                    <p>CEO & Founder</p>
                </div>
            </div>
            <div class="team-card">
                <div class="team-photo"></div>
                <div class="team-info">
                    <h4>Siti Nurhaliza</h4>
                    <p>Chief Technology Officer</p>
                </div>
            </div>
            <div class="team-card">
                <div class="team-photo"></div>
                <div class="team-info">
                    <h4>Budi Santoso</h4>
                    <p>Head of Product</p>
                </div>
            </div>
            <div class="team-card">
                <div class="team-photo"></div>
                <div class="team-info">
                    <h4>Diana Putri</h4>
                    <p>Head of Customer Success</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Floating Navigate Button -->
    <div class="swipe-component">
        <button id="swipeBtnTentang" class="swipe-btn" aria-label="Kembali ke halaman Index">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M19 12H5M12 19l-7-7 7-7" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
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
                    <li><a href="dashboard.php">Dashboard</a></li>
                    <li><a href="#">Analytics</a></li>
                    <li><a href="#">Reports</a></li>
                    <li><a href="#">Integrasi</a></li>
                </ul>
            </div>
            <div class="footer-section">
                <h4>Perusahaan</h4>
                <ul class="footer-links">
                    <li><a href="tentang.php">Tentang Kami</a></li>
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

        // Intersection Observer for fade-in animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -100px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Animate sections on scroll
        document.querySelectorAll('.story-section, .mission-vision, .values-section, .team-section, .about-stats, .cta-section').forEach(section => {
            section.style.opacity = '0';
            section.style.transform = 'translateY(30px)';
            section.style.transition = 'opacity 0.8s ease, transform 0.8s ease';
            observer.observe(section);
        });

        // Add stagger effect to cards
        document.querySelectorAll('.value-card, .team-card').forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = `opacity 0.6s ease ${index * 0.1}s, transform 0.6s ease ${index * 0.1}s`;
            
            const cardObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, { threshold: 0.1 });
            
            cardObserver.observe(card);
        });

        // Counter animation for stats
        const animateCounter = (element, target) => {
            const duration = 2000;
            const step = (target / duration) * 16;
            let current = 0;
            
            const isPercentage = target === 99.9;
            const is24x7 = element.textContent.includes('24/7');
            
            if (is24x7) return; // Skip animation for 24/7
            
            const timer = setInterval(() => {
                current += step;
                if (current >= target) {
                    element.textContent = isPercentage ? '99.9%' : (target >= 1000 ? '10K+' : '50+');
                    clearInterval(timer);
                } else {
                    if (isPercentage) {
                        element.textContent = current.toFixed(1) + '%';
                    } else if (target >= 1000) {
                        element.textContent = Math.floor(current / 100) / 10 + 'K+';
                    } else {
                        element.textContent = Math.floor(current) + '+';
                    }
                }
            }, 16);
        };

        // Observe stats section for counter animation
        const statsObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const statNumbers = entry.target.querySelectorAll('.stat-number');
                    statNumbers.forEach(stat => {
                        const text = stat.textContent;
                        if (text.includes('99.9%')) {
                            animateCounter(stat, 99.9);
                        } else if (text.includes('10K+')) {
                            animateCounter(stat, 10000);
                        } else if (text.includes('50+')) {
                            animateCounter(stat, 50);
                        }
                    });
                    statsObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.5 });

        const statsSection = document.querySelector('.about-stats');
        if (statsSection) {
            statsObserver.observe(statsSection);
        }

        // Swipe and Arrow Navigation (Tentang) - Navigate back to index
        (function() {
            const goIndex = () => { window.location.href = 'index.php'; };

            // Keyboard arrows
            window.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowRight') {
                    goIndex();
                }
                // ArrowLeft intentionally does nothing on tentang page
            });

            // Touch swipe
            let touchStartX = null;
            let touchStartY = null;
            const threshold = 60; // px

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
                    if (dx > 0) {
                        goIndex(); // swipe right
                    }
                    // swipe left intentionally does nothing
                }
                touchStartX = touchStartY = null;
            }, { passive: true });
        })();

        // Click handler for floating button
        const btnTentang = document.getElementById('swipeBtnTentang');
        if (btnTentang) {
            btnTentang.addEventListener('click', () => {
                window.location.href = 'index.php';
            });
        }
    </script>
</body>
</html>