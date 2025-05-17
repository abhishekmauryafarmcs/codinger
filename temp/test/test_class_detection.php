<?php
require_once __DIR__ . '/../../config/compiler_paths.php';

// Test code with different class names
$testCases = [
    [
        'name' => 'Standard Solution class',
        'code' => 'public class Solution { 
    public static void main(String[] args) {
        System.out.println("Hello from Solution class");
    }
}'
    ],
    [
        'name' => 'ArraySum class',
        'code' => 'public class ArraySum {
    public static void main(String[] args) {
        int[] numbers = {1, 2, 3, 4, 5};
        int sum = 0;
        for (int num : numbers) {
            sum += num;
        }
        System.out.println("Sum: " + sum);
    }
}'
    ],
    [
        'name' => 'Class with spaces in declaration',
        'code' => 'public   class   HelloWorld   {
    public static void main(String[] args) {
        System.out.println("Hello, World!");
    }
}'
    ]
];

// Get the Java commands
$javacCmd = getCompilerCommand('java', 'javac');
$javaCmd = getCompilerCommand('java', 'java');

echo "Using Java compiler at: $javacCmd\n";
echo "Using Java runtime at: $javaCmd\n\n";

// Function to extract class name
function extractClassName($code) {
    if (preg_match('/public\s+class\s+([a-zA-Z0-9_]+)/', $code, $matches)) {
        return $matches[1];
    }
    return 'Solution'; // Default
}

// Test directory
$testDir = __DIR__ . '/java_class_test';
if (!file_exists($testDir)) {
    mkdir($testDir, 0777, true);
}

// Run tests
foreach ($testCases as $index => $test) {
    echo "Test Case #" . ($index + 1) . ": " . $test['name'] . "\n";
    echo "-----------------------------------------------------\n";
    
    // Extract class name
    $className = extractClassName($test['code']);
    echo "Extracted class name: $className\n";
    
    // Create source file
    $sourceFile = $testDir . '/' . $className . '.java';
    file_put_contents($sourceFile, $test['code']);
    echo "Created source file: $sourceFile\n";
    
    // Compile
    $compileCmd = "$javacCmd " . escapeshellarg($sourceFile);
    echo "Compiling with: $compileCmd\n";
    $compileOutput = [];
    $compileStatus = 0;
    exec($compileCmd . " 2>&1", $compileOutput, $compileStatus);
    
    if ($compileStatus !== 0) {
        echo "Compilation failed:\n";
        echo implode("\n", $compileOutput) . "\n";
    } else {
        echo "Compilation successful.\n";
        
        // Run
        $runCmd = "$javaCmd -classpath " . escapeshellarg($testDir) . " $className";
        echo "Running with: $runCmd\n";
        $runOutput = [];
        $runStatus = 0;
        exec($runCmd . " 2>&1", $runOutput, $runStatus);
        
        if ($runStatus !== 0) {
            echo "Execution failed:\n";
            echo implode("\n", $runOutput) . "\n";
        } else {
            echo "Execution output:\n";
            echo implode("\n", $runOutput) . "\n";
        }
    }
    
    echo "\n\n";
}

// Clean up
echo "Cleaning up test files...\n";
array_map('unlink', glob("$testDir/*.java"));
array_map('unlink', glob("$testDir/*.class"));
rmdir($testDir);
echo "Done.\n";
?> 