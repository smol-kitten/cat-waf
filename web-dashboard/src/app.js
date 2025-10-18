// CatWAF Dashboard - Catboy Themed Web Interface
// API Configuration
const API_BASE_URL = '/api';
const API_TOKEN = localStorage.getItem('api_token') || '';

// Toast Notification Utility
const Toast = {
    show: function(message, type = 'info') {
        // Remove existing toast if any
        const existing = document.getElementById('toast-notification');
        if (existing) existing.remove();
        
        // Create toast element
        const toast = document.createElement('div');
        toast.id = 'toast-notification';
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : type === 'warning' ? '#f59e0b' : '#3b82f6'};
            color: white;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 10000;
            animation: slideIn 0.3s ease-out;
        `;
        
        document.body.appendChild(toast);
        
        // Auto-remove after 3 seconds
        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease-out';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
};

// Alias for compatibility
const showToast = (message, type) => Toast.show(message, type);

// HTML Escape Utility
function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// State Management
let currentPage = 'overview';
let stats = {};
let charts = {};
let envDefaults = {}; // Store environment defaults

// Keyboard Shortcuts
document.addEventListener('keydown', (e) => {
    // Ctrl/Cmd + K: Quick search (planned)
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        console.log('Quick search coming soon!');
        Toast.show('Quick search coming soon! 🔍', 'info');
    }
    
    // Ctrl/Cmd + S: Save (if in edit mode)
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        const saveBtn = document.querySelector('.save-site-btn:not([disabled])');
        if (saveBtn && saveBtn.offsetParent !== null) {
            e.preventDefault();
            saveBtn.click();
            Toast.show('Saving... 💾', 'info');
        }
    }
    
    // Esc: Close modals
    if (e.key === 'Escape') {
        const modals = document.querySelectorAll('.modal.active');
        modals.forEach(modal => {
            modal.classList.remove('active');
        });
    }
});

// Load environment defaults from backend
async function loadEnvironmentDefaults() {
}

// Initialize App
document.addEventListener('DOMContentLoaded', () => {
    initializeApp();
});

async function initializeApp() {
    console.log('🐱 Initializing CatWAF Dashboard...');
    
    // Verify authentication
    if (!API_TOKEN) {
        console.warn('No API token found, redirecting to login...');
        window.location.href = '/login.html';
        return;
    }
    
    // Setup navigation
    setupNavigation();
    
    // Setup modals
    setupModals();
    
    // Setup logout button
    setupLogout();
    
    // Check API connection
    const isConnected = await checkAPIConnection();
    if (!isConnected) {
        console.error('API connection failed, redirecting to login...');
        localStorage.removeItem('api_token');
        window.location.href = '/login.html';
        return;
    }
    
    // Load environment defaults
    await loadEnvironmentDefaults();
    
    // Load initial data
    await loadDashboardData();
    
    // Setup time range selector for traffic chart
    const timeRangeSelector = document.getElementById('timeRange');
    if (timeRangeSelector) {
        timeRangeSelector.addEventListener('change', async (e) => {
            const range = e.target.value;
            await loadDashboardData(range);
        });
    }
    
    // Setup auto-refresh
    setInterval(() => {
        if (currentPage === 'overview') {
            loadDashboardData();
        }
    }, 30000); // Refresh every 30 seconds
    
    console.log('✅ Dashboard initialized successfully!');
}

// Navigation
function setupNavigation() {
    const navItems = document.querySelectorAll('.nav-item');
    navItems.forEach(item => {
        item.addEventListener('click', (e) => {
            const page = item.getAttribute('data-page');
            // Skip items without data-page (like external links)
            if (!page) return;
            
            e.preventDefault();
            navigateToPage(page);
        });
    });
}

function navigateToPage(page) {
    currentPage = page;
    
    // Update nav active state
    document.querySelectorAll('.nav-item').forEach(item => {
        item.classList.remove('active');
        if (item.getAttribute('data-page') === page) {
            item.classList.add('active');
        }
    });
    
    // Update page visibility
    document.querySelectorAll('.page').forEach(p => {
        p.classList.remove('active');
    });
    const pageElement = document.getElementById(`${page}-page`);
    if (pageElement) {
        pageElement.classList.add('active');
    } else {
        console.error(`Page element not found: ${page}-page`);
    }
    
    // Update page title
    const titles = {
        'overview': 'Dashboard Overview',
        'sites': 'Protected Sites',
        'bans': 'IP Bans',
        'security': 'Security Events',
        'modsecurity': 'ModSecurity WAF',
        'bots': 'Bot Protection',
        'telemetry': 'Performance Telemetry',
        'analytics': 'GoAccess Analytics',
        'logs': 'Access Logs',
        'settings': 'Settings'
    };
    const pageTitle = document.getElementById('page-title');
    if (pageTitle) {
        pageTitle.textContent = titles[page];
    }
    
    // Load page data
    loadPageData(page);
}

async function loadPageData(page) {
    switch(page) {
        case 'overview':
            await loadDashboardData();
            break;
        case 'sites':
            await loadSites();
            break;
        case 'bans':
            await loadBans();
            break;
        case 'security':
            await loadSecuritySummary();
            await loadSecurityEvents();
            break;
        case 'modsecurity':
            await loadModSecurityData();
            break;
        case 'bots':
            await loadBotProtectionData();
            break;
        case 'telemetry':
            await loadTelemetryData();
            break;
        case 'analytics':
            await loadGoAccessData();
            break;
        case 'logs':
            await populateLogSiteFilter();
            await loadLogs();
            break;
        case 'settings':
            await loadSettings();
            break;
    }
}

// API Functions
async function checkAPIConnection() {
    try {
        const response = await apiRequest('/stats');
        if (response) {
            updateAPIStatus(true);
            return true;
        } else {
            updateAPIStatus(false);
            return false;
        }
    } catch (error) {
        console.error('API connection failed:', error);
        updateAPIStatus(false);
        return false;
    }
}

function updateAPIStatus(connected) {
    const statusDot = document.querySelector('.status-dot');
    const statusText = statusDot.nextElementSibling;
    
    if (connected) {
        statusDot.classList.remove('error');
        statusText.textContent = 'API Connected';
    } else {
        statusDot.classList.add('error');
        statusText.textContent = 'API Disconnected';
    }
}

async function apiRequest(endpoint, methodOrOptions = {}, bodyData = null) {
    const url = `${API_BASE_URL}${endpoint}`;
    
    // Support both calling conventions:
    // New: apiRequest(url, { method: 'POST', body: '...' })
    // Old: apiRequest(url, 'POST', data)
    let options = {};
    if (typeof methodOrOptions === 'string') {
        // Old convention: second param is method string
        options.method = methodOrOptions;
        if (bodyData !== null) {
            options.body = JSON.stringify(bodyData);
        }
    } else {
        // New convention: second param is options object
        options = methodOrOptions;
    }
    
    // Use Headers API to avoid encoding issues
    const headers = new Headers();
    headers.append('Content-Type', 'application/json');
    
    if (API_TOKEN) {
        headers.append('Authorization', 'Bearer ' + API_TOKEN);
    }
    
    // Add any additional headers from options
    if (options.headers) {
        Object.keys(options.headers).forEach(key => {
            headers.append(key, options.headers[key]);
        });
    }
    
    try {
        const response = await fetch(url, {
            ...options,
            headers: headers,
            cache: 'no-store' // Prevent browser caching
        });
        
        // Handle different HTTP status codes
        if (response.status === 401) {
            Toast.show('Authentication failed. Please log in again.', 'error');
            localStorage.removeItem('api_token');
            setTimeout(() => window.location.reload(), 2000);
            return null;
        }
        
        if (response.status === 403) {
            Toast.show('Permission denied. Insufficient privileges.', 'error');
            return null;
        }
        
        if (response.status === 404) {
            Toast.show('Resource not found. Please refresh.', 'warning');
            return null;
        }
        
        if (response.status === 429) {
            Toast.show('Too many requests. Please slow down!', 'warning');
            return null;
        }
        
        if (response.status >= 500) {
            Toast.show('Server error. Please try again later.', 'error');
            return null;
        }
        
        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            const errorMsg = errorData.error || errorData.message || response.statusText;
            Toast.show(`Error: ${errorMsg}`, 'error');
            throw new Error(errorMsg);
        }
        
        return await response.json();
    } catch (error) {
        console.error('API request error:', error);
        
        // Network error
        if (error.name === 'TypeError' && error.message.includes('fetch')) {
            Toast.show('Network error. Check your connection.', 'error');
        } else {
            Toast.show('Request failed. Check console for details.', 'error');
        }
        
        return null;
    }
}

// Dashboard Data Loading
async function loadDashboardData(range = '24h') {
    try {
        const response = await apiRequest(`/stats?range=${range}`);
        const statsData = response?.stats || {};
        
        if (statsData && Object.keys(statsData).length > 0) {
            stats = statsData;
            updateStatCards(statsData);
            updateCharts(statsData);
            await loadRecentActivity();
        } else {
            // Show zeros if no data
            updateStatCards({
                total_requests: 0,
                blocked_requests: 0,
                unique_ips: 0,
                active_bans: 0
            });
        }
    } catch (error) {
        console.error('Error loading dashboard data:', error);
    }
}

function updateStatCards(data) {
    // Total Sites -> Total Requests (HTML uses totalRequests)
    const statRequests = document.getElementById('totalRequests');
    if (statRequests) statRequests.textContent = formatNumber(data.total_requests || 0);
    
    // Active Sites -> Blocked (HTML uses blockedRequests)
    const statBlocked = document.getElementById('blockedRequests');
    if (statBlocked) statBlocked.textContent = formatNumber(data.blocked_requests || 0);
    
    // Unique IPs
    const statIPs = document.getElementById('uniqueIps');
    if (statIPs) statIPs.textContent = formatNumber(data.unique_ips || 0);
    
    // Active Bans
    const statBans = document.getElementById('activeBans');
    if (statBans) statBans.textContent = data.active_bans || '0';
}

// Helper function to get color based on status code
function getStatusColor(statusCode) {
    const code = parseInt(statusCode);
    
    // 2xx - Blue shades (darker as higher)
    if (code >= 200 && code < 300) {
        if (code === 200) return '#3b82f6';      // Blue
        if (code === 201) return '#2563eb';      // Darker blue
        if (code === 204) return '#1d4ed8';      // Even darker
        return '#1e40af';                        // Darkest blue
    }
    
    // 3xx - Gray
    if (code >= 300 && code < 400) {
        return '#6b7280';                        // Gray
    }
    
    // 4xx - Yellow to Orange
    if (code >= 400 && code < 500) {
        if (code === 400 || code === 401) return '#fbbf24';  // Yellow
        if (code === 403) return '#f59e0b';                  // Orange
        if (code === 404) return '#f97316';                  // Darker orange
        if (code >= 429) return '#ea580c';                   // Deep orange
        return '#fb923c';                                    // Default orange
    }
    
    // 5xx - Red shades (darker as severity increases)
    if (code >= 500) {
        if (code === 500) return '#ef4444';      // Red
        if (code === 502) return '#dc2626';      // Darker red
        if (code === 503) return '#b91c1c';      // Even darker
        return '#991b1b';                        // Darkest red
    }
    
    return '#9ca3af';  // Default gray for unknown
}

function updateCharts(data) {
    // Transform requests_over_time to chart format
    const trafficData = {
        labels: (data.requests_over_time || []).map(r => {
            const date = new Date(r.hour);
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }),
        requests: (data.requests_over_time || []).map(r => parseInt(r.count))
    };
    
    // Transform status_codes to pie chart format with proper colors
    const statusCodes = data.status_codes || [];
    const securityData = {
        labels: statusCodes.map(s => `Status ${s.status_code}`),
        values: statusCodes.map(s => parseInt(s.count)),
        colors: statusCodes.map(s => getStatusColor(s.status_code))
    };
    
    // Traffic Chart
    updateTrafficChart(trafficData);
    
    // Security Chart
    updateSecurityChart(securityData);
}

function updateTrafficChart(data) {
    const ctx = document.getElementById('trafficChart');
    if (!ctx) return;
    
    if (charts.traffic) {
        charts.traffic.destroy();
    }
    
    // Use actual data or show empty chart
    const hasData = data && data.labels && data.labels.length > 0;
    const labels = hasData ? data.labels : ['00:00', '04:00', '08:00', '12:00', '16:00', '20:00'];
    const values = hasData ? data.requests : [0, 0, 0, 0, 0, 0];
    
    charts.traffic = new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Requests',
                data: values,
                borderColor: '#FF6B9D',
                backgroundColor: 'rgba(255, 107, 157, 0.1)',
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { 
                    beginAtZero: true,
                    grid: { color: '#363650' },
                    ticks: { color: '#B4B4C8' }
                },
                x: {
                    grid: { color: '#363650' },
                    ticks: { color: '#B4B4C8' }
                }
            }
        }
    });
}

function updateSecurityChart(data) {
    const ctx = document.getElementById('statusChart');
    if (!ctx) return;
    
    if (charts.security) {
        charts.security.destroy();
    }
    
    // Use actual data or show all zeros
    const hasData = data && data.labels && data.labels.length > 0;
    const labels = hasData ? data.labels : ['No Data'];
    const values = hasData ? data.values : [1];
    const colors = hasData && data.colors ? data.colors : ['#6b7280'];
    
    charts.security = new Chart(ctx.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: values,
                backgroundColor: colors,
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: '#B4B4C8' }
                }
            }
        }
    });
}

async function loadRecentActivity() {
    try {
        const logsResponse = await apiRequest('/logs?limit=5');
        const eventsResponse = await apiRequest('/modsec?limit=5');
        
        const logs = logsResponse?.logs || [];
        const events = eventsResponse?.logs || eventsResponse?.events || [];
        
        const activityList = document.getElementById('recentEvents');
        if (!activityList) return;
        activityList.innerHTML = '';
        
        // Combine and sort by timestamp
        const activities = [
            ...logs.map(log => {
                // Parse request string like "GET / HTTP/1.1"
                const requestParts = (log.request || '').split(' ');
                const method = requestParts[0] || 'GET';
                const path = requestParts[1] || '/';
                const status = log.status || log.status_code || 200;
                const domain = log.domain || log.host || '';
                
                return {
                    type: 'access',
                    message: domain ? `${domain} - ${method} ${path} (${status})` : `${method} ${path} - ${status}`,
                    time: log.timestamp,
                    severity: status >= 500 ? 'critical' : (status >= 400 ? 'warning' : 'info')
                };
            }),
            ...events.map(event => {
                const ruleId = event.rule_id || event.id || '';
                const ruleMsg = event.rule_msg || event.message || event.msg || 'Security rule triggered';
                const severity = event.severity || event.action || 'WARNING';
                
                return {
                    type: 'security',
                    message: ruleId ? `[${ruleId}] ${ruleMsg}` : ruleMsg,
                    time: event.timestamp,
                    severity: severity.toUpperCase() === 'CRITICAL' || severity.toUpperCase() === 'BLOCKED' ? 'critical' : 'warning'
                };
            })
        ].sort((a, b) => new Date(b.time) - new Date(a.time)).slice(0, 5);
        
        if (activities.length === 0) {
            activityList.innerHTML = '<div class="empty-state">No recent activity</div>';
            return;
        }
        
        activities.forEach(activity => {
            const item = document.createElement('div');
            item.className = `activity-item ${activity.severity}`;
            item.innerHTML = `
                <div>${activity.message}</div>
                <small class="text-muted">${formatTime(activity.time)}</small>
            `;
            activityList.appendChild(item);
        });
    } catch (error) {
        console.error('Error loading recent activity:', error);
    }
}

// Sites Management
async function loadSites() {
    try {
        const response = await apiRequest('/sites');
        const sites = response?.sites || [];
        
        const sitesGrid = document.getElementById('sitesGrid');
        if (!sitesGrid) return;
        
        if (sites.length === 0) {
            sitesGrid.innerHTML = '<div class="empty-state">No sites configured yet. Click "Add Site" to get started!</div>';
            return;
        }
        
        sitesGrid.innerHTML = '';
        
        sites.forEach(site => {
            const card = createSiteCard(site);
            sitesGrid.appendChild(card);
        });
    } catch (error) {
        console.error('Error loading sites:', error);
        Toast.show('❌ Failed to load sites', 'error');
    }
}

function createSiteCard(site) {
    const div = document.createElement('div');
    div.className = 'site-card';
    div.innerHTML = `
        <div class="site-header">
            <div class="site-domain">${site.domain}</div>
            <span class="site-status ${site.enabled ? 'active' : 'inactive'}">
                ${site.enabled ? 'Active' : 'Inactive'}
            </span>
        </div>
        <div class="site-info">
            <div>Backend: ${site.backend_url || site.backend || 'N/A'}</div>
            <div>SSL: ${site.ssl_enabled ? 'Enabled' : 'Disabled'}</div>
            <div>Rate Limit: ${site.rate_limit_zone || 'None'}</div>
        </div>
        <div class="site-actions">
            <button class="btn-secondary" onclick="editSite(${site.id})">✏️ Edit</button>
            <button class="btn-secondary" onclick="copySite(${site.id})">📋 Copy</button>
            <button class="btn-secondary" onclick="deleteSite(${site.id})">🗑️ Delete</button>
        </div>
    `;
    return div;
}

// Bans Management
async function loadBans() {
    try {
        const response = await apiRequest('/bans');
        const bans = response?.bans || [];
        
        const container = document.getElementById('bansTable');
        if (!container) return;
        
        // Create table if it doesn't exist
        if (!container.querySelector('table')) {
            container.innerHTML = `
                <table id="bans-table">
                    <thead>
                        <tr>
                            <th>IP Address</th>
                            <th>Reason</th>
                            <th>Banned At</th>
                            <th>Expires</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            `;
        }
        
        const tbody = document.querySelector('#bans-table tbody');
        if (!tbody) return;
        tbody.innerHTML = '';
        
        if (bans.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 2rem; color: var(--text-muted);">No banned IPs yet</td></tr>';
            return;
        }
        
        bans.forEach(ban => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${ban.ip_address}</td>
                <td>${ban.reason || 'Manual ban'}</td>
                <td>${formatDate(ban.banned_at)}</td>
                <td>${ban.expires_at ? formatDate(ban.expires_at) : 'Permanent'}</td>
                <td>
                    <button class="btn-secondary" onclick="unbanIP('${ban.ip_address}')">Unban</button>
                </td>
            `;
            tbody.appendChild(row);
        });
    } catch (error) {
        console.error('Error loading bans:', error);
        Toast.show('❌ Failed to load bans', 'error');
    }
}

async function banIP(ip, reason, duration) {
    try {
        await apiRequest('/bans', {
            method: 'POST',
            body: JSON.stringify({ ip, reason, duration })
        });
        
        closeModal('banModal');
        await loadBans();
    } catch (error) {
        console.error('Error banning IP:', error);
        Toast.show('Failed to ban IP address', 'error');
    }
}

async function unbanIP(ip) {
    if (!confirm(`Are you sure you want to unban ${ip}?`)) return;
    
    try {
        await apiRequest(`/bans/${ip}`, { method: 'DELETE' });
        await loadBans();
    } catch (error) {
        console.error('Error unbanning IP:', error);
        Toast.show('Failed to unban IP address', 'error');
    }
}

