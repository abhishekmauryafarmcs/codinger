<?php
/**
 * Compiler Paths Configuration
 * 
 * This file defines the paths to compilers and interpreters used by the Codinger platform.
 * Modify these paths to match your system's configuration.
 */

// Define compiler paths (use empty string for default PATH lookup)
$COMPILER_PATHS = [
    'java' => [
        'bin_dir' => 'C:\\Program Files\\Java\\jdk-17\\bin',
        'javac_cmd' => 'javac',
        'java_cmd' => 'java'
    ],
    'cpp' => [
        'bin_dir' => '',  // Use system PATH
        'compiler_cmd' => 'g++'
    ],
    'python' => [
        'bin_dir' => '',  // Use system PATH
        'interpreter_cmd' => 'python'
    ]
];

/**
 * Get the full command path for a compiler/interpreter
 * 
 * @param string $language The programming language (java, cpp, python)
 * @param string $cmd The command to get (javac, java, g++, python)
 * @return string The full command path
 */
function getCompilerCommand($language, $cmd) {
    global $COMPILER_PATHS;
    
    if (!isset($COMPILER_PATHS[$language])) {
        return $cmd; // Return the command as-is if language not configured
    }
    
    $config = $COMPILER_PATHS[$language];
    $cmdKey = '';
    
    // Determine which command key to use
    switch ($cmd) {
        case 'javac':
            $cmdKey = 'javac_cmd';
            break;
        case 'java':
            $cmdKey = 'java_cmd';
            break;
        case 'g++':
            $cmdKey = 'compiler_cmd';
            break;
        case 'python':
            $cmdKey = 'interpreter_cmd';
            break;
        default:
            return $cmd; // Return the command as-is if not recognized
    }
    
    if (!isset($config[$cmdKey])) {
        return $cmd; // Return the command as-is if command not configured
    }
    
    // If bin_dir is specified, prepend it to the command
    if (!empty($config['bin_dir'])) {
        return '"' . rtrim($config['bin_dir'], '\\') . '\\' . $config[$cmdKey] . '"';
    }
    
    // Otherwise return just the command
    return $config[$cmdKey];
}
?> 