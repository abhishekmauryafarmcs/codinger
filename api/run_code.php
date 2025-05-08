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

    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized');
    }

    // Get JSON input
    $input_raw = file_get_contents('php://input');
    $input = json_decode($input_raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON input: ' . json_last_error_msg());
    }

    if (!isset($input['code']) || !isset($input['language'])) {
        throw new Exception('Missing required parameters');
    }

    $code = $input['code'];
    $language = $input['language'];
    $customInput = isset($input['input']) ? $input['input'] : '';

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
    
    // Save the custom input to a file
    file_put_contents($inputFile, $customInput);

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

    // Run the code
    $runCmd = str_replace(
        ['{source}', '{executable}', '{classdir}'],
        [$sourceFile, $executableFile, $tempDir],
        $langConfig['run']
    );
    $runCmd .= " < " . escapeshellarg($inputFile) . " > " . escapeshellarg($outputFile) . " 2>> " . escapeshellarg($errorFile);

    // Set timeout for execution (5 seconds)
    $startTime = microtime(true);
    $runOutput = [];
    $runStatus = 0;
    exec($runCmd, $runOutput, $runStatus);
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    if ($runStatus !== 0) {
        $error = file_get_contents($errorFile);
        throw new Exception("Runtime Error: " . $error);
    }

    $output = file_get_contents($outputFile);

    // Clean up temporary files
    cleanupTempDir($tempDir);
    
    // Discard any PHP output and warnings
    ob_end_clean();

    // Return the result as JSON
    echo json_encode([
        'output' => $output,
        'executionTime' => $executionTime
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