// Security Events
async function loadSecurityEvents() {
    try {
        const response = await apiRequest('/modsec?limit=50');
        // API returns array directly, not wrapped
        const events = Array.isArray(response) ? response : (response?.logs || response?.events || []);
        
        const eventsList = document.getElementById('securityEvents');
        if (!eventsList) return;
        
        if (events.length === 0) {
            eventsList.innerHTML = '<div class="empty-state">No security events recorded yet</div>';
            return;
        }
        
        eventsList.innerHTML = '';
        
        events.forEach(event => {
            // Parse rule IDs if they're comma-separated
            const ruleIds = event.rule_id || 'N/A';
            const firstRuleId = ruleIds.toString().split(',')[0];
            
            // Parse rule message - it might be concatenated with |
            const ruleMsg = event.rule_message || event.rule_msg || 'Security rule triggered';
            const shortMsg = ruleMsg.split('|')[0].trim();
            
            // Determine severity color
            const severityClass = event.action === 'blocked' ? 'critical' : 'warning';
            
            const item = document.createElement('div');
            item.className = `event-item ${severityClass}`;
            item.innerHTML = `
                <div><strong>[${firstRuleId}] ${shortMsg}</strong></div>
                <div>
                    <span>IP: ${event.client_ip || 'unknown'}</span> | 
                    <span>Domain: ${event.domain || 'N/A'}</span> | 
                    <span>Method: ${event.method || 'GET'}</span> |
                    <span>Action: ${event.action || 'N/A'}</span>
                </div>
                <small>${formatDateTime(event.timestamp)}</small>
            `;
            eventsList.appendChild(item);
        });
    } catch (error) {
        console.error('Error loading security events:', error);
        Toast.show('❌ Failed to load security events', 'error');
    }
}

// Load Security Summary
async function loadSecuritySummary() {
    try {
        // Get ModSecurity blocks
        const modsecResponse = await apiRequest('/modsec/stats?period=24h');
        const modsecBlocks = modsecResponse?.total_blocks || 0;
        
        // Get Bot blocks
        const botResponse = await apiRequest('/bots/stats?period=24h');
        const botBlocks = botResponse?.total_blocked || 0;
        
        // Get active bans
        const bansResponse = await apiRequest('/bans');
        const activeBans = bansResponse?.bans?.length || 0;
        
        // Get auto-bans today
        const autoBansResponse = await apiRequest('/bans/auto?period=today');
        const autoBans = autoBansResponse?.count || 0;
        
        // Update UI
        document.getElementById('summary-modsec-blocks').textContent = modsecBlocks;
        document.getElementById('summary-bot-blocks').textContent = botBlocks;
        document.getElementById('summary-active-bans').textContent = activeBans;
        document.getElementById('summary-auto-bans').textContent = autoBans;
        
        // Load auto-ban settings
        const settingsResponse = await apiRequest('/settings');
        const settings = settingsResponse?.settings || {};
        document.getElementById('enableAutoBan').checked = settings.enable_auto_ban === '1' || settings.enable_auto_ban === true;
        document.getElementById('excludeCloudflare').checked = settings.exclude_cloudflare_ips === '1' || settings.exclude_cloudflare_ips === true;
    } catch (error) {
        console.error('Error loading security summary:', error);
        // Set defaults on error
        document.getElementById('summary-modsec-blocks').textContent = '0';
        document.getElementById('summary-bot-blocks').textContent = '0';
        document.getElementById('summary-active-bans').textContent = '0';
        document.getElementById('summary-auto-bans').textContent = '0';
    }
}

// Update Auto-Ban Settings
async function updateAutoBanSettings() {
    try {
        const enableAutoBan = document.getElementById('enableAutoBan').checked;
        const excludeCloudflare = document.getElementById('excludeCloudflare').checked;
        
        await apiRequest('/settings', {
            method: 'POST',
            body: JSON.stringify({
                enable_auto_ban: enableAutoBan ? '1' : '0',
                exclude_cloudflare_ips: excludeCloudflare ? '1' : '0'
            })
        });
        
        Toast.show(`Auto-ban ${enableAutoBan ? 'enabled' : 'disabled'}`, 'success');
    } catch (error) {
        console.error('Error updating auto-ban settings:', error);
        Toast.show('Failed to update auto-ban settings', 'error');
    }
}

// Analytics
async function loadAnalytics() {
    try {
        const response = await apiRequest('/stats');
        const stats = response?.stats || {};
        
        // Top IPs
        const topIPs = stats.top_ips || [];
        const topIPsList = document.getElementById('topIps');
        if (topIPsList) {
            topIPsList.innerHTML = '';
            if (topIPs.length === 0) {
                topIPsList.innerHTML = '<div class="empty-state">No data available</div>';
            } else {
                topIPs.forEach(item => {
                    const div = document.createElement('div');
                    div.className = 'list-item';
                    div.innerHTML = `<span>${item.ip_address}</span><span>${item.count}</span>`;
                    topIPsList.appendChild(div);
                });
            }
        }
        
        // Top Domains
        const topDomains = stats.top_domains || [];
        const topDomainsList = document.getElementById('topDomains');
        if (topDomainsList) {
            topDomainsList.innerHTML = '';
            if (topDomains.length === 0) {
                topDomainsList.innerHTML = '<div class="empty-state">No data available</div>';
            } else {
                topDomains.forEach(item => {
                    const div = document.createElement('div');
                    div.className = 'list-item';
                    div.innerHTML = `<span>${item.domain}</span><span>${item.count}</span>`;
                    topDomainsList.appendChild(div);
                });
            }
        }
        
        // Attack Types (Status codes as proxy)
        const statusCodes = stats.status_codes || [];
        const attackTypesList = document.getElementById('attackTypes');
        if (attackTypesList) {
            attackTypesList.innerHTML = '';
            const attackCodes = statusCodes.filter(s => s.status_code >= 400);
            if (attackCodes.length === 0) {
                attackTypesList.innerHTML = '<div class="empty-state">No attacks detected</div>';
            } else {
                attackCodes.forEach(item => {
                    const div = document.createElement('div');
                    div.className = 'list-item';
                    div.innerHTML = `<span>HTTP ${item.status_code}</span><span>${item.count}</span>`;
                    attackTypesList.appendChild(div);
                });
            }
        }
    } catch (error) {
        console.error('Error loading analytics:', error);
    }
}

// Logs
async function loadLogs() {
    try {
        // Get filter values
        const logType = document.getElementById('logType')?.value || 'access';
        const limit = document.getElementById('logLimit')?.value || 100;
        const siteFilter = document.getElementById('logSiteFilter')?.value || '';
        
        // Build query parameters
        let query = `limit=${limit}`;
        if (siteFilter) {
            query += `&domain=${encodeURIComponent(siteFilter)}`;
        }
        
        const response = await apiRequest(`/logs?${query}`);
        const logs = response?.logs || [];
        
        const logsContainer = document.getElementById('logsContainer');
        if (!logsContainer) return;
        
        if (logs.length === 0) {
            logsContainer.innerHTML = '<div class="empty-state">No access logs available yet</div>';
            return;
        }
        
        logsContainer.innerHTML = '';
        
        logs.forEach(log => {
            let displayText = '';
            
            // Check if log is a raw string (from /logs endpoint) or structured object (from access_logs table)
            if (typeof log === 'object' && !log.raw) {
                // Structured log object
                const requestParts = (log.request || '').split(' ');
                const method = requestParts[0] || 'GET';
                const path = requestParts[1] || '/';
                const domain = log.domain || log.host || 'unknown';
                const status = log.status || log.status_code || '-';
                const ip = log.ip || log.ip_address || 'unknown';
                
                displayText = `[${formatDateTime(log.timestamp)}] [${domain}] ${ip} - ${method} ${path} - ${status}`;
            } else {
                // Raw log string - parse nginx log format
                const rawLog = log.raw || log;
                
                // Parse NGINX log format: domain - ip - [timestamp] "METHOD path HTTP/version" status ...
                const logMatch = rawLog.match(/^(\S+) - ([\d\.\:a-fA-F]+) - \[([^\]]+)\] "(\S+) ([^\s"]+)[^"]*" (\d+)/);
                
                if (logMatch) {
                    const [, domain, ip, timestamp, method, path, status] = logMatch;
                    displayText = `[${timestamp}] [${domain}] ${ip} - ${method} ${path} - ${status}`;
                } else {
                    // Fallback to showing raw log
                    displayText = rawLog;
                }
            }
            
            const entry = document.createElement('div');
            entry.className = 'log-entry';
            entry.textContent = displayText;
            logsContainer.appendChild(entry);
        });
    } catch (error) {
        console.error('Error loading logs:', error);
    }
}

// Populate site filter dropdown
async function populateLogSiteFilter() {
    try {
        const response = await apiRequest('/sites');
        const sites = response?.sites || [];
        const select = document.getElementById('logSiteFilter');
        
        if (!select) return;
        
        // Keep "All Sites" option
        select.innerHTML = '<option value="">All Sites</option>';
        
        // Add each site
        sites.forEach(site => {
            const option = document.createElement('option');
            option.value = site.domain;
            option.textContent = site.domain;
            select.appendChild(option);
        });
    } catch (error) {
        console.error('Error populating site filter:', error);
    }
}

// Settings
async function loadSettings() {
    try {
        const response = await apiRequest('/settings');
        let settingsData = response?.settings || {};
        
        // If settings is an array, convert to object (backwards compatibility)
        let settings = settingsData;
        if (Array.isArray(settingsData)) {
            settings = {};
            settingsData.forEach(s => {
                if (s.setting_key && s.setting_value !== undefined) {
                    settings[s.setting_key] = s.setting_value;
                }
            });
        }
        
        // Populate settings form
        Object.keys(settings).forEach(key => {
            const input = document.getElementById(`setting-${key}`);
            if (input) {
                if (input.type === 'checkbox') {
                    input.checked = settings[key] === '1' || settings[key] === 'true';
                } else {
                    input.value = settings[key];
                }
            }
        });
        
        // Load certificate status
        loadCertificateStatus();
        
        // Load custom block rules
        loadBlockRules();
        
        // Load cleanup stats
        loadCleanupStats();
    } catch (error) {
        console.error('Error loading settings:', error);
    }
}

async function saveSettings() {
    const settings = {
        default_rate_limit: document.getElementById('setting-default_rate_limit').value,
        ban_duration: document.getElementById('setting-ban_duration').value,
        max_request_size: document.getElementById('setting-max_request_size').value,
        api_token: document.getElementById('setting-api_token').value
    };
    
    try {
        await apiRequest('/settings', {
            method: 'POST',
            body: JSON.stringify(settings)
        });
        
        if (settings.api_token) {
            localStorage.setItem('api_token', settings.api_token);
        }
        
        Toast.show('Settings saved successfully!', 'success');
    } catch (error) {
        console.error('Error saving settings:', error);
        Toast.show('Failed to save settings', 'error');
    }
}

window.saveWafSettings = async () => {
    const settings = {
        default_rate_limit: document.getElementById('setting-default_rate_limit')?.value,
        ban_duration: document.getElementById('setting-ban_duration')?.value,
        paranoia_level: document.getElementById('setting-paranoia_level')?.value
    };
    
    try {
        // Save each setting
        for (const [key, value] of Object.entries(settings)) {
            if (value) {
                await apiRequest(`/settings/${key}`, {
                    method: 'PUT',
                    body: JSON.stringify({ value })
                });
            }
        }
        
        Toast.show('✅ WAF settings saved successfully!', 'success');
    } catch (error) {
        console.error('Error saving WAF settings:', error);
        Toast.show('❌ Failed to save WAF settings', 'error');
    }
};

window.saveEmailSettings = async () => {
    const settings = {
        email_enabled: document.getElementById('setting-email_enabled')?.checked ? 1 : 0,
        smtp_server: document.getElementById('setting-smtp_server')?.value.trim(),
        smtp_port: document.getElementById('setting-smtp_port')?.value,
        smtp_user: document.getElementById('setting-smtp_user')?.value.trim(),
        smtp_pass: document.getElementById('setting-smtp_pass')?.value,
        alert_recipients: document.getElementById('setting-alert_recipients')?.value.trim(),
        alert_threshold: document.getElementById('setting-alert_threshold')?.value
    };
    
    if (settings.email_enabled && (!settings.smtp_server || !settings.smtp_user)) {
        Toast.show('Please fill in SMTP server and username', 'warning');
        return;
    }
    
    try {
        // Save each setting
        for (const [key, value] of Object.entries(settings)) {
            if (value !== undefined && value !== null) {
                await apiRequest(`/settings/${key}`, {
                    method: 'PUT',
                    body: JSON.stringify({ value: value.toString() })
                });
            }
        }
        
        Toast.show('✅ Email settings saved successfully!', 'success');
    } catch (error) {
        console.error('Error saving email settings:', error);
        Toast.show('❌ Failed to save email settings', 'error');
    }
};

window.saveAcmeSettings = async () => {
    const settings = {
        acme_email: document.getElementById('setting-acme_email')?.value.trim(),
        acme_auto_renew: document.getElementById('setting-acme_auto_renew')?.checked ? 1 : 0,
        acme_server: document.getElementById('setting-acme_server')?.value.trim()
    };
    
    if (!settings.acme_email) {
        showToast('Please enter an email address for ACME', 'error');
        return;
    }
    
    // Validate email format
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(settings.acme_email)) {
        showToast('Please enter a valid email address', 'error');
        return;
    }
    
    try {
        // Save each setting
        for (const [key, value] of Object.entries(settings)) {
            if (value !== undefined && value !== null) {
                await apiRequest(`/settings/${key}`, {
                    method: 'PUT',
                    body: JSON.stringify({ value: value.toString() })
                });
            }
        }
        
        showToast('Certificate settings saved successfully!', 'success');
        loadCertificateStatus(); // Refresh certificate display
    } catch (error) {
        console.error('Error saving ACME settings:', error);
        showToast('Failed to save certificate settings', 'error');
    }
};

async function loadCertificateStatus() {
    const container = document.getElementById('certStatusContainer');
    if (!container) return;
    
    try {
        const sites = await apiRequest('/sites');
        const sslSites = sites.sites.filter(site => site.ssl_enabled);
        
        if (sslSites.length === 0) {
            container.innerHTML = '<p style="color: #666;">No SSL-enabled sites configured.</p>';
            return;
        }
        
        let html = '<div class="cert-list">';
        for (const site of sslSites) {
            // Skip special domains that don't need certificates
            if (site.domain === '_' || site.domain.includes('*')) {
                continue;
            }
            
            const certInfo = await getCertificateInfo(site.domain);
            
            // Handle cases where certificate info couldn't be retrieved
            if (!certInfo || !certInfo.exists) {
                html += `
                    <div class="cert-item">
                        <div class="cert-domain">
                            <strong>${site.domain}</strong>
                            <span class="badge badge-danger">No Certificate</span>
                        </div>
                        <div class="cert-details">
                            <span style="color: #ff6b6b;">Certificate not found</span>
                        </div>
                        <div class="cert-actions">
                            <button class="btn-primary btn-sm" onclick="issueCertificate('${site.domain}')">Issue Certificate</button>
                        </div>
                    </div>
                `;
                continue;
            }
            
            const daysUntilExpiry = certInfo.daysUntilExpiry || 0;
            let statusClass = 'success';
            let statusText = 'Valid';
            
            if (daysUntilExpiry < 0) {
                statusClass = 'danger';
                statusText = 'Expired';
            } else if (daysUntilExpiry < 7) {
                statusClass = 'critical';
                statusText = 'Expires Soon';
            } else if (daysUntilExpiry < 30) {
                statusClass = 'warning';
                statusText = 'Renewing Soon';
            }
            
            html += `
                <div class="cert-item">
                    <div class="cert-domain">
                        <strong>${site.domain}</strong>
                        <span class="badge badge-${statusClass}">${statusText}</span>
                    </div>
                    <div class="cert-details">
                        <span>Expires: ${certInfo.expiryDate}</span>
                        <span>(${daysUntilExpiry} days)</span>
                        ${certInfo.issuer ? `<span style="font-size: 0.85em; color: #666;">Issuer: ${certInfo.issuer}</span>` : ''}
                    </div>
                    <div class="cert-actions">
                        <button class="btn-secondary btn-sm" onclick="renewCertificate('${site.domain}')">Renew Now</button>
                        <button class="btn-secondary btn-sm" onclick="revokeCertificate('${site.domain}')">Revoke</button>
                    </div>
                </div>
            `;
        }
        html += '</div>';
        container.innerHTML = html;
    } catch (error) {
        console.error('Error loading certificate status:', error);
        container.innerHTML = '<p style="color: #ff6b6b;">Failed to load certificate information.</p>';
    }
}

async function getCertificateInfo(domain) {
    try {
        const response = await apiRequest(`/certificates/${domain}`);
        return response;
    } catch (error) {
        // Certificate doesn't exist or error occurred
        return {
            exists: false,
            daysUntilExpiry: 0,
            expiryDate: 'N/A',
            issuer: null
        };
    }
}

window.issueCertificate = async (domain) => {
    if (!confirm(`Issue a new SSL certificate for ${domain}?\n\nThis will use Let's Encrypt and requires the domain to point to this server.`)) {
        return;
    }
    
    showToast(`Issuing certificate for ${domain}...`, 'info');
    
    try {
        await apiRequest(`/certificates/${domain}`, {
            method: 'POST'
        });
        showToast(`Certificate issued successfully for ${domain}!`, 'success');
        loadCertificateStatus();
    } catch (error) {
        console.error('Error issuing certificate:', error);
        showToast(`Failed to issue certificate: ${error.message}`, 'error');
    }
};

window.renewCertificate = async (domain) => {
    if (!confirm(`Renew SSL certificate for ${domain}?`)) {
        return;
    }
    
    showToast(`Renewing certificate for ${domain}...`, 'info');
    
    try {
        await apiRequest(`/certificates/${domain}/renew`, {
            method: 'POST'
        });
        showToast(`Certificate renewed successfully for ${domain}!`, 'success');
        loadCertificateStatus();
    } catch (error) {
        console.error('Error renewing certificate:', error);
        showToast(`Failed to renew certificate: ${error.message}`, 'error');
    }
};

window.renewAllCertificates = async () => {
    if (!confirm('Process ALL SSL certificates?\n\nThis will renew existing certificates and issue new ones for domains that don\'t have them yet.\n\nThis may take several minutes.\n\nContinue?')) {
        return;
    }
    
    showToast('Processing certificates for all sites...', 'info');
    
    try {
        const response = await apiRequest('/certificates/renew-all', {
            method: 'POST'
        });
        
        if (response.success) {
            // Count issued vs renewed
            const issued = response.results.filter(r => r.status === 'success' && r.action === 'issued').length;
            const renewed = response.results.filter(r => r.status === 'success' && r.action === 'renewed').length;
            
            let message = `Processed ${response.succeeded} certificates`;
            if (issued > 0 && renewed > 0) {
                message += ` (${issued} issued, ${renewed} renewed)`;
            } else if (issued > 0) {
                message += ` (${issued} issued)`;
            } else if (renewed > 0) {
                message += ` (${renewed} renewed)`;
            }
            if (response.failed > 0) {
                message += `, ${response.failed} failed`;
            }
            
            showToast(message, response.failed > 0 ? 'warning' : 'success');
            
            // Show detailed results
            if (response.results && response.results.length > 0) {
                console.log('Certificate processing results:', response.results);
                const failedSites = response.results.filter(r => r.status === 'failed');
                if (failedSites.length > 0) {
                    console.error('Failed operations:', failedSites);
                    setTimeout(() => {
                        alert(`Failed to process:\n${failedSites.map(s => `- ${s.domain}: ${s.error || s.reason}`).join('\n')}`);
                    }, 1000);
                }
            }
            
            loadCertificateStatus();
        }
    } catch (error) {
        console.error('Error processing certificates:', error);
        showToast(`Failed to process certificates: ${error.message}`, 'error');
    }
};

