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
    $user_id = $_SESSION['user_id'];

    // Get problem details for generating test cases
    $stmt = $conn->prepare("SELECT title, input_format, output_format, constraints, points FROM problems WHERE id = ?");
    $stmt->bind_param("i", $problem_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $problem = $result->fetch_assoc();

    if (!$problem) {
        throw new Exception('Problem not found');
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
    
    // File extensions and compilation commands for each language
    $config = [
        'cpp' => [
            'extension' => 'cpp',
            'compile' => true,
            'compile_cmd' => 'g++ "{source}" -o "{executable}"',
            'run' => '"{executable}"',
            'filename' => 'solution.cpp',
            'check_cmd' => 'g++ --version'
        ],
        'java' => [
            'extension' => 'java',
            'compile' => true,
            'compile_cmd' => 'javac "{source}"',
            'run' => 'java -classpath "{classdir}" Solution',
            'filename' => 'Solution.java',
            'check_cmd' => 'javac -version'
        ],
        'python' => [
            'extension' => 'py',
            'compile' => false,
            'run' => 'python "{source}"',
            'filename' => 'solution.py',
            'check_cmd' => 'python --version'
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

    $sourceFile = $tempDir . '/' . $langConfig['filename'];
    $executableFile = $tempDir . '/solution';
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $executableFile .= '.exe';
    }
    $inputFile = $tempDir . '/input.txt';
    $outputFile = $tempDir . '/output.txt';
    $errorFile = $tempDir . '/error.txt';

    // Save the code to a file
    file_put_contents($sourceFile, $code);

    // Compile the code if needed
    if ($langConfig['compile']) {
        $compileCmd = str_replace(
            ['{source}', '{executable}', '{classdir}'],
            [$sourceFile, $executableFile, $tempDir],
            $langConfig['compile_cmd']
        );
        $compileOutput = [];
        $compileStatus = 0;
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
    foreach ($testCases as $index => $testCase) {
        // Save test input
        file_put_contents($inputFile, $testCase['test_input']);

        // Run the code
        $runCmd = str_replace(
            ['{source}', '{executable}', '{classdir}'],
            [$sourceFile, $executableFile, $tempDir],
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
        }

        $results[] = [
            'passed' => $passed,
            'time' => $executionTime,
            'expected' => $passed ? null : $expected,
            'actual' => $passed ? null : $output,
            'input' => $testCase['test_input'], // Include input for better debugging
            'is_visible' => isset($testCase['is_visible']) ? $testCase['is_visible'] : 1
        ];
    }

    // Save submission to database
    $status = $allPassed ? 'accepted' : 'wrong_answer';
    $score = $testCasesPassed * ($problem['points'] / $totalTestCases);
    $stmt = $conn->prepare("INSERT INTO submissions (user_id, problem_id, code, language, status, test_cases_passed, total_test_cases, score) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisssidi", $user_id, $problem_id, $code, $language, $status, $testCasesPassed, $totalTestCases, $score);
    $stmt->execute();

    // Clean up
    cleanupTempDir($tempDir);

    // Discard any PHP output and warnings
    ob_end_clean();
    
    // Return the result as JSON
    echo json_encode([
        'status' => $status,
        'testCases' => $results
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