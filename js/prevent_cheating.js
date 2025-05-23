document.addEventListener('DOMContentLoaded', function() {
    // Get the contest ID from the URL or data attribute
    const urlParams = new URLSearchParams(window.location.search);
    const contestId = urlParams.get('id') || document.body.getAttribute('data-contest-id') || 'unknown';
    const userId = document.body.getAttribute('data-user-id'); // Get user ID from body attribute
    const storageKey = `pageSwitchCount_${contestId}_${userId}`; // Include user ID in storage key
    
    // Clear any previous user's data for this contest
    Object.keys(localStorage).forEach(key => {
        if (key.includes(`problem-${contestId}`) || // Clear previous code
            key.includes(`pageSwitchCount_${contestId}`) || // Clear previous switch counts
            (key.includes(`contest_${contestId}`) && !key.includes('_terminated'))) { // Clear contest data but keep termination status
            localStorage.removeItem(key);
        }
    });
    
    // Check if we're loading from a refresh and clear the flag with a small delay
    // This ensures the visibilitychange events don't count during initial page load
    setTimeout(() => {
        sessionStorage.removeItem('page_refreshing');
    }, 1000);
    
    // Check if this contest was previously terminated for this user
    // This is a client-side check that works alongside server-side validation
    const wasTerminated = localStorage.getItem(`contest_${contestId}_terminated`) === 'true';
    
    // Get contest status from the page to check if contest is still active
    const contestStatusEl = document.querySelector('meta[name="contest-status"]');
    const contestIsCurrentlyActive = contestStatusEl && contestStatusEl.getAttribute('content') === 'active';
    
    // Only enforce termination if the contest is still active
    if (wasTerminated && contestIsCurrentlyActive) {
        const reason = localStorage.getItem(`contest_${contestId}_termination_reason`) || 'Contest rules violation';
        const terminationTime = localStorage.getItem(`contest_${contestId}_termination_time`) || Date.now().toString();
        const terminationDate = new Date(parseInt(terminationTime));
        
        // Create and show permanent termination notice
        const terminationOverlay = document.createElement('div');
        terminationOverlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.95);
            z-index: 99999;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: white;
            font-family: system-ui, -apple-system, sans-serif;
            text-align: center;
            padding: 20px;
        `;
        
        const icon = document.createElement('div');
        icon.innerHTML = '<svg width="80" height="80" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 4.5C7.03 4.5 3 8.53 3 13.5C3 18.47 7.03 22.5 12 22.5C16.97 22.5 21 18.47 21 13.5C21 8.53 16.97 4.5 12 4.5ZM12 20.5C8.14 20.5 5 17.36 5 13.5C5 9.64 8.14 6.5 12 6.5C15.86 6.5 19 9.64 19 13.5C19 17.36 15.86 20.5 12 20.5Z" fill="#ff4757"/><path d="M13 10.5H11V16.5H13V10.5Z" fill="#ff4757"/><path d="M13 8.5H11V9.5H13V8.5Z" fill="#ff4757"/><path d="M12 2C6.48 2 2 6.48 2 12C2 17.52 6.48 22 12 22C17.52 22 22 17.52 22 12C22 6.48 17.52 2 12 2ZM12 20C7.59 20 4 16.41 4 12C4 7.59 7.59 4 12 4C16.41 4 20 7.59 20 12C20 16.41 16.41 20 12 20Z" fill="#ff4757"/></svg>';
        
        const title = document.createElement('h1');
        title.textContent = 'Contest Access Denied';
        title.style.cssText = 'color: #ff4757; margin: 20px 0; font-size: 2.5rem;';
        
        const message = document.createElement('p');
        message.innerHTML = `This contest has been <strong>permanently terminated</strong> for your account due to a violation of contest rules:<br><strong>${reason}</strong>`;
        message.style.cssText = 'font-size: 1.2rem; max-width: 600px; line-height: 1.6;';
        
        const timestamp = document.createElement('p');
        timestamp.textContent = `Termination occurred on: ${terminationDate.toLocaleString()}`;
        timestamp.style.cssText = 'color: #aaa; margin-top: 20px; font-size: 0.9rem;';
        
        const returnButton = document.createElement('button');
        returnButton.textContent = 'Return to Dashboard';
        returnButton.style.cssText = `
            background: #3498db;
            border: none;
            color: white;
            padding: 12px 30px;
            border-radius: 5px;
            font-size: 1.1rem;
            margin-top: 30px;
            cursor: pointer;
            transition: background 0.3s;
        `;
        returnButton.addEventListener('mouseover', () => {
            returnButton.style.background = '#2980b9';
        });
        returnButton.addEventListener('mouseout', () => {
            returnButton.style.background = '#3498db';
        });
        returnButton.addEventListener('click', () => {
            window.location.href = 'dashboard.php?error=contest_terminated';
        });
        
        terminationOverlay.appendChild(icon);
        terminationOverlay.appendChild(title);
        terminationOverlay.appendChild(message);
        terminationOverlay.appendChild(timestamp);
        terminationOverlay.appendChild(returnButton);
        
        // Add the overlay to the document
        document.body.appendChild(terminationOverlay);
        
        // Also make an API call to verify the termination status server-side
        fetch('../api/check_contest_status.php?contest_id=' + contestId)
            .then(response => response.json())
            .then(data => {
                if (!data.terminated) {
                    // If server says the contest is not terminated, clear local storage and reload
                    // This handles the case where a user might have manipulated localStorage
                    localStorage.removeItem(`contest_${contestId}_terminated`);
                    localStorage.removeItem(`contest_${contestId}_termination_reason`);
                    localStorage.removeItem(`contest_${contestId}_termination_time`);
                    window.location.reload();
                }
            })
            .catch(error => {
                // If we can't verify with the server, keep the termination screen
                // Better safe than sorry
            });
        
        // Stop further execution of the script
        return;
    }
    
    // Initialize from localStorage if available, otherwise start at 0
    let pageSwitchCount = parseInt(localStorage.getItem(storageKey) || '0');
    
    // Instead of hardcoding maxSwitches, get it from the data attribute we'll add to the page
    const maxSwitches = parseInt(document.body.getAttribute('data-max-tab-switches') || 3);
    // Also check if copy-paste is allowed based on data attribute from the page
    const isCopyPasteAllowed = document.body.getAttribute('data-allow-copy-paste') === "1";
    // Check if right click should be prevented
    const preventRightClick = document.body.getAttribute('data-prevent-right-click') === "1";
    let isContestActive = true;
    let warningTimeout;
    let lastViolationTime = 0;
    let debugMode = true; // Set to true to enable console logging for development
    
    // Track clipboard state to detect external copying
    let internalClipboardContent = null;
    let lastFocusTime = Date.now();
    let pageHiddenStartTime = 0;
    let devToolsViolationCount = 0; // New counter for dev tools violations
    const maxDevToolsViolations = 2; // Max allowed dev tools openings before termination
    
    let fullscreenCountdownInterval = null;
    let fullscreenCountdownValue = 5; // Default 5 seconds
    
    // Track if this is the first fullscreen prompt - Check localStorage first, defaulting to true if not found
    let isFirstFullscreenPrompt = localStorage.getItem(`contest_${contestId}_first_fullscreen`) !== 'false';
    
    // Set a flag to bypass the countdown for the first fullscreen prompt
    const bypassCountdownForFirstPrompt = true;
    
    // --- Fullscreen Enforcement Variables ---
    let fullscreenEnforcerOverlay = null;
    let codeMirrorEditor = null; // Will hold the CodeMirror instance
    
    // Flag to track if page is being reloaded/refreshed
    let isPageRefreshing = false;
    
    // Listen for beforeunload to detect page refreshes
    window.addEventListener('beforeunload', function() {
        isPageRefreshing = true;
        // We store this in sessionStorage as well since isPageRefreshing variable won't persist across page loads
        sessionStorage.setItem('page_refreshing', 'true');
        // Give it a short timeout to be cleared
        setTimeout(() => {
            sessionStorage.removeItem('page_refreshing');
        }, 2000);
    });
    
    // Check if we're coming from a page refresh when the page loads
    const wasRefreshing = sessionStorage.getItem('page_refreshing') === 'true';
    if (wasRefreshing) {
        // Clear the flag immediately
        sessionStorage.removeItem('page_refreshing');
    }
    
    // Function to be called from contest.php when timer ends
    window.setContestEnded = function() {
        isContestActive = false;
        updateFullscreenEnforcement(); // This will unlock the editor if it was locked
        if (fullscreenCountdownInterval) {
            clearInterval(fullscreenCountdownInterval);
            fullscreenCountdownInterval = null;
        }
        
        // Clean up all localStorage items related to this contest to prevent issues with future contests
        localStorage.removeItem(storageKey); // Remove page switch count
        localStorage.removeItem(`contest_${contestId}_terminated`);
        localStorage.removeItem(`contest_${contestId}_termination_reason`);
        localStorage.removeItem(`contest_${contestId}_termination_time`);
        localStorage.removeItem(`contest_${contestId}_first_fullscreen`);
        
        // Hide violation status bar if it's still visible
        if (statusBar && statusBar.style.opacity === '1') {
            statusBar.style.opacity = '0';
            statusBar.style.transform = 'translateY(-20px)';
            setTimeout(() => {
                if (statusBar.style.opacity === '0') {
                    statusBar.style.display = 'none';
                }
            }, 300);
        }    
    };
    
    // Debug helper function - enabled for development
    function debug(message) {
        if (debugMode) {
            console.log(message);
        }
    }
    
    // --- Fullscreen Helper Functions ---
    function isFullscreen() {
        return !!(document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement);
    }

    function requestSystemFullscreen() {
        const el = document.documentElement;
        if (el.requestFullscreen) {
            el.requestFullscreen().catch(err => debug(`Error requesting fullscreen: ${err.message}`));
        } else if (el.webkitRequestFullscreen) { /* Safari */
            el.webkitRequestFullscreen().catch(err => debug(`Error requesting fullscreen (webkit): ${err.message}`));
        } else if (el.mozRequestFullScreen) { /* Firefox */
            el.mozRequestFullScreen().catch(err => debug(`Error requesting fullscreen (moz): ${err.message}`));
        } else if (el.msRequestFullscreen) { /* IE/Edge */
            el.msRequestFullscreen().catch(err => debug(`Error requesting fullscreen (ms): ${err.message}`));
        }
    }

    function createFullscreenEnforcerUI() {
        if (document.getElementById('fullscreen-enforcer')) return;

        fullscreenEnforcerOverlay = document.createElement('div');
        fullscreenEnforcerOverlay.id = 'fullscreen-enforcer';
        fullscreenEnforcerOverlay.style.cssText = `
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.95); color: white;
            display: flex; flex-direction: column; justify-content: center; align-items: center;
            z-index: 20000; text-align: center; padding: 20px;
            font-family: system-ui, -apple-system, sans-serif;
        `;

        const message = document.createElement('h2');
        // Different message for initial vs subsequent prompts
        if (isFirstFullscreenPrompt) {
            message.innerHTML = '<i class="bi bi-arrows-fullscreen" style="font-size: 2em; margin-bottom: 15px;"></i><br>Fullscreen Required';
        } else {
            message.innerHTML = '<i class="bi bi-exclamation-triangle-fill" style="font-size: 2em; margin-bottom: 15px; color: #ffc107;"></i><br>Fullscreen Exit Detected!';
        }
        message.style.cssText = 'font-size: 2rem; margin-bottom: 20px; color: #fff;';
        
        const subMessage = document.createElement('p');
        if (isFirstFullscreenPrompt) {
            subMessage.textContent = 'Please enter fullscreen mode to access the contest and write your code.';
        } else {
            subMessage.textContent = 'Please re-enter fullscreen mode immediately. Failure to do so will result in contest termination.';
        }
        subMessage.style.cssText = 'font-size: 1.1rem; margin-bottom: 30px; color: #ccc; max-width: 500px;';

        const countdownDisplay = document.createElement('p');
        countdownDisplay.id = 'fullscreen-countdown-timer';
        countdownDisplay.style.cssText = 'font-size: 2.5rem; color: #ffc107; margin: 15px 0; font-weight: bold; display: none;';

        const switchInfo = document.createElement('p');
        switchInfo.id = 'fullscreen-switch-info';
        switchInfo.style.cssText = 'font-size: 1rem; margin-bottom: 20px; color: #ffc107;'; // Yellow color for visibility

        const enterButton = document.createElement('button');
        if (isFirstFullscreenPrompt) {
            enterButton.innerHTML = '<i class="bi bi-arrows-angle-expand"></i> Enter Fullscreen';
        } else {
            enterButton.innerHTML = '<i class="bi bi-arrows-angle-expand"></i> Re-Enter Fullscreen';
        }
        enterButton.className = 'btn btn-primary btn-lg'; // Using Bootstrap classes
        enterButton.style.padding = '12px 25px';
        enterButton.style.fontSize = '1.1rem';
        enterButton.onclick = requestSystemFullscreen;

        fullscreenEnforcerOverlay.appendChild(message);
        fullscreenEnforcerOverlay.appendChild(subMessage);
        fullscreenEnforcerOverlay.appendChild(countdownDisplay);
        fullscreenEnforcerOverlay.appendChild(switchInfo);
        fullscreenEnforcerOverlay.appendChild(enterButton);
        document.body.appendChild(fullscreenEnforcerOverlay);
    }

    function updateFullscreenEnforcement() {
        const countdownDisplay = document.getElementById('fullscreen-countdown-timer');

        if (!isContestActive) { 
             if (fullscreenEnforcerOverlay) fullscreenEnforcerOverlay.style.display = 'none';
             if (countdownDisplay) countdownDisplay.style.display = 'none';
             if (fullscreenCountdownInterval) {
                clearInterval(fullscreenCountdownInterval);
                fullscreenCountdownInterval = null;
             }
             if (codeMirrorEditor) {
                codeMirrorEditor.setOption("readOnly", false);
                if (codeMirrorEditor.getWrapperElement()) {
                    codeMirrorEditor.getWrapperElement().style.opacity = '1';
                    codeMirrorEditor.getWrapperElement().style.pointerEvents = 'auto';
                }
            }
            return;
        }

        if (isFullscreen()) {
            if (fullscreenEnforcerOverlay) fullscreenEnforcerOverlay.style.display = 'none';
            if (countdownDisplay) countdownDisplay.style.display = 'none';
            if (fullscreenCountdownInterval) {
                clearInterval(fullscreenCountdownInterval);
                fullscreenCountdownInterval = null;
            }
            if (codeMirrorEditor) {
                codeMirrorEditor.setOption("readOnly", false);
                codeMirrorEditor.refresh(); // Refresh editor state
                if (codeMirrorEditor.getWrapperElement()) {
                    codeMirrorEditor.getWrapperElement().style.opacity = '1';
                    codeMirrorEditor.getWrapperElement().style.pointerEvents = 'auto';
                }
            }
            
            // After user successfully enters fullscreen for the first time, update flag and store in localStorage
            if (isFirstFullscreenPrompt) {
                isFirstFullscreenPrompt = false;
                localStorage.setItem(`contest_${contestId}_first_fullscreen`, 'false');
            }
        } else {
            createFullscreenEnforcerUI(); // Ensure UI is created
            const messageElement = fullscreenEnforcerOverlay.querySelector('h2');
            const subMessageElement = fullscreenEnforcerOverlay.querySelector('p:not(#fullscreen-switch-info):not(#fullscreen-countdown-timer)');
            const enterButton = fullscreenEnforcerOverlay.querySelector('button');
            const switchInfoElement = document.getElementById('fullscreen-switch-info');
            
            // If it's the first prompt, enter fullscreen immediately
            if (isFirstFullscreenPrompt && bypassCountdownForFirstPrompt) {
                // Immediately attempt to enter fullscreen on first load
                setTimeout(() => {
                    requestSystemFullscreen();
                    // Set first fullscreen flag to false to prevent re-triggering
                    isFirstFullscreenPrompt = false;
                    localStorage.setItem(`contest_${contestId}_first_fullscreen`, 'false');
                }, 1000); // Small delay to allow UI to render
            }
            // Start countdown for non-first prompts or if immediate entry is disabled
            else if (!isFirstFullscreenPrompt && !fullscreenCountdownInterval) {
                fullscreenCountdownValue = 5; // Reset countdown to 5 seconds
                
                if (messageElement) messageElement.innerHTML = '<i class="bi bi-exclamation-triangle-fill" style="font-size: 2em; margin-bottom: 15px; color: #ffc107;"></i><br>Fullscreen Exit Detected!';
                if (subMessageElement) subMessageElement.textContent = 'Please re-enter fullscreen mode immediately. Failure to do so will result in contest termination.';
                if (enterButton) enterButton.innerHTML = '<i class="bi bi-arrows-angle-expand"></i> Re-Enter Fullscreen';
                
                if (countdownDisplay) {
                    countdownDisplay.textContent = `${fullscreenCountdownValue}s`;
                    countdownDisplay.style.display = 'block';
                }

                fullscreenCountdownInterval = setInterval(() => {
                    fullscreenCountdownValue--;
                    if(countdownDisplay) countdownDisplay.textContent = `${fullscreenCountdownValue}s`;

                    if (isFullscreen()) { 
                        clearInterval(fullscreenCountdownInterval);
                        fullscreenCountdownInterval = null;
                        if(countdownDisplay) countdownDisplay.style.display = 'none';
                        updateFullscreenEnforcement(); 
                        return;
                    }

                    if (fullscreenCountdownValue <= 0) {
                        clearInterval(fullscreenCountdownInterval);
                        fullscreenCountdownInterval = null;
                        if (!isFullscreen()) { 
                            terminateContest('Failed to re-enter fullscreen within the allowed time.');
                        }
                    }
                }, 1000);
            }
            
            if (fullscreenEnforcerOverlay) {
                fullscreenEnforcerOverlay.style.display = 'flex';
                if (switchInfoElement) {
                    switchInfoElement.textContent = `Page Switches: ${pageSwitchCount} / ${maxSwitches}`;
                }
            }
            if (codeMirrorEditor) {
                codeMirrorEditor.setOption("readOnly", true);
                codeMirrorEditor.refresh(); // Refresh editor state
                 if (codeMirrorEditor.getWrapperElement()) {
                    codeMirrorEditor.getWrapperElement().style.opacity = '0.7'; // Visually indicate disabled
                    codeMirrorEditor.getWrapperElement().style.pointerEvents = 'none';
                }
            }
        }
    }

    // Attempt to find CodeMirror instance (might be initialized later)
    function findCodeMirrorInstance() {
        if (window.editor && typeof window.editor.setOption === 'function') {
            codeMirrorEditor = window.editor;
            updateFullscreenEnforcement(); // Update state once editor is found
        } else {
            // If contest.php has multiple editors, this might need adjustment
            // For now, assuming one global 'editor' or a single .CodeMirror element
            const cmElement = document.querySelector('.CodeMirror');
            if (cmElement && cmElement.CodeMirror) {
                 codeMirrorEditor = cmElement.CodeMirror;
                 updateFullscreenEnforcement();
            }
        }
    }
    
    // --- End of Fullscreen Helper Functions ---

    // Prevent right click only if the setting is enabled
    document.addEventListener('contextmenu', function(e) {
        if (isContestActive && preventRightClick) {
            e.preventDefault();
            if (!isFullscreen()) { // Show fullscreen prompt if right click is attempted outside fullscreen
                showWarningModal('Please enter fullscreen. Right-clicking is also disabled.');
                updateFullscreenEnforcement(); // Re-check and show overlay if needed
            } else {
                showWarningModal('Right-clicking is not allowed during the contest.');
            }
        }
    });

    // Function to clear clipboard - enhanced version
    function clearClipboard() {
        try {
            // Try multiple methods to clear the clipboard for better reliability
            
            // Method 1: Modern Clipboard API
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText('');
            }
            
            // Method 2: Legacy execCommand approach
            const textarea = document.createElement('textarea');
            textarea.value = '';
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand('copy');
            } catch (e) {}
            document.body.removeChild(textarea);
            
            return true;
        } catch (error) {
            // Error handling, but no console log
        }
        return false;
    }
    
    // Function to refresh page with cleared cache
    function refreshPageWithClearedCache() {
        // Store the current contest ID to preserve it
        const contestId = urlParams.get('id');
        
        // Mark that we're refreshing to prevent counting as tab switch
        isPageRefreshing = true;
        sessionStorage.setItem('page_refreshing', 'true');
        
        // Build the cache-busting URL
        const refreshUrl = window.location.pathname + 
            (contestId ? `?id=${contestId}&refresh_cache=1` : '?refresh_cache=1') + 
            `&t=${Date.now()}`;
            
        // Navigate to the URL, which forces a complete page reload
        window.location.href = refreshUrl;
    }
    
    // Check and clear clipboard when window gains focus
    window.addEventListener('focus', function() {
        if (!isContestActive || isCopyPasteAllowed) return;
        
        const timeSinceLastFocus = Date.now() - lastFocusTime;
        
        // If window was unfocused for more than a brief moment (indicating tab switch)
        if (timeSinceLastFocus > 300) {
            // Clear the clipboard when returning to the contest page
            clearClipboard();
            
            // If user was away for more than 5 seconds, perform a page refresh
            if (pageHiddenStartTime > 0 && (Date.now() - pageHiddenStartTime) > 5000) {
                setTimeout(() => {
                    refreshPageWithClearedCache();
                }, 100); // Shortened timeout
            } else {
                showWarningModal('Clipboard has been cleared for security reasons.');
            }
        }
        
        lastFocusTime = Date.now();
        pageHiddenStartTime = 0; // Reset the hidden start time
    });
    
    // Prevent copy/paste with keyboard shortcuts and handle F5
    document.addEventListener('keydown', function(e) {
        if (isContestActive) {
            // Check if the event target is inside CodeMirror
            const isInCodeMirror = e.target.closest('.CodeMirror') !== null;
            
            // --- Check for Developer Tools Shortcuts ---
            const isDevToolsShortcut = 
                (e.key === 'F12') || 
                (e.ctrlKey && e.shiftKey && e.key.toLowerCase() === 'i') || // Ctrl+Shift+I
                (e.ctrlKey && e.shiftKey && e.key.toLowerCase() === 'j') || // Ctrl+Shift+J
                (e.ctrlKey && e.shiftKey && e.key.toLowerCase() === 'c') || // Ctrl+Shift+C
                (e.metaKey && e.altKey && e.key.toLowerCase() === 'i') ||   // Cmd+Option+I (Mac)
                (e.metaKey && e.altKey && e.key.toLowerCase() === 'j') ||   // Cmd+Option+J (Mac)
                (e.metaKey && e.altKey && e.key.toLowerCase() === 'c');      // Cmd+Option+C (Mac)

            if (isDevToolsShortcut) {
                e.preventDefault();
                handleDevToolsViolation(); // Trigger violation handling
                return;
            }
            
            // --- Check for Tab Switching Shortcuts ---
            const isTabSwitchShortcut = 
                (e.ctrlKey && e.key === 'Tab') ||                                     // Ctrl+Tab, Ctrl+Shift+Tab (Shift is a modifier)
                (e.ctrlKey && e.key === 'PageDown') ||                                // Ctrl+PageDown
                (e.ctrlKey && e.key === 'PageUp') ||                                  // Ctrl+PageUp
                (e.ctrlKey && e.key >= '1' && e.key <= '9') ||                        // Ctrl+[1-9]
                (e.metaKey && e.shiftKey && (e.key === ']' || e.key === '[')) ||       // Cmd+Shift+] or [
                (e.metaKey && e.altKey && (e.key === 'ArrowRight' || e.key === 'ArrowLeft')); // Cmd+Option+Left/Right
            
            if (isTabSwitchShortcut) {
                // Allow tab switching if it happens within CodeMirror itself (e.g. some plugins might use similar shortcuts)
                // Though this is less likely for the listed browser-level shortcuts.
                if (isInCodeMirror) {
                    return;
                }
                e.preventDefault();
                handlePageSwitchViolation('Attempted tab switch using shortcut!');
                return;
            }
            
            // Handle F5 and Ctrl+R to prevent page refresh
            if (e.key === 'F5' || (e.ctrlKey && e.key.toLowerCase() === 'r')) {
                e.preventDefault();
                handlePageSwitchViolation('Page refresh attempted!');
                return;
            }
            
            // For copy-paste in CodeMirror, we allow it but track it
            if (isInCodeMirror) {
                // Still allow keyboard shortcuts in CodeMirror
                return;
            }
            
            // Prevent copy (Ctrl+C) - only if copy-paste is not allowed
            if (!isCopyPasteAllowed && e.ctrlKey && e.key.toLowerCase() === 'c') {
                e.preventDefault();
                showWarningModal('Copying is not allowed during the contest.');
            }
            // Prevent paste (Ctrl+V) - only if copy-paste is not allowed
            if (!isCopyPasteAllowed && e.ctrlKey && e.key.toLowerCase() === 'v') {
                e.preventDefault();
                showWarningModal('Pasting is not allowed during the contest.');
            }
            
            // F11 to enter/exit fullscreen - browser handles this, our listener will catch the change
            if (e.key === 'Escape' && isFullscreen()) {
                 // User might be trying to exit fullscreen with Esc. Our fullscreenchange listener will handle it.
            }
            if (isInCodeMirror && !isFullscreen()) {
                // If user tries to type in CodeMirror when not in fullscreen
                e.preventDefault();
                showWarningModal("Please enter fullscreen mode to write code.");
                updateFullscreenEnforcement(); // Ensure overlay is shown
                return;
            }
        }
    });
    
    // Track internal copy operations
    document.addEventListener('copy', function(e) {
        // Check if the event target is inside CodeMirror
        const isInCodeMirror = e.target.closest('.CodeMirror') !== null;
        
        // Allow copy in CodeMirror regardless of settings
        if (isInCodeMirror) {
            // Mark this as an internal copy operation
            try {
                const selection = document.getSelection();
                if (selection) {
                    internalClipboardContent = selection.toString();
                }
            } catch (error) {
                // No console.log for error
            }
            return;
        }
        
        if (isContestActive && !isCopyPasteAllowed) {
            e.preventDefault();
            showWarningModal('Copying is not allowed during the contest.');
        }
    });

    document.addEventListener('paste', function(e) {
        // Check if the event target is inside CodeMirror
        const isInCodeMirror = e.target.closest('.CodeMirror') !== null;
        
        if (isInCodeMirror) {
            // If copy-paste prevention is enabled, verify clipboard content
            if (!isCopyPasteAllowed) {
                // Try to get clipboard data
                try {
                    // For security reasons, we cannot directly access clipboard content
                    // We can only block the paste event
                    
                    // If we haven't tracked this as an internal copy, block the paste
                    if (!internalClipboardContent) {
                        e.preventDefault();
                        showWarningModal('Pasting from external sources is not allowed.');
                        return;
                    }
                } catch (error) {
                    // No console.log for error
                }
            }
            return; // Allow paste in CodeMirror
        }
        
        if (isContestActive && !isCopyPasteAllowed) {
            e.preventDefault();
            showWarningModal('Pasting is not allowed during the contest.');
        }
    });
    
    // Handle visibility change (tab switching)
    document.addEventListener('visibilitychange', function() {
        if (document.hidden && isContestActive) {
            pageHiddenStartTime = Date.now();
            
            // We don't immediately count this as a violation
            // We'll wait to see if it's a refresh or actual tab switch
            
            // If page is being refreshed, don't count it as a tab switch
            if (!isPageRefreshing) {
            handlePageSwitchViolation('Tab switched!');
            }
        } else if (!document.hidden && isContestActive && pageHiddenStartTime > 0) {
            // Page became visible again after being hidden
            const timeHidden = Date.now() - pageHiddenStartTime;
            
            // Clear clipboard
            clearClipboard();
            
            // If we're not in fullscreen, prioritize showing the fullscreen warning with countdown
            if (!isFullscreen()) {
                // We need to ensure this isn't treated as a first-time fullscreen prompt
                // and persist this state in localStorage
                isFirstFullscreenPrompt = false;
                localStorage.setItem(`contest_${contestId}_first_fullscreen`, 'false');
                
                // Show a warning about tab switching
                showWarningModal('Tab switching detected! Please re-enter fullscreen immediately.');
                
                // Force update the fullscreen UI with countdown
                if (fullscreenCountdownInterval) {
                    clearInterval(fullscreenCountdownInterval);
                    fullscreenCountdownInterval = null;
                }
                updateFullscreenEnforcement();
                
                // Do not refresh page in this case, as we want to show the fullscreen countdown
            } 
            // Only refresh if in fullscreen and away for more than 5 seconds
            else if (timeHidden > 5000 && isFullscreen()) {
                showWarningModal('Refreshing page and clearing clipboard for security reasons...');
                setTimeout(() => {
                    refreshPageWithClearedCache();
                }, 1500);
            }
            
            pageHiddenStartTime = 0;
        }
    });
    
    // Handle window blur (window switching)
    window.addEventListener('blur', function() {
        if (isContestActive && !document.hidden) {
            handlePageSwitchViolation('Window focus lost!');
        }
    });
    
    // Handle browser navigation attempts
    window.addEventListener('popstate', function(e) {
        if (isContestActive) {
            // Re-push the state to prevent navigation
            history.pushState({page: 1}, originalTitle, window.location.href);
            handlePageSwitchViolation('Navigation attempted!');
        }
    });
    
    // --- Initialize Fullscreen Enforcement & other features ---
    
    // Try to find CodeMirror instance periodically until found or for a few seconds
    let findCmInterval = setInterval(() => {
        if (!codeMirrorEditor) {
            findCodeMirrorInstance();
        } else {
            clearInterval(findCmInterval); // Stop trying once found
        }
    }, 500);
    setTimeout(() => clearInterval(findCmInterval), 5000); // Stop after 5 seconds

    // Initial check and UI setup
    createFullscreenEnforcerUI(); // Create it so it's ready
    updateFullscreenEnforcement(); // Initial state check

    // Listen for fullscreen changes
    ['fullscreenchange', 'webkitfullscreenchange', 'mozfullscreenchange', 'MSFullscreenChange'].forEach(event => {
        document.addEventListener(event, updateFullscreenEnforcement);
    });

    // Initialize the violation indicators (this should be after these elements are created)
    updateViolationIndicators(); 
    
    // Check if UI elements were properly initialized
    setTimeout(() => {
        const violationCountElement = document.getElementById('violation-count');
        const warningModalElement = document.getElementById('warning-modal');
        
        if (!violationCountElement || !warningModalElement) {
            // Prevention system UI (violations) not properly initialized
        }
         if (!fullscreenEnforcerOverlay && isContestActive && !isFullscreen()) {
            createFullscreenEnforcerUI();
            updateFullscreenEnforcement();
         }
         
        // Special handling for page refresh while fullscreen enforcement was active
        if (isContestActive && !isFullscreen() && !isFirstFullscreenPrompt) {
            // This means the page was refreshed while not in fullscreen mode
            // and we've previously shown the first fullscreen prompt
            // Re-trigger the fullscreen enforcement with countdown
            if (fullscreenCountdownInterval) {
                clearInterval(fullscreenCountdownInterval);
                fullscreenCountdownInterval = null;
            }
            
            // Force update the fullscreen UI with countdown
            createFullscreenEnforcerUI();
            updateFullscreenEnforcement();
        }
    }, 1000);

    const finishButtons = document.querySelectorAll('.contest-finish-btn, .submit-final-btn');
    finishButtons.forEach(button => {
        button.addEventListener('click', function() {
            localStorage.removeItem(storageKey);
            isContestActive = false; // Contest is over
            clearInterval(devToolsCheckInterval); // Stop dev tools check when contest finishes
            updateFullscreenEnforcement(); // Release fullscreen enforcement
        });
    });

    // Function to handle developer tools violations
    function handleDevToolsViolation() {
        if (!isContestActive) return;

        devToolsViolationCount++;
        const now = Date.now();
        if (now - lastViolationTime < 2000) return; // Prevent rapid multiple triggers
        lastViolationTime = now;

        showWarningModal(`Developer tools detected (${devToolsViolationCount}/${maxDevToolsViolations}). Please close them to continue.`);
        updateViolationIndicators(); // This might need adjustment to show dev tools violations separately or combined

        if (devToolsViolationCount >= maxDevToolsViolations) {
            terminateContest('Developer tools abuse detected. Contest terminated.');
        }
    }
    
    // Function to handle page switch violations (tab switching, window switching, etc.)
    function handlePageSwitchViolation(reason) {
        if (!isContestActive) return;
        
        // Don't count violations if page is refreshing
        if (isPageRefreshing || sessionStorage.getItem('page_refreshing') === 'true') {
            return;
        }
        
        // Increment the counter
        pageSwitchCount++;
        
        // Store the new count in localStorage
        localStorage.setItem(storageKey, pageSwitchCount.toString());
        
        // Update UI elements if they exist
        updateViolationIndicators();
        
        // Display warning message if not already showing a warning
        const now = Date.now();
        if (now - lastViolationTime > 2000) {
            lastViolationTime = now;
            showWarningModal(`Page switch detected! (${pageSwitchCount}/${maxSwitches})`);
        }
        
        // If page switches exceed the maximum allowed, terminate the contest immediately
        if (pageSwitchCount >= maxSwitches) {
            // Get contest name if available in the page
            let contestName = document.querySelector('.contest-title')?.textContent || 
                            document.title || 'This contest';
            
            // Mark this contest as terminated in localStorage
            localStorage.setItem(`contest_${contestId}_terminated`, 'true');
            localStorage.setItem(`contest_${contestId}_termination_reason`, 'Exceeded maximum allowed page switches');
            localStorage.setItem(`contest_${contestId}_termination_time`, Date.now().toString());
            
            // Immediately redirect to dashboard without waiting for API call
            const friendlyMessage = `Your access to "${contestName}" has been revoked due to excessive page switching. You used ${pageSwitchCount} switches out of the maximum allowed ${maxSwitches}.`;
            window.location.href = `../student/dashboard.php?error=contest_terminated&reason=page_switch_violations&message=${encodeURIComponent(friendlyMessage)}`;
            
            // The following will only run if the redirect fails for some reason
            terminateContest('Exceeded maximum allowed page switches.');
        }
    }
    
    // Function to show warning modal or status bar
    function showWarningModal(message) {
        // Try to find or create modal - this is a simplified version; in practice, you'd have proper UI
        const warningModal = document.getElementById('warning-modal') || createWarningModal();
        
        // Set the message
        const messageElement = warningModal.querySelector('.message');
        if (messageElement) {
            messageElement.textContent = message;
        }
        
        // Show the modal
        warningModal.style.display = 'block';
        
        // Auto-hide after a few seconds
        clearTimeout(warningTimeout);
        warningTimeout = setTimeout(() => {
            warningModal.style.display = 'none';
        }, 3000);
    }
    
    // Function to create a warning modal if it doesn't exist
    function createWarningModal() {
        const modal = document.createElement('div');
        modal.id = 'warning-modal';
        modal.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: #dc3545;
            color: white;
            padding: 15px 20px;
            border-radius: 5px;
            z-index: 10000;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            display: none;
            transition: opacity 0.3s;
            max-width: 400px;
            font-family: system-ui, -apple-system, sans-serif;
            font-size: 14px;
            text-align: center;
        `;
        
        const messageElement = document.createElement('div');
        messageElement.className = 'message';
        modal.appendChild(messageElement);
        
        document.body.appendChild(modal);
        return modal;
    }
    
    // Function to update violation indicators in UI
    function updateViolationIndicators() {
        // Update the page switch info display in fullscreen overlay
        const switchInfoElement = document.getElementById('fullscreen-switch-info');
        if (switchInfoElement) {
            switchInfoElement.textContent = `Page Switches: ${pageSwitchCount} / ${maxSwitches}`;
            
            // Add warning color if approaching limit
            if (pageSwitchCount >= maxSwitches - 1) {
                switchInfoElement.style.color = '#dc3545'; // Red
            } else if (pageSwitchCount >= maxSwitches - 2) {
                switchInfoElement.style.color = '#ffc107'; // Yellow
            }
        }
        
        // If there's a separate violation counter element, update it too
        const violationCountElement = document.getElementById('violation-count');
        if (violationCountElement) {
            violationCountElement.textContent = pageSwitchCount.toString();
            
            // Add warning classes if approaching limit
            violationCountElement.classList.remove('warning', 'danger');
            if (pageSwitchCount >= maxSwitches - 1) {
                violationCountElement.classList.add('danger');
            } else if (pageSwitchCount >= maxSwitches - 2) {
                violationCountElement.classList.add('warning');
            }
        }
    }
    
    // Function to terminate the contest due to violations
    function terminateContest(reason) {
        if (!isContestActive) return; // Don't terminate if contest isn't active
        
        isContestActive = false; // Mark contest as no longer active
        
        // Store termination state in localStorage
        localStorage.setItem(`contest_${contestId}_terminated`, 'true');
        localStorage.setItem(`contest_${contestId}_termination_reason`, reason);
        localStorage.setItem(`contest_${contestId}_termination_time`, Date.now().toString());
        
        // Clear any active countdowns/intervals
        if (fullscreenCountdownInterval) {
            clearInterval(fullscreenCountdownInterval);
            fullscreenCountdownInterval = null;
        }
        
        if (devToolsCheckInterval) {
            clearInterval(devToolsCheckInterval);
            devToolsCheckInterval = null;
        }
        
        // Determine the reason code
        let reasonCode;
        if (reason.includes('fullscreen')) {
            reasonCode = 'fullscreen_violation';
        } else if (reason.includes('developer tools') || reason.includes('Developer tools')) {
            reasonCode = 'developer_tools';
        } else {
            reasonCode = 'page_switch_violations';
        }
        
        // Send termination to the server using AJAX
        fetch('../api/record_contest_exit.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                contest_id: contestId,
                reason: reasonCode,
                permanent: true
            })
        })
        .then(response => response.json())
        .then(data => {
            console.log('Termination recorded:', data);
            
            // After successfully recording the termination, redirect to dashboard
            redirectToDashboardWithTerminationMessage(reason, reasonCode);
        })
        .catch(error => {
            console.error('Error recording termination:', error);
            
            // Even if there's an error with the API call, still redirect to dashboard
            redirectToDashboardWithTerminationMessage(reason, reasonCode);
        });
        
        // Also submit a form as a backup method to ensure termination is recorded
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '../api/record_contest_exit.php';
        form.style.display = 'none';
        
        const contestIdField = document.createElement('input');
        contestIdField.name = 'contest_id';
        contestIdField.value = contestId;
        
        const reasonField = document.createElement('input');
        reasonField.name = 'reason';
        reasonField.value = reasonCode;
        
        const permanentField = document.createElement('input');
        permanentField.name = 'permanent';
        permanentField.value = 'true';
        
        form.appendChild(contestIdField);
        form.appendChild(reasonField);
        form.appendChild(permanentField);
        
        document.body.appendChild(form);
        
        // If AJAX is blocked or fails, submit the form after a delay
        setTimeout(() => {
            try {
                form.submit();
            } catch (e) {
                // If form submission fails too, force redirect to dashboard
                redirectToDashboardWithTerminationMessage(reason, reasonCode);
            }
        }, 1000);
    }
    
    // Helper function to handle redirection to dashboard with appropriate message
    function redirectToDashboardWithTerminationMessage(reason, reasonCode) {
        // Get contest name if available in the page
        let contestName = document.querySelector('.contest-title')?.textContent || 
                        document.title || 'This contest';
        
        // Create a more user-friendly message based on the reason
        let friendlyMessage;
        switch(reasonCode) {
            case 'fullscreen_violation':
                friendlyMessage = `Your access to "${contestName}" has been permanently revoked due to fullscreen mode violations.`;
                break;
            case 'developer_tools':
                friendlyMessage = `Your access to "${contestName}" has been permanently revoked due to the use of developer tools.`;
                break;
            case 'page_switch_violations':
                friendlyMessage = `Your access to "${contestName}" has been permanently revoked due to excessive page switches.`;
                break;
            default:
                friendlyMessage = `Your access to "${contestName}" has been permanently revoked due to contest rule violations.`;
        }
        
        // Redirect to the dashboard with appropriate error parameters
        window.location.href = `../student/dashboard.php?error=contest_terminated&reason=${encodeURIComponent(reason)}&message=${encodeURIComponent(friendlyMessage)}`;
    }

    // DevTools check - periodically check if dev tools are open
    // Commented out for development as per user request
    /*
    if (isContestActive && detectDeveloperTools()) {
        handleDevToolsViolation();
    }
    setInterval(() => {
        if (isContestActive && detectDeveloperTools()) {
            handleDevToolsViolation();
        }
    }, 2000); // Check every 2 seconds
    */

    // Periodically check for developer tools
    // We need to be careful with setInterval and debugger statements, as they can be disruptive.
    // A better approach might be to check on focus or specific interactions.
    // For now, a gentle interval check.
    let devToolsCheckInterval = setInterval(() => {
        if (isContestActive && detectDeveloperTools()) {
            handleDevToolsViolation();
        }
    }, 5000); // Check every 5 seconds
    
    // Function to show a beautiful success overlay when all test cases pass
    window.showSuccessOverlay = function(problemName) {
        // Create overlay container
        const successOverlay = document.createElement('div');
        successOverlay.id = 'success-overlay';
        successOverlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.85);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 10000;
            opacity: 0;
            transition: opacity 0.5s ease-in-out;
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
        `;
        
        // Create success animation with confetti effect
        const successAnimation = document.createElement('div');
        successAnimation.style.cssText = `
            position: relative;
            width: 150px;
            height: 150px;
            margin-bottom: 20px;
        `;
        
        // Success checkmark
        const checkmark = document.createElement('div');
        checkmark.style.cssText = `
            position: absolute;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: #28a745;
            display: flex;
            justify-content: center;
            align-items: center;
            animation: pulse 1.5s infinite;
            box-shadow: 0 0 30px rgba(40, 167, 69, 0.6);
        `;
        
        checkmark.innerHTML = `
            <svg width="80" height="80" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z" fill="white"/>
            </svg>
        `;
        
        successAnimation.appendChild(checkmark);
        
        // Create message content
        const messageContainer = document.createElement('div');
        messageContainer.style.cssText = `
            color: white;
            text-align: center;
            max-width: 600px;
            padding: 20px;
        `;
        
        const title = document.createElement('h2');
        title.textContent = 'All Test Cases Passed!';
        title.style.cssText = `
            font-size: 2.5rem;
            margin-bottom: 15px;
            color: #28a745;
            font-weight: bold;
            text-shadow: 0 0 10px rgba(40, 167, 69, 0.5);
        `;
        
        const message = document.createElement('p');
        message.innerHTML = problemName ? 
            `Congratulations! You've successfully solved <strong>${problemName}</strong>. All test cases have passed.` :
            `Congratulations! You've successfully passed all test cases.`;
        message.style.cssText = `
            font-size: 1.2rem;
            margin-bottom: 30px;
            color: #e9ecef;
            line-height: 1.6;
        `;
        
        const continueBtn = document.createElement('button');
        continueBtn.textContent = 'Continue';
        continueBtn.style.cssText = `
            padding: 10px 25px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        `;
        
        continueBtn.onmouseover = () => {
            continueBtn.style.background = '#218838';
            continueBtn.style.transform = 'translateY(-2px)';
        };
        
        continueBtn.onmouseout = () => {
            continueBtn.style.background = '#28a745';
            continueBtn.style.transform = 'translateY(0)';
        };
        
        continueBtn.onclick = () => {
            hideSuccessOverlay();
        };
        
        messageContainer.appendChild(title);
        messageContainer.appendChild(message);
        messageContainer.appendChild(continueBtn);
        
        // Add elements to overlay
        successOverlay.appendChild(successAnimation);
        successOverlay.appendChild(messageContainer);
        
        // Add confetti elements
        for (let i = 0; i < 40; i++) {
            const confetti = document.createElement('div');
            const size = Math.random() * 10 + 5;
            const color = getRandomColor();
            
            confetti.style.cssText = `
                position: absolute;
                width: ${size}px;
                height: ${size}px;
                background-color: ${color};
                top: -20px;
                left: ${Math.random() * 100}%;
                opacity: ${Math.random() * 0.8 + 0.2};
                animation: confetti-fall ${Math.random() * 3 + 2}s linear infinite,
                           confetti-sway ${Math.random() * 4 + 3}s ease-in-out infinite alternate;
                transform: rotate(${Math.random() * 360}deg);
            `;
            
            successOverlay.appendChild(confetti);
        }
        
        // Add CSS animation styles
        const styleSheet = document.createElement('style');
        styleSheet.textContent = `
            @keyframes pulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.1); }
                100% { transform: scale(1); }
            }
            
            @keyframes confetti-fall {
                0% { top: -20px; }
                100% { top: 100vh; }
            }
            
            @keyframes confetti-sway {
                0% { transform: translateX(0) rotate(0); }
                50% { transform: translateX(100px) rotate(90deg); }
                100% { transform: translateX(-100px) rotate(-90deg); }
            }
        `;
        
        document.head.appendChild(styleSheet);
        document.body.appendChild(successOverlay);
        
        // Fade in effect
        setTimeout(() => {
            successOverlay.style.opacity = '1';
        }, 100);
        
        // Auto-hide after 8 seconds (optional)
        setTimeout(() => {
            hideSuccessOverlay();
        }, 8000);
        
        // Function to hide the overlay
        function hideSuccessOverlay() {
            successOverlay.style.opacity = '0';
            setTimeout(() => {
                if (successOverlay.parentNode) {
                    successOverlay.parentNode.removeChild(successOverlay);
                }
            }, 500);
        }
        
        // Function to get random colors for confetti
        function getRandomColor() {
            const colors = [
                '#f94144', '#f3722c', '#f8961e', '#f9c74f', 
                '#90be6d', '#43aa8b', '#4d908e', '#577590', 
                '#277da1', '#ff66ff', '#66ffff'
            ];
            return colors[Math.floor(Math.random() * colors.length)];
        }
    };
}); 