window.revokeCertificate = async (domain) => {
    if (!confirm(`⚠️ DANGER: Revoke SSL certificate for ${domain}?\n\nThis action cannot be undone and will immediately disable HTTPS for this domain.`)) {
        return;
    }
    
    showToast(`Revoking certificate for ${domain}...`, 'info');
    
    try {
        await apiRequest(`/certificates/${domain}`, {
            method: 'DELETE'
        });
        showToast(`Certificate revoked for ${domain}`, 'success');
        loadCertificateStatus();
    } catch (error) {
        console.error('Error revoking certificate:', error);
        showToast(`Failed to revoke certificate: ${error.message}`, 'error');
    }
};

window.saveApiToken = () => {
    const token = document.getElementById('apiToken').value.trim();
    if (token) {
        localStorage.setItem('api_token', token);
        Toast.show('✅ API token saved!', 'success');
    } else {
        Toast.show('Please enter a token', 'warning');
    }
};

// Modal Management
function setupModals() {
    // Close modal on background click
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                closeModal(modal.id);
            }
        });
    });
    
    // Setup add site form
    const addSiteForm = document.getElementById('add-site-form');
    if (addSiteForm) {
        addSiteForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const siteData = Object.fromEntries(formData.entries());
            siteData.enabled = formData.has('enabled');
            siteData.ssl_enabled = formData.has('ssl_enabled');
        
        try {
            await apiRequest('/sites', {
                method: 'POST',
                body: JSON.stringify(siteData)
            });
            
            closeModal('addSiteModal');
            e.target.reset();
            await loadSites();
        } catch (error) {
            console.error('Error adding site:', error);
            Toast.show('Failed to add site', 'error');
            }
        });
    }
    
    // Setup ban IP form
    const banIPForm = document.getElementById('ban-ip-form');
    if (banIPForm) {
        banIPForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const banData = Object.fromEntries(formData.entries());
            
            await banIP(banData.ip_address, banData.reason, banData.duration);
            e.target.reset();
        });
    }
}

function setupLogout() {
    // Create logout button if it doesn't exist
    const header = document.querySelector('.header');
    if (!header) return;
    
    const logoutBtn = document.createElement('button');
    logoutBtn.className = 'btn-logout';
    logoutBtn.innerHTML = '🚪 Logout';
    logoutBtn.style.cssText = `
        background: rgba(255, 107, 157, 0.2);
        border: 1px solid var(--primary);
        color: var(--primary);
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        cursor: pointer;
        font-size: 0.875rem;
        font-weight: 500;
        transition: all 0.2s;
        margin-left: auto;
    `;
    
    logoutBtn.addEventListener('mouseenter', () => {
        logoutBtn.style.background = 'var(--primary)';
        logoutBtn.style.color = 'white';
    });
    
    logoutBtn.addEventListener('mouseleave', () => {
        logoutBtn.style.background = 'rgba(255, 107, 157, 0.2)';
        logoutBtn.style.color = 'var(--primary)';
    });
    
    logoutBtn.addEventListener('click', () => {
        if (confirm('Are you sure you want to logout? 🐱')) {
            localStorage.removeItem('api_token');
            window.location.href = '/login.html';
        }
    });
    
    header.style.display = 'flex';
    header.style.alignItems = 'center';
    header.appendChild(logoutBtn);
}

