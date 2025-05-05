<?php
session_start();
require_once 'config/db.php';

// Check if we're viewing a specific contest
$viewing_contest = isset($_GET['contest_id']);

if ($viewing_contest) {
    $contest_id = $_GET['contest_id'];
    $_SESSION['current_contest_id'] = $contest_id; // Store contest ID in session

    // Get contest details
    $stmt = $conn->prepare("
        SELECT * FROM contests 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $contest_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $contest = $result->fetch_assoc();
    $stmt->close();

    if (!$contest) {
        header("Location: index.php");
        exit();
    }

    // Check contest status
    $now = new DateTime();
    $start_time = new DateTime($contest['start_time']);
    $end_time = new DateTime($contest['end_time']);

    if ($now < $start_time) {
        $contest_status = 'upcoming';
    } elseif ($now > $end_time) {
        $contest_status = 'completed';
    } else {
        $contest_status = 'ongoing';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $viewing_contest ? htmlspecialchars($contest['title']) . ' - ' : ''; ?>Codinger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;600&display=swap');
        
        body {
            margin: 0;
            padding: 0;
            height: 100vh;
            background-color: #060606;
            overflow: hidden;
            font-family: 'Fira Code', monospace;
        }
        .hero-section {
            position: relative;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #060606;
        }
        #squares-background {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
        }
        #squares-background canvas {
            position: absolute;
            top: 0;
            left: 0;
            width: 100% !important;
            height: 100% !important;
            background: #060606;
        }
        .hero-content {
            position: relative;
            z-index: 2;
            color: white;
            text-align: center;
        }
        .hero-section h1 {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            text-shadow: 0 0 10px rgba(13, 110, 253, 0.5);
            opacity: 0;
            transform: translateY(-20px);
            animation: fadeInDown 0.8s ease forwards, typing 2.5s steps(30, end) 0.8s forwards;
            white-space: nowrap;
            overflow: hidden;
            width: 0;
        }
        .hero-section h1::before {
            content: "<";
            color: #0d6efd;
            margin-right: 0.5rem;
            opacity: 0.8;
        }
        .hero-section h1::after {
            content: "/>";
            color: #0d6efd;
            margin-left: 0.5rem;
            opacity: 0.8;
        }
        .hero-section p {
            font-size: 1.5rem;
            margin-bottom: 2.5rem;
            color: #a8b2d1;
            opacity: 0;
            transform: translateY(20px);
            animation: fadeInUp 0.8s ease forwards 0.3s;
        }
        .hero-section p::before {
            content: "// ";
            color: #0d6efd;
            opacity: 0.8;
        }
        .btn-group {
            display: flex;
            gap: 1.5rem;
            justify-content: center;
            opacity: 0;
            transform: translateY(20px);
            animation: fadeInUp 0.8s ease forwards 0.6s;
        }
        .btn-lg {
            padding: 1rem 3rem;
            font-size: 1.2rem;
            border-radius: 50px;
            transition: all 0.3s ease;
            font-family: 'Fira Code', monospace;
            position: relative;
            overflow: hidden;
        }
        .btn-primary {
            background: transparent;
            border: 2px solid #0d6efd;
            color: #0d6efd;
            position: relative;
            z-index: 1;
        }
        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 0;
            height: 100%;
            background: #0d6efd;
            transition: all 0.5s ease;
            z-index: -1;
        }
        .btn-primary:hover::before {
            width: 100%;
        }
        .btn-primary:hover {
            color: white;
            transform: translateY(-2px);
        }
        .btn-outline-light {
            background: transparent;
            border: 2px solid rgba(255, 255, 255, 0.5);
            position: relative;
            z-index: 1;
        }
        .btn-outline-light::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 0;
            height: 100%;
            background: rgba(255, 255, 255, 0.1);
            transition: all 0.5s ease;
            z-index: -1;
        }
        .btn-outline-light:hover::before {
            width: 100%;
        }
        .btn-outline-light:hover {
            border-color: white;
            transform: translateY(-2px);
        }
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .cursor {
            display: inline-block;
            width: 2px;
            height: 1.2em;
            background-color: #0d6efd;
            margin-left: 5px;
            animation: blink 1s infinite;
            vertical-align: middle;
        }
        @keyframes blink {
            50% { opacity: 0; }
        }
        @keyframes typing {
            from { 
                width: 0;
            }
            to { 
                width: 100%;
            }
        }
        @keyframes typing-cursor {
            from { opacity: 1; }
            to { opacity: 0; }
        }
        .btn-primary .btn-hover-text {
            display: none;
            color: white;
        }
        .btn-primary .btn-text {
            display: inline-block;
        }
        .btn-primary:hover .btn-hover-text {
            display: inline-block;
        }
        .btn-primary:hover .btn-text {
            display: none;
        }
        .btn-primary {
            min-width: 200px;
        }

        /* Responsive Styles */
        @media (max-width: 768px) {
            .hero-section {
                min-height: 100vh;
                height: auto;
                padding: 2rem 1rem;
            }
            .hero-section h1 {
                font-size: 2rem;
                margin-bottom: 1rem;
                width: 100%;
                white-space: normal;
                overflow: visible;
                animation: fadeInDown 0.8s ease forwards;
            }
            .hero-section h1::before,
            .hero-section h1::after {
                display: none;
            }
            .hero-section p {
                font-size: 1.1rem;
                margin-bottom: 2rem;
                padding: 0;
                white-space: normal;
            }
            .btn-group {
                flex-direction: column;
                width: 100%;
                gap: 1rem;
            }
            .btn-lg {
                width: 100%;
                max-width: 300px;
                margin: 0 auto;
                padding: 0.8rem 1.5rem;
                font-size: 1rem;
            }
            .btn-primary {
                min-width: unset;
            }
            .hero-content {
                width: 100%;
                padding: 0 1rem;
            }
            .cursor {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .hero-section {
                padding: 1.5rem 1rem;
            }
            .hero-section h1 {
                font-size: 1.8rem;
                line-height: 1.3;
                margin-bottom: 1rem;
            }
            .hero-section p {
                font-size: 1rem;
                line-height: 1.4;
                margin-bottom: 1.5rem;
            }
            .hero-section p::before {
                display: none;
            }
            .btn-lg {
                padding: 0.7rem 1.2rem;
                font-size: 0.9rem;
            }
        }

        @media (max-height: 600px) {
            .hero-section {
                height: auto;
                min-height: 100vh;
                padding: 2rem 1rem;
            }
            .hero-content {
                padding: 1rem;
            }
            .hero-section h1 {
                font-size: 1.8rem;
                margin-bottom: 0.8rem;
            }
            .hero-section p {
                font-size: 1rem;
                margin-bottom: 1.2rem;
            }
            .btn-lg {
                padding: 0.6rem 1.2rem;
                font-size: 0.9rem;
            }
        }

        /* Remove typing animation on mobile */
        @media (max-width: 768px) {
            .hero-section h1 {
                width: 100% !important;
                animation: fadeInDown 0.8s ease forwards !important;
            }
        }
    </style>
    <?php if ($viewing_contest): ?>
    <style>
        #timer {
            position: fixed;
            top: 20px;
            left: 20px;
            background-color: #0d6efd;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            font-weight: bold;
            z-index: 9999;
        }
    </style>
    <?php if ($contest_status === 'ongoing'): ?>
    <script>
        let isContestActive = true;

        // Timer function
        function startTimer(endTime) {
            function updateTimer() {
                if (!isContestActive) return;

                const now = new Date().getTime();
                const timeLeft = endTime - now;

                if (timeLeft <= 0) {
                    document.getElementById('timer').innerHTML = "Time's Up!";
                    submitContest();
                    return;
                }

                const hours = Math.floor((timeLeft % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);

                document.getElementById('timer').innerHTML = 
                    `Time Left: ${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            }

            // Update timer every second
            updateTimer();
            setInterval(updateTimer, 1000);
        }

        function submitContest() {
            if (!isContestActive) return;
            
            isContestActive = false;
            console.log('Submitting contest');
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'submit_contest.php';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'auto_submit';
            input.value = '1';
            
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }

        // Initialize when document is ready
        document.addEventListener('DOMContentLoaded', function() {
            // Start timer
            const endTime = new Date('<?php echo $contest['end_time']; ?>').getTime();
            startTimer(endTime);
        });
    </script>
    <?php endif; ?>
    <?php endif; ?>
</head>
<body>
    <?php if ($viewing_contest && $contest_status === 'ongoing'): ?>
        <div id="timer">Loading timer...</div>
    <?php endif; ?>

    <?php if ($viewing_contest): ?>
        <div class="container py-4">
            <h1 class="mb-4"><?php echo htmlspecialchars($contest['title']); ?></h1>
            
            <?php if ($contest_status === 'ongoing'): ?>
                <div class="alert alert-info">
                    Contest is in progress.
                </div>
            <?php elseif ($contest_status === 'upcoming'): ?>
                <div class="alert alert-warning">
                    Contest has not started yet. Start time: <?php echo date('M d, Y h:i A', strtotime($contest['start_time'])); ?>
                </div>
            <?php else: ?>
                <div class="alert alert-secondary">
                    Contest has ended.
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="hero-section">
            <div id="squares-background"></div>
            <div class="hero-content">
                <h1>Welcome to Codinger<span class="cursor"></span></h1>
                <p>The Best Coding Platform Created By Abhishek Maurya</p>
                <?php if(isset($_SESSION['user_id'])): ?>
                    <div class="btn-group">
                        <a href="<?php echo $_SESSION['role'] === 'admin' ? 'admin/index.php' : 'student/dashboard.php'; ?>" class="btn btn-primary btn-lg">
                            <span class="btn-text">Dashboard</span>
                            <span class="btn-hover-text">&lt;Go to Dashboard/&gt;</span>
                        </a>
                    </div>
                <?php else: ?>
                    <div class="btn-group">
                        <a href="register.php" class="btn btn-primary btn-lg">Get Started</a>
                        <a href="login.php" class="btn btn-outline-light btn-lg">Sign In</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/squares-background.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.getElementById('squares-background');
            if (container) {
                container.innerHTML = '';
                
                const squares = new SquaresBackground({
                    direction: 'diagonal',
                    speed: 0.2,
                    squareSize: 35,
                    borderColor: '#333',
                    hoverFillColor: '#444'
                });
                squares.mount(container);

                window.dispatchEvent(new Event('resize'));
            }
        });
    </script>
</body>
</html> 