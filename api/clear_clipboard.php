<?php
// Set JSON content type and CORS headers for tunnel access
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request (important for CORS with tunnels)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/session.php';

// Check if the user is logged in as a student
// Skip authentication check if running in tunnel mode with a special parameter
$skipAuth = isset($_GET['tunnel_mode']) && $_GET['tunnel_mode'] === 'true';
if (!$skipAuth && !isStudentSessionValid()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Log the request
$log_file = '../logs/security_actions.log';
$log_dir = dirname($log_file);
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0777, true);
}

$user_id = $_SESSION['student']['user_id'] ?? 'unknown';
$timestamp = date('Y-m-d H:i:s');
$event = isset($_GET['event']) ? $_GET['event'] : 'unknown';
$ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

$log_message = "[$timestamp] User ID: $user_id, IP: $ip_address, Event: $event, Action: clear_clipboard\n";
file_put_contents($log_file, $log_message, FILE_APPEND);

// Try to determine the most suitable Python interpreter
function findPythonInterpreter() {
    $candidates = ['python3', 'python', 'py'];
    foreach ($candidates as $cmd) {
        $output = [];
        $return_var = -1;
        @exec($cmd . ' --version 2>&1', $output, $return_var);
        if ($return_var === 0) {
            return $cmd;
        }
    }
    return null;
}

// Path to the Python script
$pythonScript = '../scripts/clear_clipboard.py';

// Check if the script exists
if (!file_exists($pythonScript)) {
    error_log("Script not found: $pythonScript");
    echo json_encode([
        'success' => false, 
        'message' => 'Security script not found',
        'path' => realpath('../scripts'),
        'script' => $pythonScript
    ]);
    exit();
}

// Determine OS and Python command
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    // Windows
    $python = 'python';
    $command = $python . ' "' . $pythonScript . '" 2>&1';
} else {
    // Linux/Unix/Mac - try to find the best Python interpreter
    $python = findPythonInterpreter();
    if (!$python) {
        error_log("No Python interpreter found");
        echo json_encode(['success' => false, 'message' => 'No Python interpreter found']);
        exit();
    }
    $command = $python . ' "' . $pythonScript . '" 2>&1';
}

// Execute the command with a timeout
$output = [];
$return_var = -1;

// Log the command we're about to execute
error_log("Executing command: $command");

// Use proc_open for better control and timeout
$descriptorspec = [
    0 => ["pipe", "r"],  // stdin
    1 => ["pipe", "w"],  // stdout
    2 => ["pipe", "w"]   // stderr
];

$process = proc_open($command, $descriptorspec, $pipes, null, null);

if (is_resource($process)) {
    // Close stdin
    fclose($pipes[0]);
    
    // Read stdout
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    
    // Read stderr
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    
    // Get the exit code
    $return_var = proc_close($process);
    
    $output = array_filter(explode("\n", $stdout));
    if ($stderr) {
        error_log("Python script stderr: $stderr");
        $output[] = "Error: $stderr";
    }
} else {
    error_log("Failed to execute process");
    echo json_encode(['success' => false, 'message' => 'Failed to execute process']);
    exit();
}

// Log the result
error_log("Clear clipboard command executed with return code: $return_var");
error_log("Output: " . implode("\n", $output));

// Return the result
if ($return_var === 0) {
    echo json_encode([
        'success' => true, 
        'message' => 'Clipboard cleared successfully',
        'output' => implode("\n", $output),
        'timestamp' => time()
    ]);
} else {
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to clear clipboard', 
        'error' => implode("\n", $output),
        'command' => $command,
        'python' => $python,
        'returnCode' => $return_var,
        'timestamp' => time()
    ]);
}
?> 