function openModal(modalId) {
    document.getElementById(modalId).classList.add('active');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

// Helper Functions
function formatNumber(num) {
    if (num >= 1000000) {
        return (num / 1000000).toFixed(1) + 'M';
    }
    if (num >= 1000) {
        return (num / 1000).toFixed(1) + 'K';
    }
    return num.toString();
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString();
}

function formatTime(dateString) {
    if (!dateString) return 'Unknown';
    
    let date;
    // Handle nginx log format: "15/Oct/2025:23:32:39 +0000"
    if (dateString.includes('/')) {
        const parts = dateString.match(/(\d+)\/(\w+)\/(\d+):(\d+):(\d+):(\d+)/);
        if (parts) {
            const months = {Jan:0,Feb:1,Mar:2,Apr:3,May:4,Jun:5,Jul:6,Aug:7,Sep:8,Oct:9,Nov:10,Dec:11};
            date = new Date(parts[3], months[parts[2]], parts[1], parts[4], parts[5], parts[6]);
        } else {
            date = new Date(dateString);
        }
    } else {
        date = new Date(dateString);
    }
    
    if (isNaN(date.getTime())) return 'Invalid date';
    
    const now = new Date();
    const diff = Math.floor((now - date) / 1000);
    
    if (diff < 0) return 'just now';
    if (diff < 60) return `${diff}s ago`;
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return `${Math.floor(diff / 86400)}d ago`;
}

function formatDateTime(dateString) {
    if (!dateString) return 'Unknown';
    
    // Handle nginx log format: "15/Oct/2025:23:32:39 +0000"
    if (dateString.includes('/')) {
        const parts = dateString.match(/(\d+)\/(\w+)\/(\d+):(\d+):(\d+):(\d+)/);
        if (parts) {
            const months = {Jan:0,Feb:1,Mar:2,Apr:3,May:4,Jun:5,Jul:6,Aug:7,Sep:8,Oct:9,Nov:10,Dec:11};
            const date = new Date(parts[3], months[parts[2]], parts[1], parts[4], parts[5], parts[6]);
            return date.toLocaleString();
        }
    }
    
    // Fallback to standard date parsing
    const date = new Date(dateString);
    return isNaN(date.getTime()) ? 'Invalid Date' : date.toLocaleString();
}

// Global Functions for onclick handlers
window.openModal = openModal;
window.closeModal = closeModal;

// Open rate limit presets page with authentication
function openRateLimitPresets() {
    const token = API_TOKEN || localStorage.getItem('apiToken');
    if (token) {
        window.open(`rate-limit-presets.html?token=${encodeURIComponent(token)}`, '_blank');
    } else {
        showToast('Please authenticate first', 'error');
    }
}
window.openRateLimitPresets = openRateLimitPresets;

// Reset site modal to default state
function resetSiteModal() {
    const modalTitle = document.querySelector('#addSiteModal .modal-header h3');
    const modalButton = document.querySelector('#addSiteModal .btn-primary');
    if (modalTitle) modalTitle.textContent = 'Add New Site';
    if (modalButton) {
        modalButton.textContent = 'Add Site';
        modalButton.onclick = window.addSite;
    }
    
    // Reset all form fields
    const fields = {
        siteDomain: '',
        siteBackend: '',
        siteRateLimit: 'general',
        siteCustomRateLimit: '',
        siteBlockedCountries: '',
        siteAllowedCountries: '',
        siteAuthUser: '',
        siteAuthPass: '',
        siteIPWhitelist: ''
    };
    
    Object.keys(fields).forEach(id => {
        const elem = document.getElementById(id);
        if (elem) elem.value = fields[id];
    });
    
    // Reset checkboxes
    const sslCheck = document.getElementById('siteSSL');
    const modSecCheck = document.getElementById('siteModSec');
    const geoipCheck = document.getElementById('siteGeoIP');
    const basicAuthCheck = document.getElementById('siteBasicAuth');
    
    if (sslCheck) sslCheck.checked = true;
    if (modSecCheck) modSecCheck.checked = true;
    if (geoipCheck) geoipCheck.checked = false;
    if (basicAuthCheck) basicAuthCheck.checked = false;
    
    // Hide conditional sections
    const customRateLimitGroup = document.getElementById('customRateLimitGroup');
    const geoipConfigGroup = document.getElementById('geoipConfigGroup');
    const basicAuthGroup = document.getElementById('basicAuthGroup');
    
    if (customRateLimitGroup) customRateLimitGroup.style.display = 'none';
    if (geoipConfigGroup) geoipConfigGroup.style.display = 'none';
    if (basicAuthGroup) basicAuthGroup.style.display = 'none';
}

window.showAddSiteModal = () => {
    // Navigate to add site page
    currentSiteData = {}; // Empty data for new site
    navigateToPage('add-site');
    
    // Initialize tabs
    initializeAddSiteTabs();
    
    // Load general tab by default
    loadAddSiteTab('general');
};
window.showBanModal = () => openModal('banModal');

// Toggle functions for site modal
window.toggleCustomRateLimit = () => {
    const select = document.getElementById('siteRateLimit');
    const customGroup = document.getElementById('customRateLimitGroup');
    if (customGroup) {
        customGroup.style.display = select.value === 'custom' ? 'block' : 'none';
    }
};

window.toggleGeoIPConfig = () => {
    const checkbox = document.getElementById('siteGeoIP');
    const configGroup = document.getElementById('geoipConfigGroup');
    if (configGroup) {
        configGroup.style.display = checkbox.checked ? 'block' : 'none';
    }
};

window.toggleBasicAuth = () => {
    const checkbox = document.getElementById('siteBasicAuth');
    const authGroup = document.getElementById('basicAuthGroup');
    if (authGroup) {
        authGroup.style.display = checkbox.checked ? 'block' : 'none';
    }
};

window.toggleImageOpt = () => {
    const checkbox = document.getElementById('siteImageOpt');
    const imageOptGroup = document.getElementById('imageOptGroup');
    if (imageOptGroup) {
        imageOptGroup.style.display = checkbox.checked ? 'block' : 'none';
    }
};

window.addSite = async () => {
    const domain = document.getElementById('siteDomain').value.trim();
    const backend = document.getElementById('siteBackend').value.trim();
    const rateLimit = document.getElementById('siteRateLimit').value;
    const customRateLimit = document.getElementById('siteCustomRateLimit')?.value;
    const sslEnabled = document.getElementById('siteSSL')?.checked;
    const modSecEnabled = document.getElementById('siteModSec').checked;
    const geoipEnabled = document.getElementById('siteGeoIP').checked;
    const blockedCountries = document.getElementById('siteBlockedCountries')?.value.trim();
    const allowedCountries = document.getElementById('siteAllowedCountries')?.value.trim();
    const basicAuthEnabled = document.getElementById('siteBasicAuth')?.checked;
    const authUser = document.getElementById('siteAuthUser')?.value.trim();
    const authPass = document.getElementById('siteAuthPass')?.value.trim();
    const ipWhitelist = document.getElementById('siteIPWhitelist')?.value.trim();
    
    // Performance & Compression
    const gzipEnabled = document.getElementById('siteGzip')?.checked;
    const brotliEnabled = document.getElementById('siteBrotli')?.checked;
    const compressionLevel = document.getElementById('siteCompressionLevel')?.value || 6;
    
    // Image Optimization
    const imageOptEnabled = document.getElementById('siteImageOpt')?.checked;
    const imageQuality = document.getElementById('siteImageQuality')?.value || 85;
    
    // Headers & Telemetry
    const wafHeaders = document.getElementById('siteWAFHeaders')?.checked;
    const telemetry = document.getElementById('siteTelemetry')?.checked;
    const customHeaders = document.getElementById('siteCustomHeaders')?.value.trim();
    
    if (!domain || !backend) {
        Toast.show('Please fill in all required fields', 'warning');
        return;
    }
    
    // Build custom config object
    const customConfig = {};
    if (basicAuthEnabled && authUser && authPass) {
        customConfig.basic_auth = { username: authUser, password: authPass };
    }
    if (ipWhitelist) {
        customConfig.ip_whitelist = ipWhitelist;
    }
    if (rateLimit === 'custom' && customRateLimit) {
        customConfig.custom_rate_limit = parseInt(customRateLimit);
    }
    
    try {
        await apiRequest('/sites', {
            method: 'POST',
            body: JSON.stringify({
                domain: domain,
                backend_url: backend,
                rate_limit_zone: rateLimit === 'custom' ? 'custom' : rateLimit,
                ssl_enabled: sslEnabled ? 1 : 0,
                enable_modsecurity: modSecEnabled ? 1 : 0,
                enable_geoip_blocking: geoipEnabled ? 1 : 0,
                blocked_countries: blockedCountries || null,
                allowed_countries: allowedCountries || null,
                custom_config: Object.keys(customConfig).length > 0 ? JSON.stringify(customConfig) : null,
                enabled: 1,
                // Compression & optimization
                enable_gzip: gzipEnabled ? 1 : 0,
                enable_brotli: brotliEnabled ? 1 : 0,
                compression_level: parseInt(compressionLevel),
                enable_image_optimization: imageOptEnabled ? 1 : 0,
                image_quality: parseInt(imageQuality),
                enable_waf_headers: wafHeaders ? 1 : 0,
                enable_telemetry: telemetry ? 1 : 0,
                custom_headers: customHeaders || null
            })
        });
        
        closeModal('addSiteModal');
        
        // Reset modal for next use
        resetSiteModal();
        
        Toast.show('✅ Site added successfully!', 'success');
        await loadSites();
    } catch (error) {
        console.error('Error adding site:', error);
        Toast.show('❌ Failed to add site: ' + error.message, 'error');
    }
};
window.banIp = async () => {
    const ip = document.getElementById('banIp').value.trim();
    const reason = document.getElementById('banReason').value.trim();
    const duration = parseInt(document.getElementById('banDuration').value);
    const permanent = document.getElementById('banPermanent').checked;
    
    if (!ip) {
        Toast.show('Please enter an IP address', 'warning');
        return;
    }
    
    try {
        await apiRequest('/bans', {
            method: 'POST',
            body: JSON.stringify({
                ip_address: ip,
                reason: reason || 'Manual ban',
                duration: permanent ? null : duration,
                permanent: permanent ? 1 : 0
            })
        });
        
        closeModal('banModal');
        // Clear form
        document.getElementById('banIp').value = '';
        document.getElementById('banReason').value = '';
        document.getElementById('banDuration').value = '3600';
        document.getElementById('banPermanent').checked = false;
        
        Toast.show('✅ IP banned successfully!', 'success');
        await loadBans();
    } catch (error) {
        console.error('Error banning IP:', error);
        Toast.show('❌ Failed to ban IP: ' + error.message, 'error');
    }
};
window.showPage = navigateToPage;

// Edit Site and Copy Site functions moved to line 1914+
// (Using integrated editor instead of separate page)

// Copy Site - Open add site page with pre-filled data
window.copySite = async (id) => {
    try {
        const response = await apiRequest(`/sites/${id}`, {
            method: 'COPY'
        });
        
        if (response && response.success) {
            Toast.show(`✅ Site copied as: ${response.domain}`, 'success');
            await loadSites();
            
            // Open editor for the new copy
            if (response.id) {
                editSite(response.id);
            }
        }
    } catch (error) {
        console.error('Error copying site:', error);
        Toast.show('❌ Failed to copy site', 'error');
    }
};

// Update existing site
async function updateSite(id) {
    const domain = document.getElementById('siteDomain').value.trim();
    const backend = document.getElementById('siteBackend').value.trim();
    const rateLimit = document.getElementById('siteRateLimit').value;
    const modSecEnabled = document.getElementById('siteModSec').checked;
    const geoipEnabled = document.getElementById('siteGeoIP').checked;
    
    if (!domain || !backend) {
        Toast.show('Please fill in all required fields', 'warning');
        return;
    }
    
    try {
        await apiRequest(`/sites/${id}`, {
            method: 'PUT',
            body: JSON.stringify({
                domain: domain,
                backend_url: backend,
                rate_limit_zone: rateLimit,
                enable_modsecurity: modSecEnabled ? 1 : 0,
                enable_geoip_blocking: geoipEnabled ? 1 : 0
            })
        });
        
        closeModal('addSiteModal');
        
        // Reset modal for next use
        resetSiteModal();
        
        await loadSites();
        Toast.show('✅ Site updated successfully!', 'success');
    } catch (error) {
        console.error('Error updating site:', error);
        Toast.show('❌ Failed to update site: ' + error.message, 'error');
    }
}

window.deleteSite = async (id) => {
    if (!confirm('Are you sure you want to delete this site?')) return;
    
    try {
        await apiRequest(`/sites/${id}`, { method: 'DELETE' });
        await loadSites();
    } catch (error) {
        console.error('Error deleting site:', error);
        Toast.show('Failed to delete site', 'error');
    }
};

// ============================================
// ModSecurity Page Functions
// ============================================
async function loadModSecurityData() {
    try {
        // Load ModSecurity stats and events
        const stats = await apiRequest('/stats/modsecurity');
        
        // Update stats cards
        if (stats) {
            document.getElementById('modsecRulesCount').textContent = stats.rules_loaded !== undefined ? stats.rules_loaded : 'Loading...';
            document.getElementById('modsecBlocksToday').textContent = stats.blocks_today || '0';
            document.getElementById('modsecWarningsToday').textContent = stats.warnings_today || '0';
            document.getElementById('modsecParanoia').textContent = stats.paranoia_level || '1';
            
            // Update ModSecurity status badge
            const statusEl = document.getElementById('modsecStatus');
            if (stats.rules_loaded && stats.rules_loaded > 0) {
                statusEl.innerHTML = `<span class="status-badge active">✓ Active (${stats.rules_loaded} rules)</span>`;
            } else {
                statusEl.innerHTML = '<span class="status-badge inactive">✗ Inactive</span>';
            }
        }
        
        // Load top triggered rules
        await loadTopModSecRules();
        
        // Load recent events
        await loadModSecEvents();
    } catch (error) {
        console.error('Error loading ModSecurity data:', error);
    }
}

async function loadTopModSecRules() {
    try {
        const response = await apiRequest('/modsec/top-rules');
        const tbody = document.getElementById('topModsecRulesBody');
        
        if (!response || response.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align: center;">No rules triggered yet</td></tr>';
            return;
        }
        
        tbody.innerHTML = response.map(rule => `
            <tr>
                <td><code>${rule.rule_id}</code></td>
                <td>${rule.rule_message || 'N/A'}</td>
                <td>
                    <span class="badge badge-${(rule.severity || 'notice').toLowerCase()}">
                        ${rule.severity || 'NOTICE'}
                    </span>
                </td>
                <td><strong>${rule.trigger_count}</strong></td>
            </tr>
        `).join('');
    } catch (error) {
        console.error('Error loading top ModSec rules:', error);
        document.getElementById('topModsecRulesBody').innerHTML = 
            '<tr><td colspan="4" style="text-align: center; color: #ef4444;">Error loading data</td></tr>';
    }
}

async function loadModSecEvents() {
    try {
        const severity = document.getElementById('modsecSeverityFilter')?.value || '';
        // Disable GeoIP for performance (each lookup takes 1+ second)
        const response = await apiRequest(`/modsec/events?severity=${severity}&limit=50&geoip=false`);
        const tbody = document.getElementById('modsecEventsBody');
        
        if (!response || response.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">No security events</td></tr>';
            return;
        }
        
        tbody.innerHTML = response.map(event => `
            <tr>
                <td>${new Date(event.timestamp).toLocaleString()}</td>
                <td>
                    ${event.flag || '�'} 
                    <span style="font-size: 0.875rem;">${event.country || 'N/A'}</span>
                    ${event.city ? `<br><small style="color: #94a3b8;">${event.city}</small>` : ''}
                </td>
                <td><code>${event.client_ip || event.ip_address || 'N/A'}</code></td>
                <td><code>${event.rule_id || 'N/A'}</code></td>
                <td>
                    <span class="badge badge-${(event.severity || 'notice').toLowerCase()}">
                        ${event.severity || 'NOTICE'}
                    </span>
                </td>
                <td style="max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    ${event.rule_message || event.message || 'N/A'}
                </td>
                <td>
                    <span class="badge badge-${(event.action || '').toLowerCase() === 'blocked' ? 'danger' : 'warning'}">
                        ${event.action || 'LOGGED'}
                    </span>
                </td>
            </tr>
        `).join('');
    } catch (error) {
        console.error('Error loading ModSec events:', error);
        document.getElementById('modsecEventsBody').innerHTML = 
            '<tr><td colspan="7" style="text-align: center; color: #ef4444;">Error loading events</td></tr>';
    }
}

// ============================================
// Bot Protection Page Functions
// ============================================
let botActivityChartInstance = null;

async function loadBotProtectionData() {
    try {
        // Load bot stats
        const stats = await apiRequest('/stats/bots');
        
        if (stats) {
            document.getElementById('botsDetected').textContent = stats.total_detected || '0';
            document.getElementById('goodBots').textContent = stats.good_bots || '0';
            document.getElementById('badBots').textContent = stats.bad_bots || '0';
            document.getElementById('botDetectionRate').textContent = 
                stats.detection_rate ? `${stats.detection_rate}%` : '0%';
        }
        
        // Load bot detections
        await loadBotDetections();
        
        // Load bot activity chart
        await loadBotActivityChart();
    } catch (error) {
        console.error('Error loading bot protection data:', error);
    }
}

async function loadBotActivityChart() {
    try {
        const response = await apiRequest('/bots?limit=1000');
        const bots = response?.bots || [];
        
        if (bots.length === 0) {
            console.log('No bot detections found');
            return;
        }
        
        // Group by hour for ALL available bot data (not just last 24h)
        const hourlyData = {};
        
        bots.forEach(bot => {
            const timestamp = new Date(bot.timestamp || bot.created_at);
            const hourKey = timestamp.toISOString().substring(0, 13); // YYYY-MM-DDTHH
            
            if (!hourlyData[hourKey]) {
                hourlyData[hourKey] = { total: 0, blocked: 0, allowed: 0 };
            }
            
            hourlyData[hourKey].total++;
            if (bot.action === 'blocked' || bot.blocked) {
                hourlyData[hourKey].blocked++;
            } else {
                hourlyData[hourKey].allowed++;
            }
        });
        
        // Prepare chart data
        const labels = Object.keys(hourlyData).sort().map(key => {
            const date = new Date(key + ':00:00');
            return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        });
        
        const totalData = Object.keys(hourlyData).sort().map(key => hourlyData[key].total);
        const blockedData = Object.keys(hourlyData).sort().map(key => hourlyData[key].blocked);
        const allowedData = Object.keys(hourlyData).sort().map(key => hourlyData[key].allowed);
        
        // Destroy existing chart if it exists
        if (botActivityChartInstance) {
            botActivityChartInstance.destroy();
        }
        
        // Create new chart
        const ctx = document.getElementById('botActivityChart');
        if (!ctx) return;
        
        botActivityChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Blocked',
                        data: blockedData,
                        borderColor: '#ff6b6b',
                        backgroundColor: 'rgba(255, 107, 107, 0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Allowed',
                        data: allowedData,
                        borderColor: '#51cf66',
                        backgroundColor: 'rgba(81, 207, 102, 0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Total',
                        data: totalData,
                        borderColor: '#4dabf7',
                        backgroundColor: 'rgba(77, 171, 247, 0.1)',
                        tension: 0.4,
                        fill: false,
                        borderDash: [5, 5]
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    } catch (error) {
        console.error('Error loading bot activity chart:', error);
    }
}

async function loadBotDetections() {
    try {
        const response = await apiRequest('/bots/detections?limit=50');
        const tbody = document.getElementById('botDetectionsBody');
        
        if (!response || response.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No bot detections yet</td></tr>';
            return;
        }
        
        tbody.innerHTML = response.map(bot => `
            <tr>
                <td>${new Date(bot.timestamp).toLocaleString()}</td>
                <td><code>${bot.ip_address}</code></td>
                <td style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    ${bot.user_agent || 'Unknown'}
                </td>
                <td>
                    <span class="badge badge-${bot.bot_type === 'good' ? 'success' : 'danger'}">
                        ${bot.bot_type || 'unknown'}
                    </span>
                </td>
                <td>${bot.confidence !== null && bot.confidence !== undefined ? bot.confidence + '%' : 'N/A'}</td>
                <td>
                    <span class="badge badge-${bot.action === 'allowed' ? 'success' : 'warning'}">
                        ${bot.action || 'unknown'}
                    </span>
                </td>
            </tr>
        `).join('');
    } catch (error) {
        console.error('Error loading bot detections:', error);
        document.getElementById('botDetectionsBody').innerHTML = 
            '<tr><td colspan="6" style="text-align: center; color: #ef4444;">Error loading data</td></tr>';
    }
}

// ============================================
// Telemetry Page Functions
// ============================================
async function loadTelemetryData() {
    try {
        const timeRange = document.getElementById('telemetryTimeRange')?.value || '24h';
        
        // Load telemetry stats
        const stats = await apiRequest(`/telemetry/stats?range=${timeRange}`);
        
        if (stats) {
            document.getElementById('avgResponseTime').textContent = 
                stats.avg_response_time ? `${stats.avg_response_time}ms` : 'N/A';
            document.getElementById('requestsPerMinute').textContent = stats.requests_per_minute || '0';
            document.getElementById('errorRate').textContent = 
                stats.error_rate ? `${stats.error_rate}%` : 'N/A';
        }
        
        // Load slowest endpoints
        await loadSlowestEndpoints();
        
        // Load backend performance
        await loadBackendPerformance();
        
        // Load response time distribution chart
        await loadResponseTimeChart();
    } catch (error) {
        console.error('Error loading telemetry data:', error);
    }
}

// Load Response Time Distribution Chart
let responseTimeChartInstance = null;

async function loadResponseTimeChart() {
    try {
        const response = await apiRequest('/telemetry/response-time-distribution');
        
        if (!response || !response.labels || !response.data) {
            console.warn('No response time data available');
            return;
        }
        
        const ctx = document.getElementById('responseTimeChart');
        if (!ctx) return;
        
        // Destroy existing chart if it exists
        if (responseTimeChartInstance) {
            responseTimeChartInstance.destroy();
        }
        
        responseTimeChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: response.labels,
                datasets: [{
                    label: 'Request Count',
                    data: response.data,
                    backgroundColor: 'rgba(59, 130, 246, 0.6)',
                    borderColor: 'rgba(59, 130, 246, 1)',
                    borderWidth: 2,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    title: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    } catch (error) {
        console.error('Error loading response time chart:', error);
    }
}

async function loadSlowestEndpoints() {
    try {
        const response = await apiRequest('/telemetry/slowest-endpoints?limit=50');
        const tbody = document.getElementById('slowestEndpointsBody');
        
        if (!response || response.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No data available</td></tr>';
            return;
        }
        
        // Filter out static assets (images, CSS, JS, fonts, etc.)
        const staticAssetExtensions = ['.png', '.jpg', '.jpeg', '.gif', '.webp', '.svg', '.ico', 
                                       '.css', '.js', '.woff', '.woff2', '.ttf', '.eot', '.otf',
                                       '.mp4', '.webm', '.mp3', '.pdf', '.zip', '.xml', '.txt'];
        
        const dynamicEndpoints = response.filter(endpoint => {
            const path = (endpoint.path || '').toLowerCase();
            return !staticAssetExtensions.some(ext => path.endsWith(ext));
        }).slice(0, 10); // Take top 10 after filtering
        
        if (dynamicEndpoints.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No dynamic endpoints found</td></tr>';
            return;
        }
        
        tbody.innerHTML = dynamicEndpoints.map(endpoint => {
            // Truncate path if too long for display
            let displayPath = endpoint.path;
            if (displayPath.length > 80) {
                displayPath = displayPath.substring(0, 80) + '...';
            }
            
            return `
            <tr>
                <td><strong>${endpoint.domain || 'unknown'}</strong></td>
                <td><code title="${endpoint.path}">${displayPath}</code></td>
                <td>${endpoint.avg_response}ms</td>
                <td>${endpoint.p95}ms</td>
                <td>${endpoint.p99}ms</td>
                <td>${endpoint.request_count}</td>
            </tr>
            `;
        }).join('');
    } catch (error) {
        console.error('Error loading slowest endpoints:', error);
        document.getElementById('slowestEndpointsBody').innerHTML = 
            '<tr><td colspan="6" style="text-align: center; color: #ef4444;">Error loading data</td></tr>';
    }
}

async function loadBackendPerformance() {
    try {
        const response = await apiRequest('/telemetry/backend-performance');
        const tbody = document.getElementById('backendPerformanceBody');
        
        if (!response || response.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">No backend data</td></tr>';
            return;
        }
        
        tbody.innerHTML = response.map(backend => `
            <tr>
                <td><code>${backend.backend_server}</code></td>
                <td>${backend.request_count}</td>
                <td>${backend.avg_response}ms</td>
                <td>
                    <span class="badge badge-${backend.error_count > 0 ? 'danger' : 'success'}">
                        ${backend.error_count}
                    </span>
                </td>
                <td>
                    <span class="status-dot ${backend.status === 'up' ? 'active' : ''}"></span>
                    ${backend.status}
                </td>
            </tr>
        `).join('');
    } catch (error) {
        console.error('Error loading backend performance:', error);
        document.getElementById('backendPerformanceBody').innerHTML = 
            '<tr><td colspan="5" style="text-align: center; color: #ef4444;">Error loading data</td></tr>';
    }
}

// ============================================
// GoAccess Analytics Page
// ============================================
async function loadGoAccessData() {
    try {
        // Embed GoAccess iframe or fetch JSON report
        toast.info('Opening GoAccess analytics...');
        
        // For now, show message about viewing GoAccess
        const analyticsPage = document.getElementById('analytics-page');
        if (analyticsPage) {
            analyticsPage.innerHTML = `
                <div class="page-header">
                    <h2>📈 GoAccess Analytics</h2>
                </div>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>Real-time Analytics Dashboard</h3>
                    </div>
                    <div style="padding: 20px; text-align: center;">
                        <p style="margin-bottom: 20px; color: #666;">
                            GoAccess provides real-time web analytics and monitoring.
                        </p>
                        <button class="btn-primary" onclick="window.open('http://localhost:7890', '_blank')">
                            🔗 Open GoAccess Dashboard
                        </button>
                    </div>
                    <div style="padding: 20px;">
                        <iframe 
                            src="http://localhost:7890" 
                            style="width: 100%; height: 800px; border: 1px solid #e2e8f0; border-radius: 10px;"
                            title="GoAccess Analytics">
                        </iframe>
                    </div>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error loading GoAccess data:', error);
        toast.error('Failed to load GoAccess analytics');
    }
}

// ============================================
window.unbanIP = unbanIP;
window.saveSettings = saveSettings;
window.loadBans = loadBans;
window.loadModSecEvents = loadModSecEvents;
window.loadTelemetry = loadTelemetryData;

// Regenerate all site configurations
async function regenerateAllConfigs() {
    if (!confirm('Regenerate NGINX configurations for all sites? This will reload NGINX after ~5 seconds.')) {
        return;
    }
    
    try {
        Toast.show('Regenerating all configurations...', 'info');
        await apiRequest('/regenerate/all', 'POST');
        Toast.show('All configurations regenerated! NGINX will reload shortly.', 'success');
    } catch (error) {
        console.error('Error regenerating configs:', error);
        Toast.show('Failed to regenerate configurations', 'error');
    }
}

window.regenerateAllConfigs = regenerateAllConfigs;

// Site Editor State
let currentSiteData = null;
let currentSiteId = null;
let editorBackends = [];
let editorBackendIdCounter = 0;

// Site Management Functions
function editSite(siteId) {
    // Load editor panel in dashboard
    loadSiteEditor(siteId);
}

function copySite(siteId) {
    // Load site data and show add form with copied values
    copySiteToForm(siteId);
}

async function deleteSite(siteId) {
    if (!confirm('Are you sure you want to delete this site? This will remove the NGINX configuration.')) {
        return;
    }
    
    try {
        await apiRequest(`/sites/${siteId}`, 'DELETE');
        showToast('success', 'Site deleted successfully');
        loadSites(); // Reload sites list
    } catch (error) {
        console.error('Error deleting site:', error);
        showToast('error', 'Failed to delete site');
    }
}

window.editSite = editSite;
window.copySite = copySite;
window.deleteSite = deleteSite;

// Site Editor Functions
async function loadSiteEditor(siteId) {
    currentSiteId = siteId;
    
    console.log('Loading site editor for ID:', siteId);
    
    // Show editor nav item
    const editorNavItem = document.querySelector('.nav-item[data-page="site-editor"]');
    if (editorNavItem) {
        editorNavItem.style.display = 'flex';
    } else {
        console.error('Editor nav item not found');
    }
    
    // Navigate to editor page
    navigateToPage('site-editor');
    
    // Load site data
    try {
        const response = await apiRequest(`/sites/${siteId}`);
        currentSiteData = response.site;
        
        console.log('Site data loaded:', currentSiteData);
        
        // Update header
        const titleEl = document.getElementById('editorSiteTitle');
        const domainEl = document.getElementById('editorSiteDomain');
        
        if (titleEl) {
            titleEl.textContent = `Edit: ${currentSiteData.domain}`;
        } else {
            console.error('editorSiteTitle element not found');
        }
        
        if (domainEl) {
            domainEl.textContent = `ID: ${currentSiteData.id} | Status: ${currentSiteData.enabled ? 'Enabled' : 'Disabled'}`;
        } else {
            console.error('editorSiteDomain element not found');
        }
        
        // Setup editor tabs
        setupEditorTabs();
        
        // Load last active tab or default to general
        const lastTab = sessionStorage.getItem('lastEditorTab') || 'general';
        loadEditorTab(lastTab);
        
        // Setup auto-save after a short delay
        setupAutoSave();
        
    } catch (error) {
        console.error('Error loading site:', error);
        Toast.show('Failed to load site data', 'error');
        navigateToPage('sites');
    }
}

function setupEditorTabs() {
    const tabBtns = document.querySelectorAll('.tab-btn[data-editor-tab]');
    
    tabBtns.forEach(btn => {
        btn.addEventListener('click', async () => {
            const tab = btn.getAttribute('data-editor-tab');
            
            // Update active state
            tabBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            
            // Store last active tab
            sessionStorage.setItem('lastEditorTab', tab);
            
            // Reload site data from server to get fresh data
            try {
                const response = await apiRequest(`/sites/${currentSiteId}`);
                currentSiteData = response.site;
                console.log('Reloaded site data for tab:', tab);
            } catch (error) {
                console.error('Error reloading site data:', error);
            }
            
            // Load tab content
            loadEditorTab(tab);
            
            // Re-setup auto-save after tab content loads
            setTimeout(() => {
                setupAutoSave();
            }, 200);
        });
    });
}

function loadEditorTab(tab) {
    console.log('Loading editor tab:', tab);
    const content = document.getElementById('editorContent');
    
    if (!content) {
        console.error('editorContent element not found!');
        return;
    }
    
    let html = '';
    
    switch(tab) {
        case 'general':
            html = renderGeneralTab();
            break;
        case 'security':
            html = renderSecurityTab();
            break;
        case 'performance':
            html = renderPerformanceTab();
            break;
        case 'backends':
            html = renderBackendsTab();
            break;
        case 'ssl':
            html = renderSSLTab();
            break;
        case 'access':
            html = renderAccessTab();
            break;
        case 'advanced':
            html = renderAdvancedTab();
            break;
        default:
            console.warn('Unknown tab:', tab);
            html = '<p>Tab not found</p>';
    }
    
    console.log('Setting editor content, HTML length:', html.length);
    content.innerHTML = html;
    console.log('Editor content set successfully');
    
    // Special initialization for backends tab
    if (tab === 'backends') {
        initializeBackends();
    }
    
    // Load certificate info for SSL tab
    if (tab === 'ssl') {
        loadCertificateInfo();
    }
}

function renderGeneralTab() {
    const data = currentSiteData;
    return `
        <div class="editor-panel">
            <h3>🌐 Basic Configuration</h3>
            <p>Configure domain and backend server settings</p>
            
            <div class="form-group">
                <label>Domain Name *</label>
                <input type="text" id="edit_domain" class="form-input" value="${data.domain || ''}" placeholder="example.com">
                <small style="color: var(--text-muted);">The domain name for this site</small>
            </div>
            
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="edit_wildcard_subdomains" ${data.wildcard_subdomains ? 'checked' : ''}>
                    <span>Match All Subdomains (*.${data.domain || 'domain.com'})</span>
                </label>
                <small style="color: var(--text-muted); display: block; margin-top: 0.5rem;">
                    Enable this to handle all subdomains (e.g., api.domain.com, blog.domain.com, etc.)
                </small>
            </div>
            
            <div class="form-group">
                <label>Backend Configuration</label>
                <div style="padding: 1rem; background: rgba(100, 181, 246, 0.1); border-radius: var(--radius-md); border: 1px solid rgba(100, 181, 246, 0.3);">
                    <p style="margin: 0; color: var(--info);">💡 Backend servers are now configured in the <strong>Backends</strong> tab</p>
                    <small style="color: var(--text-secondary); display: block; margin-top: 0.5rem;">Configure single or multiple backend servers with load balancing</small>
                </div>
            </div>
            
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="edit_enabled" ${data.enabled ? 'checked' : ''}>
                    <span>Site Enabled</span>
                </label>
            </div>

        </div>
    `;
}

function renderSecurityTab() {
    const data = currentSiteData;
    return `
        <div class="editor-panel">
            <h3>🛡️ ModSecurity WAF</h3>
            <p>Web Application Firewall with OWASP Core Rule Set</p>
            
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="edit_enable_modsecurity" ${data.enable_modsecurity ? 'checked' : ''}>
                    <span>Enable ModSecurity</span>
                </label>
                <small style="color: var(--text-muted);">Protect against SQL injection, XSS, and other attacks</small>
            </div>
        </div>
        
        <div class="editor-panel">
            <h3>🤖 Bot Protection</h3>
            <p>Block malicious bots and scrapers</p>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="edit_enable_bot_protection" ${data.enable_bot_protection !== 0 ? 'checked' : ''}>
                    <span>Enable Bot Protection</span>
                </label>
                <small style="color: var(--text-muted);">Block known bad bots (100+ patterns). Good bots like Googlebot are whitelisted.</small>
            </div>
        </div>
        
        <div class="editor-panel">
            <h3>🚦 Rate Limiting</h3>
            <p>Prevent abuse and DDoS attacks</p>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="edit_enable_rate_limit" ${data.enable_rate_limit !== 0 ? 'checked' : ''}>
                    <span>Enable Rate Limiting</span>
                </label>
            </div>
            
            <div class="form-group">
                <label>Rate Limit Preset</label>
                <select id="edit_rate_limit_zone" class="form-input" onchange="toggleCustomRateLimit()">
                    <option value="general" ${data.rate_limit_zone === 'general' ? 'selected' : ''}>General (10 req/s)</option>
                    <option value="strict" ${data.rate_limit_zone === 'strict' ? 'selected' : ''}>Strict (2 req/s)</option>
                    <option value="api" ${data.rate_limit_zone === 'api' ? 'selected' : ''}>API (30 req/s)</option>
                    <option value="custom" ${data.rate_limit_zone === 'custom' ? 'selected' : ''}>Custom</option>
                </select>
            </div>
            
            <div class="form-group" id="custom_rate_group" style="display: ${data.rate_limit_zone === 'custom' ? 'block' : 'none'};">
                <label>Requests per Second</label>
                <input type="number" id="edit_custom_rate_limit" class="form-input" min="1" max="1000" value="${data.custom_rate_limit || 10}">
                <small style="color: var(--text-muted);">Maximum requests per second per IP</small>
            </div>
            
            <div class="form-group">
                <label>Burst Size</label>
                <input type="number" id="edit_rate_limit_burst" class="form-input" min="1" max="100" value="${data.rate_limit_burst || 20}">
                <small style="color: var(--text-muted);">Allow bursts up to this many requests</small>
            </div>
        </div>
    `;
}

function renderPerformanceTab() {
    const data = currentSiteData;
    return `
        <div class="editor-panel">
            <h3>📦 Compression</h3>
            <p>Reduce bandwidth and improve load times</p>
            <div class="form-grid">
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="edit_enable_gzip" ${data.enable_gzip !== 0 ? 'checked' : ''}>
                        <span>Enable Gzip</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="edit_enable_brotli" ${data.enable_brotli !== 0 ? 'checked' : ''}>
                        <span>Enable Brotli</span>
                    </label>
                    <small style="color: var(--text-muted);">Brotli provides better compression than Gzip</small>
                </div>
            </div>
        </div>
        
        
        <div class="editor-panel">
            <h3>🧩 JavaScript Challenge</h3>
            <p>DDoS protection with proof-of-work challenge</p>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="edit_challenge_enabled" ${data.challenge_enabled ? 'checked' : ''}>
                    <span>Enable JavaScript Challenge</span>
                </label>
                <small style="color: var(--text-muted);">Requires visitors to solve a challenge before accessing the site</small>
            </div>
            
            <div class="form-group">
                <label>Challenge Difficulty</label>
                <input type="range" id="edit_challenge_difficulty" style="width:100%;" min="12" max="24" value="${data.challenge_difficulty || 18}" oninput="document.getElementById('difficultyValue').textContent = this.value">
                <div style="display: flex; justify-content: space-between; align-items:center; margin-top: 0.5rem; gap:0.5rem;">
                    <small style="color: var(--text-muted);">Very Easy (12)</small>
                    <div style="flex:1; text-align:center;">
                        <small id="difficultyLabel" style="color: var(--text-secondary);">Difficulty</small>
                        <div style="font-weight:600; color:var(--text-primary);" id="difficultyValue">${data.challenge_difficulty || 18}</div>
                    </div>
                    <small style="color: var(--text-muted);">Very Hard (24)</small>
                </div>
                <small style="color: var(--text-muted); display: block; margin-top: 0.5rem;">Higher = more protection, longer solve time</small>
            </div>
            
            <div class="form-group">
                <label>Token Duration (hours)</label>
                <input type="number" id="edit_challenge_duration" class="form-input" min="0.1" max="168" step="0.5" value="${data.challenge_duration || 1}">
                <small style="color: var(--text-muted);">How long the challenge token remains valid</small>
            </div>
            
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="edit_challenge_bypass_cf" ${data.challenge_bypass_cf ? 'checked' : ''}>
                    <span>Bypass Challenge for Cloudflare IPs</span>
                </label>
                <small style="color: var(--text-muted);">Skip challenge if request comes through Cloudflare</small>
            </div>
        </div>
    `;
}

function renderBackendsTab() {
    const data = currentSiteData;
    return `
        <div class="editor-panel">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h3>⚖️ Load Balancing</h3>
                <button class="btn-sm btn-outline" onclick="viewRawConfig()" title="View generated NGINX config">📄 View Raw Config</button>
            </div>
            <p>Configure backend servers for your site</p>
            
            <div class="form-group">
                <label>Load Balancing Method</label>
                <select id="edit_lb_method" class="form-input" onchange="updateLBMethod()">
                    <option value="round_robin">Round Robin - Distribute requests evenly</option>
                    <option value="least_conn">Least Connections - Send to server with fewest connections</option>
                    <option value="ip_hash">IP Hash - Same client always goes to same server</option>
                    <option value="hash">Hash - Custom hash based on variable</option>
                </select>
            </div>
            
            <div class="form-group" id="hash_key_group" style="display: none;">
                <label>Hash Key</label>
                <input type="text" id="edit_hash_key" class="form-input" placeholder="$request_uri">
            </div>
        </div>
        
        <div class="editor-panel">
            <h3>🖥️ Backend Servers</h3>
            <div id="backends-list"></div>
            <button class="btn-secondary" onclick="addEditorBackend()" style="margin-top: 1rem; width: 100%;">
                <span>➕</span>
                <span>Add Backend Server</span>
            </button>
        </div>
        
        <div class="editor-panel">
            <h3>💓 Health Checks</h3>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="edit_health_check_enabled" onchange="toggleHealthChecks()">
                    <span>Enable Health Checks</span>
                </label>
            </div>
            
            <div id="health-check-settings" style="display: none;">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Check Interval (seconds)</label>
                        <input type="number" id="edit_health_check_interval" class="form-input" value="10" min="1">
                    </div>
                    <div class="form-group">
                        <label>Health Check Path</label>
                        <input type="text" id="edit_health_check_path" class="form-input" placeholder="/health">
                    </div>
                </div>
            </div>
        </div>
    `;
}

function renderSSLTab() {
    const data = currentSiteData;
    return `
        <div class="editor-panel" id="cert-info-panel">
            <h3>📜 Current Certificate</h3>
            <div id="cert-info-content" style="padding: 1rem; background: var(--bg-tertiary); border-radius: var(--radius-md);">
                <p style="color: var(--text-muted); margin: 0;">Loading certificate information...</p>
            </div>
        </div>
        
        <div class="editor-panel">
            <h3>🔐 SSL/TLS Configuration</h3>
            <p>Configure HTTPS and certificate settings</p>
            
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="edit_ssl_enabled" ${data.ssl_enabled ? 'checked' : ''}>
                    <span>Enable SSL/TLS</span>
                </label>
                <small style="color: var(--text-muted);">Automatically obtain Let's Encrypt certificate</small>
            </div>
            
            <div class="form-group">
                <label>Certificate Challenge Type</label>
                <select id="edit_ssl_challenge_type" class="form-input" onchange="toggleSSLChallengeType()">
                    <option value="http-01" ${data.ssl_challenge_type === 'http-01' || !data.ssl_challenge_type ? 'selected' : ''}>Let's Encrypt (HTTP Challenge)</option>
                    <option value="dns-01" ${data.ssl_challenge_type === 'dns-01' ? 'selected' : ''}>Let's Encrypt (DNS Challenge)</option>
                    <option value="snakeoil" ${data.ssl_challenge_type === 'snakeoil' ? 'selected' : ''}>Self-Signed (Snakeoil)</option>
                    <option value="custom" ${data.ssl_challenge_type === 'custom' ? 'selected' : ''}>Custom Certificate</option>
                </select>
                <small style="color: var(--text-muted);">HTTP-01 requires public web access. DNS-01 for wildcards. Snakeoil for quick testing.</small>
            </div>
            
            <div id="cloudflare-dns-group" style="display: ${data.ssl_challenge_type === 'dns-01' ? 'block' : 'none'};">
                <div class="form-group">
                    <label>Cloudflare API Token</label>
                    <input type="password" id="edit_cf_api_token" class="form-input" value="${data.cf_api_token || envDefaults.cloudflare?.api_key || ''}" placeholder="Your Cloudflare API token">
                    <small style="color: var(--text-muted);">
                        ${envDefaults.cloudflare?.has_credentials ? '✅ Using default from .env file' : '⚠️ Configure CF_API_KEY in .env for automatic fill'} | 
                        <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" style="color: var(--primary);">Create a token</a> with Zone:DNS:Edit permissions
                    </small>
                </div>
                
                <div class="form-group">
                    <label>Cloudflare Zone ID</label>
                    <input type="text" id="edit_cf_zone_id" class="form-input" value="${data.cf_zone_id || ''}" placeholder="Zone ID from Cloudflare dashboard">
                    <small style="color: var(--text-muted);">📍 Found in your domain's Overview page on Cloudflare</small>
                </div>
            </div>
        </div>
        
        <div class="editor-panel">
            <h3>📜 Custom Certificate Upload</h3>
            <p>Upload your own SSL certificate (e.g., from Cloudflare, purchased cert)</p>
            
            <div class="cert-upload-area" onclick="document.getElementById('cert_file').click()">
                <input type="file" id="cert_file" accept=".pem,.crt,.cer" onchange="handleCertUpload('cert')" style="display: none;">
                <span style="font-size: 3rem;">📄</span>
                <p style="margin: 1rem 0 0.5rem 0; color: var(--text-primary);">Click to upload certificate</p>
                <small style="color: var(--text-muted);">Supported formats: PEM, CRT, CER</small>
            </div>
            
            <div class="cert-upload-area" style="margin-top: 1rem;" onclick="document.getElementById('key_file').click()">
                <input type="file" id="key_file" accept=".pem,.key" onchange="handleCertUpload('key')" style="display: none;">
                <span style="font-size: 3rem;">🔑</span>
                <p style="margin: 1rem 0 0.5rem 0; color: var(--text-primary);">Click to upload private key</p>
                <small style="color: var(--text-muted);">Supported formats: PEM, KEY</small>
            </div>
            
            <div id="cert-upload-status" style="margin-top: 1rem;"></div>
            
            <button class="btn-primary" onclick="uploadCustomCertificate()" style="margin-top: 1rem; width: 100%;">
                <span>⬆️</span>
                <span>Upload Certificate</span>
            </button>
        </div>
    `;
}

function renderAccessTab() {
    const data = currentSiteData;
    return `
        <div class="editor-panel">
            <h3>🌍 GeoIP Blocking</h3>
            <p>Block or allow traffic from specific countries</p>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="edit_enable_geoip_blocking" ${data.enable_geoip_blocking ? 'checked' : ''}>
                    <span>Enable GeoIP Blocking</span>
                </label>
            </div>
            
            <div class="form-group">
                <label>Blocked Countries (comma-separated ISO codes)</label>
                <input type="text" id="edit_blocked_countries" class="form-input" value="${data.blocked_countries || ''}" placeholder="CN, RU, KP">
                <small style="color: var(--text-muted);">Block traffic from these countries (ISO 3166-1 alpha-2 codes)</small>
            </div>
        </div>
        
        <div class="editor-panel">
            <h3>🔐 IP Whitelist</h3>
            <p>Restrict access to specific IP addresses</p>
            <div class="form-group">
                <label>Allowed IPs/CIDRs (one per line)</label>
                <textarea id="edit_ip_whitelist" class="form-input" rows="5" placeholder="192.168.1.0/24&#10;10.0.0.5&#10;203.0.113.42">${data.ip_whitelist || ''}</textarea>
                <small style="color: var(--text-muted);">One IP or CIDR block per line. Leave empty to allow all.</small>
            </div>
            
            <div class="form-group" style="margin-top: 1.5rem; padding: 1rem; background: #f0f7ff; border-left: 4px solid #3b82f6; border-radius: 4px;">
                <label class="checkbox-label">
                    <input type="checkbox" id="edit_local_only" ${data.local_only ? 'checked' : ''}>
                    <span>🔒 Restrict to Local Network Only</span>
                </label>
                <small style="color: #666; display: block; margin-top: 0.5rem;">
                    Block all external traffic - only allow access from local/private IP ranges (127.0.0.0/8, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16). 
                    This setting is enforced at the NGINX level and cannot be bypassed.
                </small>
            </div>
        </div>
        
        <div class="editor-panel">
            <h3>🔐 Basic Authentication</h3>
            <p>Require username/password to access site</p>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="edit_enable_basic_auth" ${data.enable_basic_auth ? 'checked' : ''}>
                    <span>Enable Basic Auth</span>
                </label>
            </div>
            
            <div class="form-group">
                <label>Username</label>
                <input type="text" id="edit_auth_username" class="form-input" value="${data.basic_auth_username || ''}" placeholder="admin">
            </div>
            
            <div class="form-group">
                <label>Password</label>
                <input type="password" id="edit_auth_password" class="form-input" placeholder="Enter new password">
                <small style="color: var(--text-muted);">Leave empty to keep existing password</small>
            </div>
        </div>
    `;
}

function renderAdvancedTab() {
    const data = currentSiteData;
    return `
        <div class="editor-panel">
            <h3>🔧 Headers</h3>
            <p>Add custom headers to responses</p>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="edit_enable_waf_headers" ${data.enable_waf_headers !== 0 ? 'checked' : ''}>
                    <span>Enable WAF Headers</span>
                </label>
                <small style="color: var(--text-muted);">Add X-Protected-By, X-Frame-Options, X-XSS-Protection headers</small>
            </div>
            
            <div class="form-group">
                <label>Custom Headers (one per line)</label>
                <textarea id="edit_custom_headers" class="form-input" rows="5" placeholder="X-Custom-Header 'value'&#10;X-Another-Header 'another value'">${data.custom_headers || ''}</textarea>
                <small style="color: var(--text-muted);">Format: Header-Name 'value'</small>
            </div>
        </div>
        
        <div class="editor-panel">
            <h3>⚙️ Custom Configuration</h3>
            <p>Advanced NGINX configuration (experts only)</p>
            <div class="form-group">
                <label>Custom NGINX Directives</label>
                <textarea id="edit_custom_config" class="form-input" rows="8" placeholder="# Add custom NGINX configuration here&#10;# Example:&#10;# client_max_body_size 100m;&#10;# proxy_connect_timeout 120s;">${data.custom_config || ''}</textarea>
                <small style="color: var(--text-muted);">⚠️ Advanced feature - invalid config will break the site</small>
            </div>
        </div>
        
        <div class="editor-panel">
            <h3>📄 Error Pages</h3>
            <p>Customize error pages for 403, 404, 429, and 500 errors</p>
            
            <div class="form-group">
                <label>Error Page Mode</label>
                <select id="edit_error_page_mode" class="form-input" onchange="toggleErrorPageMode()">
                    <option value="template" ${(data.error_page_mode || 'template') === 'template' ? 'selected' : ''}>Use Built-in CatWAF Templates</option>
                    <option value="custom" ${data.error_page_mode === 'custom' ? 'selected' : ''}>Custom URLs</option>
                </select>
                <small style="color: var(--text-muted);">Choose between styled CatWAF error pages or your own custom pages</small>
            </div>
            
            <div id="customErrorPagesGroup" style="display: ${data.error_page_mode === 'custom' ? 'block' : 'none'};">
                <div class="form-group">
                    <label>403 Forbidden Page</label>
                    <input type="text" id="edit_error_page_403" class="form-input" value="${data.error_page_403 || '/errors/403.html'}" placeholder="/custom-403.html or https://example.com/403">
                    <small style="color: var(--text-muted);">Path or URL for 403 errors</small>
                </div>
                
                <div class="form-group">
                    <label>404 Not Found Page</label>
                    <input type="text" id="edit_error_page_404" class="form-input" value="${data.error_page_404 || '/errors/404.html'}" placeholder="/custom-404.html or https://example.com/404">
                    <small style="color: var(--text-muted);">Path or URL for 404 errors</small>
                </div>
                
                <div class="form-group">
                    <label>429 Rate Limited Page</label>
                    <input type="text" id="edit_error_page_429" class="form-input" value="${data.error_page_429 || '/errors/429.html'}" placeholder="/custom-429.html or https://example.com/429">
                    <small style="color: var(--text-muted);">Path or URL for rate limit errors</small>
                </div>
                
                <div class="form-group">
                    <label>500 Server Error Page</label>
                    <input type="text" id="edit_error_page_500" class="form-input" value="${data.error_page_500 || '/errors/500.html'}" placeholder="/custom-500.html or https://example.com/500">
                    <small style="color: var(--text-muted);">Path or URL for 500/502/503/504 errors</small>
                </div>
            </div>
        </div>
    `;
}

// Toggle error page custom fields
window.toggleErrorPageMode = function() {
    const mode = document.getElementById('edit_error_page_mode')?.value;
    const customGroup = document.getElementById('customErrorPagesGroup');
    if (customGroup) {
        customGroup.style.display = mode === 'custom' ? 'block' : 'none';
    }
};

// Backend management for editor
function initializeBackends() {
    const data = currentSiteData;
    
    // Parse backends from data
    if (data.backends) {
        try {
            editorBackends = JSON.parse(data.backends);
        } catch (e) {
            editorBackends = [];
        }
    }
    
    // Fallback to backend_url
    if (editorBackends.length === 0 && data.backend_url) {
        // Parse backend_url to separate host and port
        const backendUrl = data.backend_url;
        let address = backendUrl;
        let port = 8080;
        
        // Check if it has port
        if (backendUrl.includes(':')) {
            const parts = backendUrl.split(':');
            address = parts[0];
            port = parseInt(parts[1]) || 8080;
        }
        
        editorBackends = [{
            id: 0,
            address: address,
            port: port,
            useProtocolPorts: false,
            httpPort: 80,
            httpsPort: 443,
            wsPort: 80,
            wssPort: 443,
            weight: 1,
            max_fails: 3,
            fail_timeout: 30,
            backup: false,
            down: false
        }];
    }
    
    editorBackendIdCounter = editorBackends.length > 0 ? Math.max(...editorBackends.map(b => b.id || 0)) + 1 : 0;
    
    // Set form values
    document.getElementById('edit_lb_method').value = data.lb_method || 'round_robin';
    document.getElementById('edit_hash_key').value = data.hash_key || '$request_uri';
    document.getElementById('edit_health_check_enabled').checked = data.health_check_enabled == 1;
    document.getElementById('edit_health_check_interval').value = data.health_check_interval || 10;
    document.getElementById('edit_health_check_path').value = data.health_check_path || '/health';
    
    updateLBMethod();
    toggleHealthChecks();
    renderBackendsList();
}

function renderBackendsList() {
    const container = document.getElementById('backends-list');
    if (!container) return;
    
    if (editorBackends.length === 0) {
        container.innerHTML = '<p style="color: var(--text-muted); text-align: center; padding: 2rem;">No backend servers configured</p>';
        return;
    }
    
    container.innerHTML = editorBackends.map((backend, index) => `
        <div class="backend-card">
            <div class="backend-card-header">
                <h4>Backend ${index + 1}</h4>
                <button class="btn-danger btn-sm" onclick="removeEditorBackend(${backend.id})">🗑️ Remove</button>
            </div>
            <div class="form-group">
                <label>Server Address *</label>
                <input type="text" class="form-input backend-address" data-id="${backend.id}" value="${backend.address || ''}" placeholder="192.168.1.100" onchange="updateEditorBackend(${backend.id})">
                <small style="color: var(--text-muted);">IP or hostname (ports configured below)</small>
            </div>
            
            <div class="form-group">
                <label style="display:block; margin-bottom: 0.25rem;">Protocols</label>
                <div style="display:flex; gap: 0.75rem; flex-wrap:wrap;">
                    <label class="checkbox-label" style="margin:0;">
                        <input type="checkbox" class="backend-proto-enabled" data-proto="http" data-id="${backend.id}" ${backend.proto?.http !== false ? 'checked' : ''} onchange="toggleBackendProtocol(${backend.id}, 'http')">
                        <span>HTTP</span>
                    </label>
                    <label class="checkbox-label" style="margin:0;">
                        <input type="checkbox" class="backend-proto-enabled" data-proto="https" data-id="${backend.id}" ${backend.proto?.https !== false ? 'checked' : ''} onchange="toggleBackendProtocol(${backend.id}, 'https')">
                        <span>HTTPS</span>
                    </label>
                    <label class="checkbox-label" style="margin:0;">
                        <input type="checkbox" class="backend-proto-enabled" data-proto="websocket" data-id="${backend.id}" ${backend.proto?.websocket ? 'checked' : ''} onchange="toggleBackendProtocol(${backend.id}, 'websocket')">
                        <span>WebSocket</span>
                    </label>
                </div>
                <small style="color: var(--text-muted); display:block; margin-top:0.5rem;">Select which protocols this backend supports</small>
            </div>

            <div class="backend-fields" style="display:flex; gap:1rem; flex-wrap:wrap;">
                <div class="form-group" style="min-width:140px;">
                    <label>HTTP Port</label>
                    <input type="number" class="form-input backend-http-port" data-id="${backend.id}" value="${backend.httpPort || 80}" min="1" max="65535" onchange="updateEditorBackend(${backend.id})">
                </div>
                <div class="form-group" style="min-width:140px;">
                    <label>HTTPS Port</label>
                    <input type="number" class="form-input backend-https-port" data-id="${backend.id}" value="${backend.httpsPort || 443}" min="1" max="65535" onchange="updateEditorBackend(${backend.id})">
                </div>
                <div class="form-group" style="min-width:140px;">
                    <label>WebSocket Port</label>
                    <input type="number" class="form-input backend-ws-port" data-id="${backend.id}" value="${backend.wsPort || 80}" min="1" max="65535" onchange="updateEditorBackend(${backend.id})">
                    <small style="color: var(--text-muted);">For WS/WSS traffic</small>
                </div>
            </div>
            
            <div class="backend-fields">
                <div class="form-group">
                    <label>Weight</label>
                    <input type="number" class="form-input backend-weight" data-id="${backend.id}" value="${backend.weight || 1}" min="1" onchange="updateEditorBackend(${backend.id})">
                </div>
                <div class="form-group">
                    <label>Max Fails</label>
                    <input type="number" class="form-input backend-max-fails" data-id="${backend.id}" value="${backend.max_fails || 3}" min="1" onchange="updateEditorBackend(${backend.id})">
                </div>
                <div class="form-group">
                    <label>Fail Timeout (s)</label>
                    <input type="number" class="form-input backend-fail-timeout" data-id="${backend.id}" value="${backend.fail_timeout || 30}" min="1" onchange="updateEditorBackend(${backend.id})">
                </div>
            </div>
            <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                <label class="checkbox-label">
                    <input type="checkbox" class="backend-backup" data-id="${backend.id}" ${backend.backup ? 'checked' : ''} onchange="updateEditorBackend(${backend.id})">
                    <span>Backup</span>
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" class="backend-down" data-id="${backend.id}" ${backend.down ? 'checked' : ''} onchange="updateEditorBackend(${backend.id})">
                    <span>Down</span>
                </label>
            </div>
        </div>
    `).join('');
}

function addEditorBackend() {
    editorBackends.push({
        id: editorBackendIdCounter++,
        address: '',
        httpPort: 80,
        httpsPort: 443,
        wsPort: 80,
        weight: 1,
        max_fails: 3,
        fail_timeout: 30,
        backup: false,
        down: false
    });
    renderBackendsList();
}

function removeEditorBackend(id) {
    if (editorBackends.length === 1) {
        showToast('Cannot remove the last backend. Sites need at least one backend server.', 'error');
        return;
    }
    
    if (confirm('Remove this backend server?')) {
        editorBackends = editorBackends.filter(b => b.id !== id);
        renderBackendsList();
        // Auto-save the backends change
        autoSaveField('backends', JSON.stringify(editorBackends));
    }
}

function toggleProtocolPorts(id) {
    const backend = editorBackends.find(b => b.id === id);
    if (!backend) return;
    
    const checkbox = document.querySelector(`.backend-use-protocol-ports[data-id="${id}"]`);
    backend.useProtocolPorts = checkbox.checked;
    
    const protocolPortsDiv = document.getElementById(`backend-ports-${id}`);
    const singlePortDiv = document.getElementById(`backend-single-port-${id}`);
    
    if (backend.useProtocolPorts) {
        protocolPortsDiv.style.display = '';
        singlePortDiv.style.display = 'none';
    } else {
        protocolPortsDiv.style.display = 'none';
        singlePortDiv.style.display = '';
    }
    
    updateEditorBackend(id);
}

function updateEditorBackend(id) {
    const backend = editorBackends.find(b => b.id === id);
    if (!backend) return;
    
    backend.address = document.querySelector(`.backend-address[data-id="${id}"]`)?.value || '';
    backend.weight = parseInt(document.querySelector(`.backend-weight[data-id="${id}"]`)?.value) || 1;
    backend.max_fails = parseInt(document.querySelector(`.backend-max-fails[data-id="${id}"]`)?.value) || 3;
    backend.fail_timeout = parseInt(document.querySelector(`.backend-fail-timeout[data-id="${id}"]`)?.value) || 30;
    backend.backup = document.querySelector(`.backend-backup[data-id="${id}"]`)?.checked || false;
    backend.down = document.querySelector(`.backend-down[data-id="${id}"]`)?.checked || false;
    
    // Protocol enable flags (simplified: http, https, websocket)
    backend.proto = backend.proto || {};
    backend.proto.http = document.querySelector(`.backend-proto-enabled[data-id="${id}"][data-proto="http"]`)?.checked || false;
    backend.proto.https = document.querySelector(`.backend-proto-enabled[data-id="${id}"][data-proto="https"]`)?.checked || false;
    backend.proto.websocket = document.querySelector(`.backend-proto-enabled[data-id="${id}"][data-proto="websocket"]`)?.checked || false;

    // Simplified ports: HTTP, HTTPS, WebSocket
    backend.httpPort = parseInt(document.querySelector(`.backend-http-port[data-id="${id}"]`)?.value) || 80;
    backend.httpsPort = parseInt(document.querySelector(`.backend-https-port[data-id="${id}"]`)?.value) || 443;
    backend.wsPort = parseInt(document.querySelector(`.backend-ws-port[data-id="${id}"]`)?.value) || 80;
    
    // Auto-save backends when updated
    clearTimeout(autoSaveTimeout);
    autoSaveTimeout = setTimeout(() => {
        autoSaveField('backends', JSON.stringify(editorBackends));
    }, AUTO_SAVE_DELAY);
}

function updateLBMethod() {
    const method = document.getElementById('edit_lb_method').value;
    const hashKeyGroup = document.getElementById('hash_key_group');
    
    if (method === 'hash') {
        hashKeyGroup.style.display = '';
    } else {
        hashKeyGroup.style.display = 'none';
    }
}

// Toggle a specific protocol enable/disable for a backend
function toggleBackendProtocol(id, proto) {
    const backend = editorBackends.find(b => b.id === id);
    if (!backend) return;
    backend.proto = backend.proto || {};
    const checkbox = document.querySelector(`.backend-proto-enabled[data-id="${id}"][data-proto="${proto}"]`);
    backend.proto[proto] = checkbox?.checked || false;
    // Re-render to show/hide port inputs
    renderBackendsList();
    // Ensure values saved
    updateEditorBackend(id);
}

// Expose for inline onclick handlers
window.toggleBackendProtocol = toggleBackendProtocol;

// Load certificate information for SSL/TLS tab
async function loadCertificateInfo() {
    const certInfoContent = document.getElementById('cert-info-content');
    if (!certInfoContent) return;
    
    try {
        const domain = currentSiteData.domain;
        if (!domain) {
            certInfoContent.innerHTML = '<p style="color: var(--text-muted); margin: 0;">No domain configured yet</p>';
            return;
        }
        
        certInfoContent.innerHTML = '<p style="color: var(--text-muted); margin: 0;">Loading certificate information...</p>';
        
        const response = await apiRequest(`/certificates/${domain}`);
        
        if (response.error) {
            certInfoContent.innerHTML = `
                <div style="color: var(--warning);">
                    <p style="margin: 0 0 0.5rem 0;">\u26a0\ufe0f No certificate found for ${domain}</p>
                    <small style="color: var(--text-muted);">Enable SSL/TLS and save to generate a certificate</small>
                </div>
            `;
            return;
        }
        
        // Use daysUntilExpiry from API response (already calculated server-side)
        const daysUntilExpiry = response.daysUntilExpiry || 0;
        
        let statusBadge = '';
        let statusColor = '';
        if (daysUntilExpiry > 30) {
            statusBadge = '\u2705 Valid';
            statusColor = 'var(--success)';
        } else if (daysUntilExpiry > 7) {
            statusBadge = '\u26a0\ufe0f Expires Soon';
            statusColor = 'var(--warning)';
        } else {
            statusBadge = '\u274c Expired / Expiring';
            statusColor = 'var(--danger)';
        }
        
        certInfoContent.innerHTML = `
            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                <div style="display: flex; justify-content: between; align-items: center;">
                    <div>
                        <strong style="color: var(--text-primary);">${domain}</strong>
                        <span style="margin-left: 1rem; padding: 0.25rem 0.75rem; background: rgba(76, 175, 80, 0.15); color: ${statusColor}; border-radius: 12px; font-size: 0.75rem; font-weight: 600;">${statusBadge}</span>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                    <div>
                        <small style="color: var(--text-muted); display: block;">Issuer</small>
                        <span style="color: var(--text-primary);">${response.issuer || 'Unknown'}</span>
                    </div>
                    <div>
                        <small style="color: var(--text-muted); display: block;">Issued</small>
                        <span style="color: var(--text-primary);">${response.validFrom || 'Unknown'}</span>
                    </div>
                    <div>
                        <small style="color: var(--text-muted); display: block;">Expires</small>
                        <span style="color: var(--text-primary);">${response.expiryDate || 'Unknown'}</span>
                    </div>
                    <div>
                        <small style="color: var(--text-muted); display: block;">Valid For</small>
                        <span style="color: ${statusColor}; font-weight: 600;">${daysUntilExpiry >= 0 ? daysUntilExpiry : 0} days</span>
                    </div>
                </div>
                
                <div style="margin-top: 0.5rem;">
                    <button class="btn-secondary" onclick="renewCertificate('${domain}')" style="width: 100%;">
                        <span>\ud83d\udd04</span>
                        <span>Renew Certificate</span>
                    </button>
                </div>
            </div>
        `;
    } catch (error) {
        console.error('Error loading certificate info:', error);
        certInfoContent.innerHTML = `
            <div style="color: var(--danger);">
                <p style="margin: 0;">\u274c Error loading certificate information</p>
                <small style="color: var(--text-muted); display: block; margin-top: 0.5rem;">${error.message || 'Unknown error'}</small>
            </div>
        `;
    }
}

function renewCertificate(domain) {
    if (!confirm(`Renew certificate for ${domain}?\\n\\nThis will request a new certificate from Let's Encrypt.`)) {
        return;
    }
    
    showToast('Renewing certificate...', 'loading');
    
    apiRequest(`/certificates/${domain}/renew`, 'POST')
        .then(response => {
            showToast(`Certificate renewed successfully for ${domain}`, 'success');
            loadCertificateInfo(); // Reload cert info
        })
        .catch(error => {
            showToast(`Failed to renew certificate: ${error.message}`, 'error');
        });
}

function toggleHealthChecks() {
    const enabled = document.getElementById('edit_health_check_enabled').checked;
    const settings = document.getElementById('health-check-settings');
    if (settings) {
        settings.style.display = enabled ? 'block' : 'none';
    }
}

// Certificate upload
let certFile = null;
let keyFile = null;

function handleCertUpload(type) {
    const input = type === 'cert' ? document.getElementById('cert_file') : document.getElementById('key_file');
    const file = input.files[0];
    
    if (file) {
        if (type === 'cert') {
            certFile = file;
            Toast.show(`Certificate file selected: ${file.name}`, 'success');
        } else {
            keyFile = file;
            Toast.show(`Private key file selected: ${file.name}`, 'success');
        }
    }
}

async function uploadCustomCertificate() {
    if (!certFile || !keyFile) {
        Toast.show('Please select both certificate and private key files', 'error');
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('certificate', certFile);
        formData.append('private_key', keyFile);
        formData.append('site_id', currentSiteId);
        formData.append('domain', currentSiteData.domain);
        
        const response = await fetch(`${API_BASE_URL}/certificates/upload`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${API_TOKEN}`
            },
            body: formData
        });
        
        if (!response.ok) {
            throw new Error('Upload failed');
        }
        
        Toast.show('Certificate uploaded successfully! NGINX will reload.', 'success');
        certFile = null;
        keyFile = null;
        
    } catch (error) {
        console.error('Error uploading certificate:', error);
        Toast.show('Failed to upload certificate', 'error');
    }
}

// Save site editor
async function saveSiteEditor() {
    try {
        // Start with existing data to preserve fields from other tabs
        const formData = { ...currentSiteData };
        
        // Helper: Only update if element exists
        const set = (field, id, isCheckbox = false, parser = null) => {
            const el = document.getElementById(id);
            if (el) {
                if (isCheckbox) {
                    formData[field] = el.checked ? 1 : 0;
                } else if (parser) {
                    formData[field] = parser(el.value);
                } else {
                    formData[field] = el.value || '';
                }
            }
        };
        
        // General tab
        set('domain', 'edit_domain');
        set('wildcard_subdomains', 'edit_wildcard_subdomains', true);
        set('enabled', 'edit_enabled', true);
        set('backend_url', 'edit_backend_url');
        
        // Security tab
        set('enable_modsecurity', 'edit_enable_modsecurity', true);
        set('enable_bot_protection', 'edit_enable_bot_protection', true);
        set('enable_rate_limit', 'edit_enable_rate_limit', true);
        set('rate_limit_zone', 'edit_rate_limit_zone');
        set('custom_rate_limit', 'edit_custom_rate_limit', false, val => parseInt(val) || 10);
        set('rate_limit_burst', 'edit_rate_limit_burst', false, val => parseInt(val) || 20);
        
        // Performance tab
        set('enable_gzip', 'edit_enable_gzip', true);
        set('enable_brotli', 'edit_enable_brotli', true);
        set('challenge_enabled', 'edit_challenge_enabled', true);
        set('challenge_difficulty', 'edit_challenge_difficulty', false, val => parseInt(val) || 18);
        set('challenge_duration', 'edit_challenge_duration', false, val => parseFloat(val) || 1);
        set('challenge_bypass_cf', 'edit_challenge_bypass_cf', true);
        
        // SSL tab
        set('ssl_enabled', 'edit_ssl_enabled', true);
        set('ssl_challenge_type', 'edit_ssl_challenge_type');
        set('cf_api_token', 'edit_cf_api_token');
        set('cf_zone_id', 'edit_cf_zone_id');
        
        // Access tab
        set('enable_geoip_blocking', 'edit_enable_geoip_blocking', true);
        set('blocked_countries', 'edit_blocked_countries');
        set('ip_whitelist', 'edit_ip_whitelist');
        set('local_only', 'edit_local_only', true);
        set('enable_basic_auth', 'edit_enable_basic_auth', true);
        set('basic_auth_username', 'edit_auth_username');
        set('basic_auth_password', 'edit_auth_password');
        
        // Advanced tab
        set('enable_waf_headers', 'edit_enable_waf_headers', true);
        set('custom_headers', 'edit_custom_headers');
        set('custom_config', 'edit_custom_config');
        
        // Backends tab
        if (document.getElementById('edit_lb_method')) {
            if (editorBackends && editorBackends.length > 0) {
                formData.backends = JSON.stringify(editorBackends);
                set('lb_method', 'edit_lb_method');
                set('hash_key', 'edit_hash_key');
                set('health_check_enabled', 'edit_health_check_enabled', true);
                set('health_check_interval', 'edit_health_check_interval', false, val => parseInt(val) || 10);
                set('health_check_path', 'edit_health_check_path');
            }
        }
        
        await apiRequest(`/sites/${currentSiteId}`, 'PUT', formData);
        Toast.show('✅ Site saved successfully! Changes will apply in ~5 seconds.', 'success');
        
        // Dispatch event for tab switching
        window.dispatchEvent(new Event('siteSaved'));
        
        // Reload site data after longer delay to see toast
        setTimeout(() => loadSiteEditor(currentSiteId), 2000);
        
    } catch (error) {
        console.error('Error saving site:', error);
        Toast.show('Failed to save site', 'error');
    }
}

// Auto-save functionality for live updates
let autoSaveTimeout = null;
const AUTO_SAVE_DELAY = 800; // ms delay after typing stops

function updateAutoSaveStatus(status, message) {
    const icon = document.getElementById('autoSaveIcon');
    const text = document.getElementById('autoSaveText');
    if (!icon || !text) return;
    
    switch(status) {
        case 'saving':
            icon.textContent = '⏳';
            text.textContent = 'Saving...';
            text.style.color = 'var(--warning-color)';
            break;
        case 'saved':
            icon.textContent = '✅';
            text.textContent = message || 'Saved';
            text.style.color = 'var(--success-color)';
            setTimeout(() => {
                icon.textContent = '💾';
                text.textContent = 'Auto-save enabled';
                text.style.color = 'var(--text-muted)';
            }, 2000);
            break;
        case 'error':
            icon.textContent = '❌';
            text.textContent = message || 'Save failed';
            text.style.color = 'var(--danger-color)';
            setTimeout(() => {
                icon.textContent = '💾';
                text.textContent = 'Auto-save enabled';
                text.style.color = 'var(--text-muted)';
            }, 3000);
            break;
    }
}

async function autoSaveField(fieldName, value) {
    if (!currentSiteId) return;
    
    updateAutoSaveStatus('saving');
    
    try {
        const data = { [fieldName]: value };
        await apiRequest(`/sites/${currentSiteId}`, 'PATCH', data);
        updateAutoSaveStatus('saved', `${fieldName} saved`);
    } catch (error) {
        console.error('Auto-save error:', error);
        updateAutoSaveStatus('error', 'Failed to save');
        Toast.show(`❌ ${error.message}`, 'error');
    }
}

function setupAutoSave() {
    // Wait for DOM to be ready
    setTimeout(() => {
        const editorContent = document.getElementById('editorContent');
        if (!editorContent) return;
        
        // Setup listeners for all input fields
        editorContent.addEventListener('change', (e) => {
            const el = e.target;
            if (!el.id || !el.id.startsWith('edit_')) return;
            
            const fieldName = el.id.replace('edit_', '');
            let value;
            
            if (el.type === 'checkbox') {
                value = el.checked ? 1 : 0;
            } else if (el.type === 'number') {
                value = parseInt(el.value) || 0;
            } else {
                value = el.value;
            }
            
            // Debounce for text inputs
            if (el.tagName === 'TEXTAREA' || el.type === 'text') {
                clearTimeout(autoSaveTimeout);
                autoSaveTimeout = setTimeout(() => {
                    autoSaveField(fieldName, value);
                }, AUTO_SAVE_DELAY);
            } else {
                // Immediate save for checkboxes, selects, etc.
                autoSaveField(fieldName, value);
            }
        });
        
        // Also handle textarea input events for typing
        editorContent.addEventListener('input', (e) => {
            const el = e.target;
            if (el.tagName === 'TEXTAREA' && el.id && el.id.startsWith('edit_')) {
                clearTimeout(autoSaveTimeout);
                autoSaveTimeout = setTimeout(() => {
                    const fieldName = el.id.replace('edit_', '');
                    autoSaveField(fieldName, el.value);
                }, AUTO_SAVE_DELAY);
            }
        });
    }, 500);
}

function copySiteToForm(siteId) {
    // For now, just navigate to add site - we can enhance this later
    showAddSiteModal();
}

window.loadSiteEditor = loadSiteEditor;
window.saveSiteEditor = saveSiteEditor;
window.setupAutoSave = setupAutoSave;
window.addEditorBackend = addEditorBackend;
window.removeEditorBackend = removeEditorBackend;
window.updateEditorBackend = updateEditorBackend;
window.toggleProtocolPorts = toggleProtocolPorts;
window.updateLBMethod = updateLBMethod;
window.toggleHealthChecks = toggleHealthChecks;
window.handleCertUpload = handleCertUpload;
window.uploadCustomCertificate = uploadCustomCertificate;

// Toggle custom rate limit
window.toggleCustomRateLimit = function() {
    const select = document.getElementById('edit_rate_limit_zone');
    const customGroup = document.getElementById('custom_rate_group');
    if (customGroup && select) {
        customGroup.style.display = select.value === 'custom' ? 'block' : 'none';
    }
};

// Toggle SSL challenge type
window.toggleSSLChallengeType = function() {
    const select = document.getElementById('edit_ssl_challenge_type');
    const cloudflareGroup = document.getElementById('cloudflare-dns-group');
    if (cloudflareGroup && select) {
        cloudflareGroup.style.display = select.value === 'dns-01' ? 'block' : 'none';
    }
};

// Add Site Page Functions
function initializeAddSiteTabs() {
    const tabs = document.querySelectorAll('[data-add-tab]');
    tabs.forEach(btn => {
        btn.addEventListener('click', () => {
            // Remove active from all tabs
            tabs.forEach(t => t.classList.remove('active'));
            // Add active to clicked tab
            btn.classList.add('active');
            // Load tab content
            loadAddSiteTab(btn.getAttribute('data-add-tab'));
        });
    });
}

function loadAddSiteTab(tab) {
    const content = document.getElementById('addSiteContent');
    if (!content) return;
    
    // Save current form data before switching tabs
    saveAddSiteFormData();
    
    let html = '';
    switch(tab) {
        case 'general':
            html = renderAddSiteGeneralTab();
            break;
        case 'security':
            html = renderAddSiteSecurityTab();
            break;
        case 'ssl':
            html = renderAddSiteSSLTab();
            break;
    }
    
    content.innerHTML = html;
    
    // Add event listeners after rendering
    if (tab === 'ssl') {
        const sslTypeSelect = document.getElementById('new_ssl_challenge_type');
        if (sslTypeSelect) {
            sslTypeSelect.addEventListener('change', (e) => {
                const cfApiGroup = document.getElementById('new_cf_api_group');
                const cfZoneGroup = document.getElementById('new_cf_zone_group');
                const isDns = e.target.value === 'dns-01';
                if (cfApiGroup) cfApiGroup.style.display = isDns ? 'block' : 'none';
                if (cfZoneGroup) cfZoneGroup.style.display = isDns ? 'block' : 'none';
            });
        }
    }
}

// Save form data to preserve across tab switches
function saveAddSiteFormData() {
    if (!currentSiteData) currentSiteData = {};
    
    const fields = [
        'new_domain', 'new_backend_url', 'new_enabled', 'new_wildcard_subdomains',
        'new_enable_modsecurity', 'new_enable_bot_protection', 'new_enable_rate_limit',
        'new_rate_limit_zone', 'new_custom_rate_limit', 'new_rate_limit_burst',
        'new_enable_gzip', 'new_enable_brotli',
        'new_ssl_enabled', 'new_ssl_challenge_type', 'new_cf_api_token', 'new_cf_zone_id',
        'new_enable_geoip_blocking', 'new_blocked_countries', 'new_ip_whitelist',
        'new_enable_basic_auth', 'new_auth_username', 'new_auth_password'
    ];
    
    fields.forEach(fieldId => {
        const el = document.getElementById(fieldId);
        if (el) {
            const key = fieldId.replace('new_', '');
            if (el.type === 'checkbox') {
                currentSiteData[key] = el.checked ? 1 : 0;
            } else {
                currentSiteData[key] = el.value;
            }
        }
    });
}

function renderAddSiteGeneralTab() {
    const data = currentSiteData || {};
    return `
        <div class="editor-panel">
            <h3>🌐 Basic Configuration</h3>
            <div class="form-group">
                <label>Domain Name *</label>
                <input type="text" id="new_domain" class="form-input" value="${data.domain || ''}" placeholder="example.com">
            </div>
            
            <div class="form-group">
                <label>Backend Server *</label>
                <input type="text" id="new_backend_url" class="form-input" value="${data.backend_url || ''}" placeholder="192.168.1.100:8080">
            </div>
            
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="new_enabled" ${data.enabled !== 0 ? 'checked' : ''}>
                    <span>Site Enabled</span>
                </label>
            </div>
        </div>
    `;
}

function renderAddSiteSecurityTab() {
    const data = currentSiteData || {};
    return `
        <div class="editor-panel">
            <h3>🛡️ Security Settings</h3>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="new_enable_modsecurity" ${data.enable_modsecurity !== 0 ? 'checked' : ''}>
                    <span>Enable ModSecurity WAF</span>
                </label>
            </div>
            
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="new_enable_bot_protection" ${data.enable_bot_protection !== 0 ? 'checked' : ''}>
                    <span>Enable Bot Protection</span>
                </label>
            </div>
            
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="new_enable_rate_limit" ${data.enable_rate_limit !== 0 ? 'checked' : ''}>
                    <span>Enable Rate Limiting</span>
                </label>
            </div>
        </div>
    `;
}

function renderAddSiteSSLTab() {
    const data = currentSiteData || {};
    return `
        <div class="editor-panel">
            <h3>🔐 SSL/TLS</h3>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="new_ssl_enabled" ${data.ssl_enabled ? 'checked' : ''}>
                    <span>Enable SSL/TLS</span>
                </label>
            </div>
            
            <div class="form-group">
                <label>SSL Certificate Type</label>
                <select id="new_ssl_challenge_type" class="form-input">
                    <option value="http-01" ${(!data.ssl_challenge_type || data.ssl_challenge_type === 'http-01') ? 'selected' : ''}>Let's Encrypt (HTTP Challenge)</option>
                    <option value="dns-01" ${data.ssl_challenge_type === 'dns-01' ? 'selected' : ''}>Let's Encrypt (DNS Challenge)</option>
                    <option value="snakeoil" ${data.ssl_challenge_type === 'snakeoil' ? 'selected' : ''}>Self-Signed (Snakeoil)</option>
                    <option value="custom" ${data.ssl_challenge_type === 'custom' ? 'selected' : ''}>Custom Certificate</option>
                </select>
                <small style="color: var(--text-muted); display: block; margin-top: 0.25rem;">
                    Snakeoil: Quick start with self-signed cert (not for production)
                </small>
            </div>
            
            <div class="form-group" id="new_cf_api_group" style="display: ${data.ssl_challenge_type === 'dns-01' ? 'block' : 'none'};">
                <label>Cloudflare API Token</label>
                <input type="password" id="new_cf_api_token" class="form-input" value="${data.cf_api_token || envDefaults.cloudflare?.api_key || ''}" placeholder="Your Cloudflare API token">
                <small style="color: var(--text-muted); display: block; margin-top: 0.25rem;">
                    ${envDefaults.cloudflare?.has_credentials ? '✅ Using default from .env file' : '⚠️ Configure CF_API_KEY in .env for automatic fill'}
                </small>
            </div>
            
            <div class="form-group" id="new_cf_zone_group" style="display: ${data.ssl_challenge_type === 'dns-01' ? 'block' : 'none'};">
                <label>Cloudflare Zone ID</label>
                <input type="text" id="new_cf_zone_id" class="form-input" value="${data.cf_zone_id || ''}" placeholder="Your Cloudflare zone ID">
                <small style="color: var(--text-muted); display: block; margin-top: 0.25rem;">
                    📍 Found in your domain's Overview page on Cloudflare
                </small>
            </div>
        </div>
    `;
}

async function saveNewSite() {
    try {
        const domain = document.getElementById('new_domain')?.value;
        const backend_url = document.getElementById('new_backend_url')?.value;
        
        if (!domain || !backend_url) {
            Toast.show('Please fill in domain and backend server', 'warning');
            return;
        }
        
        const formData = {
            domain,
            backend_url,
            enabled: document.getElementById('new_enabled')?.checked ? 1 : 0,
            enable_modsecurity: document.getElementById('new_enable_modsecurity')?.checked ? 1 : 0,
            enable_bot_protection: document.getElementById('new_enable_bot_protection')?.checked ? 1 : 0,
            enable_rate_limit: document.getElementById('new_enable_rate_limit')?.checked ? 1 : 0,
            ssl_enabled: document.getElementById('new_ssl_enabled')?.checked ? 1 : 0,
            ssl_challenge_type: document.getElementById('new_ssl_challenge_type')?.value || 'http-01',
            cf_api_token: document.getElementById('new_cf_api_token')?.value || null,
            cf_zone_id: document.getElementById('new_cf_zone_id')?.value || null
        };
        
        await apiRequest('/sites', 'POST', formData);
        Toast.show('Site added successfully!', 'success');
        
        // Clear form data
        currentSiteData = null;
        
        // Navigate back to sites page
        setTimeout(() => {
            navigateToPage('sites');
            loadSites();
        }, 1000);
        
    } catch (error) {
        console.error('Error adding site:', error);
        Toast.show('Failed to add site', 'error');
    }
}

window.saveNewSite = saveNewSite;

// Challenge difficulty slider handler
document.addEventListener('DOMContentLoaded', () => {
    const difficultySlider = document.getElementById('siteChallengeDifficulty');
    const difficultyValue = document.getElementById('challengeDifficultyValue');
    
    if (difficultySlider && difficultyValue) {
        difficultySlider.addEventListener('input', (e) => {
            const value = parseInt(e.target.value);
            let label = '';
            if (value <= 14) label = `Easy (${value})`;
            else if (value <= 19) label = `Medium (${value})`;
            else label = `Hard (${value})`;
            difficultyValue.textContent = label;
        });
    }
});


// ==================== Import/Export Functions ====================

window.exportSites = async function() {
    try {
        Toast.show('Exporting sites...', 'info');
        
        const response = await fetch(`${API_BASE_URL}/endpoints/sites-export.php?download=1`, {
            method: 'GET',
            headers: {
                'X-API-Key': API_TOKEN
            }
        });
        
        if (!response.ok) {
            throw new Error('Export failed');
        }
        
        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `waf-sites-export-${new Date().toISOString().split('T')[0]}.json`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
        
        Toast.show('✅ Sites exported successfully!', 'success');
    } catch (error) {
        console.error('Error exporting sites:', error);
        Toast.show('❌ Failed to export sites', 'error');
    }
};

window.showImportModal = function() {
    openModal('importModal');
    document.getElementById('importJson').value = '';
    document.getElementById('importFile').value = '';
    document.getElementById('importMode').value = 'merge';
    document.getElementById('importDryRun').checked = false;
    document.getElementById('importPreview').style.display = 'none';
};

window.handleImportFile = function(event) {
    const file = event.target.files[0];
    if (!file) return;
    
    const reader = new FileReader();
    reader.onload = (e) => {
        document.getElementById('importJson').value = e.target.result;
    };
    reader.readAsText(file);
};

window.importSites = async function() {
    try {
        const jsonText = document.getElementById('importJson').value.trim();
        const mode = document.getElementById('importMode').value;
        const dryRun = document.getElementById('importDryRun').checked;
        
        if (!jsonText) {
            Toast.show('⚠️ Please provide JSON data', 'warning');
            return;
        }
        
        // Validate JSON
        let jsonData;
        try {
            jsonData = JSON.parse(jsonText);
        } catch (e) {
            Toast.show('❌ Invalid JSON format', 'error');
            return;
        }
        
        if (!jsonData.sites || !Array.isArray(jsonData.sites)) {
            Toast.show('❌ Invalid format: Expected {sites: [...]}', 'error');
            return;
        }
        
        // Confirm replace mode
        if (mode === 'replace' && !dryRun) {
            if (!confirm('⚠️ REPLACE MODE will delete all existing sites and import new ones. A backup will be created. Continue?')) {
                return;
            }
        }
        
        Toast.show(`${dryRun ? 'Validating' : 'Importing'} ${jsonData.sites.length} sites...`, 'info');
        
        const queryParams = new URLSearchParams({ mode });
        if (dryRun) queryParams.append('dry_run', '1');
        
        const response = await fetch(`${API_BASE_URL}/endpoints/sites-import.php?${queryParams}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-API-Key': API_TOKEN
            },
            body: jsonText
        });
        
        const result = await response.json();
        
        if (!response.ok) {
            throw new Error(result.error || 'Import failed');
        }
        
        // Show results
        const preview = document.getElementById('importPreview');
        const previewContent = document.getElementById('importPreviewContent');
        preview.style.display = 'block';
        
        let summary = '';
        if (dryRun) {
            summary = `DRY RUN - No changes made\n\n`;
        }
        summary += `Mode: ${result.mode}\n`;
        summary += `Imported: ${result.imported}\n`;
        summary += `Updated: ${result.updated}\n`;
        summary += `Skipped: ${result.skipped}\n`;
        if (result.errors && result.errors.length > 0) {
            summary += `\nErrors:\n${result.errors.join('\n')}`;
        }
        if (result.sites && result.sites.length > 0) {
            summary += `\n\nSites:\n`;
            result.sites.forEach(s => {
                summary += `  - ${s.domain}: ${s.action}\n`;
            });
        }
        previewContent.textContent = summary;
        
        if (dryRun) {
            Toast.show('✅ Validation complete - Review results above', 'success');
        } else {
            Toast.show(`✅ Import complete! ${result.imported} added, ${result.updated} updated`, 'success');
            
            // Refresh sites list
            setTimeout(() => {
                closeModal('importModal');
                loadSites();
            }, 2000);
        }
        
    } catch (error) {
        console.error('Error importing sites:', error);
        Toast.show(`❌ Import failed: ${error.message}`, 'error');
    }
};

// Auto-detect Cloudflare zones for sites
window.detectCloudflareZones = async function(siteId = null, force = false) {
    try {
        const loadingMsg = siteId ? 'Detecting Cloudflare zone...' : 'Detecting Cloudflare zones for all sites...';
        Toast.show(loadingMsg, 'info');
        
        let url = `${API_BASE_URL}/endpoints/cloudflare-zone-detect.php`;
        const params = new URLSearchParams();
        if (siteId) params.append('site_id', siteId);
        if (force) params.append('force', '1');
        
        if (params.toString()) {
            url += '?' + params.toString();
        }
        
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Authorization': 'Bearer ' + API_TOKEN
            }
        });
        
        const result = await response.json();
        
        if (!response.ok) {
            if (result.error === 'Cloudflare credentials not configured') {
                Toast.show('⚠️ Cloudflare API credentials not configured. Set CLOUDFLARE_API_TOKEN in docker-compose.yml', 'warning');
                
                // Show detailed instructions
                const shouldShowHelp = confirm(
                    'Cloudflare API credentials are required.\n\n' +
                    'Would you like to see setup instructions?'
                );
                
                if (shouldShowHelp) {
                    alert(
                        'Setup Instructions:\n\n' +
                        '1. Go to Cloudflare Dashboard → My Profile → API Tokens\n' +
                        '2. Create a token with "Zone:Read" permissions\n' +
                        '3. Add to docker-compose.yml under dashboard service:\n' +
                        '   environment:\n' +
                        '     - CLOUDFLARE_API_TOKEN=your_token_here\n\n' +
                        '4. Restart: docker compose up -d dashboard'
                    );
                }
                return;
            }
            throw new Error(result.message || result.error || 'Detection failed');
        }
        
        if (!result.success) {
            throw new Error(result.message || 'Detection failed');
        }
        
        // Show results
        const total = result.detected + result.failed;
        const resultMsg = `✅ Detected ${result.detected}/${total} zones` + (result.failed > 0 ? ` (${result.failed} not found)` : '');
        
        Toast.show(resultMsg, result.failed > 0 ? 'warning' : 'success');
        
        // Show detailed results if available
        if (result.sites && result.sites.length > 0) {
            console.log('Cloudflare Zone Detection Results:', result.sites);
            
            let details = 'Zone Detection Results:\n\n';
            result.sites.forEach(site => {
                if (site.status === 'detected') {
                    details += `✅ ${site.domain} → ${site.zone_id}\n`;
                } else {
                    details += `❌ ${site.domain}: ${site.message}\n`;
                }
            });
            
            // Show in console for now, could create a modal later
            console.log(details);
        }
        
        // Reload sites list to show updated zone IDs
        if (result.detected > 0) {
            setTimeout(() => loadSites(), 1000);
        }
        
    } catch (error) {
        console.error('Error detecting Cloudflare zones:', error);
        Toast.show(`❌ ${error.message}`, 'error');
    }
};

