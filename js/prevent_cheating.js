document.addEventListener('DOMContentLoaded', function() {
    let pageSwitchCount = 0;
    // Instead of hardcoding maxSwitches, get it from the data attribute we'll add to the page
    const maxSwitches = parseInt(document.body.getAttribute('data-max-tab-switches') || 3);
    // Also check if copy-paste is allowed based on data attribute from the page
    const isCopyPasteAllowed = document.body.getAttribute('data-allow-copy-paste') === "1";
    let isContestActive = true;
    let warningTimeout;
    let lastViolationTime = 0;
    let debugMode = false; // Set to true to enable console logging
    
    // Debug helper function
    function debug(message) {
        if (debugMode) {
            console.log("[Cheat Prevention]:", message);
        }
    }
    
    debug("Initializing cheat prevention system");
    debug(`Tab switches configured: ${maxSwitches}`);
    debug(`Copy-paste allowed: ${isCopyPasteAllowed}`);
    
    // Prevent right click
    document.addEventListener('contextmenu', function(e) {
        if (isContestActive) {
            e.preventDefault();
            showWarningModal('Right-clicking is not allowed during the contest.');
        }
    });

    // Prevent copy/paste and show warning - only if copy-paste is not allowed
    document.addEventListener('keydown', function(e) {
        if (isContestActive) {
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
            // Existing F5 prevention
            if (e.key === 'F5' || (e.ctrlKey && e.key === 'r')) {
                e.preventDefault();
                handlePageSwitchViolation('Page refresh attempted!');
            }
        }
    });

    // Prevent copy/paste through context menu and mouse events - only if copy-paste is not allowed
    document.addEventListener('copy', function(e) {
        if (isContestActive && !isCopyPasteAllowed) {
            e.preventDefault();
            showWarningModal('Copying is not allowed during the contest.');
        }
    });

    document.addEventListener('paste', function(e) {
        if (isContestActive && !isCopyPasteAllowed) {
            e.preventDefault();
            showWarningModal('Pasting is not allowed during the contest.');
        }
    });
    
    // Remove any existing prevention elements from previous versions
    const existingElements = [
        document.getElementById('violation-warning'),
        document.getElementById('violations-counter'),
        document.getElementById('tab-switch-warning'),
        document.getElementById('warning-indicator'),
        document.getElementById('cheating-prevention-system')
    ];
    
    existingElements.forEach(element => {
        if (element) element.remove();
    });
    
    // Ensure page state tracking is set up immediately
    document.title = document.title || "Contest Page";
    const originalTitle = document.title;
    
    // Immediately push state to prevent back navigation
    history.pushState({page: 1}, originalTitle, window.location.href);

    // Create main container for all warning elements
    const warningSystem = document.createElement('div');
    warningSystem.id = 'cheating-prevention-system';
    document.body.appendChild(warningSystem);

    // Create modal warning overlay
    const warningOverlay = document.createElement('div');
    warningOverlay.id = 'warning-overlay';
    warningOverlay.style.cssText = `
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.85);
        z-index: 10000;
        backdrop-filter: blur(8px);
    `;
    warningSystem.appendChild(warningOverlay);

    // Create modal warning dialog
    const warningModal = document.createElement('div');
    warningModal.id = 'warning-modal';
    warningModal.style.cssText = `
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: #1e1e2f;
        border-radius: 16px;
        padding: 0;
        width: 90%;
        max-width: 500px;
        box-shadow: 0 25px 50px -12px rgba(255, 56, 56, 0.5);
        overflow: hidden;
        font-family: system-ui, -apple-system, sans-serif;
    `;
    warningOverlay.appendChild(warningModal);

    // Create warning header
    const warningHeader = document.createElement('div');
    warningHeader.style.cssText = `
        background: linear-gradient(135deg, #ff4757, #ff6b81);
        color: white;
        padding: 20px 30px;
        font-size: 22px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 12px;
    `;
    warningModal.appendChild(warningHeader);

    // Create warning content
    const warningContent = document.createElement('div');
    warningContent.style.cssText = `
        padding: 25px 30px;
        color: white;
        font-size: 16px;
        line-height: 1.6;
    `;
    warningModal.appendChild(warningContent);

    // Create violations status bar
    const statusBar = document.createElement('div');
    statusBar.id = 'violations-status';
    statusBar.style.cssText = `
        position: fixed;
        top: 15px;
        left: 15px;
        background: rgba(30, 30, 47, 0.95);
        border-radius: 15px;
        padding: 15px;
        color: white;
        font-family: system-ui, -apple-system, sans-serif;
        font-size: 14px;
        z-index: 9990;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        display: flex;
        flex-direction: column;
        gap: 12px;
        min-width: 220px;
        backdrop-filter: blur(10px);
        transition: all 0.3s ease;
        opacity: 0;
        transform: translateY(-20px);
    `;
    
    const statusTitle = document.createElement('div');
    statusTitle.style.cssText = `
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 600;
        font-size: 15px;
        color: #fff;
        letter-spacing: 0.3px;
    `;
    statusTitle.innerHTML = '<svg width="18" height="18" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 1.5C4.41015 1.5 1.5 4.41015 1.5 8C1.5 11.5899 4.41015 14.5 8 14.5C11.5899 14.5 14.5 11.5899 14.5 8C14.5 4.41015 11.5899 1.5 8 1.5ZM8 13C5.23858 13 3 10.7614 3 8C3 5.23858 5.23858 3 8 3C10.7614 3 13 5.23858 13 8C13 10.7614 10.7614 13 8 13Z" fill="white"/><path d="M7.25 5C7.25 4.58579 7.58579 4.25 8 4.25C8.41421 4.25 8.75 4.58579 8.75 5V8.5C8.75 8.91421 8.41421 9.25 8 9.25C7.58579 9.25 7.25 8.91421 7.25 8.5V5Z" fill="white"/><circle cx="8" cy="11" r="1" fill="white"/></svg> Contest Security';
    
    const violationCounter = document.createElement('div');
    violationCounter.style.cssText = `
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: rgba(255, 255, 255, 0.05);
        padding: 8px 12px;
        border-radius: 10px;
        font-size: 13px;
    `;
    violationCounter.innerHTML = `<span style="color: rgba(255,255,255,0.8);">Page Switches:</span> <span id="violation-count" style="font-weight: 600;">0/${maxSwitches}</span>`;
    
    const indicatorContainer = document.createElement('div');
    indicatorContainer.style.cssText = `
        display: flex;
        gap: 8px;
        height: 6px;
        padding: 0 2px;
    `;
    
    // Create indicators based on the maximum number of switches
    for (let i = 0; i < maxSwitches; i++) {
        const indicator = document.createElement('div');
        indicator.className = 'violation-indicator';
        indicator.style.cssText = `
            height: 6px;
            flex: 1;
            background: #2ecc71;
            border-radius: 3px;
            transition: background-color 0.3s ease;
        `;
        indicatorContainer.appendChild(indicator);
    }
    
    statusBar.appendChild(statusTitle);
    statusBar.appendChild(violationCounter);
    statusBar.appendChild(indicatorContainer);
    warningSystem.appendChild(statusBar);
    
    // Add CSS keyframes for animations
    const styleSheet = document.createElement('style');
    styleSheet.textContent = `
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        @keyframes shake {
            0%, 100% { transform: translate(-50%, -50%); }
            10%, 30%, 50%, 70%, 90% { transform: translate(-52%, -50%); }
            20%, 40%, 60%, 80% { transform: translate(-48%, -50%); }
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }
        @keyframes slideInTop {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        @keyframes emergencyPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(255, 71, 87, 0.7); }
            50% { box-shadow: 0 0 0 15px rgba(255, 71, 87, 0); }
        }
        
        #warning-overlay {
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        #warning-overlay.visible {
            opacity: 1;
        }
        
        /* Allow text selection in CodeMirror always */
        .CodeMirror {
            -webkit-user-select: text !important;
            -moz-user-select: text !important;
            -ms-user-select: text !important;
            user-select: text !important;
        }
        
        /* Only apply user-select none if copy-paste is not allowed */
        ${!isCopyPasteAllowed ? `
        body {
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }` : ''}
    `;
    document.head.appendChild(styleSheet);
    
    // Update indicators function to handle dynamic number of indicators
    function updateViolationIndicators() {
        debug(`Updating violation indicators: ${pageSwitchCount}/${maxSwitches}`);
        
        // Update text counter
        const violationCount = document.getElementById('violation-count');
        if (violationCount) {
            violationCount.textContent = `${pageSwitchCount}/${maxSwitches}`;
        }
        
        // Update indicator colors
        const indicators = document.querySelectorAll('.violation-indicator');
        
        indicators.forEach((indicator, index) => {
            if (index < pageSwitchCount) {
                // Make indicators red when switched
                indicator.style.backgroundColor = '#ff4757';
                
                // Add animation
                indicator.style.animation = 'pulse 0.5s';
                setTimeout(() => {
                    indicator.style.animation = '';
                }, 500);
            } else {
                indicator.className = 'violation-indicator';
            }
        });
        
        // Show the status bar with animation
        statusBar.style.display = 'flex';
        statusBar.style.opacity = '1';
        statusBar.style.transform = 'translateY(0)';

        // Clear any existing hide timeout
        if (window.statusBarTimeout) {
            clearTimeout(window.statusBarTimeout);
        }

        // Hide status bar after 5 seconds unless there's an active warning
        window.statusBarTimeout = setTimeout(() => {
            if (!warningOverlay.classList.contains('visible')) {
                statusBar.style.opacity = '0';
                statusBar.style.transform = 'translateY(-20px)';
                setTimeout(() => {
                    if (statusBar.style.opacity === '0') {
                        statusBar.style.display = 'none';
                    }
                }, 300);
            }
        }, 5000);
    }
    
    // Function to hide warning modal
    function hideWarningModal() {
        debug("Hiding warning modal");
        // Hide using class approach for more reliable animation
        warningOverlay.classList.remove('visible');
        
        // Then remove from DOM after animation completes
        setTimeout(function() {
            if (warningOverlay) {
                warningOverlay.style.display = 'none';
                if (warningModal) {
                    warningModal.style.animation = '';
                }
            }
            debug("Warning modal hidden completely");
        }, 300);
    }
    
    // Function to show warning modal
    function showWarningModal(message, isTerminal = false) {
        if (!isContestActive) return;
        
        debug(`Showing warning modal: ${isTerminal ? 'Terminal' : 'Warning'} - ${message}`);
        
        // Clear any existing timeout to prevent conflicts
        if (warningTimeout) {
            debug("Clearing existing warning timeout");
            clearTimeout(warningTimeout);
            warningTimeout = null;
        }
        
        // Set content based on warning type
        warningHeader.innerHTML = isTerminal ? 
            '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 7.75C12.4142 7.75 12.75 8.08579 12.75 8.5V13.5C12.75 13.9142 12.4142 14.25 12 14.25C11.5858 14.25 11.25 13.9142 11.25 13.5V8.5C11.25 8.08579 11.5858 7.75 12 7.75Z" fill="white"/><circle cx="12" cy="17" r="1" fill="white"/><path d="M8.97046 3.34239C10.3372 0.93724 13.6628 0.937241 15.0295 3.34239L22.3922 16.2764C23.7218 18.5979 22.0273 21.5 19.3626 21.5H4.63736C1.97271 21.5 0.278183 18.5979 1.60779 16.2764L8.97046 3.34239Z" stroke="white" stroke-width="2"/></svg> Contest Terminated' : 
            '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 7.75C12.4142 7.75 12.75 8.08579 12.75 8.5V13.5C12.75 13.9142 12.4142 14.25 12 14.25C11.5858 14.25 11.25 13.9142 11.25 13.5V8.5C11.25 8.08579 11.5858 7.75 12 7.75Z" fill="white"/><circle cx="12" cy="17" r="1" fill="white"/><path d="M8.97046 3.34239C10.3372 0.93724 13.6628 0.937241 15.0295 3.34239L22.3922 16.2764C23.7218 18.5979 22.0273 21.5 19.3626 21.5H4.63736C1.97271 21.5 0.278183 18.5979 1.60779 16.2764L8.97046 3.34239Z" stroke="white" stroke-width="2"/></svg> Warning';
        
        // Set warning content
        warningContent.innerHTML = `
            <p>${message}</p>
            ${isTerminal ? '<p style="margin-top: 15px; opacity: 0.8;">Redirecting to dashboard...</p>' : ''}
        `;
        
        // Show the warning with class-based approach
        warningOverlay.style.display = 'block';
        // Force reflow to ensure animation starts properly
        void warningOverlay.offsetWidth;
        warningOverlay.classList.add('visible');
        
        // Set appropriate animation and styling for terminal vs warning
        if (isTerminal) {
            warningModal.style.animation = 'shake 0.5s ease';
            warningModal.style.boxShadow = '0 25px 50px -12px rgba(255, 56, 56, 0.7)';
            statusBar.style.animation = 'emergencyPulse 1.5s infinite';
        } else {
            warningModal.style.animation = 'pulse 2s infinite';
            
            // Auto-hide warning after 3 seconds for non-terminal warnings
            debug("Setting warning timeout for 3 seconds");
            warningTimeout = setTimeout(function() {
                debug("Warning timeout triggered, hiding warning");
                hideWarningModal();
            }, 3000);
        }
    }
    
    // Function to handle contest termination
    function terminateContest() {
        isContestActive = false;
        
        // Show terminal warning
        showWarningModal('You have exceeded the maximum number of page switch violations. Your contest session has been terminated.', true);
        
        // Create a form to post the termination reason
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'dashboard.php';
        form.style.display = 'none';
        
        const reasonInput = document.createElement('input');
        reasonInput.type = 'hidden';
        reasonInput.name = 'termination_reason';
        reasonInput.value = 'page_switch_violations';
        
        const statusInput = document.createElement('input');
        statusInput.type = 'hidden';
        statusInput.name = 'status';
        statusInput.value = 'terminated';
        
        form.appendChild(reasonInput);
        form.appendChild(statusInput);
        document.body.appendChild(form);
        
        // Submit the form after a delay
        setTimeout(() => {
            form.submit();
        }, 3000);
    }
    
    // Function to handle page switch violations
    function handlePageSwitchViolation(type) {
        // Prevent counting violations too quickly (cooldown of 1 second)
        const now = Date.now();
        if (now - lastViolationTime < 1000) return;
        lastViolationTime = now;
        
        // Count the violation
        pageSwitchCount++;
        updateViolationIndicators();
        
        // Show appropriate warning
        if (pageSwitchCount >= maxSwitches) {
            terminateContest();
        } else {
            const remainingChances = maxSwitches - pageSwitchCount;
            showWarningModal(`${type} You have ${remainingChances} warning${remainingChances === 1 ? '' : 's'} remaining before termination.`);
        }
    }
    
    // Handle visibility change (tab switching)
    document.addEventListener('visibilitychange', function() {
        if (document.hidden && isContestActive) {
            handlePageSwitchViolation('Tab switched!');
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
    
    // Prevent closing/reloading page
    window.addEventListener('beforeunload', function(e) {
        if (isContestActive) {
            e.preventDefault();
            e.returnValue = 'Are you sure you want to leave? This will be counted as a violation!';
            return e.returnValue;
        }
    });
    
    // Initialize the violation indicators
    updateViolationIndicators();
    
    // Check if UI elements were properly initialized
    setTimeout(() => {
        const violationCountElement = document.getElementById('violation-count');
        const warningModalElement = document.getElementById('warning-modal');
        
        if (!violationCountElement || !warningModalElement) {
            console.log('Prevention system UI not properly initialized. Reloading...');
            // Force a hard reload of the page (bypass cache)
            window.location.reload(true);
        }
    }, 1000);
}); 