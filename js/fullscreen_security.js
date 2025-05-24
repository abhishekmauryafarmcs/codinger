/**
 * Fullscreen Security Module
 * Detects fullscreen changes and implements security measures
 */
(function() {
    'use strict';
    
    // Configuration
    const config = {
        // Whether to clear clipboard on fullscreen enter
        clearClipboardOnFullscreen: true, // Enable for production
        
        // Whether to periodically check clipboard when in fullscreen
        periodicClipboardCheck: true, // Enable for production
        
        // Interval in ms for clipboard checks when in fullscreen
        clipboardCheckInterval: 3000, // Check more frequently (every 3 seconds)
        
        // Whether to log events
        logEvents: false, // Disable for production
        
        // Whether to show notifications to user
        showNotifications: true,
        
        // URLs for security actions - use relative path for tunnel compatibility
        endpoints: {
            // This will work in both direct and tunneled access
            clearClipboard: getRelativeApiPath('clear_clipboard.php')
        },
        
        // Notification duration in ms
        notificationDuration: 3000
    };
    
    // Track fullscreen state
    let isInFullscreen = false;
    
    // Keep track of clipboard check interval
    let clipboardCheckIntervalId = null;
    
    // Known allowed clipboard content (for monitoring changes)
    let allowedClipboardContent = '';
    
    /**
     * Helper function to get relative API path that works with tunnels
     */
    function getRelativeApiPath(endpoint) {
        // Get the base path from the current script path
        const scriptPath = document.currentScript?.src || '';
        const basePath = scriptPath.split('/js/')[0] || '';
        
        if (basePath) {
            return `${basePath}/api/${endpoint}`;
        }
        
        // Fallback to relative path from current page
        return `../api/${endpoint}`;
    }
    
    /**
     * Initialize the security module
     */
    function init() {
        // Listen for fullscreen change events
        document.addEventListener('fullscreenchange', handleFullscreenChange);
        document.addEventListener('webkitfullscreenchange', handleFullscreenChange);
        document.addEventListener('mozfullscreenchange', handleFullscreenChange);
        document.addEventListener('MSFullscreenChange', handleFullscreenChange);
        
        // Also listen for focus events to detect tab switching
        window.addEventListener('focus', onWindowFocus);
        
        // Clear clipboard on page load for initial security
        clearClipboard('page_load');
        
        log('Fullscreen security module initialized with endpoint: ' + config.endpoints.clearClipboard);
    }
    
    /**
     * Handle window focus event
     */
    function onWindowFocus() {
        if (isInFullscreen) {
            // When window regains focus while in fullscreen, clear clipboard
            clearClipboard('window_focus');
        }
    }
    
    /**
     * Handle fullscreen change events
     */
    function handleFullscreenChange() {
        // Check if we're now in fullscreen mode
        const currentlyInFullscreen = !!(
            document.fullscreenElement ||
            document.webkitFullscreenElement ||
            document.mozFullScreenElement ||
            document.msFullscreenElement
        );
        
        // If state has changed
        if (currentlyInFullscreen !== isInFullscreen) {
            isInFullscreen = currentlyInFullscreen;
            
            if (isInFullscreen) {
                onEnterFullscreen();
            } else {
                onExitFullscreen();
            }
        }
    }
    
    /**
     * Actions to take when entering fullscreen
     */
    function onEnterFullscreen() {
        log('Entered fullscreen mode');
        
        if (config.clearClipboardOnFullscreen) {
            clearClipboard('enter_fullscreen');
        }
        
        if (config.periodicClipboardCheck) {
            startPeriodicClipboardCheck();
        }
        
        if (config.showNotifications) {
            showNotification('Fullscreen mode activated. Clipboard has been cleared for security.', 'warning');
        }
    }
    
    /**
     * Actions to take when exiting fullscreen
     */
    function onExitFullscreen() {
        log('Exited fullscreen mode');
        
        // Stop periodic clipboard checking
        stopPeriodicClipboardCheck();
    }
    
    /**
     * Start periodic clipboard checking
     */
    function startPeriodicClipboardCheck() {
        if (clipboardCheckIntervalId) {
            clearInterval(clipboardCheckIntervalId);
        }
        
        clipboardCheckIntervalId = setInterval(function() {
            checkAndClearClipboardIfNeeded();
        }, config.clipboardCheckInterval);
        
        log('Started periodic clipboard checking');
    }
    
    /**
     * Stop periodic clipboard checking
     */
    function stopPeriodicClipboardCheck() {
        if (clipboardCheckIntervalId) {
            clearInterval(clipboardCheckIntervalId);
            clipboardCheckIntervalId = null;
            log('Stopped periodic clipboard checking');
        }
    }
    
    /**
     * Check clipboard content and clear if it contains code
     */
    function checkAndClearClipboardIfNeeded() {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.readText()
                .then(text => {
                    // Skip if clipboard is empty or matches our known allowed content
                    if (!text || text === allowedClipboardContent) {
                        return;
                    }
                    
                    // Check if content looks like code (basic heuristic)
                    // Looking for code indicators like brackets, semicolons, common keywords
                    const codeIndicators = [
                        '{', '}', ';', 'function', 'class', 'public', 'private',
                        'def ', 'import ', 'from ', 'include', '#include', 'int ',
                        'void ', 'return', 'for(', 'while(', 'if(', 'else', 'cout'
                    ];
                    
                    const mightBeCode = codeIndicators.some(indicator => 
                        text.includes(indicator)
                    );
                    
                    if (mightBeCode) {
                        log('Detected potential code in clipboard, clearing');
                        clearClipboard('clipboard_check_detected_code');
                        showNotification('External code detected in clipboard. Clipboard has been cleared.', 'error');
                    }
                })
                .catch(err => {
                    // Reading clipboard might fail due to permissions
                    log('Failed to read clipboard: ' + err);
                });
        }
    }
    
    /**
     * Clear the clipboard via multiple methods for better reliability
     * @param {string} event - The event that triggered the clearing
     */
    function clearClipboard(event) {
        log('Clearing clipboard');
        
        // Try all client-side methods first for immediate effect
        clearClipboardAllMethods();
        
        // Then call server for more thorough clearing
        fetch(config.endpoints.clearClipboard + '?event=' + encodeURIComponent(event))
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    log('Clipboard cleared successfully via server');
                } else {
                    log('Failed to clear clipboard via server: ' + (data.error || data.message));
                    // If server-side clearing failed, try client-side again
                    clearClipboardAllMethods();
                }
            })
            .catch(error => {
                log('Error calling clipboard clear endpoint: ' + error.message);
                // If the fetch failed, make sure we still cleared client-side
                clearClipboardAllMethods();
            });
    }
    
    /**
     * Clear clipboard using all available methods
     */
    function clearClipboardAllMethods() {
        // Method 1: Modern Clipboard API
        clearClipboardModern();
        
        // Method 2: Legacy execCommand
        clearClipboardLegacy();
        
        // Method 3: Overwrite with empty value by simulating paste action
        clearClipboardSimulatedPaste();
    }
    
    /**
     * Clear clipboard using modern Clipboard API
     */
    function clearClipboardModern() {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText('')
                .then(() => {
                    log('Clipboard cleared via Clipboard API');
                    allowedClipboardContent = '';
                })
                .catch(err => log('Failed to clear clipboard via Clipboard API: ' + err));
        }
    }
    
    /**
     * Clear clipboard using legacy execCommand
     */
    function clearClipboardLegacy() {
        const textArea = document.createElement('textarea');
        textArea.value = '';
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        textArea.style.top = '-999999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
            const successful = document.execCommand('copy');
            if (successful) {
                log('Clipboard cleared via execCommand');
                allowedClipboardContent = '';
            }
        } catch (err) {
            log('Failed to clear clipboard via execCommand: ' + err);
        }
        
        document.body.removeChild(textArea);
    }
    
    /**
     * Clear clipboard by simulating paste behavior
     */
    function clearClipboardSimulatedPaste() {
        // Create a content-editable div
        const editableDiv = document.createElement('div');
        editableDiv.contentEditable = 'true';
        editableDiv.style.position = 'fixed';
        editableDiv.style.left = '-999999px';
        editableDiv.style.top = '-999999px';
        document.body.appendChild(editableDiv);
        
        // Focus and select all content
        editableDiv.focus();
        document.execCommand('selectAll', false, null);
        
        // Insert empty text and try to copy that
        editableDiv.textContent = '';
        try {
            document.execCommand('insertText', false, '');
            document.execCommand('copy');
            log('Clipboard cleared via simulated paste');
            allowedClipboardContent = '';
        } catch (err) {
            log('Failed to clear clipboard via simulated paste: ' + err);
        }
        
        document.body.removeChild(editableDiv);
    }
    
    /**
     * Show a notification to the user
     * @param {string} message - The notification message
     * @param {string} type - The notification type (info, warning, error)
     */
    function showNotification(message, type = 'info') {
        // Check if notification container exists, create if not
        let container = document.getElementById('security-notifications');
        if (!container) {
            container = document.createElement('div');
            container.id = 'security-notifications';
            container.style.position = 'fixed';
            container.style.top = '20px';
            container.style.right = '20px';
            container.style.zIndex = '9999';
            document.body.appendChild(container);
        }
        
        // Create notification element
        const notification = document.createElement('div');
        notification.className = 'security-notification ' + type;
        notification.textContent = message;
        
        // Style the notification
        notification.style.backgroundColor = type === 'warning' ? '#ffc107' : 
                                           type === 'error' ? '#dc3545' : 
                                           '#17a2b8';
        notification.style.color = type === 'warning' ? '#212529' : '#ffffff';
        notification.style.padding = '15px';
        notification.style.marginBottom = '10px';
        notification.style.borderRadius = '5px';
        notification.style.boxShadow = '0 2px 5px rgba(0,0,0,0.2)';
        notification.style.opacity = '0';
        notification.style.transition = 'opacity 0.3s ease-in-out';
        
        // Add to container
        container.appendChild(notification);
        
        // Animate in
        setTimeout(() => {
            notification.style.opacity = '1';
        }, 10);
        
        // Remove after delay
        setTimeout(() => {
            notification.style.opacity = '0';
            setTimeout(() => {
                container.removeChild(notification);
            }, 300);
        }, config.notificationDuration);
    }
    
    /**
     * Log function - enabled for development
     */
    function log(message) {
        if (config.logEvents) {
            console.log('[FullscreenSecurity] ' + message);
        }
    }
    
    // Initialize when the page loads
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(); 