// ============================================
// Custom Block Rules Functions
// ============================================

async function loadBlockRules() {
    try {
        const rules = await apiRequest('/custom-block-rules');
        const tbody = document.getElementById('blockRulesBody');
        
        if (!rules || rules.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">No custom block rules yet. Click "Add Rule" to create one.</td></tr>';
            return;
        }
        
        tbody.innerHTML = rules.map(rule => `
            <tr>
                <td>
                    <label class="toggle" style="display: inline-block;">
                        <input type="checkbox" ${rule.enabled ? 'checked' : ''} onchange="toggleBlockRule(${rule.id}, this.checked)">
                        <span class="toggle-slider"></span>
                    </label>
                </td>
                <td><strong>${escapeHtml(rule.name)}</strong></td>
                <td><code>${escapeHtml(rule.pattern)}</code></td>
                <td><span class="badge badge-${rule.pattern_type}">${rule.pattern_type}</span></td>
                <td><span class="badge badge-${rule.severity.toLowerCase()}">${rule.severity}</span></td>
                <td><code>${rule.rule_id}</code></td>
                <td>
                    <button class="btn-icon" onclick="editBlockRule(${rule.id})" title="Edit">
                        <span>✏️</span>
                    </button>
                    <button class="btn-icon" onclick="deleteBlockRule(${rule.id})" title="Delete">
                        <span>🗑️</span>
                    </button>
                </td>
            </tr>
        `).join('');
    } catch (error) {
        console.error('Error loading block rules:', error);
        Toast.show('Failed to load block rules', 'error');
    }
}

