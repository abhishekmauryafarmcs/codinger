<?php
// Include our configuration
require_once __DIR__ . '/../../config/compiler_paths.php';

// Get the Java commands
$javacCmd = getCompilerCommand('java', 'javac');
$javaCmd = getCompilerCommand('java', 'java');

echo "Using Java compiler at: $javacCmd\n";
echo "Using Java runtime at: $javaCmd\n\n";

// Compile the Java file
$compileCmd = "$javacCmd " . __DIR__ . "/Solution.java";
echo "Compiling with: $compileCmd\n";
$compileOutput = [];
$compileStatus = 0;
exec($compileCmd . " 2>&1", $compileOutput, $compileStatus);

if ($compileStatus !== 0) {
    echo "Compilation failed:\n";
    echo implode("\n", $compileOutput) . "\n";
    exit(1);
}

echo "Compilation successful.\n\n";

// Run the Java class
$runCmd = "$javaCmd -classpath " . __DIR__ . " Solution";
echo "Running with: $runCmd\n";
$runOutput = [];
$runStatus = 0;
exec($runCmd . " 2>&1", $runOutput, $runStatus);

if ($runStatus !== 0) {
    echo "Execution failed:\n";
    echo implode("\n", $runOutput) . "\n";
    exit(1);
}

echo "Execution output:\n";
echo implode("\n", $runOutput) . "\n";
?> 