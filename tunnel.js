const localtunnel = require('localtunnel');
const path = require('path');

async function startTunnel() {
    try {
        console.log('\n🔄 Starting localtunnel...');
        console.log('Make sure your XAMPP/Web server is running!');

        // Get the current directory name
        const currentDir = path.basename(process.cwd());
        
        const tunnel = await localtunnel({ 
            port: 80,
            subdomain: 'codinger-abhishek',
            // Performance optimizations
            maxConnections: 100,
            keepAlive: true,
            allowInvalidCert: true,
            // Use closest server
            region: 'us',
            // Optimize connection settings
            responseTimeout: 15000,
            requestTimeout: 15000,
            // Enable compression
            enableCompression: true
        });

        // Modify the URL to point to your specific directory
        const baseUrl = tunnel.url;
        const appUrl = baseUrl + '/coding_web';

        console.log('\n✅ Connection successful!');
        console.log('\n🚀 Your website is now available at:');
        console.log(appUrl);
        console.log('\nℹ️  Main URLs:');
        console.log('   📁 Website Root:', appUrl);
        console.log('   🔐 Admin Panel:', appUrl + '/admin');
        console.log('   👤 Login Page:', appUrl + '/login.php');
        console.log('\nℹ️  Press Ctrl+C to stop the tunnel\n');

        let isConnected = true;
        let reconnectAttempts = 0;
        const maxReconnectAttempts = 3;

        // Keep connection alive with periodic pings
        const keepAliveInterval = setInterval(() => {
            if (isConnected) {
                tunnel.emit('ping');
            }
        }, 15000);

        tunnel.on('close', () => {
            if (isConnected) {
                console.log('\n❌ Tunnel closed');
                isConnected = false;
                clearInterval(keepAliveInterval);
                process.exit(0);
            }
        });

        tunnel.on('error', async (err) => {
            console.error('\n⚠️ Tunnel error:', err.message);
            if (isConnected && reconnectAttempts < maxReconnectAttempts) {
                isConnected = false;
                reconnectAttempts++;
                console.log(`\n🔄 Reconnecting (${reconnectAttempts}/${maxReconnectAttempts})...`);
                try {
                    clearInterval(keepAliveInterval);
                    await tunnel.close();
                } catch (e) {}
                setTimeout(startTunnel, 3000);
            } else if (reconnectAttempts >= maxReconnectAttempts) {
                console.log('\n❌ Max reconnection attempts reached.');
                clearInterval(keepAliveInterval);
                process.exit(1);
            }
        });

        // Handle successful pings
        tunnel.on('ping', () => {
            // Connection is healthy
        });

        // Monitor connection speed
        let lastPingTime = Date.now();
        tunnel.on('request', (info) => {
            const currentTime = Date.now();
            const latency = currentTime - lastPingTime;
            lastPingTime = currentTime;

            if (latency > 5000) { // If latency is too high
                console.log('\n⚠️ High latency detected:', latency + 'ms');
            }
        });

        process.on('SIGINT', async () => {
            if (isConnected) {
                console.log('\n👋 Closing tunnel...');
                isConnected = false;
                clearInterval(keepAliveInterval);
                try {
                    await tunnel.close();
                } catch (e) {}
                process.exit(0);
            }
        });

    } catch (error) {
        console.error('\n❌ Failed to start tunnel:', error.message);
        console.error('\nPlease check:');
        console.log('1. XAMPP Apache is running (check XAMPP Control Panel)');
        console.log('2. http://localhost/coding_web is accessible in your browser');
        console.log('3. No other tunnels are running');
        console.log('4. Your firewall allows Node.js connections');
        process.exit(1);
    }
}

// Performance tips
console.log('\n⚡ Starting tunnel service with performance optimizations...');
console.log('📌 Prerequisites:');
console.log('   1. XAMPP Apache must be running');
console.log('   2. http://localhost/coding_web should be accessible');
console.log('   3. For best performance:');
console.log('      - Close other bandwidth-heavy applications');
console.log('      - Use a stable internet connection');
console.log('      - Keep the terminal window open');

startTunnel(); 