function showAddBlockRuleModal() {
    document.getElementById('blockRuleModalTitle').textContent = 'Add Block Rule';
    document.getElementById('blockRuleId').value = '';
    document.getElementById('blockRuleName').value = '';
    document.getElementById('blockRulePattern').value = '';
    document.getElementById('blockRulePatternType').value = 'exact';
    document.getElementById('blockRuleSeverity').value = 'CRITICAL';
    document.getElementById('blockRuleMessage').value = 'Access to this path is forbidden';
    document.getElementById('blockRuleEnabled').checked = true;
    
    openModal('blockRuleModal');
}

async function editBlockRule(id) {
    try {
        const rules = await apiRequest('/custom-block-rules');
        const rule = rules.find(r => r.id === id);
        
        if (!rule) {
            Toast.show('Rule not found', 'error');
            return;
        }
        
        document.getElementById('blockRuleModalTitle').textContent = 'Edit Block Rule';
        document.getElementById('blockRuleId').value = rule.id;
        document.getElementById('blockRuleName').value = rule.name;
        document.getElementById('blockRulePattern').value = rule.pattern;
        document.getElementById('blockRulePatternType').value = rule.pattern_type;
        document.getElementById('blockRuleSeverity').value = rule.severity;
        document.getElementById('blockRuleMessage').value = rule.block_message;
        document.getElementById('blockRuleEnabled').checked = rule.enabled;
        
        openModal('blockRuleModal');
    } catch (error) {
        console.error('Error loading block rule:', error);
        Toast.show('Failed to load rule details', 'error');
    }
}

