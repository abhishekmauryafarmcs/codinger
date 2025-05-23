<?php
// LNCT Branded Landing Page
session_start(); // Start the session
$isLoggedIn = isset($_SESSION['student']['user_id']); // Check if student is logged in
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LNCT Group of Colleges - Central India's No.1 Engineering Institution</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;600&family=Montserrat:wght@700;400&display=swap" rel="stylesheet">
    <style>
        /* Base styles from index.php */
        body {
            background: #f5f7fa;
            font-family: 'Montserrat', 'Fira Code', monospace, Arial, sans-serif;
            color: #222;
        }
        .lnct-navbar {
            background: #1a1a1a; /* Matte black color */
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            z-index: 1000;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .lnct-navbar .navbar-brand img {
            height: 48px;
        }
        /* Update navbar text color for better visibility on matte black background */
        .lnct-navbar .nav-link,
        .lnct-navbar .navbar-brand,
        .lnct-navbar .navbar-text {
            color: rgba(255,255,255,0.9) !important;
        }
        .lnct-navbar .nav-link:hover {
            color: #fff !important;
        }
        .hero-section {
            background: linear-gradient(120deg,rgb(57, 8, 81) 30%, #ff914d 100%);
            color: #fff;
            padding: 4rem 0 2rem 0;
            position: relative;
            overflow: hidden;
            margin-bottom: 30px;
        }
        .hero-section .hero-img-container {
            perspective: 1000px;
            max-width: 350px;
            margin: 0 auto;
        }
        .hero-section .hero-img {
            max-width: 100%;
            border-radius: 1rem;
            box-shadow: 0 8px 32px rgba(13,110,253,0.15);
            transition: transform 0.6s;
            transform-style: preserve-3d;
            backface-visibility: hidden;
        }
        .hero-section .hero-img-front {
            position: relative;
            z-index: 2;
        }
        
        /* Incorporated styles from style.css */
        .hero-section h1 {
            font-weight: 700;
            margin-bottom: 20px;
        }
        
        .card {
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-5px);
        }
        
        .card-title {
            color: #4a5568;
            font-weight: 600;
        }
        
        .navbar-brand {
            font-weight: 700;
            font-size: 1.5rem;
        }
        
        .btn-primary {
            background-color: #4c1d95;
            border-color: #4c1d95;
        }
        
        .btn-primary:hover {
            background-color: #5b21b6;
            border-color: #5b21b6;
        }
        
        .nav-link {
            font-weight: 500;
        }
        
        /* Form Styles */
        .form-container {
            max-width: 500px;
            margin: 40px auto;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .form-container h2 {
            text-align: center;
            color: #4a5568;
            margin-bottom: 30px;
        }
        
        /* Dashboard Styles */
        .dashboard-stats {
            background: #f7fafc;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        
        .stats-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        /* Contest List Styles */
        .contest-card {
            margin-bottom: 20px;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .contest-card .status-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
        }
        
        /* Problem Page Styles */
        .problem-description {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .code-editor {
            border-radius: 8px;
            overflow: hidden;
            margin-top: 20px;
        }
        
        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .hero-section {
                padding: 60px 0;
            }
            
            .form-container {
                margin: 20px;
            }
        }
        .hero-section .hero-img-back {
            position: absolute;
            top: 0;
            left: 0;
            transform: rotateY(180deg);
        }
        .hero-section .hero-img-container:hover .hero-img-front {
            transform: rotateY(180deg);
        }
        .hero-section .hero-img-container:hover .hero-img-back {
            transform: rotateY(0);
        }
        .hero-section h1 {
            font-size: 2.8rem;
            font-weight: 700;
            margin-bottom: 1rem;
            letter-spacing: -1px;
        }
        .hero-section p {
            font-size: 1.25rem;
            color: #e3e9f7;
            margin-bottom: 2rem;
        }
        .stats-section {
            background: #eeebfb;
            padding: 3rem 0 2rem 0;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 2rem;
            margin: 0 1rem;
        }
        .stat-item {
            background: #f0f6ff;
            border-radius: 1rem;
            padding: 2rem 1rem;
            text-align: center;
            box-shadow: 0 2px 8px rgba(13,110,253,0.04);
            transition: transform 0.2s;
        }
        .stat-item:hover {
            transform: translateY(-6px) scale(1.03);
        }
        .stat-number {
            font-size: 2.2rem;
            font-weight: 700;
            color: #0d6efd;
        }
        .stat-label {
            color: #222;
            font-size: 1.05rem;
            margin-top: 0.5rem;
        }
        .why-lnct {
            background: #f5f7fa;
            padding: 3rem 0 2rem 0;
        }
        .why-lnct h2 {
            text-align: center;
            font-size: 2.2rem;
            font-weight: 700;
            color: #0d6efd;
            margin-bottom: 2.5rem;
        }
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
            margin: 0 1rem;
        }
        .feature-item {
            background: #fff;
            border-radius: 1rem;
            box-shadow: 0 2px 8px rgba(13,110,253,0.04);
            padding: 2rem 1rem 1.5rem 1rem;
            text-align: center;
            transition: box-shadow 0.2s, transform 0.2s;
        }
        .feature-item:hover {
            box-shadow: 0 8px 32px rgba(13,110,253,0.10);
            transform: translateY(-6px) scale(1.03);
        }
        .feature-image {
                width: 100%;
            max-width: 180px;
            height: 120px;
            object-fit: cover;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 8px rgba(13,110,253,0.07);
        }
        .feature-item h3 {
            color: #0d6efd;
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0.7rem;
        }
        .feature-item p {
            color: #444;
            font-size: 1rem;
        }
        @media (max-width: 768px) {
            .hero-section {
                text-align: center;
            }
            .hero-section .hero-img {
                margin: 2rem auto 0 auto;
            }
            .stats-grid, .features-grid {
                grid-template-columns: 1fr;
                margin: 0;
            }
        }

        /* Rocket Animation Styles */
        .rocket-container {
            position: fixed;
            top: 80px;
            right: -100px;
            z-index: 9999;
            opacity: 0;
            transition: opacity 0.5s ease;
            pointer-events: none;
        }

        .rocket {
            width: 40px;
            height: 60px;
            background: linear-gradient(45deg, #ff4757, #ff6b81);
            clip-path: polygon(50% 0%, 100% 100%, 0% 100%);
            position: relative;
            transform: rotate(45deg);
            filter: drop-shadow(0 0 10px rgba(255, 71, 87, 0.5));
        }

        .rocket::before {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 20px;
            height: 20px;
            background: #ff4757;
            border-radius: 50%;
        }

        .smoke {
            position: absolute;
            bottom: -30px;
            left: 50%;
            transform: translateX(-50%);
            width: 8px;
            height: 20px;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 50%;
            filter: blur(4px);
            animation: smoke 0.5s infinite;
        }

        .smoke::before,
        .smoke::after {
            content: '';
            position: absolute;
            width: 8px;
            height: 20px;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 50%;
            filter: blur(4px);
        }

        .smoke::before {
            left: -15px;
            animation: smoke 0.5s infinite 0.2s;
        }

        .smoke::after {
            right: -15px;
            animation: smoke 0.5s infinite 0.4s;
        }

        @keyframes smoke {
            0% { transform: translateY(0) scale(1); opacity: 0.8; }
            100% { transform: translateY(-20px) scale(2); opacity: 0; }
        }

        .rocket-fly {
            animation: rocketFly 2s forwards;
        }

        @keyframes rocketFly {
            0% { transform: translateX(0) rotate(45deg); }
            100% { transform: translateX(-100vw) rotate(45deg); }
        }

        .navbar-brand {
            position: relative;
            transition: all 0.5s ease;
        }

        .navbar-brand.collision {
            animation: collision 0.5s ease;
        }

        @keyframes collision {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }

        .login-btn {
            opacity: 0;
            transform: scale(0);
            transition: all 0.5s ease;
        }

        .login-btn.show {
            opacity: 1;
            transform: scale(1);
        }
    </style>
</head>
<body>
    <!-- Rocket Container -->
    <div class="rocket-container" id="rocketContainer">
        <div class="rocket" id="rocket">
            <div class="smoke"></div>
        </div>
    </div>

    <!-- Navbar -->
    <nav class="navbar lnct-navbar navbar-expand-lg navbar-light sticky-top">
        <div class="container">
            <a class="navbar-brand" href="#" id="brandLogo">
                <img src="images/LNCT-Logo.png" alt="LNCT Logo">
            </a>
            <?php if (!$isLoggedIn): ?>
            <span class="navbar-text ms-2 fw-bold text-primary" id="brandText">LNCT Group of Colleges</span>
            <?php endif; ?>
            <?php if ($isLoggedIn): ?>
                <a href="student/dashboard.php" class="btn btn-success login-btn ms-auto" id="loginBtn">Dashboard</a>
            <?php else: ?>
                <a href="login.php" class="btn btn-primary login-btn ms-auto" id="loginBtn" style="display: none;">Student Login</a>
            <?php endif; ?>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <h1>Central India's No.1 Engineering Institution</h1>
                    <p>Synonymous with Excellence in Higher Education. 32+ Years of Academic Brilliance, Top Placements, and World-Class Infrastructure.</p>
                    <a href="#why-lnct" class="btn btn-light btn-lg text-primary fw-bold shadow-sm">Why LNCT?</a>
                </div>
                <div class="col-lg-6 text-center">
                    <div class="hero-img-container">
                        <img src="images/LNCT-Slider1-768x768.jpeg" alt="LNCT Campus" class="hero-img hero-img-front">
                        <img src="images/LNCT-Slider2-768x768.jpeg" alt="LNCT Campus" class="hero-img hero-img-back">
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="stats-section">
        <div class="container">
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-number count-up" data-target="1.12" data-prefix="₹" data-suffix=" Cr.">0</div>
                    <div class="stat-label">Offers For 2024 Batch</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number count-up" data-target="1600" data-suffix="+">0</div>
                    <div class="stat-label">Total Offers in last 5 Years</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number count-up" data-target="9877" data-suffix="+">0</div>
                    <div class="stat-label">Offers 10 Lakhs & Above</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number count-up" data-target="171">0</div>
                    <div class="stat-label">NIRF All India Rank</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number count-up" data-target="500" data-suffix="+">0</div>
                    <div class="stat-label">Top Recruiters</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number count-up" data-target="191" data-suffix="+">0</div>
                    <div class="stat-label">Patent Publications</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number count-up" data-target="211" data-suffix="+">0</div>
                    <div class="stat-label">Ph.D Faculties</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Why LNCT Section -->
    <section class="why-lnct" id="why-lnct">
        <div class="container">
            <h2>WHY LNCT?</h2>
            <div class="features-grid">
                <div class="feature-item">
                    <img src="images/LNCT-Slider1-768x768.jpeg" alt="Infrastructure" class="feature-image">
                    <h3>State-of-the-Art Infrastructure</h3>
                    <p>Best Infrastructure with State-of-the-Art laboratories, Latest Machines and Smart Classrooms with A/V facility.</p>
                </div>
                <div class="feature-item">
                    <img src="images/Side-Cover-1024x1024.jpeg" alt="Sports Facilities" class="feature-image">
                    <h3>Excellence in Sports</h3>
                    <p>Producing National & International Players in Drop Row Ball, Base Ball, Throw Ball, Kabbaddi etc.</p>
                </div>
                <div class="feature-item">
                    <img src="images/32YRS-03-min-1024x1024.png" alt="Academic Excellence" class="feature-image">
                    <h3>32+ Years of Excellence</h3>
                    <p>Highest Chancellor's Awards and Highest Placements in Central India.</p>
                </div>
                <div class="feature-item">
                    <img src="images/NBA-LOGO-2.png" alt="NBA Accreditation" class="feature-image">
                    <h3>NBA Accreditation</h3>
                    <p>NBA Accreditation & 188+ Ph.D Faculties for Academic Excellence. 191+ Patents filed and published in last 3 years.</p>
                </div>
                <div class="feature-item">
                    <img src="images/Placment_2022.webp" alt="Placements" class="feature-image">
                    <h3>Unbeatable Placements</h3>
                    <p>Unbeatable Record Placement of Central India with 1800+ Offers by 40 Companies Closed Campus only for LNCT Group of Colleges.</p>
                </div>
                <div class="feature-item">
                    <img src="images/LNCT-Slider2-768x768.jpeg" alt="Campus Life" class="feature-image">
                    <h3>World-Class Campus</h3>
                    <p>Lush Green Campus with Boys & Girls Hostels, 24hr Security, Dispensary, Bank ATMs, GYM, Indoor and Outdoor Fields.</p>
                </div>
                <div class="feature-item">
                    <a href="https://www.linkedin.com/posts/abhishek-maurya-707106158_futureofwork-employability-careerdevelopment-activity-7261072249518968833-N96d?utm_source=share&utm_medium=member_desktop&rcm=ACoAACW_OHcB17oGkEcPXYgbVqM4kaT8hBdwscg" target="_blank" rel="noopener noreferrer">
                        <img src="images/award image.jpeg" alt="Innovation Award" class="feature-image">
                    </a>
                    <h3>Innovation Award</h3>
                    <p>LNCT provides students a platform to develop ideas into reality through Idealab. Students participate and win many hackathons, showcasing their innovation and creativity.</p>
                    </div>
            </div>
        </div>
    </section>

    <footer class="text-center py-4 bg-white mt-4 border-top">
        <img src="images/lnct-logo-footer-300x106-1.png" alt="LNCT Footer Logo" style="height:40px;">
        <div class="mt-2 text-muted" style="font-size:0.95rem;">&copy; <?php echo date('Y'); ?> LNCT Group of Colleges. All rights reserved.</div>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Count up animation for stats
        document.addEventListener('DOMContentLoaded', function() {
        function animateCountUp(el, target, duration, prefix = '', suffix = '', decimals = 0) {
            let start = 0;
            let startTimestamp = null;
            const step = (timestamp) => {
                if (!startTimestamp) startTimestamp = timestamp;
                const progress = Math.min((timestamp - startTimestamp) / duration, 1);
                let value = start + (target - start) * progress;
                value = decimals > 0 ? value.toFixed(decimals) : Math.floor(value);
                el.textContent = `${prefix}${value}${suffix}`;
                if (progress < 1) {
                    window.requestAnimationFrame(step);
                } else {
                    el.textContent = `${prefix}${target}${suffix}`;
                }
            };
            window.requestAnimationFrame(step);
        }
        document.querySelectorAll('.count-up').forEach(function(el) {
            const target = parseFloat(el.getAttribute('data-target'));
            const prefix = el.getAttribute('data-prefix') || '';
            const suffix = el.getAttribute('data-suffix') || '';
            const decimals = (target % 1 !== 0) ? 2 : 0;
            animateCountUp(el, target, 1500, prefix, suffix, decimals);
        });
        });

        document.addEventListener('DOMContentLoaded', function() {
            // Only run the rocket animation if user is not logged in
            <?php if (!$isLoggedIn): ?>
            setTimeout(() => {
                const rocketContainer = document.getElementById('rocketContainer');
                const rocket = document.getElementById('rocket');
                const brandLogo = document.getElementById('brandLogo');
                const brandText = document.getElementById('brandText');
                const loginBtn = document.getElementById('loginBtn');

                // Show rocket
                rocketContainer.style.opacity = '1';
                rocket.classList.add('rocket-fly');

                // After rocket animation
                setTimeout(() => {
                    // Collision effect
                    brandLogo.classList.add('collision');
                    brandText.classList.add('collision');

                    // Hide rocket
                    rocketContainer.style.opacity = '0';

                    // Remove brand text after collision animation
                    setTimeout(() => {
                        brandText.style.display = 'none';
                    }, 500);

                    // Show login button
                    loginBtn.style.display = 'block';
                    setTimeout(() => {
                        loginBtn.classList.add('show');
                    }, 100);
                }, 2000);
            }, 1500);
            <?php else: ?>
            // If logged in, show the dashboard button immediately
            const loginBtn = document.getElementById('loginBtn');
            loginBtn.style.display = 'block';
            loginBtn.classList.add('show');
            <?php endif; ?>
        });
    </script>
</body>
</html> 