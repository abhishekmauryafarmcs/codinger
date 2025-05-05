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

if (!isset($input['code']) || !isset($input['language']) || !isset($input['problem_id'])) {
    echo json_encode(['error' => 'Missing required parameters']);
    exit();
}

$code = $input['code'];
$language = $input['language'];
$problem_id = $input['problem_id'];
$user_id = $_SESSION['user_id'];

// Get problem details for generating test cases
$stmt = $conn->prepare("SELECT title, input_format, output_format, constraints, sample_input, sample_output FROM problems WHERE id = ?");
$stmt->bind_param("i", $problem_id);
$stmt->execute();
$result = $stmt->get_result();
$problem = $result->fetch_assoc();

if (!$problem) {
    echo json_encode(['error' => 'Problem not found']);
    exit();
}

// Check if test_cases table exists
function tableExists($conn, $table) {
    $result = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $result->num_rows > 0;
}

// Function to get test cases from database
function getTestCasesFromDatabase($conn, $problem_id) {
    $testCases = [];
    
    // Only get visible test cases for student submissions
    $stmt = $conn->prepare("SELECT input, expected_output FROM test_cases WHERE problem_id = ? AND is_visible = 1 ORDER BY id ASC");
    $stmt->bind_param("i", $problem_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $testCases[] = [
            'test_input' => $row['input'],
            'test_output' => $row['expected_output']
        ];
    }
    
    return $testCases;
}

