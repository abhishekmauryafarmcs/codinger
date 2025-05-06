<?php
require_once '../config/session.php';

// Force reload with cache clearing if the refresh parameter is set
if (isset($_GET['refresh_cache']) && $_GET['refresh_cache'] === '1') {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
}

// Check if user is logged in and is a student
if (!isStudentSessionValid()) {
    header("Location: ../login.php?error=session_expired");
    exit();
}

require_once '../config/db.php';

// Check if contest ID is provided
if (!isset($_GET['id'])) {
    header("Location: dashboard.php");
    exit();
}

$contest_id = (int)$_GET['id'];
$user_id = $_SESSION['user_id'];

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
    if (!isset($_SESSION['verified_contests'][$contest_id])) {
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
                $_SESSION['verified_contests'][$contest_id] = $user['enrollment_number'];
            }
        } else {
            // User record not found (shouldn't happen)
            header("Location: dashboard.php?error=user_not_found");
            exit();
        }
    }
}

// Store contest ID in session for violation tracking
$_SESSION['current_contest_id'] = $contest_id;

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
$stmt = $conn->prepare("SELECT * FROM problems WHERE contest_id = ? ORDER BY points ASC");
$stmt->bind_param("i", $contest_id);
$stmt->execute();
$problems = $stmt->get_result();

// Handle submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['problem_id'])) {
    $problem_id = $_POST['problem_id'];
    $code = $_POST['code'];
    $language = $_POST['language'];
    
    // Basic validation
    if (empty($code)) {
        $error = "Code cannot be empty";
    } else {
        // In a real application, you would evaluate the code here
        // For this example, we'll randomly determine if it's correct
        $status = rand(0, 1) ? 'accepted' : 'wrong_answer';
        
        $stmt = $conn->prepare("INSERT INTO submissions (user_id, problem_id, code, language, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iisss", $user_id, $problem_id, $code, $language, $status);
        
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
            border-left: 4px solid #6c757d;
            margin-bottom: 15px;
            padding: 10px;
            background: #f8f9fa;
        }
        .test-case.passed {
            border-left-color: #28a745;
        }
        .test-case.failed {
            border-left-color: #dc3545;
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
            color: #ff6b6b;
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
    </style>
</head>
<body data-max-tab-switches="<?php echo htmlspecialchars($contest['allowed_tab_switches']); ?>" data-allow-copy-paste="<?php echo $contest['prevent_copy_paste'] ? '0' : '1'; ?>">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="../index.php">Codinger</a>
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
                        <a class="nav-link" href="dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <span class="nav-link">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../logout.php">Logout</a>
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
                            </div>
                            <div>
                                <button class="btn btn-primary" onclick="runCode()">Run Code</button>
                                <button class="btn btn-success" onclick="submitCode()">Submit</button>
                            </div>
                        </div>

                        <textarea id="editor"></textarea>

                        <ul class="nav nav-tabs mt-3" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link active" data-bs-toggle="tab" href="#output">Output</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#testcases">Test Cases</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#custom">Custom Input</a>
                            </li>
                        </ul>

                        <div class="tab-content">
                            <div id="output" class="tab-pane active">
                                <div class="output-section mt-3" id="codeOutput">
                                    <div class="text-muted">Click 'Run Code' to see the output</div>
                                </div>
                            </div>
                            <div id="testcases" class="tab-pane">
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
    <script>
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

        // Language-specific starter code
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
        }

        // Initialize with C++ code
        editor.setValue(starterCode.cpp);

        // Function to run code
        function runCode() {
            const code = editor.getValue();
            const language = document.getElementById("language").value;
            const customInput = document.getElementById("customInput").value;

            // Show loading state
            document.getElementById("codeOutput").innerHTML = "Running...";
            
            // Switch to output tab
            document.querySelector('a[href="#output"]').click();

            // Make API call to run code
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
                    document.getElementById("codeOutput").innerHTML = 
                        `<div class="error-output">${data.error}</div>`;
                } else {
                    document.getElementById("codeOutput").innerHTML = 
                        `<div class="success-output">
                            <div class="mb-2">Output:</div>
                            <div class="output-content">${data.output || '(no output)'}</div>
                            <div class="execution-time mt-2">Execution Time: ${data.executionTime}ms</div>
                        </div>`;
                }
            })
            .catch(error => {
                document.getElementById("codeOutput").innerHTML = 
                    `<div class="error-output">Error: ${error.message}</div>`;
            });
        }

        // Function to submit code
        function submitCode() {
            const code = editor.getValue();
            const language = document.getElementById("language").value;
            const problemId = currentProblemId; // Use the global variable

            // Show loading state
            document.getElementById("testCaseResults").innerHTML = "Running test cases...";

            // Make API call to submit code
            fetch('../api/submit_code.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    code: code,
                    language: language,
                    problem_id: problemId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById("testCaseResults").innerHTML = 
                        `<div class="error-output">${data.error}</div>`;
                    return;
                }
                
                let resultsHtml = '';
                data.testCases.forEach((testCase, index) => {
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
                });
                document.getElementById("testCaseResults").innerHTML = resultsHtml;
            })
            .catch(error => {
                document.getElementById("testCaseResults").innerHTML = 
                    `<div class="error-output">Error: ${error.message}</div>`;
            });
        }

        let currentProblemId = null; // Global variable to store current problem ID

        // Load problem function
        function loadProblem(problem) {
            console.log('Loading problem:', problem); // Debug log
            
            currentProblemId = problem.id;
            
            // Set problem title
            document.getElementById("problemTitle").textContent = problem.title;
            
            // Set problem description
            document.getElementById("problemDescription").innerHTML = problem.description || 'No description available';
            
            // Set input format
            document.getElementById("inputFormat").textContent = problem.input_format || 'No input format specified';
            
            // Set output format
            document.getElementById("outputFormat").textContent = problem.output_format || 'No output format specified';
            
            // Set constraints
            document.getElementById("constraints").textContent = problem.constraints || 'No constraints specified';
            
            // Load test cases from API
            loadTestCases(problem.id);
            
            // Reset editor and test case results
            editor.setValue(starterCode[document.getElementById("language").value]);
            document.getElementById("testCaseResults").innerHTML = '';
            document.getElementById("customOutput").innerHTML = '';
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
            $problemData = array(
                'id' => $problem['id'],
                'title' => $problem['title'],
                'description' => $problem['description'],
                'input_format' => $problem['input_format'],
                'output_format' => $problem['output_format'],
                'constraints' => $problem['constraints'],
                'sample_input' => $problem['sample_input'],
                'sample_output' => $problem['sample_output'],
                'points' => $problem['points']
            );
            $problemsArray[] = $problemData;
        }
        ?>
        
        // Create problems navigation
        const problems = <?php echo json_encode($problemsArray); ?>;
        const problemsNav = document.createElement('div');
        problemsNav.className = 'list-group mb-3';
        problems.forEach((problem, index) => {
            const button = document.createElement('button');
            button.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
            button.innerHTML = `
                ${problem.title}
                <span class="badge bg-primary rounded-pill">${problem.points} points</span>
            `;
            button.onclick = () => loadProblem(problem);
            problemsNav.appendChild(button);
        });
        document.querySelector('#problemsNavigation').appendChild(problemsNav);

        // Load first problem by default if available
        if (problems.length > 0) {
            loadProblem(problems[0]);
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
        
        // Initialize timer when page loads
        document.addEventListener('DOMContentLoaded', updateTimer);
    </script>
</body>
</html> 