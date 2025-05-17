#!/usr/bin/env python
# Script to clear clipboard contents
import sys
import os
import time
import platform
import traceback

def get_log_file_path():
    """Get the path to the log file that works across different environments"""
    script_dir = os.path.dirname(os.path.abspath(__file__))
    base_dir = os.path.dirname(script_dir)
    log_dir = os.path.join(base_dir, 'logs')
    
    # Create logs directory if it doesn't exist
    try:
        if not os.path.exists(log_dir):
            os.makedirs(log_dir)
    except Exception as e:
        # If we can't create the directory, fall back to current directory
        log_dir = script_dir
        
    return os.path.join(log_dir, 'clipboard_clear.log')

def log_message(message):
    """Log a message to the log file"""
    try:
        log_file = get_log_file_path()
        timestamp = time.strftime("%Y-%m-%d %H:%M:%S")
        with open(log_file, "a") as log:
            log.write(f"{timestamp} - {message}\n")
    except Exception as e:
        # If we can't write to the log file, print to stderr
        print(f"Logging error: {e}", file=sys.stderr)

def clear_windows_clipboard():
    """Clear clipboard on Windows using multiple methods"""
    success = False
    
    # Method 1: Using ctypes
    try:
        import ctypes
        if ctypes.windll.user32.OpenClipboard(None):
            ctypes.windll.user32.EmptyClipboard()
            ctypes.windll.user32.CloseClipboard()
            log_message("Clipboard cleared on Windows using ctypes")
            success = True
    except Exception as e:
        log_message(f"Failed to clear clipboard using ctypes: {e}")
    
    # Method 2: Using pywin32 if available
    if not success:
        try:
            import win32clipboard
            win32clipboard.OpenClipboard()
            win32clipboard.EmptyClipboard()
            win32clipboard.CloseClipboard()
            log_message("Clipboard cleared on Windows using pywin32")
            success = True
        except ImportError:
            log_message("pywin32 not available")
        except Exception as e:
            log_message(f"Failed to clear clipboard using pywin32: {e}")
    
    # Method 3: Using PowerShell
    if not success:
        try:
            os.system('powershell -command "Set-Clipboard -Value \'\'"')
            log_message("Clipboard cleared on Windows using PowerShell")
            success = True
        except Exception as e:
            log_message(f"Failed to clear clipboard using PowerShell: {e}")
            
    return success

def clear_macos_clipboard():
    """Clear clipboard on macOS using multiple methods"""
    success = False
    
    # Method 1: Using pbcopy
    try:
        os.system("pbcopy < /dev/null")
        log_message("Clipboard cleared on macOS using pbcopy")
        success = True
    except Exception as e:
        log_message(f"Failed to clear clipboard using pbcopy: {e}")
    
    # Method 2: Using osascript
    if not success:
        try:
            os.system('osascript -e "set the clipboard to \\"\\""')
            log_message("Clipboard cleared on macOS using osascript")
            success = True
        except Exception as e:
            log_message(f"Failed to clear clipboard using osascript: {e}")
            
    return success

def clear_linux_clipboard():
    """Clear clipboard on Linux using multiple methods"""
    success = False
    
    # Method 1: Using xsel
    try:
        if os.system("which xsel > /dev/null 2>&1") == 0:
            os.system("xsel -bc")
            log_message("Clipboard cleared on Linux using xsel")
            success = True
    except Exception as e:
        log_message(f"Failed to clear clipboard using xsel: {e}")
    
    # Method 2: Using xclip
    if not success:
        try:
            if os.system("which xclip > /dev/null 2>&1") == 0:
                os.system("xclip -selection clipboard < /dev/null")
                log_message("Clipboard cleared on Linux using xclip")
                success = True
        except Exception as e:
            log_message(f"Failed to clear clipboard using xclip: {e}")
    
    # Method 3: Using wl-clipboard (for Wayland)
    if not success:
        try:
            if os.system("which wl-copy > /dev/null 2>&1") == 0:
                os.system("wl-copy ''")
                log_message("Clipboard cleared on Linux using wl-copy (Wayland)")
                success = True
        except Exception as e:
            log_message(f"Failed to clear clipboard using wl-copy: {e}")
            
    return success

def clear_clipboard():
    """Clear clipboard contents on various operating systems"""
    log_message(f"Clipboard clear requested - Python {sys.version} on {platform.platform()}")
    
    try:
        # Determine OS and use appropriate method
        if os.name == 'nt':  # Windows
            return clear_windows_clipboard()
        elif sys.platform == 'darwin':  # macOS
            return clear_macos_clipboard()
        else:  # Linux/Unix
            return clear_linux_clipboard()
                
    except Exception as e:
        error_details = traceback.format_exc()
        log_message(f"Error clearing clipboard: {e}\n{error_details}")
        print(f"Error clearing clipboard: {e}", file=sys.stderr)
        return False

# When run directly
if __name__ == "__main__":
    success = clear_clipboard()
    print("Clipboard cleared successfully" if success else "Failed to clear clipboard")
    sys.exit(0 if success else 1) 