# Codinger Compiler Configuration

This document explains how to configure custom compiler and interpreter paths for the Codinger platform.

## Overview

The Codinger platform uses a configuration file to specify the paths to compilers and interpreters. This allows for custom configurations without modifying the core code.

The configuration file is located at:
```
config/compiler_paths.php
```

## Current Configuration

The platform currently uses the following compilers/interpreters:
- Java: Custom path to JDK 17 at `C:\Program Files\Java\jdk-17\bin`
- C++: Using system PATH (g++)
- Python: Using system PATH

## Java Class Name Detection

The platform now automatically detects the public class name in Java submissions and creates the source file with the matching name. This allows students to use any public class name in their Java code without needing to manually match it with the filename.

For example, if a student writes:
```java
public class ArraySum {
    public static void main(String[] args) {
        // Code here
    }
}
```

The system will automatically save this as `ArraySum.java` instead of forcing the student to use `Solution.java`.

## How to Change Compiler Paths

1. Open the file `config/compiler_paths.php`
2. Modify the `$COMPILER_PATHS` array to point to your compiler installations
3. Save the file

### Example Configuration

```php
$COMPILER_PATHS = [
    'java' => [
        'bin_dir' => 'C:\\Program Files\\Java\\jdk-17\\bin',
        'javac_cmd' => 'javac',
        'java_cmd' => 'java'
    ],
    'cpp' => [
        'bin_dir' => 'C:\\MinGW\\bin',  // Example custom path for C++
        'compiler_cmd' => 'g++'
    ],
    'python' => [
        'bin_dir' => 'C:\\Python310',  // Example custom path for Python
        'interpreter_cmd' => 'python'
    ]
];
```

## Testing Your Configuration

You can test if your compiler paths are configured correctly by running the test script:

```
C:\xampp\php\php.exe -f temp\test\test.php
```

You can also test the Java class name detection with:

```
C:\xampp\php\php.exe -f temp\test\test_class_detection.php
```

## Troubleshooting

If you encounter issues with compilers:

1. Verify that the paths in the configuration file are correct
2. Ensure the compiler is properly installed and executable
3. Check if the user running the web server has sufficient permissions to access the compiler
4. Review error logs for specific compiler errors

For Java-specific issues:
- Ensure JAVA_HOME is set correctly in your system environment
- Verify that the JDK bin directory is correctly specified
- Check that both `javac` and `java` commands are accessible

## Adding a New Language

To add support for a new programming language:

1. Add a new entry to the `$COMPILER_PATHS` array in `config/compiler_paths.php`
2. Modify the code execution logic in `api/run_code.php` and `api/submit_code.php`
3. Add the language option to the front-end interface in `student/contest.php` 