// Function to generate random test cases based on problem type
function generateTestCases($problem) {
    $testCases = [];
    
    // Split sample input and output into separate test cases
    $sample_inputs = explode("\n", trim($problem['sample_input']));
    $sample_outputs = explode("\n", trim($problem['sample_output']));
    
    // Add each sample test case separately
    foreach ($sample_inputs as $index => $input) {
        if (isset($sample_outputs[$index])) {
            $testCases[] = [
                'test_input' => trim($input),
                'test_output' => trim($sample_outputs[$index])
            ];
        }
    }

    // Check if input format mentions string or characters
    $isStringProblem = (
        stripos($problem['input_format'], 'string') !== false ||
        stripos($problem['input_format'], 'character') !== false ||
        stripos($problem['input_format'], 'text') !== false ||
        stripos($problem['input_format'], 'word') !== false
    );

    if ($isStringProblem) {
        // Generate string-based test cases
        $testTypes = [
            'basic' => function() {
                return generateRandomString(5, 10, 'abcdefghijklmnopqrstuvwxyz');
            },
            'mixed_case' => function() {
                return generateRandomString(5, 10, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ');
            },
            'alphanumeric' => function() {
                return generateRandomString(5, 10, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');
            },
            'with_spaces' => function() {
                $words = [];
                for ($i = 0; $i < rand(2, 4); $i++) {
                    $words[] = generateRandomString(3, 7, 'abcdefghijklmnopqrstuvwxyz');
                }
                return implode(' ', $words);
            }
        ];

        // Generate test cases based on problem type
        foreach ($testTypes as $type => $generator) {
            $input = $generator();
            $output = '';

            // Determine expected output based on problem description
            if (stripos($problem['title'], 'reverse') !== false) {
                $output = strrev($input);
            } 
            else if (stripos($problem['title'], 'uppercase') !== false || 
                     stripos($problem['title'], 'upper case') !== false) {
                $output = strtoupper($input);
            }
            else if (stripos($problem['title'], 'lowercase') !== false || 
                     stripos($problem['title'], 'lower case') !== false) {
                $output = strtolower($input);
            }
            else if (stripos($problem['title'], 'length') !== false) {
                $output = (string)strlen($input);
            }
            else if (stripos($problem['title'], 'count') !== false && 
                     stripos($problem['title'], 'vowel') !== false) {
                $output = (string)preg_match_all('/[aeiou]/i', $input);
            }
            else if (stripos($problem['title'], 'count') !== false && 
                     stripos($problem['title'], 'word') !== false) {
                $output = (string)str_word_count($input);
            }
            else {
                // Default behavior - use the first sample case to determine transformation
                $sample_input = trim($sample_inputs[0]);
                $sample_output = trim($sample_outputs[0]);
                if (strlen($sample_input) === strlen($sample_output)) {
                    // Might be character transformation
                    $output = strtr($input, $sample_input, $sample_output);
                }
            }

            if ($output !== '') {
                $testCases[] = [
                    'test_input' => $input,
                    'test_output' => $output
                ];
            }
        }
    } else if (stripos($problem['title'], 'sum') !== false) {
        // Existing number sum logic
        for ($i = 0; $i < 5; $i++) {
            $a = rand(1, 1000);
            $b = rand(1, 1000);
            $testCases[] = [
                'test_input' => "$a $b",
                'test_output' => (string)($a + $b)
            ];
        }
    } else {
        // Existing default logic for number-based problems
        $constraints = strtolower($problem['constraints']);
        $min = 1;
        $max = 1000;
        
        if (preg_match('/(\d+)\s*[<≤]\s*[a-z]\s*[<≤]\s*(\d+)/', $constraints, $matches)) {
            $min = intval($matches[1]);
            $max = intval($matches[2]);
        }

        for ($i = 0; $i < 5; $i++) {
            if (stripos($problem['input_format'], 'first line contains n') !== false) {
                $n = rand(1, 10);
                $input = $n . "\n";
                $numbers = [];
                for ($j = 0; $j < $n; $j++) {
                    $numbers[] = rand($min, $max);
                }
                $input .= implode(' ', $numbers);
                
                if (stripos($problem['output_format'], 'sum') !== false) {
                    $output = array_sum($numbers);
                } elseif (stripos($problem['output_format'], 'maximum') !== false || 
                         stripos($problem['output_format'], 'max') !== false) {
                    $output = max($numbers);
                } elseif (stripos($problem['output_format'], 'minimum') !== false || 
                         stripos($problem['output_format'], 'min') !== false) {
                    $output = min($numbers);
                }
            } else {
                $a = rand($min, $max);
                $b = rand($min, $max);
                $input = "$a $b";
                $output = $a + $b;
            }
            
            $testCases[] = [
                'test_input' => $input,
                'test_output' => (string)$output
            ];
        }
    }
    
    return $testCases;
}

// Helper function to generate random strings
function generateRandomString($minLength, $maxLength, $characters) {
    $length = rand($minLength, $maxLength);
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

// Get or generate test cases
$testCases = [];
if (tableExists($conn, 'test_cases')) {
    // Try to get test cases from database
    $testCases = getTestCasesFromDatabase($conn, $problem_id);
}

// Fall back to generating test cases if none found in database
if (empty($testCases)) {
    $testCases = generateTestCases($problem);
}

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

    // Run against each test case
    $results = [];
    $allPassed = true;
    foreach ($testCases as $index => $testCase) {
        // Save test input
        file_put_contents($inputFile, $testCase['test_input']);

        // Run the code
        $runCmd = str_replace(
            ['{source}', '{executable}'],
            [$sourceFile, $executableFile],
            $langConfig['run']
        );
        $runCmd .= " < " . escapeshellarg($inputFile) . " > " . escapeshellarg($outputFile) . " 2>> " . escapeshellarg($errorFile);

        $startTime = microtime(true);
        exec($runCmd, $runOutput, $runStatus);
        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        if ($runStatus !== 0) {
            $error = file_get_contents($errorFile);
            throw new Exception("Runtime Error:\n" . $error);
        }

        $output = trim(file_get_contents($outputFile));
        $expected = trim($testCase['test_output']);
        $passed = $output === $expected;
        $allPassed = $allPassed && $passed;

        $results[] = [
            'passed' => $passed,
            'time' => $executionTime,
            'expected' => $passed ? null : $expected,
            'actual' => $passed ? null : $output,
            'input' => $testCase['test_input'] // Include input for better debugging
        ];
    }

    // Save submission to database
    $status = $allPassed ? 'accepted' : 'wrong_answer';
    $stmt = $conn->prepare("INSERT INTO submissions (user_id, problem_id, code, language, status) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iisss", $user_id, $problem_id, $code, $language, $status);
    $stmt->execute();

    // Clean up
    exec("rm -rf " . escapeshellarg($tempDir));

    echo json_encode([
        'status' => $status,
        'testCases' => $results
    ]);

} catch (Exception $e) {
    // Clean up on error
    if (isset($tempDir) && is_dir($tempDir)) {
        exec("rm -rf " . escapeshellarg($tempDir));
    }
    echo json_encode(['error' => $e->getMessage()]);
}
?> 