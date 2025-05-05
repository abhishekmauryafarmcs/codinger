<?php
// Get any existing contest ID from the query string
$contest_id = isset($_GET['id']) ? '?id=' . intval($_GET['id']) : '';

// Set cache clearing headers
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Output a small script to clear localStorage and sessionStorage
echo '<!DOCTYPE html>
<html>
<head>
    <title>Clearing Cache...</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100vh;
        }
        .message {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            max-width: 600px;
            text-align: center;
        }
        .spinner {
            border: 4px solid rgba(0, 0, 0, 0.1);
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border-left-color: #3498db;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="message">
        <h2>Clearing Browser Cache</h2>
        <div class="spinner"></div>
        <p>Please wait, we\'re clearing your browser cache for the contest page...</p>
    </div>
    
    <script>
        // Clear browser caches
        try {
            // Clear localStorage
            localStorage.clear();
            
            // Clear sessionStorage
            sessionStorage.clear();
            
            // Clear application cache if available
            if (window.applicationCache) {
                window.applicationCache.swapCache();
            }
            
            // Force clear JS cache by appending timestamp to URL
            setTimeout(function() {
                window.location.href = "student/contest.php' . $contest_id . '&refresh_cache=1&t=" + Date.now();
            }, 1500);
        } catch (e) {
            console.error("Error clearing cache:", e);
            // Redirect anyway
            setTimeout(function() {
                window.location.href = "student/contest.php' . $contest_id . '&refresh_cache=1&t=" + Date.now();
            }, 1500);
        }
    </script>
</body>
</html>';
exit;
?> 