<?php
// Force browser to clear cache for this page
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past

require_once '../config/session.php';

// Force reload with cache clearing if the refresh parameter is set
if (isset($_GET['refresh_cache']) && $_GET['refresh_cache'] === '1') {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
}

// Check if user is logged in and is a student
if (!isStudentSessionValid()) {
    // Check if we still have user ID but session was invalidated (another login)
    if (isset($_SESSION['student']['user_id']) && !isset($_SESSION['student']['validated'])) {
        header("Location: ../login.php?error=another_login");
    } else {
        header("Location: ../login.php?error=session_expired");
    }
    exit();
}

// Mark this session as validated for this page load
$_SESSION['student']['validated'] = true;

require_once '../config/db.php';

// Check if contest ID is provided
if (!isset($_GET['id'])) {
    header("Location: dashboard.php");
    exit();
}

$contest_id = (int)$_GET['id'];
$user_id = $_SESSION['student']['user_id'];

// Check if the student has previously exited this contest
if (isset($_SESSION['student']['exited_contests'][$contest_id]) && $_SESSION['student']['exited_contests'][$contest_id] === true) {
    header("Location: dashboard.php?error=contest_exited");
    exit();
}

// Check if the student has been permanently terminated from this contest
if (isset($_SESSION['student']['terminated_contests'][$contest_id])) {
    header("Location: dashboard.php?error=contest_terminated&reason=" . urlencode($_SESSION['student']['terminated_contests'][$contest_id]['reason']));
    exit();
}

