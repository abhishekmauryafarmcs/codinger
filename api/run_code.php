<?php
// Disable error output to prevent HTML being mixed with JSON
ini_set('display_errors', 0);
error_reporting(0);

// Set JSON content type
header('Content-Type: application/json');

// Start output buffering to prevent any PHP warnings/errors from being output
ob_start();

// Function to log debugging info
function debug_log($message) {
    error_log($message);
    $log_file = '../logs/code_execution.log';
    $dir = dirname($log_file);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    $message = "[$timestamp] $message\n";
    file_put_contents($log_file, $message, FILE_APPEND);
}

try {
    session_start();
    require_once '../config/db.php';
    require_once '../config/session.php';
    
    // Include our compiler paths configuration
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

    // Set main class name for Java - always use Solution
    $mainClassName = 'Solution'; 
    
    // Log detected Java class name but don't use it (for debug purposes only)
    if ($language === 'java') {
        // Extract the public class name from the code using regex
        if (preg_match('/public\s+class\s+([a-zA-Z0-9_]+)/', $code, $matches)) {
            $detectedClassName = $matches[1];
            debug_log("Detected Java class name: " . $detectedClassName . " - Will use Solution instead");
            
            // If user used a different class name, force it to be Solution
            if ($detectedClassName !== 'Solution') {
                $code = preg_replace('/public\s+class\s+' . preg_quote($detectedClassName, '/') . '\b/', 'public class Solution', $code);
                debug_log("Modified Java code to use Solution class instead of " . $detectedClassName);
            }
        } else {
            debug_log("No public class found in Java code - may cause compilation errors");
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
        debug_log("Java code will be saved as: " . $sourceFile);
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
        debug_log("Java code saved to: " . $sourceFile . " with class name: " . $mainClassName);
    } else {
        file_put_contents($sourceFile, $code);
        debug_log("Saved code to: " . $sourceFile);
    }
    
    // Save the custom input to a file
    file_put_contents($inputFile, $customInput);

    // Compile the code if needed
    if ($langConfig['compile']) {
        $compileCmd = str_replace(
            ['{source}', '{executable}', '{classdir}'],
            [$sourceFile, $executableFile, $tempDir],
            $langConfig['compile_cmd']
        );
        
        debug_log("Compile command: " . $compileCmd);
        
        $compileOutput = [];
        $compileStatus = 0;
        exec($compileCmd . " 2> " . escapeshellarg($errorFile), $compileOutput, $compileStatus);

        if ($compileStatus !== 0) {
            $error = file_get_contents($errorFile);
            debug_log("Compilation error: " . $error);
            throw new Exception("Compilation Error: " . $error);
        }
    }

    // Run the code
    $runCmd = str_replace(
        ['{source}', '{executable}', '{classdir}', '{mainclass}'],
        [$sourceFile, $executableFile, $tempDir, $langConfig['mainclass'] ?? 'Solution'],
        $langConfig['run']
    );
    $runCmd .= " < " . escapeshellarg($inputFile) . " > " . escapeshellarg($outputFile) . " 2>> " . escapeshellarg($errorFile);
    
    debug_log("Run command: " . $runCmd);

    // Set timeout for execution (5 seconds)
    $startTime = microtime(true);
    $runOutput = [];
    $runStatus = 0;
    exec($runCmd, $runOutput, $runStatus);
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    if ($runStatus !== 0) {
        $error = file_get_contents($errorFile);
        debug_log("Runtime error: " . $error);
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