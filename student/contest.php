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
    </style>
</head>
<body data-max-tab-switches="<?php echo htmlspecialchars($contest['allowed_tab_switches']); ?>" 
      data-allow-copy-paste="<?php echo $contest['prevent_copy_paste'] ? '0' : '1'; ?>"
      data-prevent-right-click="<?php echo $contest['prevent_right_click'] ? '1' : '0'; ?>">
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
            return fetch('../api/check_submission_limit.php?problem_id=' + problemId)
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

            if (!code.trim()) {
                alert('Please write some code before submitting.');
                return;
            }

            // Show loading state
            document.getElementById("testCaseResults").innerHTML = '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>';

            // First check if the user has reached their submission limit
            checkSubmissionLimit(problemId)
                .then(limitData => {
                    if (!limitData.canSubmit) {
                        document.getElementById("testCaseResults").innerHTML = 
                            `<div class="error-output">${limitData.message}</div>`;
                        return;
                    }

                    // If submission is allowed, proceed with API call
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
                    // Remove the alert popup
                    // alert('Congratulations! All test cases passed.');
                    
                    // Optional: Add a more prominent success message in the UI instead
                    const successMessage = `<div class="alert alert-success mt-3">
                        <i class="bi bi-check-circle-fill"></i> Congratulations! All test cases passed.
                    </div>`;
                    document.getElementById("testCaseResults").innerHTML += successMessage;
                }

                // Show remaining submissions if limit is applied
                if (limitData.submissionsRemaining !== undefined && limitData.maxSubmissions > 0) {
                    const remainingMessage = `<div class="alert alert-info mt-3">You have used ${limitData.submissionsUsed + 1} out of ${limitData.maxSubmissions} allowed submissions for this problem.</div>`;
                    document.getElementById("testCaseResults").innerHTML += remainingMessage;
                }
            })
            .catch(error => {
                document.getElementById("testCaseResults").innerHTML = 
                    `<div class="error-output">Error: ${error.message}</div>`;
                    });
            });
        }

        let currentProblemId = null; // Global variable to store current problem ID

        // Load problem function
        function loadProblem(problem) {
            console.log('Loading problem:', problem); // Debug log
            
            currentProblemId = problem.id;
            
            // Debug: Check problem description
            console.log('Problem description type:', typeof problem.description);
            console.log('Problem description length:', problem.description ? problem.description.length : 0);
            
            // Debug: Log all problem properties 
            console.log('All problem properties:');
            for (const prop in problem) {
                console.log(`${prop}: ${typeof problem[prop]} (${problem[prop] ? problem[prop].toString().substring(0, 30) : 'null/undefined'})`);
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