async function saveBlockRule() {
    const id = document.getElementById('blockRuleId').value;
    const name = document.getElementById('blockRuleName').value.trim();
    const pattern = document.getElementById('blockRulePattern').value.trim();
    const patternType = document.getElementById('blockRulePatternType').value;
    const severity = document.getElementById('blockRuleSeverity').value;
    const message = document.getElementById('blockRuleMessage').value.trim();
    const enabled = document.getElementById('blockRuleEnabled').checked;
    
    if (!name || !pattern) {
        Toast.show('Name and pattern are required', 'error');
        return;
    }
    
    try {
        const data = {
            name,
            pattern,
            pattern_type: patternType,
            severity,
            block_message: message,
            enabled: enabled ? 1 : 0
        };
        
        if (id) {
            // Update existing rule
            await apiRequest(`/custom-block-rules/${id}`, 'PUT', data);
            Toast.show('Block rule updated successfully', 'success');
        } else {
            // Create new rule
            await apiRequest('/custom-block-rules', 'POST', data);
            Toast.show('Block rule created successfully', 'success');
        }
        
        closeModal('blockRuleModal');
        await loadBlockRules();
    } catch (error) {
        console.error('Error saving block rule:', error);
        Toast.show(error.message || 'Failed to save block rule', 'error');
    }
}

