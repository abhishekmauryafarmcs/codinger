/**
 * Enhanced Clipboard Security for Contest Pages
 * This script provides stronger clipboard clearing mechanisms
 */

(function() {
    // Configuration
    const config = {
        debugMode: true, // Enable debug mode for development
        clearOnLoad: false, // Disable clipboard clearing on load for development
        clearOnFocus: false, // Disable clipboard clearing on focus for development
        clearOnVisibilityChange: false, // Disable clipboard clearing on visibility change for development
        refreshThreshold: 5000 // Time in ms after which page will refresh when returning from hidden
    };
    
    // Internal tracking variables
    let lastFocusTime = Date.now();
    let pageHiddenStartTime = 0;
    let lastClipboardClearTime = 0;
    
    /**
     * Debug logging function - enabled for development
     */
    function log(message) {
        if (config.debugMode && console) {
            console.log('[ClipboardSecurity] ' + message);
        }
    }
    
    /**
     * Clear clipboard using all available methods for maximum effectiveness
     */
    function clearClipboardAllMethods() {
        log('Clearing clipboard with all available methods');
        let success = false;
        
        // Method 1: Modern Clipboard API
        if (navigator.clipboard && window.isSecureContext) {
            try {
                navigator.clipboard.writeText('')
                    .then(() => {
                        log('Clipboard cleared via Clipboard API');
                        success = true;
                    })
                    .catch(error => log('Clipboard API error: ' + error));
            } catch (error) {
                log('Exception using Clipboard API: ' + error);
            }
        }
        
        // Method 2: Legacy execCommand approach
        try {
            const textarea = document.createElement('textarea');
            textarea.value = '';
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();
            
            const successful = document.execCommand('copy');
            if (successful) {
                log('Clipboard cleared via execCommand');
                success = true;
            }
            
            document.body.removeChild(textarea);
        } catch (error) {
            log('Exception using execCommand: ' + error);
        }
        
        // Method 3: Content editable approach
        try {
            const div = document.createElement('div');
            div.contentEditable = 'true';
            div.style.position = 'fixed';
            div.style.opacity = '0';
            document.body.appendChild(div);
            div.focus();
            document.execCommand('selectAll', false, null);
            document.execCommand('delete');
            document.execCommand('copy');
            document.body.removeChild(div);
            log('Attempted clipboard clear via contentEditable');
            success = true;
        } catch (error) {
            log('Exception using contentEditable: ' + error);
        }
        
        lastClipboardClearTime = Date.now();
        return success;
    }
    
    /**
     * Refresh page with cache clearing parameters
     */
    function refreshPage() {
        log('Forcing page refresh with cache clearing');
        
        // Get any contest ID from URL
        const urlParams = new URLSearchParams(window.location.search);
        const contestId = urlParams.get('id');
        
        // Build cache-busting URL
        const refreshUrl = window.location.pathname + 
            (contestId ? `?id=${contestId}&refresh_cache=1` : '?refresh_cache=1') + 
            `&t=${Date.now()}`;
        
        // Navigate to force a complete page reload
        window.location.href = refreshUrl;
    }
    
    /**
     * Show user feedback about clipboard actions
     */
    function showNotification(message, isRefreshing = false) {
        // Check if the warning function exists in prevent_cheating.js
        if (typeof window.showWarningModal === 'function') {
            window.showWarningModal(message);
        } else {
            // Create our own notification if the function isn't available
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                bottom: 20px;
                right: 20px;
                background: rgba(0, 0, 0, 0.8);
                color: white;
                padding: 10px 15px;
                border-radius: 5px;
                font-family: system-ui, sans-serif;
                z-index: 10000;
                transition: opacity 0.3s ease;
            `;
            notification.textContent = message;
            document.body.appendChild(notification);
            
            // Remove after a delay
            setTimeout(() => {
                notification.style.opacity = '0';
                setTimeout(() => document.body.removeChild(notification), 300);
            }, isRefreshing ? 1200 : 3000);
        }
    }
    
    /**
     * Handler for page visibility changes
     */
    function handleVisibilityChange() {
        if (document.hidden) {
            // Page is hidden (user switched tabs/minimized)
            pageHiddenStartTime = Date.now();
            log('Page hidden at: ' + pageHiddenStartTime);
        } else {
            // Page is visible again
            const timeHidden = pageHiddenStartTime > 0 ? Date.now() - pageHiddenStartTime : 0;
            log('Page visible again after ' + timeHidden + 'ms');
            
            // Clear clipboard immediately
            clearClipboardAllMethods();
            
            // Check if we need to refresh the page
            if (pageHiddenStartTime > 0 && timeHidden > config.refreshThreshold) {
                showNotification('Refreshing page and clearing clipboard for security reasons...', true);
                setTimeout(() => {
                    refreshPage();
                }, 1500);
            } else if (timeHidden > 300) { // Only show notification if away for a noticeable time
                showNotification('Clipboard has been cleared for security reasons.');
            }
            
            pageHiddenStartTime = 0;
        }
    }
    
    /**
     * Handler for window focus events
     */
    function handleWindowFocus() {
        const timeSinceLastFocus = Date.now() - lastFocusTime;
        
        // If window was unfocused for more than a brief moment
        if (timeSinceLastFocus > 300) {
            log('Window regained focus after ' + timeSinceLastFocus + 'ms');
            
            // Clear clipboard
            clearClipboardAllMethods();
            
            // If away for significant time, refresh the page
            if (timeSinceLastFocus > config.refreshThreshold) {
                showNotification('Refreshing page and clearing clipboard for security reasons...', true);
                setTimeout(() => {
                    refreshPage();
                }, 1500);
            } else {
                showNotification('Clipboard has been cleared for security reasons.');
            }
        }
        
        lastFocusTime = Date.now();
    }
    
    /**
     * Initialize the clipboard security system
     */
    function init() {
        log('Initializing clipboard security system');
        
        // Call clearClipboard on page load
        if (config.clearOnLoad) {
            clearClipboardAllMethods();
        }
        
        // Set up event listeners for window focus/visibility
        if (config.clearOnFocus) {
            window.addEventListener('focus', handleWindowFocus);
        }
        
        if (config.clearOnVisibilityChange) {
            document.addEventListener('visibilitychange', handleVisibilityChange);
        }
        
        // Expose functions for external use
        window.clipboardSecurity = {
            clearClipboard: clearClipboardAllMethods,
            refreshPage: refreshPage
        };
        
        log('Clipboard security system initialized');
    }
    
    // Initialize when the DOM is loaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(); 