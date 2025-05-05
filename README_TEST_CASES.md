# Test Cases Feature for Coding Contest Platform

This document explains how to set up and use the new test cases feature, which allows administrators to create visible and hidden test cases for programming problems.

## Setup Instructions

1. First, ensure your database is updated with the new test_cases table by following one of these methods:

   **Option 1**: Run the update script directly
   - Access the update script at: `/update_test_cases.php`
   - This will automatically create the necessary database tables and migrate existing sample inputs/outputs

   **Option 2**: Run the SQL script manually in phpMyAdmin
   - Access phpMyAdmin at: `http://localhost/phpmyadmin`
   - Select your database
   - Go to the "SQL" tab
   - Copy and paste the contents of `setup_test_cases.sql`
   - Click "Go" to execute the script

## Feature Overview

The test cases feature allows:

1. **Admin functionality**:
   - Create both visible and hidden test cases for each problem
   - Visible test cases are shown to students as examples
   - Hidden test cases are only used during evaluation and not shown to students
   - Add multiple test cases with the convenient UI

2. **Student experience**:
   - Students see only the visible test cases in the problem description
   - All test cases (visible and hidden) are used to evaluate their submissions
   - Provides a clear distinction between public examples and private test data

## How to Use (Admin)

1. When creating a new problem:
   - Fill out the basic information in the "Basic Info" and "Problem Details" tabs
   - Navigate to the "Test Cases" tab
   - The first test case is visible by default (sample case)
   - Add additional test cases by clicking the "Add Another Test Case" button
   - Toggle visibility of each test case using the switch
   - Delete additional test cases using the trash icon

2. Best practices:
   - Include 1-3 visible test cases that demonstrate the problem clearly
   - Add several hidden test cases that test edge cases and complex scenarios
   - Make sure the first (visible) test case is simple and easy to understand

## Migration Notes

- Existing problems' sample inputs/outputs are automatically migrated to visible test cases
- The system will gracefully fall back to the old method if the test_cases table doesn't exist
- The code submission and evaluation system automatically uses test cases from the database when available

## Troubleshooting

If you encounter any issues:

1. Ensure the database update completed successfully
2. Check that you have the required permissions to modify database tables
3. If problems persist, manually run the SQL from `setup_test_cases.sql`

For any further assistance, please contact the system administrator. 