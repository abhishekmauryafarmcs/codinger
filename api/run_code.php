<?php
header('Content-Type: application/json');
session_start();
require_once '../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['code']) || !isset($input['language'])) {
    echo json_encode(['error' => 'Missing required parameters']);
    exit();
}

$code = $input['code'];
$language = $input['language'];
$customInput = isset($input['input']) ? $input['input'] : '';

// Create a temporary directory for the code
$tempDir = '../temp/' . uniqid();
mkdir($tempDir, 0777, true);

// File extensions and compilation commands for each language
$config = [
    'cpp' => [
        'extension' => 'cpp',
        'compile' => 'g++ "{source}" -o "{executable}"',
        'run' => '"{executable}"',
        'filename' => 'solution.cpp',
        'check_cmd' => 'g++ --version'
    ],
    'java' => [
        'extension' => 'java',
        'compile' => 'javac "{source}"',
        'run' => 'java Solution',
        'filename' => 'Solution.java',
        'check_cmd' => 'javac -version'
    ],
    'python' => [
        'extension' => 'py',
        'compile' => null,
        'run' => 'python "{source}"',
        'filename' => 'solution.py',
        'check_cmd' => 'python --version'
    ]
];

try {
    if (!isset($config[$language])) {
        throw new Exception('Unsupported language');
    }

    $langConfig = $config[$language];
    
    // Check if compiler/interpreter is available
    exec($langConfig['check_cmd'] . " 2>&1", $versionOutput, $versionStatus);
    if ($versionStatus !== 0) {
        switch($language) {
            case 'cpp':
                throw new Exception("C++ compiler (g++) is not installed. Please install MinGW and add it to PATH.\n\nTo install:\n1. Download MinGW from https://sourceforge.net/projects/mingw/\n2. Run installer and select 'mingw32-base' and 'mingw32-gcc-g++'\n3. Add C:\\MinGW\\bin to System PATH");
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
    file_put_contents($inputFile, $customInput);

    // Compile the code if needed
    if ($langConfig['compile']) {
        $compileCmd = str_replace(
            ['{source}', '{executable}'],
            [$sourceFile, $executableFile],
            $langConfig['compile']
        );
        exec($compileCmd . " 2> " . escapeshellarg($errorFile), $compileOutput, $compileStatus);

        if ($compileStatus !== 0) {
            $error = file_get_contents($errorFile);
            throw new Exception("Compilation Error:\n" . $error);
        }
    }

    // Run the code
    $runCmd = str_replace(
        ['{source}', '{executable}'],
        [$sourceFile, $executableFile],
        $langConfig['run']
    );
    $runCmd .= " < " . escapeshellarg($inputFile) . " > " . escapeshellarg($outputFile) . " 2>> " . escapeshellarg($errorFile);

    // Set timeout for execution (5 seconds)
    $startTime = microtime(true);
    exec($runCmd, $runOutput, $runStatus);
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    if ($runStatus !== 0) {
        $error = file_get_contents($errorFile);
        throw new Exception("Runtime Error:\n" . $error);
    }

    $output = file_get_contents($outputFile);

    // Clean up
    exec("rm -rf " . escapeshellarg($tempDir));

    echo json_encode([
        'output' => $output,
        'executionTime' => $executionTime
    ]);

} catch (Exception $e) {
    // Clean up on error
    if (isset($tempDir) && is_dir($tempDir)) {
        exec("rm -rf " . escapeshellarg($tempDir));
    }
    echo json_encode(['error' => $e->getMessage()]);
}
?> 