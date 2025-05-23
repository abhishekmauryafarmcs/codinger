<?php
// Disable error output to prevent HTML being mixed with JSON
ini_set('display_errors', 0);
error_reporting(0);

// Set JSON content type
header('Content-Type: application/json');

// Start output buffering to prevent any PHP warnings/errors from being output
ob_start();

try {
    session_start();
    require_once '../config/db.php';
    require_once '../config/session.php';
    require_once '../config/compiler_paths.php';

    // Check if user is logged in
    if (!isStudentSessionValid()) {
        throw new Exception('Unauthorized');
    }

    // Get JSON input
    $input_raw = file_get_contents('php://input');
    $input = json_decode($input_raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON input: ' . json_last_error_msg());
    }

    if (!isset($input['code']) || !isset($input['language']) || !isset($input['problem_id'])) {
        throw new Exception('Missing required parameters');
    }

    $code = $input['code'];
    $language = $input['language'];
    $problem_id = $input['problem_id'];
    $user_id = $_SESSION['student']['user_id'];
    
    // Get contest ID from request if available, fallback to session
    $contest_id = isset($input['contest_id']) ? (int)$input['contest_id'] : ($_SESSION['student']['current_contest_id'] ?? null);
    
    // Log session data for debugging
    error_log("User ID from session: " . $user_id);
    error_log("Contest ID from request/session: " . ($contest_id ? $contest_id : "Not set"));
    error_log("Problem ID from request: " . $problem_id);
    
    // Check if contest ID is available
    if (!$contest_id) {
        // Try to find a valid contest for this problem as a fallback
        $stmt = $conn->prepare("
            SELECT cp.contest_id 
            FROM contest_problems cp
            WHERE cp.problem_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $problem_id);
        $stmt->execute();
        $contest_result = $stmt->get_result();
        if ($contest_result->num_rows > 0) {
            $contest_row = $contest_result->fetch_assoc();
            $contest_id = $contest_row['contest_id'];
            error_log("Found contest ID from database: " . $contest_id);
            // Update session for future requests
            $_SESSION['student']['current_contest_id'] = $contest_id;
        } else {
            throw new Exception("Could not determine contest for this problem. Please go back to the contest page and try again.");
        }
    }

    // Verify that the problem exists and belongs to the current contest
    $stmt = $conn->prepare("
        SELECT p.id, p.title, p.input_format, p.output_format, p.constraints, p.points 
        FROM problems p
        JOIN contest_problems cp ON p.id = cp.problem_id
        WHERE p.id = ? AND cp.contest_id = ?
    ");
    $stmt->bind_param("ii", $problem_id, $contest_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $problem = $result->fetch_assoc();
    
    error_log("Checking problem in contest: Problem ID=$problem_id, Contest ID=$contest_id, Result=" . ($problem ? "Found" : "Not Found"));

    if (!$problem) {
        // Let's do a direct check to see if the problem exists at all
        $stmt = $conn->prepare("SELECT id, title FROM problems WHERE id = ?");
        $stmt->bind_param("i", $problem_id);
        $stmt->execute();
        $direct_problem_result = $stmt->get_result();
        $problem_exists = $direct_problem_result->num_rows > 0;
        error_log("Direct problem check: Problem ID=$problem_id, Exists=" . ($problem_exists ? "Yes" : "No"));
        
        // Let's check if there's any contest_problems entry for this problem
        $stmt = $conn->prepare("SELECT contest_id FROM contest_problems WHERE problem_id = ?");
        $stmt->bind_param("i", $problem_id);
        $stmt->execute();
        $cp_result = $stmt->get_result();
        $has_contest = $cp_result->num_rows > 0;
        error_log("Contest_problems check: Problem ID=$problem_id, Has contest entry=" . ($has_contest ? "Yes" : "No"));
        if ($has_contest) {
            while ($cp_row = $cp_result->fetch_assoc()) {
                error_log("Contest_problems association: Problem ID=$problem_id is associated with Contest ID=" . $cp_row['contest_id']);
            }
        }
        
        // If problem doesn't exist or doesn't belong to current contest, try to get the problem directly
        $stmt = $conn->prepare("SELECT id, title, input_format, output_format, constraints, points FROM problems WHERE id = ?");
        $stmt->bind_param("i", $problem_id);
        $stmt->execute();
        $problem_result = $stmt->get_result();
        
        if ($problem_result->num_rows > 0) {
            // Problem exists but may not be connected to the contest
            $problem = $problem_result->fetch_assoc();
            error_log("Problem found directly in database: " . $problem['title']);
            
            // Try to find the correct contest for this problem
            $stmt = $conn->prepare("
                SELECT cp.contest_id 
                FROM contest_problems cp
                WHERE cp.problem_id = ?
                LIMIT 1
            ");
            $stmt->bind_param("i", $problem_id);
            $stmt->execute();
            $contest_result = $stmt->get_result();
            if ($contest_result->num_rows > 0) {
                $contest_row = $contest_result->fetch_assoc();
                $contest_id = $contest_row['contest_id'];
                error_log("Found correct contest ID: " . $contest_id);
                // Update session for future requests
                $_SESSION['student']['current_contest_id'] = $contest_id;
            } else {
                throw new Exception("This problem exists but isn't associated with any contest. Please contact an administrator.");
            }
        } else {
            throw new Exception("Problem not found for problem_id: $problem_id. Please refresh the page and try again.");
        }
    }

    // Check submission limit on the backend
    $stmt = $conn->prepare("SELECT max_submissions FROM contests WHERE id = ?");
    $stmt->bind_param("i", $contest_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $contest = $result->fetch_assoc();
    
    if (!$contest) {
        throw new Exception('Contest not found');
    }
    
    $max_submissions = intval($contest['max_submissions']);
    
    // If max_submissions is greater than 0, check the limit
    if ($max_submissions > 0) {
        // Count existing submissions for this user and problem in THIS CONTEST
        $stmt = $conn->prepare("SELECT COUNT(*) AS submission_count FROM submissions WHERE user_id = ? AND problem_id = ? AND contest_id = ?");
        $stmt->bind_param("iii", $user_id, $problem_id, $contest_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        
        $submissions_used = intval($data['submission_count']);
        
        // If user has reached the limit, reject the submission
        if ($submissions_used >= $max_submissions) {
            throw new Exception("You have reached the maximum limit of $max_submissions submissions for this problem");
        }
    }

    // Get problem details for generating test cases
    $stmt = $conn->prepare("SELECT title, input_format, output_format, constraints, points FROM problems WHERE id = ?");
    $stmt->bind_param("i", $problem_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $problem = $result->fetch_assoc();

    if (!$problem) {
        throw new Exception('Problem not found for problem_id: ' . $problem_id);
    }

    // Check if test_cases table exists
    function tableExists($conn, $table) {
        $result = $conn->query("SHOW TABLES LIKE '{$table}'");
        return $result->num_rows > 0;
    }

    // Function to get test cases from database
    function getTestCasesFromDatabase($conn, $problem_id) {
        $testCases = [];
        
        // Get all test cases (both visible and hidden) for submissions
        $stmt = $conn->prepare("SELECT input, expected_output, is_visible FROM test_cases WHERE problem_id = ? ORDER BY id ASC");
        $stmt->bind_param("i", $problem_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $testCases[] = [
                'test_input' => $row['input'],
                'test_output' => $row['expected_output'],
                'is_visible' => $row['is_visible']
            ];
        }
        
        return $testCases;
    }

    // Get test cases
    $testCases = [];
    if (tableExists($conn, 'test_cases')) {
        // Try to get test cases from database
        $testCases = getTestCasesFromDatabase($conn, $problem_id);
    }

    // If no test cases found, generate a simple test case
    if (empty($testCases)) {
        $testCases[] = [
            'test_input' => 'Default test input',
            'test_output' => 'Default test output',
            'is_visible' => 1
        ];
    }

    // Create a temporary directory for the code
    $tempDir = '../temp/' . uniqid();
    if (!file_exists('../temp')) {
        mkdir('../temp', 0777, true);
    }
    mkdir($tempDir, 0777, true);
    
    // Set main class name for Java - always use Solution
    $mainClassName = 'Solution'; 
    
    // Log detected Java class name but don't use it (for debug purposes only)
    if ($language === 'java') {
        // Extract the public class name from the code using regex
        if (preg_match('/public\s+class\s+([a-zA-Z0-9_]+)/', $code, $matches)) {
            $detectedClassName = $matches[1];
            error_log("Detected Java class name: " . $detectedClassName . " - Will use Solution instead");
            
            // If user used a different class name, force it to be Solution
            if ($detectedClassName !== 'Solution') {
                $code = preg_replace('/public\s+class\s+' . preg_quote($detectedClassName, '/') . '\b/', 'public class Solution', $code);
                error_log("Modified Java code to use Solution class instead of " . $detectedClassName);
            }
        } else {
            error_log("No public class found in Java code - may cause compilation errors");
        }
    }
    
    // File extensions and compilation commands for each language
    $config = [
        'cpp' => [
            'extension' => 'cpp',
            'compile' => true,
            'compile_cmd' => getCompilerCommand('cpp', 'g++') . ' "{source}" -o "{executable}"',
            'run' => '"{executable}"',
            'filename' => 'solution.cpp',
            'check_cmd' => getCompilerCommand('cpp', 'g++') . ' --version'
        ],
        'java' => [
            'extension' => 'java',
            'compile' => true,
            'compile_cmd' => getCompilerCommand('java', 'javac') . ' "{source}"',
            'run' => getCompilerCommand('java', 'java') . ' -classpath "{classdir}" {mainclass}',
            'filename' => $mainClassName . '.java',
            'mainclass' => $mainClassName,
            'check_cmd' => getCompilerCommand('java', 'javac') . ' -version'
        ],
        'python' => [
            'extension' => 'py',
            'compile' => false,
            'run' => getCompilerCommand('python', 'python') . ' "{source}"',
            'filename' => 'solution.py',
            'check_cmd' => getCompilerCommand('python', 'python') . ' --version'
        ]
    ];

    if (!isset($config[$language])) {
        throw new Exception('Unsupported language');
    }

    $langConfig = $config[$language];
    
    // Check if compiler/interpreter is available
    $versionOutput = [];
    $versionStatus = 0;
    exec($langConfig['check_cmd'] . " 2>&1", $versionOutput, $versionStatus);
    if ($versionStatus !== 0) {
        switch($language) {
            case 'cpp':
                throw new Exception("C++ compiler (g++) is not installed. Please install MinGW and add it to PATH.");
            case 'java':
                throw new Exception("Java compiler (javac) is not installed. Please install JDK and add it to PATH.");
            case 'python':
                throw new Exception("Python interpreter is not installed. Please install Python and add it to PATH.");
            default:
                throw new Exception("Required compiler/interpreter is not installed.");
        }
    }

    // For Java, ensure the source file matches the class name
    if ($language === 'java') {
        $sourceFile = $tempDir . '/' . $mainClassName . '.java';
        error_log("Java code will be saved as: " . $sourceFile);
    } else {
        $sourceFile = $tempDir . '/' . $langConfig['filename'];
    }
    
    $executableFile = $tempDir . '/solution';
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $executableFile .= '.exe';
    }
    $inputFile = $tempDir . '/input.txt';
    $outputFile = $tempDir . '/output.txt';
    $errorFile = $tempDir . '/error.txt';

    // Save the code to a file - extra precaution for Java files
    if ($language === 'java') {
        // For Java, explicitly save with the class name to ensure they match
        file_put_contents($sourceFile, $code);
        error_log("Java code saved to: " . $sourceFile . " with class name: " . $mainClassName);
    } else {
        file_put_contents($sourceFile, $code);
        error_log("Code saved to: " . $sourceFile);
    }

    // Compile the code if needed
    if ($langConfig['compile']) {
        $compileCmd = str_replace(
            ['{source}', '{executable}', '{classdir}'],
            [$sourceFile, $executableFile, $tempDir],
            $langConfig['compile_cmd']
        );
        $compileOutput = [];
        $compileStatus = 0;
        
        // Log the compilation command
        error_log("Compilation command: " . $compileCmd);
        
        exec($compileCmd . " 2> " . escapeshellarg($errorFile), $compileOutput, $compileStatus);

        if ($compileStatus !== 0) {
            $error = file_get_contents($errorFile);
            throw new Exception("Compilation Error: " . $error);
        }
    }

    // Run against each test case
    $results = [];
    $allPassed = true;
    $testCasesPassed = 0;
    $totalTestCases = count($testCases);
    $visibleTestCasesPassed = 0;
    $totalVisibleTestCases = 0;
    $hiddenTestCasesPassed = 0;
    $totalHiddenTestCases = 0;

    foreach ($testCases as $index => $testCase) {
        $isVisible = isset($testCase['is_visible']) ? $testCase['is_visible'] : 1;
        
        if ($isVisible) {
            $totalVisibleTestCases++;
        } else {
            $totalHiddenTestCases++;
        }

        // Save test input
        file_put_contents($inputFile, $testCase['test_input']);

        // Run the code
        $runCmd = str_replace(
            ['{source}', '{executable}', '{classdir}', '{mainclass}'],
            [$sourceFile, $executableFile, $tempDir, $langConfig['mainclass'] ?? 'Solution'],
            $langConfig['run']
        );
        $runCmd .= " < " . escapeshellarg($inputFile) . " > " . escapeshellarg($outputFile) . " 2>> " . escapeshellarg($errorFile);

        $startTime = microtime(true);
        $runOutput = [];
        $runStatus = 0;
        exec($runCmd, $runOutput, $runStatus);
        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        if ($runStatus !== 0) {
            $error = file_get_contents($errorFile);
            throw new Exception("Runtime Error: " . $error);
        }

        $output = trim(file_get_contents($outputFile));
        $expected = trim($testCase['test_output']);
        $passed = $output === $expected;
        $allPassed = $allPassed && $passed;

        if ($passed) {
            $testCasesPassed++;
            if ($isVisible) {
                $visibleTestCasesPassed++;
            } else {
                $hiddenTestCasesPassed++;
            }
        }

        $results[] = [
            'passed' => $passed,
            'time' => $executionTime,
            'expected' => $passed ? null : $expected,
            'actual' => $passed ? null : $output,
            'input' => $testCase['test_input'],
            'is_visible' => $isVisible
        ];
    }

    // Save submission to database
    $status = $allPassed ? 'accepted' : 'wrong_answer';
    $score = $testCasesPassed * ($problem['points'] / $totalTestCases);
    
    // Log submission details before saving to database
    error_log("Saving submission: User ID=$user_id, Problem ID=$problem_id, Contest ID=$contest_id, Status=$status");
    error_log("Test Cases Summary: Total=$totalTestCases, Passed=$testCasesPassed");
    error_log("Visible Test Cases: Total=$totalVisibleTestCases, Passed=$visibleTestCasesPassed");
    error_log("Hidden Test Cases: Total=$totalHiddenTestCases, Passed=$hiddenTestCasesPassed");
    
    $stmt = $conn->prepare("INSERT INTO submissions (user_id, problem_id, contest_id, code, language, status, test_cases_passed, total_test_cases, score) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        error_log("Database prepare error: " . $conn->error);
        throw new Exception("Failed to prepare submission statement: " . $conn->error);
    }
    
    $stmt->bind_param("iiisssdii", $user_id, $problem_id, $contest_id, $code, $language, $status, $testCasesPassed, $totalTestCases, $score);
    $result = $stmt->execute();
    
    if (!$result) {
        error_log("Database execution error: " . $stmt->error);
        throw new Exception("Failed to save submission: " . $stmt->error);
    } else {
        $submission_id = $conn->insert_id;
        error_log("Submission saved successfully with ID: $submission_id");
    }

    // Clean up
    cleanupTempDir($tempDir);

    // Discard any PHP output and warnings
    ob_end_clean();
    
    // Return the result as JSON
    echo json_encode([
        'status' => 'success',
        'test_results' => array_map(function($result) {
            return [
                'status' => $result['passed'] ? 'passed' : 'failed',
                'input' => $result['input'],
                'expected' => $result['expected'],
                'actual' => $result['actual'],
                'execution_time' => $result['time'],
                'error' => null
            ];
        }, array_filter($results, function($result) {
            // Only return visible test cases
            return $result['is_visible'] == 1;
        })),
        'summary' => [
            'visible_test_cases' => [
                'total' => $totalVisibleTestCases,
                'passed' => $visibleTestCasesPassed
            ],
            'hidden_test_cases' => [
                'total' => $totalHiddenTestCases,
                'passed' => $hiddenTestCasesPassed
            ],
            'all_test_cases_passed' => $allPassed
        ]
    ]);

} catch (Exception $e) {
    // Clean up on error
    if (isset($tempDir) && is_dir($tempDir)) {
        cleanupTempDir($tempDir);
    }
    
    // Discard any PHP output and warnings
    ob_end_clean();
    
    // Return error as JSON
    echo json_encode(['error' => $e->getMessage()]);
}

// Function to clean up temporary directory
function cleanupTempDir($dir) {
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . "/" . $object)) {
                    cleanupTempDir($dir . "/" . $object);
                } else {
                    @unlink($dir . "/" . $object);
                }
            }
        }
        @rmdir($dir);
    }
}
?> 