// If not in session, check the database
$stmt = $conn->prepare("
    SELECT *, is_permanent, reason FROM contest_exits 
    WHERE user_id = ? AND contest_id = ?
");
$stmt->bind_param("ii", $user_id, $contest_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $exit_data = $result->fetch_assoc();
    
    // Mark in session for future checks
    if (!isset($_SESSION['student']['exited_contests'])) {
        $_SESSION['student']['exited_contests'] = array();
    }
    $_SESSION['student']['exited_contests'][$contest_id] = true;
    
    // If this was a permanent termination, also mark it in the terminated_contests session variable
    if ($exit_data['is_permanent']) {
        if (!isset($_SESSION['student']['terminated_contests'])) {
            $_SESSION['student']['terminated_contests'] = array();
        }
        $_SESSION['student']['terminated_contests'][$contest_id] = [
            'reason' => $exit_data['reason'],
            'time' => strtotime($exit_data['exit_time'])
        ];
        
        header("Location: dashboard.php?error=contest_terminated&reason=" . urlencode($exit_data['reason']));
    } else {
        header("Location: dashboard.php?error=contest_exited");
    }
    exit();
}

// Get contest details
$stmt = $conn->prepare("
    SELECT *, 
    CASE
        WHEN start_time <= NOW() AND end_time > NOW() THEN 'active'
        WHEN start_time > NOW() THEN 'upcoming'
        ELSE 'completed'
    END as contest_status
    FROM contests 
    WHERE id = ?
");
$stmt->bind_param("i", $contest_id);
$stmt->execute();
$result = $stmt->get_result();
$contest = $result->fetch_assoc();

// Debug contest status
error_log("Contest ID: " . $contest_id . ", Status: " . ($contest ? $contest['contest_status'] : 'not found'));

// If contest doesn't exist, redirect to dashboard
if (!$contest) {
    header("Location: dashboard.php?error=invalid_contest");
    exit();
}

// Check if this is a private contest and verify enrollment
if ($contest['type'] === 'private') {
    // Check if the user has verified enrollment for this contest
    if (!isset($_SESSION['student']['verified_contests'][$contest_id])) {
        // Get the user's enrollment number
        $stmt = $conn->prepare("SELECT enrollment_number FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user_result = $stmt->get_result();
        $user = $user_result->fetch_assoc();
        
        if ($user) {
            // Check if the user's enrollment number is in the contest's approved list
            $stmt = $conn->prepare("
                SELECT * FROM contest_enrollments 
                WHERE contest_id = ? AND enrollment_number = ?
            ");
            $stmt->bind_param("is", $contest_id, $user['enrollment_number']);
            $stmt->execute();
            $enrollment_result = $stmt->get_result();
            
            if ($enrollment_result->num_rows === 0) {
                // No matching enrollment found
                header("Location: dashboard.php?error=enrollment_required&contest_id=" . $contest_id);
                exit();
            } else {
                // Store verification in session for future access
                if (!isset($_SESSION['student']['verified_contests'])) {
                    $_SESSION['student']['verified_contests'] = array();
                }
                $_SESSION['student']['verified_contests'][$contest_id] = $user['enrollment_number'];
            }
        } else {
            // User record not found (shouldn't happen)
            header("Location: dashboard.php?error=user_not_found");
            exit();
        }
    }
}

// Store contest ID in session for violation tracking
$_SESSION['student']['current_contest_id'] = $contest_id;

// Check contest status and handle accordingly
if ($contest['contest_status'] === 'upcoming') {
    header("Location: dashboard.php?error=contest_not_started");
    exit();
} elseif ($contest['contest_status'] === 'completed') {
    header("Location: dashboard.php?error=contest_ended");
    exit();
} elseif ($contest['contest_status'] !== 'active') {
    header("Location: dashboard.php?error=invalid_status");
    exit();
}

// Get problems for this contest
$stmt = $conn->prepare("
    SELECT p.* 
    FROM problems p
    JOIN contest_problems cp ON p.id = cp.problem_id
    WHERE cp.contest_id = ? 
    ORDER BY p.points ASC
");
$stmt->bind_param("i", $contest_id);
$stmt->execute();
$problems = $stmt->get_result();

// Handle submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['problem_id'])) {
    $problem_id = $_POST['problem_id'];
    $code = $_POST['code'];
    $language = $_POST['language'];
    // Use the contest ID from the URL parameter directly - fixing the variable declaration
    $current_contest_id = $contest_id; 
    
    // Basic validation
    if (empty($code)) {
        $error = "Code cannot be empty";
    } else {
        // In a real application, you would evaluate the code here
        // For this example, we'll randomly determine if it's correct
        $status = rand(0, 1) ? 'accepted' : 'wrong_answer';
        
        $stmt = $conn->prepare("INSERT INTO submissions (user_id, problem_id, contest_id, code, language, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiisss", $user_id, $problem_id, $current_contest_id, $code, $language, $status);
        
        if ($stmt->execute()) {
            $success = true;
            header("Location: contest.php?id=" . $contest_id . "&submitted=1");
            exit();
        } else {
            $error = "Error submitting solution";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($contest['title']); ?> - Codinger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/dracula.min.css" rel="stylesheet">
    <link href="../css/style.css" rel="stylesheet">
    <style>
        .CodeMirror {
            height: 400px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .problem-section {
            height: calc(100vh - 156px);
            overflow-y: auto;
        }
        .problem-content {
            padding: 20px;
            background: #fff;
            border-radius: 8px;
        }
        .problem-title {
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }
        .section-title {
            color: #2c3e50;
            margin: 20px 0 10px 0;
            font-weight: 600;
        }
        .example-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .example-box {
            background: white;
            padding: 15px;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .example-pre {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
            border: 1px solid #e9ecef;
        }
        .test-case {
            margin: 10px 0;
            padding: 15px;
            border-radius: 5px;
            border: 1px solid #ddd;
        }
        .test-case.passed {
            background-color: #d4edda;
            border-color: #c3e6cb;
        }
        .test-case.failed {
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }
        .custom-input {
            height: 100px;
            font-family: monospace;
        }
        .output-section {
            background: #1e1e1e;
            color: #fff;
            padding: 15px;
            border-radius: 4px;
            font-family: monospace;
            min-height: 100px;
            max-height: 200px;
            overflow-y: auto;
        }
        .error-output {
            color: #721c24;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .success-output {
            color: #69db7c;
        }
        .tab-content {
            padding: 20px 0;
        }
        .format-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            font-family: monospace;
            white-space: pre-wrap;
            border: 1px solid #e9ecef;
        }
        .problem-section-content {
            font-size: 1rem;
            line-height: 1.6;
        }
        .section-title {
            color: #2c3e50;
            margin: 20px 0 10px 0;
            font-weight: 600;
            font-size: 1.1rem;
        }
        #contestTimer {
            font-weight: bold;
            font-size: 1.1rem;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        #timer {
            font-family: monospace;
        }
        
        .timer-warning {
            color: #ffc107 !important;
        }
        
        .timer-danger {
            color: #dc3545 !important;
            animation: blink 1s infinite;
        }
        
        @keyframes blink {
            50% {
                opacity: 0.5;
            }
        }

        /* Add these styles for the centered timer */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030;
            background-color: #212529;
        }
        .navbar-timer {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1;
        }
        /* Add padding to body to prevent content from going under navbar */
        body {
            padding-top: 56px; /* Height of navbar */
        }
        /* Adjust problem section height to account for fixed navbar */
        .problem-section {
            height: calc(100vh - 156px); /* 100vh - (navbar height + padding) */
            overflow-y: auto;
        }
        
        /* Responsive adjustments */
        @media (max-width: 991.98px) {
            .navbar-timer {
                position: static;
                transform: none;
                margin: 10px auto;
                text-align: center;
            }
            #contestTimer {
                display: inline-block;
            }
        }

        /* Anti-cheating warning styles */
        #tab-switch-warning {
            display: none;
            position: fixed;
            top: 20px;
            right: 20px;
            background-color: #dc3545;
            color: white;
            padding: 15px 20px;
            border-radius: 5px;
            font-weight: bold;
            z-index: 9999;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            max-width: 400px;
        }

        /* Disable text selection */
        body {
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }

        /* Allow text selection in CodeMirror */
        .CodeMirror {
            -webkit-user-select: text;
            -moz-user-select: text;
            -ms-user-select: text;
            user-select: text;
        }

        .overall-result {
            margin: 20px 0;
            padding: 15px;
            border-radius: 5px;
            text-align: center;
            font-size: 1.2em;
        }
        
        .overall-result.passed {
            background-color: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
        }
        
        .overall-result.failed {
            background-color: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
        }
        
        /* Code Mirror Overlay Styles */
        .editor-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.7);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            border-radius: 4px;
            text-align: center;
            padding: 20px;
        }
        
        .final-submission-message {
            background-color: #fff;
            border-radius: 8px;
            padding: 30px;
            max-width: 80%;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }
        
        .final-submission-message h3 {
            color: #0d6efd;
            margin-bottom: 15px;
        }
        
        .final-submission-message p {
            color: #343a40;
            font-size: 1.1rem;
            margin-bottom: 10px;
        }
        
        /* Position the CodeMirror container relatively for absolute positioning of overlay */
        .code-editor-container {
            position: relative;
        }
    </style>
</head>
<body data-max-tab-switches="<?php echo htmlspecialchars($contest['allowed_tab_switches']); ?>" 
      data-allow-copy-paste="<?php echo $contest['prevent_copy_paste'] ? '0' : '1'; ?>"
      data-prevent-right-click="<?php echo $contest['prevent_right_click'] ? '1' : '0'; ?>">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <span class="navbar-brand">Codinger</span>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <!-- Centered Timer -->
            <div class="navbar-timer">
                <div id="contestTimer" class="text-warning">
                    Time Remaining: <span id="timer"></span>
                </div>
            </div>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <span class="nav-link">Welcome, <?php echo htmlspecialchars($_SESSION['student']['full_name']); ?></span>
                    </li>
                    <li class="nav-item">
                        <button class="btn btn-outline-danger my-1 ms-2" id="exitContestBtn">
                            <i class="bi bi-box-arrow-right"></i> Exit Contest
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-4">
        <div class="row">
            <!-- Code Editor Section (Left Side) -->
            <div class="col-md-7">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <select class="form-select" id="language" onchange="changeLanguage()">
                                    <option value="cpp">C++</option>
                                    <option value="java">Java</option>
                                    <option value="python">Python</option>
                                </select>
                                <!-- Java class information text -->
                                <div class="text-muted mt-1" id="javaHelperText" style="display: none; font-size: 0.8rem;">
                                    <i class="bi bi-info-circle"></i> Java submissions must use <code>public class Solution</code>
                                </div>
                            </div>
                            <div>
                                <button class="btn btn-primary" onclick="runCode()">Run Code</button>
                                <button class="btn btn-success" onclick="submitCode()">Submit</button>
                                <div id="submissionLimitInfo" class="mt-2 small"></div>
                            </div>
                        </div>

                        <div class="code-editor-container">
                            <textarea id="editor"></textarea>
                            <!-- Overlay for final submission message will be added here dynamically -->
                        </div>

                        <ul class="nav nav-tabs mt-3" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link active" data-bs-toggle="tab" href="#testcases">Test Cases</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#custom">Custom Input</a>
                            </li>
                        </ul>

                        <div class="tab-content">
                            <div id="testcases" class="tab-pane active">
                                <div id="testCaseResults"></div>
                            </div>
                            <div id="custom" class="tab-pane fade">
                                <textarea class="form-control custom-input mb-3" id="customInput" placeholder="Enter your input here..."></textarea>
                                <div class="output-section" id="customOutput"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Problem Description Section (Right Side) -->
            <div class="col-md-5">
                <div class="card problem-section">
                    <div class="card-body">
                        <!-- Problems Navigation -->
                        <div id="problemsNavigation" class="list-group mb-3"></div>
                        
                        <!-- Problem Details -->
                        <div id="problemDetails" class="problem-content">
                            <h3 id="problemTitle" class="problem-title mb-4"></h3>
                            
                            <div class="problem-section-content">
                                <h5 class="section-title">Problem Statement</h5>
                                <div id="problemDescription" class="mb-4"></div>
                                
                                <h5 class="section-title">Input Format</h5>
                                <div id="inputFormat" class="format-box mb-4"></div>
                                
                                <h5 class="section-title">Output Format</h5>
                                <div id="outputFormat" class="format-box mb-4"></div>
                                
                                <h5 class="section-title">Constraints</h5>
                                <div id="constraints" class="format-box mb-4"></div>
                                
                                <div class="example-section">
                                    <h5 class="section-title">Examples</h5>
                                    <div id="test-cases-container">
                                        <!-- Test cases will be loaded here -->
                                        <div class="text-center">
                                            <div class="spinner-border text-primary" role="status">
                                                <span class="visually-hidden">Loading...</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/clike/clike.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/python/python.min.js"></script>
    <script src="../js/prevent_cheating.js?v=<?php echo time(); ?>"></script>
    <script src="../js/fullscreen_security.js?v=<?php echo time(); ?>"></script>
    <script src="../js/clipboard_security.js?v=<?php echo time(); ?>"></script>
    <script>
        // Clipboard protection: clear clipboard and block copy/cut/paste
        function clearClipboard() {
            if (navigator.clipboard && window.isSecureContext) {
                // Modern async clipboard API
                navigator.clipboard.writeText('').catch(() => {});
            } else {
                // Fallback for older browsers
                const textarea = document.createElement('textarea');
                textarea.value = '';
                document.body.appendChild(textarea);
                textarea.select();
                try {
                    document.execCommand('copy');
                } catch (e) {}
                document.body.removeChild(textarea);
            }
        }

        // Check if element is within CodeMirror
        function isElementInCodeMirror(element) {
            while (element && element !== document.body) {
                if (element.classList.contains('CodeMirror') || 
                    element.classList.contains('CodeMirror-line') ||
                    element.classList.contains('CodeMirror-scroll') ||
                    element.classList.contains('CodeMirror-gutter') ||
                    element.classList.contains('CodeMirror-linenumber') ||
                    element.className.includes('CodeMirror')) {
                    return true;
                }
                element = element.parentElement;
            }
            return false;
        }

        // Block and clear clipboard on copy/cut/paste except in CodeMirror
        document.addEventListener('copy', function(e) {
            if (document.body.getAttribute('data-allow-copy-paste') === '0' && !isElementInCodeMirror(e.target)) {
                e.preventDefault();
                clearClipboard();
            }
        });
        
        document.addEventListener('cut', function(e) {
            if (document.body.getAttribute('data-allow-copy-paste') === '0' && !isElementInCodeMirror(e.target)) {
                e.preventDefault();
                clearClipboard();
            }
        });
        
        document.addEventListener('paste', function(e) {
            if (document.body.getAttribute('data-allow-copy-paste') === '0' && !isElementInCodeMirror(e.target)) {
                e.preventDefault();
                clearClipboard();
            }
        });
        
        // Clear clipboard on page load to prevent bringing code from outside
        document.addEventListener('DOMContentLoaded', function() {
            clearClipboard();
        });

        // Initialize CodeMirror
        let editor = CodeMirror.fromTextArea(document.getElementById("editor"), {
            lineNumbers: true,
            theme: "dracula",
            mode: "text/x-c++src",
            indentUnit: 4,
            autoCloseBrackets: true,
            matchBrackets: true,
            lineWrapping: true
        });

        // Apply anti-cheating measures to CodeMirror
        if (document.body.getAttribute('data-allow-copy-paste') === '0') {
            // Only prevent drag and drop from outside the editor
            editor.on("drop", function(cm, event) {
                // Allow drops that originate within the editor
                if (isElementInCodeMirror(event.target)) {
                    return true;
                }
                // Block drops from outside
                event.preventDefault();
                return false;
            });
            
            // Block right-click context menu outside of editor
            document.addEventListener('contextmenu', function(e) {
                if (!isElementInCodeMirror(e.target) && 
                    document.body.getAttribute('data-prevent-right-click') === '1') {
                    e.preventDefault();
                    return false;
                }
            });
        }

        // Starter code templates
        const starterCode = {
            cpp: `#include <iostream>
using namespace std;

int main() {
    // Your code here
    return 0;
}`,
            java: `public class Solution {
    public static void main(String[] args) {
        // Your code here
    }
}`,
            python: `# Your code here
`
        };

        // Change language handler
        function changeLanguage() {
            const language = document.getElementById("language").value;
            const modes = {
                cpp: "text/x-c++src",
                java: "text/x-java",
                python: "text/x-python"
            };
            editor.setOption("mode", modes[language]);
            editor.setValue(starterCode[language]);
            
            // Show/hide Java helper text
            const javaHelperText = document.getElementById("javaHelperText");
            if (javaHelperText) {
                javaHelperText.style.display = language === 'java' ? 'block' : 'none';
            }
        }

        // Java class name checker function
        function checkJavaClassName() {
            const code = editor.getValue();
            const language = document.getElementById("language").value;
            
            if (language !== 'java') {
                return;
            }
            
            fetch('../api/java_class_helper.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    code: code,
                    language: language
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    showJavaHelperMessage('error', data.error);
                    return;
                }
                
                // Handle the result
                if (data.valid) {
                    showJavaHelperMessage('success', data.message);
                } else {
                    // Show the error message and suggestions
                    let message = `<strong>${data.message}</strong><br>`;
                    if (data.suggestions && data.suggestions.length > 0) {
                        message += '<ul>';
                        data.suggestions.forEach(suggestion => {
                            message += `<li>${suggestion}</li>`;
                        });
                        message += '</ul>';
                    }
                    
                    // Add a button to apply the fix if available
                    if (data.fixedCode) {
                        message += '<button class="btn btn-primary btn-sm mt-2" onclick="applyJavaFix()">Apply Fix</button>';
                        // Store the fixed code in a variable
                        window.fixedJavaCode = data.fixedCode;
                    }
                    
                    showJavaHelperMessage('warning', message);
                }
            })
            .catch(error => {
                showJavaHelperMessage('error', `Error checking Java class: ${error.message}`);
            });
        }
        
        // Apply the Java fix
        function applyJavaFix() {
            if (window.fixedJavaCode) {
                editor.setValue(window.fixedJavaCode);
                showJavaHelperMessage('success', 'Code updated to use public class Solution');
            }
        }
        
        // Helper function to show messages
        function showJavaHelperMessage(type, message) {
            // Create a message container if it doesn't exist
            let messageContainer = document.getElementById('javaHelperMessage');
            if (!messageContainer) {
                messageContainer = document.createElement('div');
                messageContainer.id = 'javaHelperMessage';
                messageContainer.style.marginTop = '10px';
                messageContainer.style.padding = '10px';
                messageContainer.style.borderRadius = '5px';
                
                // Add it after the editor
                document.querySelector('.CodeMirror').insertAdjacentElement('afterend', messageContainer);
            }
            
            // Set the appropriate styles
            const styles = {
                success: {
                    background: '#d4edda', 
                    border: '1px solid #c3e6cb', 
                    color: '#155724'
                },
                warning: {
                    background: '#fff3cd', 
                    border: '1px solid #ffeeba', 
                    color: '#856404'
                },
                error: {
                    background: '#f8d7da', 
                    border: '1px solid #f5c6cb', 
                    color: '#721c24'
                }
            };
            
            // Apply the styles
            const style = styles[type] || styles.info;
            Object.assign(messageContainer.style, style);
            
            // Set the message content
            messageContainer.innerHTML = message;
            
            // Auto-hide after 10 seconds for success messages
            if (type === 'success') {
                setTimeout(() => {
                    messageContainer.style.display = 'none';
                }, 10000);
            }
        }

        // Initialize with C++ code
        // editor.setValue(starterCode.cpp);
        // Don't set initial value here, loadProblem will handle it

        // Function to run code
        function runCode() {
            const code = editor.getValue();
            const language = document.getElementById("language").value;
            const customInput = document.getElementById("customInput").value;

            // Determine which tab is active
            const activeTab = document.querySelector('.tab-pane.active').id;
            
            if (activeTab === 'custom') {
                // If custom tab is active, run with custom input
                document.getElementById("customOutput").innerHTML = "Running...";

            fetch('../api/run_code.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    code: code,
                    language: language,
                    input: customInput
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                        document.getElementById("customOutput").innerHTML = 
                        `<div class="error-output">${data.error}</div>`;
                } else {
                        document.getElementById("customOutput").innerHTML = 
                        `<div class="success-output">
                            <div class="mb-2">Output:</div>
                            <div class="output-content">${data.output || '(no output)'}</div>
                            <div class="execution-time mt-2">Execution Time: ${data.executionTime}ms</div>
                        </div>`;
                }
            })
            .catch(error => {
                    document.getElementById("customOutput").innerHTML = 
                    `<div class="error-output">Error: ${error.message}</div>`;
            });
            } else {
                // Show loading state in test cases tab
                document.getElementById("testCaseResults").innerHTML = "Running test cases...";

                // Get visible test cases for current problem
                fetch(`../student/get_test_cases.php?id=${currentProblemId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.visible_test_cases && data.visible_test_cases.length > 0) {
                            // Run each visible test case
                            const testPromises = data.visible_test_cases.map((testCase, index) => {
                                return fetch('../api/run_code.php', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                    },
                                    body: JSON.stringify({
                                        code: code,
                                        language: language,
                                        input: testCase.input
                                    })
                                })
                                .then(response => response.json())
                                .then(result => {
                                    return {
                                        testCase,
                                        result,
                                        index
                                    };
                                });
                            });

                            // Wait for all test cases to complete
                            Promise.all(testPromises)
                                .then(results => {
                                    let testCasesHtml = '';
                                    let allPassed = true;

                                    results.forEach(({testCase, result, index}) => {
                                        const passed = !result.error && result.output.trim() === testCase.output.trim();
                                        allPassed = allPassed && passed;

                                        testCasesHtml += `
                                            <div class="test-case ${passed ? 'passed' : 'failed'}">
                                                <strong>Test Case ${index + 1}</strong>
                                                <div>Status: ${passed ? 'Passed' : 'Failed'}</div>
                                                <div>Input:</div>
                                                <pre>${testCase.input}</pre>
                                                <div>Expected Output:</div>
                                                <pre>${testCase.output}</pre>
                                                <div>Your Output:</div>
                                                <pre>${result.error ? result.error : result.output}</pre>
                                                <div>Time: ${result.executionTime}ms</div>
                                            </div>
                                        `;
                                    });

                                    // Add summary at the top
                                    testCasesHtml = `
                                        <div class="alert alert-${allPassed ? 'success' : 'danger'} mb-3">
                                            ${allPassed ? 'All test cases passed!' : 'Some test cases failed.'}
                                        </div>
                                    ` + testCasesHtml;

                                    document.getElementById("testCaseResults").innerHTML = testCasesHtml;
                                })
                                .catch(error => {
                                    document.getElementById("testCaseResults").innerHTML = 
                                        `<div class="error-output">Error running test cases: ${error.message}</div>`;
                                });
                        } else {
                            // If no visible test cases, show message
                            document.getElementById("testCaseResults").innerHTML = 
                                '<div class="alert alert-info">No test cases available. Use custom input to test your code.</div>';
                            // Switch to custom input tab
                            document.querySelector('a[href="#custom"]').click();
                        }
                    })
                    .catch(error => {
                        document.getElementById("testCaseResults").innerHTML = 
                            `<div class="error-output">Error loading test cases: ${error.message}</div>`;
                    });
            }
        }

        // Function to check submission limits
        function checkSubmissionLimit(problemId) {
            // Add cache-busting to prevent caching
            const cacheBuster = new Date().getTime();
            const contestId = <?php echo $contest_id; ?>; // Get the contest ID from PHP
            return fetch(`../api/check_submission_limit.php?problem_id=${problemId}&contest_id=${contestId}&_=${cacheBuster}`)
                .then(response => response.json())
                .then(data => {
                    return data;
                })
                .catch(error => {
                    console.error('Error checking submission limit:', error);
                    return { canSubmit: false, error: 'Could not verify submission limit' };
                });
        }

        // Modify the submitCode function to check limits before submitting
        function submitCode() {
            const code = editor.getValue();
            const language = document.getElementById('language').value;
            const problemId = currentProblemId;
            const contestId = <?php echo $contest_id; ?>;

            console.log("Submit button clicked");
            console.log("Current problem ID:", problemId);
            console.log("Current contest ID:", contestId);
            console.log("Selected language:", language);

            if (!code.trim()) {
                alert('Please write some code before submitting.');
                return;
            }

            if (!problemId) {
                document.getElementById("testCaseResults").innerHTML = 
                    `<div class="error-output">Error: No problem selected. Please select a problem first.</div>`;
                return;
            }

            // Switch to test cases tab before proceeding
            document.querySelector('a[href="#testcases"]').click();

            // Show loading state
            document.getElementById("testCaseResults").innerHTML = '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>';

            // First check if the user has reached their submission limit
            checkSubmissionLimit(problemId)
                .then(limitData => {
                    if (!limitData.canSubmit) {
                        document.getElementById("testCaseResults").innerHTML = 
                            `<div class="error-output">${limitData.message || limitData.error || 'You have reached the maximum number of submissions for this problem.'}</div>`;
                        return;
                    }
                    
                    // Check if this is the final submission
                    const isLastSubmission = limitData.maxSubmissions > 0 && 
                                           (limitData.submissionsUsed + 1) >= limitData.maxSubmissions;
                    
                    // Function to proceed with submission
                    const proceedWithSubmission = () => {
                        console.log("Making API call to submit_code.php with problem_id:", problemId, "contest_id:", contestId);
                        
                        // If submission is allowed, proceed with API call
                        fetch('../api/submit_code.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({
                                code: code,
                                language: language,
                                problem_id: problemId,
                                contest_id: contestId
                            })
                        })
                        .then(response => {
                            console.log("API Response status:", response.status);
                            return response.json();
                        })
                        .then(data => {
                            console.log("API Response data:", data);
                            if (data.error) {
                                document.getElementById("testCaseResults").innerHTML = 
                                    `<div class="error-output">${data.error}</div>`;
                                return;
                            }
                            
                            let resultsHtml = '';
                            let allPassed = true;
                            let visiblePassed = true;
                            let hiddenPassed = true;
                            
                            // Process visible test cases first
                            data.testCases.forEach((testCase, index) => {
                                if (testCase.is_visible) {
                                    if (!testCase.passed) {
                                        visiblePassed = false;
                                        allPassed = false;
                                    }
                                    resultsHtml += `
                                        <div class="test-case ${testCase.passed ? 'passed' : 'failed'}">
                                            <strong>Test Case ${index + 1}</strong>
                                            <div>Status: ${testCase.passed ? 'Passed' : 'Failed'}</div>
                                            ${!testCase.passed ? `
                                                <div>Expected Output: ${testCase.expected}</div>
                                                <div>Your Output: ${testCase.actual}</div>
                                            ` : ''}
                                            <div>Time: ${testCase.time}ms</div>
                                        </div>
                                    `;
                                }
                            });

                            // Add hidden test cases summary
                            const hiddenCases = data.testCases.filter(tc => !tc.is_visible);
                            if (hiddenCases.length > 0) {
                                const hiddenPassed = hiddenCases.every(tc => tc.passed);
                                if (!hiddenPassed) {
                                    allPassed = false;
                                }
                                resultsHtml += `
                                    <div class="test-case ${hiddenPassed ? 'passed' : 'failed'}">
                                        <strong>Hidden Test Cases</strong>
                                        <div>Status: ${hiddenPassed ? 'All Passed' : 'Some Failed'}</div>
                                        <div>Total Hidden Cases: ${hiddenCases.length}</div>
                                    </div>
                                `;
                            }

                            // Add overall result
                            resultsHtml = `
                                <div class="overall-result ${allPassed ? 'passed' : 'failed'}">
                                    <strong>Overall Result: ${allPassed ? 'Accepted' : 'Wrong Answer'}</strong>
                                </div>
                                ${resultsHtml}
                            `;

                            document.getElementById("testCaseResults").innerHTML = resultsHtml;

                            // If all test cases passed, show success message
                            if (allPassed) {
                                // Optional: Add a more prominent success message in the UI instead
                                const successMessage = `<div class="alert alert-success mt-3">
                                    <i class="bi bi-check-circle-fill"></i> Congratulations! All test cases passed.
                                </div>`;
                                document.getElementById("testCaseResults").innerHTML += successMessage;
                                
                                // Show beautiful success overlay with confetti
                                // Get the problem name for the success message
                                const currentProblemName = currentProblemId && problems ? 
                                    (problems.find(p => p.id == currentProblemId)?.title || 'the problem') : 
                                    'the problem';
                                
                                // Call the success overlay with a slight delay so the results are visible first
                                setTimeout(() => {
                                    window.showSuccessOverlay(currentProblemName);
                                }, 800);
                            }

                            // Show remaining submissions if limit is applied
                            if (limitData.maxSubmissions > 0) {
                                const submissionsUsed = limitData.submissionsUsed + 1;
                                const remainingSubmissions = limitData.maxSubmissions - submissionsUsed;
                                const remainingMessage = `<div class="alert alert-info mt-3">
                                    <i class="bi bi-info-circle"></i> You have used ${submissionsUsed} out of ${limitData.maxSubmissions} allowed submissions for this problem. 
                                    ${remainingSubmissions > 0 ? `You have ${remainingSubmissions} submission(s) remaining.` : '(No more submissions allowed)'}
                                </div>`;
                                document.getElementById("testCaseResults").innerHTML += remainingMessage;
                                
                                // Create overlay message for final submission instead of adding to testCaseResults
                                if (remainingSubmissions <= 0) {
                                    // Disable the submit button to make it visually clear
                                    const submitBtn = document.querySelector('.btn-success[onclick="submitCode()"]');
                                    if (submitBtn) {
                                        submitBtn.disabled = true;
                                        submitBtn.classList.add('disabled');
                                        
                                        // Mark this problem as having reached submission limit
                                        submitBtn.setAttribute('data-problem-' + problemId + '-can-submit', 'false');
                                    }
                                    
                                    // Add overlay on top of CodeMirror
                                    const editorContainer = document.querySelector('.code-editor-container');
                                    const overlay = document.createElement('div');
                                    overlay.className = 'editor-overlay';
                                    overlay.innerHTML = `
                                        <div class="final-submission-message">
                                            <h3><i class="bi bi-check-circle-fill"></i> Final Submission Complete</h3>
                                            <p>Your code has been successfully submitted for this problem.</p>
                                            <p>Thank you for your participation!</p>
                                            <div class="mt-3">
                                                <button class="btn btn-primary" onclick="this.closest('.editor-overlay').style.display='none'">
                                                    Continue
                                                </button>
                                            </div>
                                        </div>
                                    `;
                                    editorContainer.appendChild(overlay);
                                    
                                    // Store the submitted code in localStorage after successful submission
                                    // This ensures the final submitted code persists, not just any typed code
                                    localStorage.setItem(`problem-${problemId}-contest-${contestId}-language-${language}-code`, code);
                                }
                            }
                            
                            // Update the submission limit info with a small delay to allow database to update
                            setTimeout(() => {
                                displaySubmissionLimitInfo();
                            }, 500);
                        })
                        .catch(error => {
                            document.getElementById("testCaseResults").innerHTML = 
                                `<div class="error-output">Error: ${error.message}</div>`;
                        });
                    };
                    
                    // If this is the last submission, show a confirmation dialog
                    if (isLastSubmission) {
                        // Remove loading state first
                        document.getElementById("testCaseResults").innerHTML = '';
                        
                        // Create custom modal for final submission confirmation
                        const modalContainer = document.createElement('div');
                        modalContainer.innerHTML = `
                            <div class="modal fade" id="finalSubmissionModal" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header bg-warning">
                                            <h5 class="modal-title">⚠️ Final Submission Warning</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p>This is your <strong>final submission</strong> for this problem. After this, you won't be able to submit any more solutions.</p>
                                            <p>Are you sure you want to proceed with this submission?</p>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="button" class="btn btn-danger" id="confirmFinalSubmission">Yes, Submit</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                        document.body.appendChild(modalContainer);
                        
                        // Initialize and show the modal
                        const modal = new bootstrap.Modal(document.getElementById('finalSubmissionModal'));
                        modal.show();
                        
                        // Handle confirmation click
                        document.getElementById('confirmFinalSubmission').addEventListener('click', function() {
                            modal.hide();
                            // Show loading state again
                            document.getElementById("testCaseResults").innerHTML = '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>';
                            // Process the submission
                            proceedWithSubmission();
                        });
                        
                        // Clean up modal after it's hidden
                        document.getElementById('finalSubmissionModal').addEventListener('hidden.bs.modal', function() {
                            document.body.removeChild(modalContainer);
                        });
                    } else {
                        // Not the final submission, proceed directly
                        proceedWithSubmission();
                    }
                });
        }

        let currentProblemId = null; // Global variable to store current problem ID

        // Function to display submission limit info for current problem
        function displaySubmissionLimitInfo() {
            if (!currentProblemId) return;
            
            const infoElement = document.getElementById("submissionLimitInfo");
            const submitBtn = document.querySelector('.btn-success[onclick="submitCode()"]');
            if (!infoElement) return;
            
            infoElement.innerHTML = '<div class="spinner-border spinner-border-sm text-secondary" role="status"><span class="visually-hidden">Loading...</span></div> Checking submission limits...';
            
            // Add a cache-busting parameter
            const cacheBuster = new Date().getTime();
            
            checkSubmissionLimit(currentProblemId)
                .then(limitData => {
                    if (limitData.maxSubmissions === 0) {
                        infoElement.innerHTML = '<i class="bi bi-infinity"></i> Unlimited submissions allowed';
                        if (submitBtn) submitBtn.disabled = false;
                    } else {
                        const submissionsRemaining = limitData.maxSubmissions - limitData.submissionsUsed;
                        const statusClass = submissionsRemaining > 1 ? 'text-success' : 
                                          submissionsRemaining === 1 ? 'text-warning' : 'text-danger';
                        infoElement.innerHTML = `<span class="${statusClass}">
                            <i class="bi bi-${submissionsRemaining > 0 ? 'check-circle' : 'x-circle'}"></i> 
                            ${limitData.submissionsUsed} of ${limitData.maxSubmissions} submissions used
                            ${submissionsRemaining > 0 ? `(${submissionsRemaining} remaining)` : '(limit reached)'}
                        </span>`;
                        
                        // Only disable the submit button if we've reached the limit for the CURRENT problem
                        if (submitBtn) {
                            submitBtn.disabled = submissionsRemaining <= 0;
                            
                            // Store the problem's submission status in a data attribute
                            submitBtn.setAttribute('data-problem-' + currentProblemId + '-can-submit', 
                                                  submissionsRemaining > 0 ? 'true' : 'false');
                        }
                    }
                })
                .catch(error => {
                    infoElement.innerHTML = '<i class="bi bi-exclamation-triangle text-warning"></i> Could not check submission limit';
                    if (submitBtn) submitBtn.disabled = false;
                    console.error('Error checking submission limit:', error);
                });
        }

        // Load problem function
        function loadProblem(problem) {
            console.log('Loading problem:', problem); // Debug log
            
            currentProblemId = problem.id;
            console.log('Set currentProblemId to:', currentProblemId);
            
            // Debug: Check problem description
            console.log('Problem description type:', typeof problem.description);
            console.log('Problem description length:', problem.description ? problem.description.length : 0);
            
            // Debug: Log all problem properties 
            console.log('All problem properties:');
            for (const prop in problem) {
                console.log(`${prop}: ${typeof problem[prop]} (${problem[prop] ? problem[prop].toString().substring(0, 30) : 'null/undefined'})`);
            }
            
            // Update submit button state based on the selected problem's submission status
            const submitBtn = document.querySelector('.btn-success[onclick="submitCode()"]');
            if (submitBtn) {
                const canSubmitAttr = submitBtn.getAttribute('data-problem-' + currentProblemId + '-can-submit');
                if (canSubmitAttr === 'false') {
                    submitBtn.disabled = true;
                    submitBtn.classList.add('disabled');
                } else {
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('disabled');
                }
            }
            
            // Set problem title
            document.getElementById("problemTitle").textContent = problem.title || 'Untitled Problem';
            
            // Set problem description - handle potentially unsafe HTML
            const descriptionElement = document.getElementById("problemDescription");
            if (problem.description && problem.description.trim()) {
                try {
                    // First try to set as HTML (if it contains formatted content)
                    descriptionElement.innerHTML = problem.description;
                } catch (e) {
                    console.error('Error setting description as HTML:', e);
                    // Fallback to plain text if HTML fails
                    descriptionElement.textContent = problem.description;
                }
            } else {
                descriptionElement.textContent = 'No description available';
            }
            
            // Set input format - use textContent for safely displaying text
            const inputFormatElement = document.getElementById("inputFormat");
            if (problem.input_format && problem.input_format.trim()) {
                inputFormatElement.textContent = problem.input_format;
            } else {
                inputFormatElement.textContent = 'No input format specified';
            }
            
            // Set output format - use textContent for safely displaying text
            const outputFormatElement = document.getElementById("outputFormat");
            if (problem.output_format && problem.output_format.trim()) {
                outputFormatElement.textContent = problem.output_format;
            } else {
                outputFormatElement.textContent = 'No output format specified';
            }
            
            // Set constraints - use textContent for safely displaying text
            const constraintsElement = document.getElementById("constraints");
            if (problem.constraints && problem.constraints.trim()) {
                constraintsElement.textContent = problem.constraints;
            } else {
                constraintsElement.textContent = 'No constraints specified';
            }
            
            // Load test cases from API
            loadTestCases(problem.id);
            
            // Reset editor and test case results
            // editor.setValue(starterCode[document.getElementById("language").value]);
            // Instead of resetting to starter code, fetch and load last submission or starter code
            loadLastSubmissionOrStarterCode(problem.id, document.getElementById("language").value);
            
            document.getElementById("testCaseResults").innerHTML = '';
            document.getElementById("customOutput").innerHTML = '';
            
            // Display submission limit info
            displaySubmissionLimitInfo();
        }
        
        // Function to load test cases from API
        function loadTestCases(problemId) {
            const testCasesContainer = document.getElementById('test-cases-container');
            testCasesContainer.innerHTML = `
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            `;
            
            fetch(`../student/get_test_cases.php?id=${problemId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.visible_test_cases && data.visible_test_cases.length > 0) {
                        let testCasesHTML = '';
                        
                        data.visible_test_cases.forEach((testCase, index) => {
                            testCasesHTML += `
                                <div class="example-box mb-3">
                                    <div class="d-flex justify-content-between">
                                        <h6 class="mb-2">Test Case ${index + 1}</h6>
                                    </div>
                                    <div class="example-input">
                                        <strong>Input:</strong>
                                        <pre class="example-pre">${testCase.input}</pre>
                                    </div>
                                    <div class="example-output">
                                        <strong>Output:</strong>
                                        <pre class="example-pre">${testCase.output}</pre>
                                    </div>
                                </div>
                            `;
                        });
                        
                        // Add a note about hidden test cases with count if available
                        if (data.hidden_test_cases_count > 0) {
                            testCasesHTML += `
                                <div class="alert alert-info mt-3">
                                    <i class="bi bi-info-circle"></i> 
                                    <small>Note: There ${data.hidden_test_cases_count === 1 ? 'is' : 'are'} ${data.hidden_test_cases_count} hidden test ${data.hidden_test_cases_count === 1 ? 'case' : 'cases'} that will also be used to evaluate your solution.</small>
                                </div>
                            `;
                        } else {
                            testCasesHTML += `
                                <div class="alert alert-info mt-3">
                                    <i class="bi bi-info-circle"></i> 
                                    <small>Note: There may be additional hidden test cases that will be used to evaluate your solution.</small>
                                </div>
                            `;
                        }
                        
                        testCasesContainer.innerHTML = testCasesHTML;
                    } else if (data.using_legacy) {
                        // Fall back to legacy display
                        testCasesContainer.innerHTML = `
                            <div class="example-box">
                                <div class="example-input">
                                    <strong>Input:</strong>
                                    <pre id="sampleInput" class="example-pre">${problem.sample_input || 'No sample input provided'}</pre>
                                </div>
                                <div class="example-output">
                                    <strong>Output:</strong>
                                    <pre id="sampleOutput" class="example-pre">${problem.sample_output || 'No sample output provided'}</pre>
                                </div>
                            </div>
                        `;
                    } else {
                        testCasesContainer.innerHTML = '<div class="alert alert-info">No example test cases available for this problem.</div>';
                    }
                })
                .catch(error => {
                    testCasesContainer.innerHTML = `<div class="alert alert-danger">Error loading test cases: ${error.message}</div>`;
                });
        }

        // Load problems list and create navigation
        <?php
        $problemsArray = array();
        while ($problem = $problems->fetch_assoc()) {
            // Clean and encode problem data to ensure valid UTF-8
            $clean_description = isset($problem['description']) ? mb_convert_encoding($problem['description'], 'UTF-8', 'UTF-8') : '';
            $clean_input_format = isset($problem['input_format']) ? mb_convert_encoding($problem['input_format'], 'UTF-8', 'UTF-8') : '';
            $clean_output_format = isset($problem['output_format']) ? mb_convert_encoding($problem['output_format'], 'UTF-8', 'UTF-8') : '';
            $clean_constraints = isset($problem['constraints']) ? mb_convert_encoding($problem['constraints'], 'UTF-8', 'UTF-8') : '';
            $clean_sample_input = isset($problem['sample_input']) ? mb_convert_encoding($problem['sample_input'], 'UTF-8', 'UTF-8') : '';
            $clean_sample_output = isset($problem['sample_output']) ? mb_convert_encoding($problem['sample_output'], 'UTF-8', 'UTF-8') : '';
            
            $problemData = array(
                'id' => $problem['id'],
                'title' => isset($problem['title']) ? mb_convert_encoding($problem['title'], 'UTF-8', 'UTF-8') : '',
                'description' => $clean_description,
                'input_format' => $clean_input_format,
                'output_format' => $clean_output_format,
                'constraints' => $clean_constraints,
                'sample_input' => $clean_sample_input,
                'sample_output' => $clean_sample_output,
                'points' => $problem['points']
            );
            $problemsArray[] = $problemData;
        }
        
        // Use JSON_INVALID_UTF8_SUBSTITUTE flag to handle invalid UTF-8 sequences
        $encoded_problems = json_encode($problemsArray, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
        
        // Fallback if json_encode fails
        if ($encoded_problems === false) {
            // Try to clean the data more aggressively
            foreach ($problemsArray as &$problem) {
                foreach ($problem as $key => $value) {
                    if (is_string($value)) {
                        // Replace any potentially problematic characters
                        $problem[$key] = preg_replace('/[^\x20-\x7E]/', '', $value);
                    }
                }
            }
            $encoded_problems = json_encode($problemsArray);
            
            // Last resort fallback - if still fails, create minimal problem data
            if ($encoded_problems === false) {
                $minimal_problems = array();
                foreach ($problemsArray as $problem) {
                    $minimal_problems[] = array(
                        'id' => $problem['id'],
                        'title' => 'Problem ' . $problem['id'],
                        'description' => 'Problem description unavailable due to encoding issues. Please contact administrator.',
                        'input_format' => '',
                        'output_format' => '',
                        'constraints' => '',
                        'sample_input' => '',
                        'sample_output' => '',
                        'points' => $problem['points']
                    );
                }
                $encoded_problems = json_encode($minimal_problems);
            }
        }
        ?>
        
        // Create problems navigation
        const problems = <?php echo $encoded_problems; ?>;
        
        // Debug problems data
        console.log('Problems data from PHP:', problems);
        if (problems.length > 0) {
            console.log('First problem description from PHP array:', problems[0].description);
            if (problems[0].description) {
                console.log('First problem description length:', problems[0].description.length);
                console.log('First problem description substring:', problems[0].description.substring(0, 50));
            }
        } else {
            console.error('No problems available for this contest!');
        }
        
        const problemsNav = document.createElement('div');
        problemsNav.className = 'list-group mb-3';
        problems.forEach((problem, index) => {
            const button = document.createElement('button');
            button.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
            button.innerHTML = `
                ${problem.title}
                <span class="badge bg-primary rounded-pill">${problem.points} points</span>
            `;
            button.onclick = () => {
                // Remove active class from all buttons
                problemsNav.querySelectorAll('.list-group-item').forEach(btn => {
                    btn.classList.remove('active');
                });
                // Add active class to the clicked button
                button.classList.add('active');
                loadProblem(problem);
            };
            problemsNav.appendChild(button);
        });
        document.querySelector('#problemsNavigation').appendChild(problemsNav);

        // Load first problem by default if available
        if (problems.length > 0) {
            // Highlight the first problem in the navigation
            if (problemsNav.firstChild) {
                problemsNav.firstChild.classList.add('active');
            }
            loadProblem(problems[0]);
        } else {
            document.getElementById('problemDetails').innerHTML = '<div class="alert alert-warning">No problems available for this contest. Please contact an administrator.</div>';
        }

        // Add this before your existing JavaScript code
        function updateTimer() {
            const endTime = new Date("<?php echo $contest['end_time']; ?>").getTime();
            
            function update() {
                const now = new Date().getTime();
                const distance = endTime - now;
                
                const hours = Math.floor(distance / (1000 * 60 * 60));
                const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((distance % (1000 * 60)) / 1000);
                
                const timerElement = document.getElementById("timer");
                const timerDisplay = `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
                
                if (distance < 0) {
                    timerElement.textContent = "Contest Ended";
                    timerElement.parentElement.classList.add('timer-danger');

                    // Notify prevent_cheating.js that the contest has ended
                    if (typeof window.setContestEnded === 'function') {
                        window.setContestEnded(); 
                    }

                    // Redirect to dashboard after 3 seconds
                    setTimeout(() => {
                        window.location.href = 'dashboard.php?error=contest_ended';
                    }, 3000);
                } else {
                    timerElement.textContent = timerDisplay;
                    
                    // Add warning class if less than 5 minutes remaining
                    if (distance < 5 * 60 * 1000) {
                        timerElement.parentElement.classList.add('timer-danger');
                    } else if (distance < 15 * 60 * 1000) {
                        timerElement.parentElement.classList.add('timer-warning');
                    }
                }
            }
            
            // Update immediately and then every second
            update();
            setInterval(update, 1000);
        }
        
        // Initialize page components when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Start contest timer
            updateTimer();
            
            // Setup exit contest button functionality
            document.getElementById('exitContestBtn').addEventListener('click', function() {
                // Create and show the exit confirmation modal
                const modalContainer = document.createElement('div');
                modalContainer.innerHTML = `
                    <div class="modal fade" id="exitContestModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header bg-danger text-white">
                                    <h5 class="modal-title">⚠️ Warning: Exit Contest</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <p><strong>Are you sure you want to exit this contest?</strong></p>
                                    <div class="alert alert-warning">
                                        <i class="bi bi-exclamation-triangle-fill"></i> Once you exit, you will NOT be able to re-enter this contest!
                                    </div>
                                    <p>Make sure you have submitted all your solutions before exiting.</p>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="button" class="btn btn-danger" id="confirmExitBtn">Yes, Exit Contest</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                document.body.appendChild(modalContainer);
                
                // Initialize and show the modal
                const modal = new bootstrap.Modal(document.getElementById('exitContestModal'));
                modal.show();
                
                // Handle confirmation click
                document.getElementById('confirmExitBtn').addEventListener('click', function() {
                    // Set flag in the database that the student has exited the contest
                    const contestId = <?php echo $contest_id; ?>;
                    
                    fetch('../api/exit_contest.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            contest_id: contestId
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Redirect to dashboard with exit status
                            window.location.href = 'dashboard.php?exit_status=success&contest_id=' + contestId;
                        } else {
                            // Show error if there was an issue
                            alert('Error exiting contest: ' + data.error);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        // Redirect anyway in case of connectivity issues
                        window.location.href = 'dashboard.php?exit_status=error&contest_id=' + contestId;
                    });
                });
                
                // Clean up modal after it's hidden
                document.getElementById('exitContestModal').addEventListener('hidden.bs.modal', function() {
                    document.body.removeChild(modalContainer);
                });
            });
            
            // Store contest ID in session (via AJAX) to ensure it's available
            fetch('../api/update_session.php?contest_id=<?php echo $contest_id; ?>', {
                method: 'GET',
                headers: {
                    'Cache-Control': 'no-cache'
                }
            })
            .then(response => response.json())
            .then(data => {
                console.log('Session updated with contest ID:', data.contest_id);
                
                // Since we have the first problem loaded by default, check submission limits
                if (currentProblemId) {
                    displaySubmissionLimitInfo();
                }
            })
            .catch(error => {
                console.error('Error updating session:', error);
            });
        });

        // Function to load last submitted code or starter code
function loadLastSubmissionOrStarterCode(problemId, language) {
    const contestId = <?php echo $contest_id; ?>;
    const storedCode = localStorage.getItem(`problem-${problemId}-contest-${contestId}-language-${language}-code`);
    if (storedCode) {
        editor.setValue(storedCode);
    } else {
        editor.setValue(starterCode[language] || starterCode.cpp); // Fallback to C++ starter if language specific not found
    }
}

// Function to save current editor content to localStorage
function saveCurrentCodeToLocalStorage(problemId, language) {
    const contestId = <?php echo $contest_id; ?>;
    const currentCode = editor.getValue();
    localStorage.setItem(`problem-${problemId}-contest-${contestId}-language-${language}-code`, currentCode);
}
        
        // Save code to localStorage when editor content changes
        editor.on('change', function() {
            if (currentProblemId && document.getElementById("language")) {
                saveCurrentCodeToLocalStorage(currentProblemId, document.getElementById("language").value);
            }
        });
    </script>
</body>
</html> 