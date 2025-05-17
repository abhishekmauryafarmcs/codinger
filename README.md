# Codinger - Online Coding Competition Platform

Codinger is a comprehensive platform designed for hosting and managing coding competitions and contests. It provides a secure environment for students to participate in coding challenges while maintaining academic integrity.

## Security Features

### Anti-Cheating Measures

The platform includes multiple security features to prevent cheating:

#### Clipboard Security

- **Fullscreen Mode Protection**: When a student enters fullscreen mode, the clipboard is automatically cleared to prevent pasting code from external sources.
- **Copy-Paste Restrictions**: The system can be configured to restrict copy-paste operations outside the editor during contests.

#### Session Security

- **Single Session Enforcement**: Only one active session is allowed per user, preventing account sharing.
- **Session Validation**: All user sessions are validated on each request to ensure proper authorization.

### Environment Protection

- **Secure Code Execution**: Student code submissions are executed in a controlled environment.
- **Resource Limitations**: Time and memory limits are enforced for submitted solutions.

## Technical Components

- **Frontend**: HTML, CSS, JavaScript, Bootstrap 5
- **Backend**: PHP
- **Database**: MySQL
- **Code Execution**: Support for C++, Java, and Python

## Security Modules

- `fullscreen_security.js`: Detects fullscreen changes and triggers clipboard clearing
- `clear_clipboard.php`: API endpoint for server-side clipboard clearing
- `clear_clipboard.py`: Python script for OS-level clipboard clearing
- `prevent_cheating.js`: Additional anti-cheating functionality

## Administrator Features

- Contest creation and management
- Problem creation and test case management
- Student account management
- Submission monitoring

## Student Features

- Contest participation
- Code submission and testing
- Personal profile and submission history 