async function toggleBlockRule(id, enabled) {
    try {
        await apiRequest(`/custom-block-rules/${id}`, 'PUT', { enabled: enabled ? 1 : 0 });
        Toast.show(`Rule ${enabled ? 'enabled' : 'disabled'}`, 'success');
        // Reload to update the rules
        await loadBlockRules();
    } catch (error) {
        console.error('Error toggling block rule:', error);
        Toast.show('Failed to toggle rule', 'error');
        // Reload to reset the toggle state
        await loadBlockRules();
    }
}

async function deleteBlockRule(id) {
    if (!confirm('Are you sure you want to delete this block rule? This will regenerate the ModSecurity rules.')) {
        return;
    }
    
    try {
        await apiRequest(`/custom-block-rules/${id}`, 'DELETE');
        Toast.show('Block rule deleted successfully', 'success');
        await loadBlockRules();
    } catch (error) {
        console.error('Error deleting block rule:', error);
        Toast.show('Failed to delete block rule', 'error');
    }
}

// Modal Helper for displaying raw content (like NGINX configs)
function showModal(title, content) {
    const modal = document.createElement('div');
    modal.className = 'modal-overlay';
    modal.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); display:flex; align-items:center; justify-content:center; z-index:10000;';
    modal.innerHTML = `
        <div style="background:var(--bg-primary); border-radius:8px; max-width:90vw; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
            <div style="padding:1.5rem; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0; color:var(--text-primary);">${title}</h3>
                <button onclick="this.closest('.modal-overlay').remove()" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-muted); padding:0; width:30px; height:30px;">&times;</button>
            </div>
            <div style="padding:1.5rem; overflow:auto; max-height:70vh; white-space:pre-wrap; font-family:monospace; font-size:0.875rem; line-height:1.5; background:var(--bg-secondary); color:var(--text-primary);">${content}</div>
        </div>
    `;
    document.body.appendChild(modal);
    // Close on background click
    modal.addEventListener('click', (e) => {
        if (e.target === modal) modal.remove();
    });
}

// View raw NGINX config for the current site
async function viewRawConfig() {
    const id = currentSiteData?.id || currentSiteId;
    if (!id) {
        Toast.show('No site selected', 'error');
        return;
    }

    Toast.show('Fetching generated NGINX config...', 'info');
    try {
        const resp = await fetch(`${API_BASE_URL}/sites/${id}/config`, {
            headers: { 'Authorization': `Bearer ${API_TOKEN}` },
            cache: 'no-store'
        });
        if (!resp.ok) {
            const err = await resp.json().catch(()=>null);
            throw new Error(err?.error || `HTTP ${resp.status}`);
        }
        const text = await resp.text();
        showModal(`NGINX Config - ${currentSiteData?.domain || 'Site #' + id}`, text);
    } catch (err) {
        console.error('Error fetching config:', err);
        Toast.show(`Failed to fetch config: ${err.message}`, 'error');
    }
}

// ============================================
// Data Cleanup Functions
// ============================================

async function loadCleanupStats() {
    try {
        const response = await apiRequest('/cleanup/stats');
        if (!response || !response.stats) return;
        
        const stats = response.stats;
        
        // Update UI with stats
        const accessLogs = stats.access_logs || {};
        const telemetry = stats.request_telemetry || {};
        const modsec = stats.modsec_events || {};
        const bots = stats.bot_detections || {};
        
        document.getElementById('cleanupAccessLogs').textContent = 
            `${(accessLogs.total || 0).toLocaleString()} total (${(accessLogs.older_than_30_days || 0).toLocaleString()} > 30d)`;
        document.getElementById('cleanupTelemetry').textContent = 
            `${(telemetry.total || 0).toLocaleString()} total (${(telemetry.older_than_30_days || 0).toLocaleString()} > 30d)`;
        document.getElementById('cleanupModSec').textContent = 
            `${(modsec.total || 0).toLocaleString()} total (${(modsec.older_than_30_days || 0).toLocaleString()} > 30d)`;
        document.getElementById('cleanupBots').textContent = 
            `${(bots.total || 0).toLocaleString()} total (${(bots.older_than_30_days || 0).toLocaleString()} > 30d)`;
            
    } catch (error) {
        console.error('Error loading cleanup stats:', error);
    }
}

async function cleanupData(type) {
    const days = document.getElementById('cleanupDays')?.value || 30;
    
    const typeNames = {
        'logs': 'Access Logs',
        'telemetry': 'Telemetry Data',
        'modsec': 'Security Events',
        'all': 'All Data'
    };
    
    const typeName = typeNames[type] || type;
    
    // Confirm action
    const confirmed = confirm(
        `⚠️ Delete ${typeName} older than ${days} days?\n\n` +
        `This action cannot be undone. Make sure you have backups if needed.`
    );
    
    if (!confirmed) return;
    
    try {
        showToast(`Deleting old ${typeName.toLowerCase()}...`, 'info');
        
        const response = await fetch(`${API_BASE_URL}/cleanup/${type}?days=${days}`, {
            method: 'DELETE',
            headers: {
                'Authorization': `Bearer ${API_TOKEN}`,
                'Content-Type': 'application/json'
            }
        });
        
        if (!response.ok) {
            const error = await response.json().catch(() => ({}));
            throw new Error(error.error || `HTTP ${response.status}`);
        }
        
        const result = await response.json();
        
        // Build success message
        let message = `✅ Cleanup complete:\n`;
        if (result.results.access_logs_deleted !== undefined) {
            message += `\n• ${result.results.access_logs_deleted.toLocaleString()} access logs deleted`;
        }
        if (result.results.telemetry_deleted !== undefined) {
            message += `\n• ${result.results.telemetry_deleted.toLocaleString()} telemetry records deleted`;
        }
        if (result.results.modsec_events_deleted !== undefined) {
            message += `\n• ${result.results.modsec_events_deleted.toLocaleString()} security events deleted`;
        }
        if (result.results.bot_detections_deleted !== undefined) {
            message += `\n• ${result.results.bot_detections_deleted.toLocaleString()} bot detections deleted`;
        }
        if (result.results.challenge_passes_deleted !== undefined && result.results.challenge_passes_deleted > 0) {
            message += `\n• ${result.results.challenge_passes_deleted.toLocaleString()} challenge passes deleted`;
        }
        
        showToast(message, 'success');
        
        // Reload cleanup stats
        await loadCleanupStats();
        
    } catch (error) {
        console.error('Cleanup error:', error);
        showToast(`Failed to clean up ${typeName.toLowerCase()}: ${error.message}`, 'error');
    }
}

window.cleanupData = cleanupData;
window.loadCleanupStats = loadCleanupStats;

// System Reset Functions
async function triggerSystemReset(autoRestore) {
    const action = autoRestore ? 'Reset & Auto-Restore' : 'Full Reset';
    const description = autoRestore 
        ? 'This will:\n1. Create a backup\n2. Destroy all containers and volumes\n3. Pull latest images\n4. Start fresh\n5. Automatically import the backup\n\nYour data will be preserved.'
        : 'This will:\n1. Create a backup\n2. Destroy all containers and volumes\n3. Pull latest images\n4. Start fresh\n\nYou will need to MANUALLY restore the backup afterward.';
    
    if (!confirm(`⚠️ ${action}\n\n${description}\n\nAre you ABSOLUTELY SURE?`)) {
        return;
    }
    
    // Second confirmation for extra safety
    const confirmText = autoRestore ? 'RESET AND RESTORE' : 'RESET';
    const userInput = prompt(`Type "${confirmText}" to confirm this destructive action:`);
    
    if (userInput !== confirmText) {
        showToast('Reset cancelled - confirmation text did not match', 'info');
        return;
    }
    
    try {
        showToast(`Starting ${action.toLowerCase()}... This may take several minutes.`, 'info');
        
        const command = autoRestore ? 'reset-restore' : 'reset';
        
        // Trigger reset via updater container
        const response = await fetch(`${API_BASE_URL}/system/reset`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${localStorage.getItem('apiToken')}`
            },
            body: JSON.stringify({ 
                command: command,
                auto_restore: autoRestore
            })
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const result = await response.json();
        
        if (result.success) {
            showToast('✅ System reset initiated! Page will reload in 30 seconds...', 'success');
            setTimeout(() => {
                window.location.reload();
            }, 30000);
        } else {
            throw new Error(result.message || 'Reset failed');
        }
        
    } catch (error) {
        console.error('Reset error:', error);
        showToast(`Failed to trigger reset: ${error.message}`, 'error');
    }
}

window.triggerSystemReset = triggerSystemReset;

// Backup & Restore Functions
let backupInfoLoaded = false;
let backupInfoLoading = false;

async function loadBackupInfo() {
    // Prevent concurrent calls
    if (backupInfoLoading || backupInfoLoaded) return;
    backupInfoLoading = true;
    
    try {
        const response = await apiRequest('/backup/info');
        if (response.success && response.info) {
            const info = response.info;
            const html = `
                <p style="margin: 0.5rem 0; color: #333;"><strong>Estimated Backup Size:</strong> ${info.estimated_size_mb} MB</p>
                <p style="margin: 0.5rem 0; color: #333;"><strong>Sites:</strong> ${info.sites.toLocaleString()}</p>
                <p style="margin: 0.5rem 0; color: #333;"><strong>Settings:</strong> ${info.settings.toLocaleString()}</p>
                <p style="margin: 0.5rem 0; color: #333;"><strong>Telemetry Records (30d):</strong> ${info.telemetry_30d.toLocaleString()}</p>
                <p style="margin: 0.5rem 0; color: #333;"><strong>Bot Detections (30d):</strong> ${info.bot_detections_30d.toLocaleString()}</p>
                <p style="margin: 0.5rem 0; color: #333;"><strong>ModSecurity Events (30d):</strong> ${info.modsec_events_30d.toLocaleString()}</p>
                <p style="margin: 0.5rem 0; color: #333;"><strong>Access Logs (7d):</strong> ${info.access_logs_7d.toLocaleString()}</p>
            `;
            document.getElementById('backupInfo').innerHTML = html;
            backupInfoLoaded = true;
        }
    } catch (error) {
        console.error('Error loading backup info:', error);
        document.getElementById('backupInfo').innerHTML = `<p style="color: #d32f2f;">Error loading backup info</p>`;
    } finally {
        backupInfoLoading = false;
    }
}

async function exportBackup() {
    try {
        showToast('Generating backup... This may take a few moments', 'info');
        
        // Use window.location to trigger download with proper API base URL
        const token = localStorage.getItem('apiToken');
        
        window.location.href = `${API_BASE_URL}/backup/export?token=${encodeURIComponent(token)}`;
        
        setTimeout(() => {
            showToast('Backup download started!', 'success');
        }, 1000);
        
    } catch (error) {
        console.error('Backup export error:', error);
        showToast(`Failed to export backup: ${error.message}`, 'error');
    }
}

async function importBackup() {
    const fileInput = document.getElementById('backupFile');
    if (!fileInput.files || fileInput.files.length === 0) {
        showToast('Please select a backup file', 'error');
        return;
    }
    
    const confirmImport = confirm('Are you sure you want to import this backup? This will modify your current configuration.');
    if (!confirmImport) return;
    
    try {
        showToast('Importing backup... This may take a while', 'info');
        
        const formData = new FormData();
        formData.append('backup', fileInput.files[0]);
        formData.append('import_sites', document.getElementById('import_sites').checked);
        formData.append('import_settings', document.getElementById('import_settings').checked);
        formData.append('import_block_rules', document.getElementById('import_block_rules').checked);
        formData.append('import_rate_limits', document.getElementById('import_rate_limits').checked);
        formData.append('import_telemetry', document.getElementById('import_telemetry').checked);
        formData.append('import_access_logs', document.getElementById('import_access_logs').checked);
        formData.append('import_bot_detections', document.getElementById('import_bot_detections').checked);
        formData.append('import_modsec_events', document.getElementById('import_modsec_events').checked);
        formData.append('merge_mode', document.getElementById('merge_mode').value);
        
        const apiUrl = document.location.origin;
        const token = localStorage.getItem('apiToken');
        
        const response = await fetch(`${apiUrl}/backup/import`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${token}`
            },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            let message = 'Backup imported successfully!';
            if (result.results && result.results.imported) {
                const imported = result.results.imported;
                message += '\n\nImported:';
                if (imported.sites) {
                    message += `\n• ${imported.sites.imported} sites (${imported.sites.skipped} skipped)`;
                }
                if (imported.settings) {
                    message += `\n• ${imported.settings} settings`;
                }
                if (imported.custom_block_rules) {
                    message += `\n• ${imported.custom_block_rules} custom block rules`;
                }
                if (imported.rate_limit_rules) {
                    message += `\n• ${imported.rate_limit_rules} rate limit presets`;
                }
                if (imported.telemetry) {
                    message += `\n• ${imported.telemetry.toLocaleString()} telemetry records`;
                }
                if (imported.access_logs) {
                    message += `\n• ${imported.access_logs.toLocaleString()} access logs`;
                }
                if (imported.bot_detections) {
                    message += `\n• ${imported.bot_detections.toLocaleString()} bot detections`;
                }
                if (imported.modsec_events) {
                    message += `\n• ${imported.modsec_events.toLocaleString()} security events`;
                }
            }
            
            showToast(message, 'success');
            
            // Clear file input
            fileInput.value = '';
            
            // Reload page after 2 seconds to show updated data
            setTimeout(() => {
                location.reload();
            }, 2000);
            
        } else {
            throw new Error(result.error || 'Import failed');
        }
        
    } catch (error) {
        console.error('Backup import error:', error);
        showToast(`Failed to import backup: ${error.message}`, 'error');
    }
}

async function saveBackupSettings() {
    try {
        const backupLocalOnly = document.getElementById('backupLocalOnly').checked;
        
        await apiRequest('/settings', {
            method: 'POST',
            body: JSON.stringify({
                backup_local_only: backupLocalOnly ? '1' : '0'
            })
        });
        
        showToast('Backup security settings saved', 'success');
    } catch (error) {
        console.error('Error saving backup settings:', error);
        showToast('Failed to save backup settings', 'error');
    }
}

async function loadBackupSettings() {
    try {
        const response = await apiRequest('/settings');
        if (response.settings) {
            const backupLocalOnly = response.settings.find(s => s.setting_key === 'backup_local_only');
            if (backupLocalOnly && document.getElementById('backupLocalOnly')) {
                document.getElementById('backupLocalOnly').checked = backupLocalOnly.setting_value === '1';
            }
        }
    } catch (error) {
        console.error('Error loading backup settings:', error);
    }
}

// Expose backup functions globally
window.exportBackup = exportBackup;
window.importBackup = importBackup;
window.loadBackupInfo = loadBackupInfo;
window.saveBackupSettings = saveBackupSettings;
window.loadBackupSettings = loadBackupSettings;

// Config Verification Functions
async function verifySiteConfig(siteData) {
    try {
        const response = await apiRequest('/verify', {
            method: 'POST',
            body: JSON.stringify(siteData)
        });
        
        return response;
    } catch (error) {
        console.error('Config verification error:', error);
        return {
            valid: false,
            issues: [{
                type: 'error',
                category: 'system',
                message: `Verification failed: ${error.message}`
            }],
            warnings: [],
            summary: {
                total_issues: 1,
                total_warnings: 0,
                can_save: false
            }
        };
    }
}

async function verifyCurrentSiteConfig() {
    if (!currentSiteData) {
        showToast('No site data to verify', 'error');
        return;
    }
    
    showToast('Verifying configuration...', 'info');
    
    const result = await verifySiteConfig(currentSiteData);
    
    // Always show feedback, even for valid configs
    if (result.valid && result.issues.length === 0 && result.warnings.length === 0) {
        showToast('✅ Configuration is valid! No issues found.', 'success');
        return;
    }
    
    // Build detailed message for issues/warnings
    let message = '';
    
    if (result.issues && result.issues.length > 0) {
        message += '❌ ERRORS (must fix):\n';
        result.issues.forEach(issue => {
            message += `  • [${issue.category}] ${issue.message}\n`;
        });
    }
    
    if (result.warnings && result.warnings.length > 0) {
        if (message) message += '\n';
        message += '⚠️ WARNINGS (recommended fixes):\n';
        result.warnings.forEach(warning => {
            message += `  • [${warning.category}] ${warning.message}\n`;
        });
    }
    
    if (message) {
        alert(message);
        if (result.valid) {
            showToast('⚠️ Configuration has warnings but can be saved', 'warning');
        } else {
            showToast('❌ Configuration has errors - cannot save!', 'error');
        }
    }
    
    return result;
}

// Expose verification functions
window.verifySiteConfig = verifySiteConfig;
window.verifyCurrentSiteConfig = verifyCurrentSiteConfig;

// Load backup info when settings page loads
let backupObserver = null;
document.addEventListener('DOMContentLoaded', () => {
    // Check if we're on settings page
    if (backupObserver) backupObserver.disconnect();
    backupObserver = new MutationObserver(() => {
        const backupInfoEl = document.getElementById('backupInfo');
        if (backupInfoEl && !backupInfoLoaded && !backupInfoLoading) {
            loadBackupInfo();
            loadBackupSettings(); // Also load backup security settings
            // Disconnect after first successful load attempt
            if (backupObserver) {
                backupObserver.disconnect();
                backupObserver = null;
            }
        }
    });
    backupObserver.observe(document.body, { childList: true, subtree: true });
});

