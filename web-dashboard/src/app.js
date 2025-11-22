// CatWAF Dashboard - Catboy Themed Web Interface
// API Configuration
const API_BASE_URL = '/api';
const API_TOKEN = localStorage.getItem('api_token') || '';

// Toast system is now provided by toast.js - remove duplicate definition
// showToast is defined globally by toast.js

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
        showToast('Quick search coming soon! 🔍', 'info');
    }
    
    // Ctrl/Cmd + S: Save (if in edit mode)
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        const saveBtn = document.querySelector('.save-site-btn:not([disabled])');
        if (saveBtn && saveBtn.offsetParent !== null) {
            e.preventDefault();
            saveBtn.click();
            showToast('Saving... 💾', 'info');
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
        case 'bot-whitelist':
            await loadBotWhitelist();
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
            showToast('Authentication failed. Please log in again.', 'error');
            localStorage.removeItem('api_token');
            setTimeout(() => window.location.reload(), 2000);
            return null;
        }
        
        if (response.status === 403) {
            showToast('Permission denied. Insufficient privileges.', 'error');
            return null;
        }
        
        if (response.status === 404) {
            showToast('Resource not found. Please refresh.', 'warning');
            return null;
        }
        
        if (response.status === 429) {
            showToast('Too many requests. Please slow down!', 'warning');
            return null;
        }
        
        if (response.status >= 500) {
            showToast('Server error. Please try again later.', 'error');
            return null;
        }
        
        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            const errorMsg = errorData.error || errorData.message || response.statusText;
            showToast(`Error: ${errorMsg}`, 'error');
            throw new Error(errorMsg);
        }
        
        return await response.json();
    } catch (error) {
        console.error('API request error:', error);
        
        // Network error
        if (error.name === 'TypeError' && error.message.includes('fetch')) {
            showToast('Network error. Check your connection.', 'error');
        } else {
            showToast('Request failed. Check console for details.', 'error');
        }
        
        return null;
    }
}

// Dashboard Data Loading
async function loadDashboardData(range = '24h') {
    try {
        const response = await apiRequest(`/stats?period=${range}`);
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
    console.log('📊 updateCharts called with data:', data);
    
    // Check if we have the new format (labels, status_2xx, etc.) or old format (requests_over_time)
    const hasNewFormat = data.labels && Array.isArray(data.labels);
    
    const trafficData = hasNewFormat ? {
        labels: data.labels || [],
        status_2xx: data.status_2xx || [],
        status_3xx: data.status_3xx || [],
        status_4xx: data.status_4xx || [],
        status_5xx: data.status_5xx || []
    } : {
        // Fallback: transform old requests_over_time format
        labels: (data.requests_over_time || []).map(r => {
            const date = new Date(r.hour);
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }),
        requests: (data.requests_over_time || []).map(r => parseInt(r.count)),
        status_2xx: [],
        status_3xx: [],
        status_4xx: [],
        status_5xx: []
    };
    
    console.log('📈 Traffic data prepared:', trafficData);
    
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
    
    console.log('🎨 updateTrafficChart with data:', data);
    
    // Use actual data or show empty chart
    const hasData = data && data.labels && data.labels.length > 0;
    const labels = hasData ? data.labels : ['No Data'];
    
    // Group requests by status code ranges for stacked chart
    const status2xx = hasData && data.status_2xx && data.status_2xx.length > 0 
        ? data.status_2xx 
        : (hasData && data.requests ? data.requests : [0]);
    const status3xx = hasData && data.status_3xx && data.status_3xx.length > 0 
        ? data.status_3xx 
        : [0];
    const status4xx = hasData && data.status_4xx && data.status_4xx.length > 0 
        ? data.status_4xx 
        : [0];
    const status5xx = hasData && data.status_5xx && data.status_5xx.length > 0 
        ? data.status_5xx 
        : [0];
    
    console.log('📊 Chart datasets:', { status2xx, status3xx, status4xx, status5xx });
    
    charts.traffic = new Chart(ctx.getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: '2xx Success',
                    data: status2xx,
                    backgroundColor: 'rgba(34, 197, 94, 0.7)',
                    borderColor: 'rgba(34, 197, 94, 1)',
                    borderWidth: 1
                },
                {
                    label: '3xx Redirect',
                    data: status3xx,
                    backgroundColor: 'rgba(107, 114, 128, 0.7)',
                    borderColor: 'rgba(107, 114, 128, 1)',
                    borderWidth: 1
                },
                {
                    label: '4xx Client Error',
                    data: status4xx,
                    backgroundColor: 'rgba(251, 191, 36, 0.7)',
                    borderColor: 'rgba(251, 191, 36, 1)',
                    borderWidth: 1
                },
                {
                    label: '5xx Server Error',
                    data: status5xx,
                    backgroundColor: 'rgba(239, 68, 68, 0.7)',
                    borderColor: 'rgba(239, 68, 68, 1)',
                    borderWidth: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: { 
                    display: true,
                    position: 'top',
                    labels: { color: '#B4B4C8' }
                },
                tooltip: {
                    callbacks: {
                        footer: function(tooltipItems) {
                            let sum = 0;
                            tooltipItems.forEach(function(tooltipItem) {
                                sum += tooltipItem.parsed.y;
                            });
                            return 'Total: ' + sum;
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    grid: { color: '#363650' },
                    ticks: { color: '#B4B4C8' }
                },
                y: { 
                    stacked: true,
                    beginAtZero: true,
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
        showToast('❌ Failed to load sites', 'error');
    }
}

function createSiteCard(site) {
    const div = document.createElement('div');
    div.className = 'site-card';
    
    // Generate status badges
    const badges = [];
    
    // Config status badge
    if (site.enabled) {
        badges.push('<span class="status-badge badge-success" title="Site is active">✓ Active</span>');
    } else {
        badges.push('<span class="status-badge badge-inactive" title="Site is disabled">⏸ Inactive</span>');
    }
    
    // SSL status badge
    if (site.ssl_enabled) {
        badges.push('<span class="status-badge badge-success" title="SSL/TLS enabled">🔒 SSL</span>');
    } else {
        badges.push('<span class="status-badge badge-info" title="HTTP only">🌐 HTTP</span>');
    }
    
    // Rate limiting badge
    if (site.rate_limit_zone && site.rate_limit_zone !== 'none') {
        badges.push('<span class="status-badge badge-info" title="Rate limiting enabled">🛡️ Rate Limited</span>');
    }
    
    // HTTP/2 badge
    if (site.http2_enabled) {
        badges.push('<span class="status-badge badge-info" title="HTTP/2 enabled">⚡ HTTP/2</span>');
    }
    
    // WebSocket badge
    if (site.websocket_enabled) {
        badges.push('<span class="status-badge badge-info" title="WebSocket support enabled">🔌 WebSocket</span>');
    }
    
    // Cloudflare IP headers badge
    if (site.cf_ip_headers) {
        badges.push('<span class="status-badge badge-info" title="Cloudflare IP headers enabled">☁️ CF Headers</span>');
    }
    
    div.innerHTML = `
        <div class="site-header">
            <div class="site-domain">${site.domain}</div>
        </div>
        <div class="site-badges">
            ${badges.join('')}
        </div>
        <div class="site-info">
            <div><strong>Backend:</strong> ${site.backend_url || site.backend || 'N/A'}</div>
            ${site.rate_limit_zone ? `<div><strong>Rate Limit:</strong> ${site.rate_limit_zone}</div>` : ''}
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
        showToast('❌ Failed to load bans', 'error');
    }
}

function showBanModal() {
    document.getElementById('banIp').value = '';
    document.getElementById('banReason').value = '';
    document.getElementById('banDuration').value = '3600';
    document.getElementById('banPermanent').checked = false;
    openModal('banModal');
}

async function banIp() {
    const ip = document.getElementById('banIp').value.trim();
    const reason = document.getElementById('banReason').value.trim();
    const isPermanent = document.getElementById('banPermanent').checked;
    const duration = isPermanent ? 0 : parseInt(document.getElementById('banDuration').value);
    
    if (!ip) {
        showToast('IP address is required', 'error');
        return;
    }
    
    try {
        await apiRequest('/bans', {
            method: 'POST',
            body: JSON.stringify({ ip_address: ip, reason, duration })
        });
        
        showToast('IP banned successfully', 'success');
        closeModal('banModal');
        await loadBans();
    } catch (error) {
        console.error('Error banning IP:', error);
        showToast('Failed to ban IP address', 'error');
    }
}

async function unbanIP(ip) {
    if (!confirm(`Are you sure you want to unban ${ip}?`)) return;
    
    try {
        await apiRequest(`/bans/${ip}`, { method: 'DELETE' });
        await loadBans();
    } catch (error) {
        console.error('Error unbanning IP:', error);
        showToast('Failed to unban IP address', 'error');
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
        showToast('❌ Failed to load security events', 'error');
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
        
        showToast(`Auto-ban ${enableAutoBan ? 'enabled' : 'disabled'}`, 'success');
    } catch (error) {
        console.error('Error updating auto-ban settings:', error);
        showToast('Failed to update auto-ban settings', 'error');
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
        
        // Load development mode setting
        const devModeCheckbox = document.getElementById('dev_mode_headers');
        if (devModeCheckbox && settings.dev_mode_headers !== undefined) {
            devModeCheckbox.checked = settings.dev_mode_headers === '1' || settings.dev_mode_headers === 'true';
        }
        
        // Load certificate status
        loadCertificateStatus();
        
        // Load custom block rules
        loadBlockRules();
        
        // Load cleanup stats
        loadCleanupStats();
        
        // Setup Settings tabs
        setupSettingsTabs();
    } catch (error) {
        console.error('Error loading settings:', error);
    }
}

// Setup Settings Page Tabs
function setupSettingsTabs() {
    const settingsPage = document.getElementById('settings-page');
    if (!settingsPage) return;
    
    const tabButtons = settingsPage.querySelectorAll('.tabs .tab[data-tab]');
    const tabContents = settingsPage.querySelectorAll('.tab-content[id]');
    
    if (tabButtons.length === 0) {
        console.log('No settings tabs found');
        return;
    }
    
    // Remove any existing listeners (prevent duplicates)
    tabButtons.forEach(btn => {
        const newBtn = btn.cloneNode(true);
        btn.parentNode.replaceChild(newBtn, btn);
    });
    
    // Re-select after cloning
    const freshTabButtons = settingsPage.querySelectorAll('.tabs .tab[data-tab]');
    
    freshTabButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const targetTab = btn.getAttribute('data-tab');
            
            // Update active button
            freshTabButtons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            
            // Update active content
            tabContents.forEach(content => {
                if (content.id === targetTab) {
                    content.classList.add('active');
                } else {
                    content.classList.remove('active');
                }
            });
            
            console.log('Switched to settings tab:', targetTab);
        });
    });
    
    // Activate first tab by default
    if (freshTabButtons.length > 0 && tabContents.length > 0) {
        freshTabButtons[0].classList.add('active');
        tabContents[0].classList.add('active');
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
        
        showToast('Settings saved successfully!', 'success');
    } catch (error) {
        console.error('Error saving settings:', error);
        showToast('Failed to save settings', 'error');
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
        
        showToast('✅ WAF settings saved successfully!', 'success');
    } catch (error) {
        console.error('Error saving WAF settings:', error);
        showToast('❌ Failed to save WAF settings', 'error');
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
        showToast('Please fill in SMTP server and username', 'warning');
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
        
        showToast('✅ Email settings saved successfully!', 'success');
    } catch (error) {
        console.error('Error saving email settings:', error);
        showToast('❌ Failed to save email settings', 'error');
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
    
    const toastId = showToast(`⏳ Issuing certificate for ${domain}... This may take 30-60 seconds.`, 'info', 60000);
    
    try {
        const response = await apiRequest(`/certificates/${domain}`, {
            method: 'POST'
        });
        showToast(`✅ Certificate issued successfully for ${domain}!`, 'success');
        loadCertificateStatus();
        // Reload sites list to update SSL status
        if (typeof loadSites === 'function') loadSites();
    } catch (error) {
        console.error('Error issuing certificate:', error);
        showToast(`❌ Failed to issue certificate: ${error.message}`, 'error', 10000);
    }
};

window.renewCertificate = async (domain) => {
    if (!confirm(`Renew SSL certificate for ${domain}?`)) {
        return;
    }
    
    const toastId = showToast(`⏳ Renewing certificate for ${domain}... This may take 30-60 seconds.`, 'info', 60000);
    
    try {
        const response = await apiRequest(`/certificates/${domain}/renew`, {
            method: 'POST'
        });
        showToast(`✅ Certificate renewed successfully for ${domain}!`, 'success');
        loadCertificateStatus();
        // Reload sites list to update SSL status
        if (typeof loadSites === 'function') loadSites();
    } catch (error) {
        console.error('Error renewing certificate:', error);
        showToast(`❌ Failed to renew certificate: ${error.message}`, 'error', 10000);
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

window.rescanAllCertificates = async () => {
    if (!confirm('Rescan and fix ALL SSL certificates?\n\nThis will:\n- Check if sites are using snakeoil when they should have real certificates\n- Copy certificates from acme.sh if available\n- Fix configuration inconsistencies\n\nThis process is safe and won\'t issue new certificates.\n\nContinue?')) {
        return;
    }
    
    showToast('🔍 Rescanning all certificates...', 'info', 30000);
    
    try {
        const response = await apiRequest('/certificates/rescan', {
            method: 'POST'
        });
        
        if (response.success) {
            let message = `Scanned ${response.total} certificates`;
            if (response.fixed > 0) {
                message += `, fixed ${response.fixed}`;
            }
            
            showToast(`✅ ${message}`, response.fixed > 0 ? 'success' : 'info');
            
            // Show detailed results
            if (response.results && response.results.length > 0) {
                console.log('Certificate rescan results:', response.results);
                const needsIssuance = response.results.filter(r => r.action === 'needs_issuance');
                if (needsIssuance.length > 0) {
                    setTimeout(() => {
                        const domains = needsIssuance.map(s => s.domain).join('\\n- ');
                        alert(`The following domains need certificates to be issued:\n\n- ${domains}\n\nPlease use the "Renew Now" button for each domain.`);
                    }, 1000);
                }
            }
            
            loadCertificateStatus();
            loadSites();
        }
    } catch (error) {
        console.error('Error rescanning certificates:', error);
        showToast(`❌ Failed to rescan certificates: ${error.message}`, 'error');
    }
};

window.rescanCertificate = async (domain) => {
    showToast(`🔍 Rescanning certificate for ${domain}...`, 'info', 10000);
    
    try {
        const response = await apiRequest(`/certificates/rescan/${domain}`, {
            method: 'POST'
        });
        
        if (response.success) {
            const result = response.result;
            let message = `Certificate: ${result.current_cert_type}`;
            
            if (result.action === 'copied_from_acme') {
                message = `✅ Fixed ${domain} - copied Let's Encrypt certificate from acme.sh`;
                showToast(message, 'success');
            } else if (result.action === 'reissued') {
                message = `✅ Issued new certificate for ${domain}`;
                showToast(message, 'success');
            } else if (result.action === 'none') {
                if (result.should_have_real_cert && result.current_cert_type === 'snakeoil') {
                    message = `⚠️ ${domain} is using snakeoil but should have a real certificate. Please issue a certificate.`;
                    showToast(message, 'warning');
                } else {
                    message = `✅ ${domain} certificate is correctly configured`;
                    showToast(message, 'success');
                }
            }
            
            loadCertificateInfo();
            loadSites();
        }
    } catch (error) {
        console.error('Error rescanning certificate:', error);
        showToast(`❌ Failed to rescan: ${error.message}`, 'error');
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
        showToast('✅ API token saved!', 'success');
    } else {
        showToast('Please enter a token', 'warning');
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
            showToast('Failed to add site', 'error');
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
        showToast('Please fill in all required fields', 'warning');
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
        
        showToast('✅ Site added successfully!', 'success');
        await loadSites();
    } catch (error) {
        console.error('Error adding site:', error);
        showToast('❌ Failed to add site: ' + error.message, 'error');
    }
};
window.banIp = async () => {
    const ip = document.getElementById('banIp').value.trim();
    const reason = document.getElementById('banReason').value.trim();
    const duration = parseInt(document.getElementById('banDuration').value);
    const permanent = document.getElementById('banPermanent').checked;
    
    if (!ip) {
        showToast('Please enter an IP address', 'warning');
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
        
        showToast('✅ IP banned successfully!', 'success');
        await loadBans();
    } catch (error) {
        console.error('Error banning IP:', error);
        showToast('❌ Failed to ban IP: ' + error.message, 'error');
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
            showToast(`✅ Site copied as: ${response.domain}`, 'success');
            await loadSites();
            
            // Open editor for the new copy
            if (response.id) {
                editSite(response.id);
            }
        }
    } catch (error) {
        console.error('Error copying site:', error);
        showToast('❌ Failed to copy site', 'error');
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
        showToast('Please fill in all required fields', 'warning');
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
        showToast('✅ Site updated successfully!', 'success');
    } catch (error) {
        console.error('Error updating site:', error);
        showToast('❌ Failed to update site: ' + error.message, 'error');
    }
}

window.deleteSite = async (id) => {
    if (!confirm('Are you sure you want to delete this site?')) return;
    
    try {
        await apiRequest(`/sites/${id}`, { method: 'DELETE' });
        await loadSites();
    } catch (error) {
        console.error('Error deleting site:', error);
        showToast('Failed to delete site', 'error');
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
        // Enable GeoIP lookup to show country/city
        const response = await apiRequest(`/modsec/events?severity=${severity}&limit=50&geoip=true`);
        const tbody = document.getElementById('modsecEventsBody');
        
        if (!response || response.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">No security events</td></tr>';
            return;
        }
        
        tbody.innerHTML = response.map(event => `
            <tr>
                <td>${new Date(event.timestamp).toLocaleString()}</td>
                <td>
                    ${event.flag || '🌐'} 
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
        
        // Load bot statistics table
        await loadBotStats();
    } catch (error) {
        console.error('Error loading bot protection data:', error);
    }
}

async function loadBotActivityChart() {
    try {
        const response = await apiRequest('/bots?limit=1000');
        // API returns array directly, not { bots: [...] }
        const bots = Array.isArray(response) ? response : (response?.bots || []);
        
        console.log('🤖 Bot Activity Chart - Loading data:', bots.length, 'bots');
        
        if (bots.length === 0) {
            console.log('⚠️ No bot detections found for chart');
            // Still create empty chart so user knows it exists
            const ctx = document.getElementById('botActivityChart');
            if (!ctx) {
                console.error('❌ Bot Activity Chart canvas not found!');
                return;
            }
            
            if (botActivityChartInstance) {
                botActivityChartInstance.destroy();
            }
            
            botActivityChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: ['No Data'],
                    datasets: [{
                        label: 'Bot Activity',
                        data: [0],
                        borderColor: '#4dabf7',
                        backgroundColor: 'rgba(77, 171, 247, 0.1)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: true, labels: { color: '#B4B4C8' } }
                    },
                    scales: {
                        y: { beginAtZero: true, grid: { color: '#363650' }, ticks: { color: '#B4B4C8' } },
                        x: { grid: { color: '#363650' }, ticks: { color: '#B4B4C8' } }
                    }
                }
            });
            
            console.log('✅ Empty bot chart created');
            return;
        }
        
        // Group by hour AND by bot name
        const hourlyData = {};
        const botCounts = {}; // Track total per bot to find top bots
        
        bots.forEach(bot => {
            const timestamp = new Date(bot.timestamp || bot.created_at);
            const hourKey = timestamp.toISOString().substring(0, 13); // YYYY-MM-DDTHH
            const botName = bot.bot_name || 'unknown';
            
            if (!hourlyData[hourKey]) {
                hourlyData[hourKey] = { total: 0, bots: {} };
            }
            
            hourlyData[hourKey].total++;
            
            // Track per-bot data
            if (!hourlyData[hourKey].bots[botName]) {
                hourlyData[hourKey].bots[botName] = 0;
            }
            hourlyData[hourKey].bots[botName]++;
            
            // Count total per bot
            botCounts[botName] = (botCounts[botName] || 0) + 1;
        });
        
        // Find top 5 most active bots
        const topBots = Object.entries(botCounts)
            .sort((a, b) => b[1] - a[1])
            .slice(0, 5)
            .map(([name]) => name);
        
        console.log('📊 Top 5 bots:', topBots, 'Total bots:', Object.keys(botCounts).length);
        
        // Prepare chart data
        const sortedHours = Object.keys(hourlyData).sort();
        const labels = sortedHours.map(key => {
            const date = new Date(key + ':00:00');
            return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        });
        
        const totalData = sortedHours.map(key => hourlyData[key].total);
        
        // Create datasets for top bots
        const botColors = [
            { border: '#4dabf7', bg: 'rgba(77, 171, 247, 0.1)' },   // Blue
            { border: '#51cf66', bg: 'rgba(81, 207, 102, 0.1)' },   // Green
            { border: '#ff6b6b', bg: 'rgba(255, 107, 107, 0.1)' },  // Red
            { border: '#ffd43b', bg: 'rgba(255, 212, 59, 0.1)' },   // Yellow
            { border: '#9775fa', bg: 'rgba(151, 117, 250, 0.1)' }   // Purple
        ];
        
        const datasets = topBots.map((botName, index) => {
            const color = botColors[index % botColors.length];
            const data = sortedHours.map(hour => hourlyData[hour].bots[botName] || 0);
            
            return {
                label: botName,
                data: data,
                borderColor: color.border,
                backgroundColor: color.bg,
                tension: 0.4,
                fill: true,
                pointRadius: 2,
                pointHoverRadius: 4
            };
        });
        
        // Add total as dashed line
        datasets.push({
            label: 'Total',
            data: totalData,
            borderColor: '#868e96',
            backgroundColor: 'transparent',
            tension: 0.4,
            fill: false,
            borderDash: [5, 5],
            pointRadius: 0,
            pointHoverRadius: 3
        });
        
        console.log('📊 Bot chart data prepared:', labels.length, 'data points,', datasets.length, 'datasets');
        
        // Destroy existing chart if it exists
        if (botActivityChartInstance) {
            botActivityChartInstance.destroy();
        }
        
        // Create new chart
        const ctx = document.getElementById('botActivityChart');
        if (!ctx) {
            console.error('❌ Bot Activity Chart canvas element not found in DOM!');
            return;
        }
        
        console.log('🎨 Creating bot activity chart...');
        
        botActivityChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { color: '#B4B4C8' }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: '#363650' },
                        ticks: { stepSize: 1, color: '#B4B4C8' }
                    },
                    x: {
                        grid: { color: '#363650' },
                        ticks: { color: '#B4B4C8' }
                    }
                }
            }
        });
        
        console.log('✅ Bot Activity Chart created successfully!');
    } catch (error) {
        console.error('❌ Error loading bot activity chart:', error);
    }
}

async function loadBotDetections() {
    try {
        const response = await apiRequest('/bots/detections?limit=50');
        const tbody = document.getElementById('botDetectionsBody');
        
        if (!response || response.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">No bot detections yet</td></tr>';
            return;
        }
        
        tbody.innerHTML = response.map(bot => {
            const botName = bot.bot_name || 'unknown';
            const botNameClean = botName.replace(/[^a-zA-Z0-9-]/g, '');
            
            return `
            <tr>
                <td>${new Date(bot.timestamp).toLocaleString()}</td>
                <td><strong>${escapeHtml(botName)}</strong></td>
                <td><code>${bot.ip_address}</code></td>
                <td style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${escapeHtml(bot.user_agent || '')}">
                    ${escapeHtml(bot.user_agent || 'Unknown')}
                </td>
                <td>
                    <span class="status-badge badge-${bot.bot_type === 'good' ? 'success' : 'danger'}">
                        ${bot.bot_type || 'unknown'}
                    </span>
                </td>
                <td>
                    <span class="status-badge badge-${bot.action === 'allowed' ? 'success' : 'danger'}">
                        ${bot.action || 'unknown'}
                    </span>
                </td>
                <td>
                    <button class="btn-icon btn-success" onclick="quickAllowBot('${botNameClean}')" title="Allow this bot">
                        ✓
                    </button>
                    <button class="btn-icon btn-warning" onclick="quickFlagBot('${botNameClean}')" title="Flag as suspicious">
                        ⚠
                    </button>
                    <button class="btn-icon btn-danger" onclick="quickBlockBot('${botNameClean}')" title="Block this bot">
                        ✕
                    </button>
                </td>
            </tr>
        `;
        }).join('');
    } catch (error) {
        console.error('Error loading bot detections:', error);
        document.getElementById('botDetectionsBody').innerHTML = 
            '<tr><td colspan="7" style="text-align: center; color: #ef4444;">Error loading data</td></tr>';
    }
}

async function loadBotStats() {
    try {
        const response = await apiRequest('/bots/bot-stats');
        const tbody = document.getElementById('botStatsBody');
        
        if (!response || response.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No bot statistics available</td></tr>';
            return;
        }
        
        tbody.innerHTML = response.map(bot => {
            // Format last seen
            const lastSeen = bot.last_seen ? new Date(bot.last_seen).toLocaleString() : 'Never';
            
            // Action badge
            let actionClass = 'badge-info';
            if (bot.action === 'allowed') actionClass = 'badge-success';
            if (bot.action === 'blocked') actionClass = 'badge-danger';
            if (bot.action === 'flagged') actionClass = 'badge-warning';
            
            return `
            <tr>
                <td><strong>${bot.bot_name || 'Unknown'}</strong></td>
                <td style="text-align: center;">${bot.count_1h || 0}</td>
                <td style="text-align: center;"><strong>${bot.count_24h || 0}</strong></td>
                <td style="text-align: center;">${bot.count_7d || 0}</td>
                <td><span class="status-badge ${actionClass}">${bot.action || 'unknown'}</span></td>
                <td style="font-size: 0.85em; color: #6b7280;">${lastSeen}</td>
            </tr>
            `;
        }).join('');
    } catch (error) {
        console.error('Error loading bot stats:', error);
        document.getElementById('botStatsBody').innerHTML = 
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
            document.getElementById('medianResponseTime').textContent = 
                stats.median_response_time ? `${stats.median_response_time}ms` : 'N/A';
            document.getElementById('requestsPerMinute').textContent = stats.requests_per_minute || '0';
            document.getElementById('errorRate').textContent = 
                stats.error_rate ? `${stats.error_rate}%` : 'N/A';
        }
        
        // Load slowest endpoints
        await loadSlowestEndpoints();
        
        // Load site performance (new)
        await loadSitePerformance();
        
        // Load backend performance
        await loadBackendPerformance();
        
        // Load top accessed endpoints
        await loadTopEndpoints();
        
        // Load top error codes
        await loadTopErrors();
        
        // Load top 404s
        await loadTop404s();
        
        // Load response time distribution chart
        await loadResponseTimeChart();
        
        // Load site list for recent requests dropdown
        await loadRecentRequestsSiteList();
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
        const excludeParam = excludedSites.length > 0 ? `&exclude=${excludedSites.join(',')}` : '';
        const response = await apiRequest(`/telemetry/slowest-endpoints?limit=50${excludeParam}`);
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

async function loadTopEndpoints() {
    try {
        const timeRange = document.getElementById('telemetryTimeRange')?.value || '24h';
        const response = await apiRequest(`/telemetry/top-endpoints?range=${timeRange}`);
        const tbody = document.getElementById('topEndpointsBody');
        
        if (!response || response.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align: center;">No data available</td></tr>';
            return;
        }
        
        // Filter out challenge page and other internal endpoints
        const filtered = response.filter(ep => {
            const path = (ep.path || '').toLowerCase();
            return !path.includes('/challenge.html') && !path.includes('/.well-known/');
        });
        
        tbody.innerHTML = filtered.map(endpoint => {
            // Truncate path if too long
            let displayPath = endpoint.path || '/';
            if (displayPath.length > 60) {
                displayPath = displayPath.substring(0, 60) + '...';
            }
            
            return `
            <tr>
                <td><strong>${endpoint.domain || 'unknown'}</strong></td>
                <td><code title="${endpoint.path}">${displayPath}</code></td>
                <td><span style="font-weight: 600; color: #3b82f6;">${endpoint.hit_count.toLocaleString()}</span></td>
                <td>${endpoint.avg_response_time}ms</td>
            </tr>
            `;
        }).join('');
    } catch (error) {
        console.error('Error loading top endpoints:', error);
        document.getElementById('topEndpointsBody').innerHTML = 
            '<tr><td colspan="4" style="text-align: center; color: #ef4444;">Error loading data</td></tr>';
    }
}

async function loadTopErrors() {
    try {
        const timeRange = document.getElementById('telemetryTimeRange')?.value || '24h';
        const response = await apiRequest(`/telemetry/error-summary?range=${timeRange}`);
        const tbody = document.getElementById('topErrorsBody');
        
        if (!response || response.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">No errors found</td></tr>';
            return;
        }
        
        tbody.innerHTML = response.map(error => {
            // Color based on error type
            let badgeClass = 'badge-warning';
            if (error.status_code >= 500) badgeClass = 'badge-danger';
            
            return `
            <tr>
                <td><span class="status-badge ${badgeClass}" style="font-size: 0.85em;">${error.status_code}</span></td>
                <td>${error.description}</td>
                <td><span style="font-weight: 600; color: var(--danger);">${error.count.toLocaleString()}</span></td>
                <td>${error.affected_domains}</td>
                <td>${error.unique_ips}</td>
            </tr>
            `;
        }).join('');
    } catch (error) {
        console.error('Error loading top errors:', error);
        document.getElementById('topErrorsBody').innerHTML = 
            '<tr><td colspan="5" style="text-align: center; color: #ef4444;">Error loading data</td></tr>';
    }
}

async function loadTop404s() {
    try {
        const timeRange = document.getElementById('telemetryTimeRange')?.value || '24h';
        const response = await apiRequest(`/telemetry/top-404s?range=${timeRange}`);
        const tbody = document.getElementById('top404sBody');
        
        if (!response || response.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align: center;">No 404 errors found</td></tr>';
            return;
        }
        
        tbody.innerHTML = response.map(endpoint => {
            // Truncate path if too long
            let displayPath = endpoint.path || '/';
            if (displayPath.length > 60) {
                displayPath = displayPath.substring(0, 60) + '...';
            }
            
            // Format timestamp
            let lastSeen = 'Unknown';
            if (endpoint.last_seen) {
                const date = new Date(endpoint.last_seen);
                lastSeen = date.toLocaleString();
            }
            
            return `
            <tr>
                <td><strong>${endpoint.domain || 'unknown'}</strong></td>
                <td><code title="${endpoint.path}">${displayPath}</code></td>
                <td><span style="font-weight: 600; color: #ef4444;">${endpoint.hit_count.toLocaleString()}</span></td>
                <td style="font-size: 0.85em; color: #6b7280;">${lastSeen}</td>
            </tr>
            `;
        }).join('');
    } catch (error) {
        console.error('Error loading top 404s:', error);
        document.getElementById('top404sBody').innerHTML = 
            '<tr><td colspan="4" style="text-align: center; color: #ef4444;">Error loading data</td></tr>';
    }
}

// Site performance metrics
let excludedSites = [];

async function loadSitePerformance() {
    try {
        const timeRange = document.getElementById('telemetryTimeRange')?.value || '24h';
        const excludeParam = excludedSites.length > 0 ? `&exclude=${excludedSites.join(',')}` : '';
        const response = await apiRequest(`/telemetry/site-performance?range=${timeRange}${excludeParam}`);
        const tbody = document.getElementById('sitePerformanceBody');
        
        if (!response || response.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No site data available</td></tr>';
            return;
        }
        
        tbody.innerHTML = response.map(site => {
            const errorBadgeClass = site.error_rate > 5 ? 'badge-danger' : site.error_rate > 1 ? 'badge-warning' : 'badge-success';
            
            return `
            <tr>
                <td><strong>${site.domain}</strong></td>
                <td>${site.hits.toLocaleString()}</td>
                <td>${site.avg_response}ms</td>
                <td style="font-size: 0.85em; color: #6b7280;">${site.min_response}ms / ${site.max_response}ms</td>
                <td>
                    <span class="badge badge-danger" style="margin-right: 4px;" title="Server Errors (5xx)">${site.server_errors}</span>
                    <span class="badge badge-warning" title="Client Errors (4xx)">${site.client_errors}</span>
                </td>
                <td>
                    <span class="badge ${errorBadgeClass}">
                        ${site.error_rate}%
                    </span>
                </td>
            </tr>
            `;
        }).join('');
    } catch (error) {
        console.error('Error loading site performance:', error);
        document.getElementById('sitePerformanceBody').innerHTML = 
            '<tr><td colspan="6" style="text-align: center; color: #ef4444;">Error loading data</td></tr>';
    }
}

// Site filter for slowest endpoints
async function toggleSiteFilter() {
    const panel = document.getElementById('siteFilterPanel');
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        await loadSiteFilterCheckboxes();
    } else {
        panel.style.display = 'none';
    }
}

async function loadSiteFilterCheckboxes() {
    try {
        const response = await apiRequest('/sites');
        const sites = response?.sites || [];
        const container = document.getElementById('siteFilterCheckboxes');
        
        if (!sites || sites.length === 0) {
            container.innerHTML = '<div style="text-align: center; color: #9ca3af;">No sites available</div>';
            return;
        }
        
        container.innerHTML = sites.map(site => {
            const isExcluded = excludedSites.includes(site.domain);
            return `
                <label style="display: flex; align-items: center; gap: 8px; padding: 8px; color: white; border-radius: 6px; cursor: pointer;">
                    <input type="checkbox" 
                           class="site-filter-checkbox" 
                           data-domain="${site.domain}" 
                           ${isExcluded ? '' : 'checked'}>
                    <span style="font-size: 0.9em;">${site.domain}</span>
                </label>
            `;
        }).join('');
    } catch (error) {
        console.error('Error loading site filter checkboxes:', error);
    }
}

function selectAllSites() {
    document.querySelectorAll('.site-filter-checkbox').forEach(cb => cb.checked = true);
}

function deselectAllSites() {
    document.querySelectorAll('.site-filter-checkbox').forEach(cb => cb.checked = false);
}

async function applySiteFilter() {
    const checkboxes = document.querySelectorAll('.site-filter-checkbox');
    excludedSites = [];
    
    checkboxes.forEach(cb => {
        if (!cb.checked) {
            excludedSites.push(cb.dataset.domain);
        }
    });
    
    // Hide the filter panel
    document.getElementById('siteFilterPanel').style.display = 'none';
    
    // Reload slowest endpoints and site performance with the filter
    await loadSlowestEndpoints();
    await loadSitePerformance();
    
    toast.success(`Filter applied! ${excludedSites.length} site(s) excluded`);
}

// Recent requests inspector
async function loadRecentRequestsSiteList() {
    try {
        const response = await apiRequest('/sites');
        const sites = response?.sites || [];
        const select = document.getElementById('recentRequestsSiteSelect');
        
        if (!sites || sites.length === 0) {
            select.innerHTML = '<option value="">No sites available</option>';
            return;
        }
        
        select.innerHTML = '<option value="">Select a site...</option>' + 
            sites.map(site => `<option value="${site.domain}">${site.domain}</option>`).join('');
    } catch (error) {
        console.error('Error loading site list for recent requests:', error);
    }
}

async function loadRecentRequests() {
    try {
        const domain = document.getElementById('recentRequestsSiteSelect')?.value;
        const limit = document.getElementById('recentRequestsLimit')?.value || 10;
        const tbody = document.getElementById('recentRequestsBody');
        
        if (!domain) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: #9ca3af;">Select a site to view recent requests</td></tr>';
            return;
        }
        
        const response = await apiRequest(`/telemetry/recent-requests?domain=${encodeURIComponent(domain)}&limit=${limit}`);
        
        if (!response || response.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No recent requests found</td></tr>';
            return;
        }
        
        tbody.innerHTML = response.map(req => {
            // Format timestamp
            const timestamp = new Date(req.timestamp);
            const timeStr = timestamp.toLocaleTimeString();
            
            // Status code badge
            let statusClass = 'badge-success';
            if (req.status_code >= 500) statusClass = 'badge-danger';
            else if (req.status_code >= 400) statusClass = 'badge-warning';
            else if (req.status_code >= 300) statusClass = 'badge-info';
            
            // Response time color
            let responseTimeColor = '#10b981'; // green
            if (req.response_time > 1000) responseTimeColor = '#ef4444'; // red
            else if (req.response_time > 500) responseTimeColor = '#f59e0b'; // orange
            else if (req.response_time > 200) responseTimeColor = '#3b82f6'; // blue
            
            // Truncate URI if too long
            let displayUri = req.uri;
            if (displayUri.length > 80) {
                displayUri = displayUri.substring(0, 80) + '...';
            }
            
            return `
            <tr>
                <td style="font-size: 0.85em; color: #6b7280; white-space: nowrap;">${timeStr}</td>
                <td><span class="badge badge-info">${req.method}</span></td>
                <td><code title="${req.uri}" style="font-size: 0.85em;">${displayUri}</code></td>
                <td><span class="badge ${statusClass}">${req.status_code}</span></td>
                <td style="color: ${responseTimeColor}; font-weight: 600;">${req.response_time}ms</td>
                <td style="font-size: 0.85em; color: #6b7280;">${req.backend_server || 'N/A'}</td>
            </tr>
            `;
        }).join('');
    } catch (error) {
        console.error('Error loading recent requests:', error);
        document.getElementById('recentRequestsBody').innerHTML = 
            '<tr><td colspan="6" style="text-align: center; color: #ef4444;">Error loading requests</td></tr>';
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
        showToast('Regenerating all configurations...', 'info');
        await apiRequest('/regenerate/all', 'POST');
        showToast('All configurations regenerated! NGINX will reload shortly.', 'success');
    } catch (error) {
        console.error('Error regenerating configs:', error);
        showToast('Failed to regenerate configurations', 'error');
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
    
    // Reset editor tabs setup flag when switching sites
    editorTabsSetup = false;
    
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
        showToast('Failed to load site data', 'error');
        navigateToPage('sites');
    }
}

// Track if editor tabs are already setup to prevent duplicate listeners
let editorTabsSetup = false;

function setupEditorTabs() {
    // Prevent duplicate setup
    if (editorTabsSetup) {
        return;
    }
    editorTabsSetup = true;
    
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
        case 'error-pages':
            html = renderErrorPagesTab();
            break;
        case 'wellknown':
            html = renderWellKnownTab();
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
    
    // Load error pages for error-pages tab
    if (tab === 'error-pages') {
        loadErrorPages();
    }
    
    // Load well-known files for wellknown tab
    if (tab === 'wellknown') {
        loadWellKnownFiles();
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
    `;
}

// Render Error Pages Tab
function renderErrorPagesTab() {
    const data = currentSiteData;
    return `
        <div class="editor-panel">
            <h3>🎨 Custom Error Pages</h3>
            <p>Upload custom HTML error pages for your site</p>
            
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="edit_custom_error_pages_enabled" ${data.custom_error_pages_enabled ? 'checked' : ''} data-field="custom_error_pages_enabled">
                    <span>Enable Custom Error Pages</span>
                </label>
                <small style="color: var(--text-muted);">When enabled, use custom HTML below. When disabled, use default templates.</small>
            </div>
        </div>
        
        <div class="editor-panel">
            <h3>📄 Error Page HTML Editor</h3>
            <p>Edit custom HTML for each error code (max 5MB per page)</p>
            
            <div class="form-group">
                <label>Select Error Code:</label>
                <select id="errorCodeSelector" class="form-input" onchange="loadSelectedErrorPage()">
                    <option value="403">403 - Forbidden</option>
                    <option value="404" selected>404 - Not Found</option>
                    <option value="429">429 - Too Many Requests</option>
                    <option value="500">500 - Internal Server Error</option>
                    <option value="502">502 - Bad Gateway</option>
                    <option value="503">503 - Service Unavailable</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>
                    <span id="errorPageLabel">Custom 404 HTML</span>
                    <span id="errorPageStatus" style="margin-left: 1rem; color: var(--text-muted);"></span>
                </label>
                <textarea id="errorPageHtml" class="form-input code-editor" rows="20" placeholder="<!DOCTYPE html>
<html>
<head>
    <title>404 Not Found</title>
    <style>
        body { font-family: Arial; text-align: center; padding: 50px; }
        h1 { font-size: 72px; margin: 0; }
    </style>
</head>
<body>
    <h1>404</h1>
    <p>Page not found</p>
</body>
</html>"></textarea>
                <small style="color: var(--text-muted);">Full HTML including DOCTYPE, head, and body tags</small>
            </div>
            
            <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                <button class="btn-primary" onclick="saveCurrentErrorPage()">
                    <span>💾</span>
                    <span>Save Error Page</span>
                </button>
                <button class="btn-secondary" onclick="previewErrorPage()">
                    <span>👁️</span>
                    <span>Preview</span>
                </button>
                <button class="btn-secondary" onclick="deleteCurrentErrorPage()">
                    <span>🗑️</span>
                    <span>Revert to Template</span>
                </button>
                <button class="btn-secondary" onclick="loadErrorPageTemplate()">
                    <span>📋</span>
                    <span>Load Default Template</span>
                </button>
            </div>
        </div>
    `;
}

// Render Well-Known Files Tab
function renderWellKnownTab() {
    const data = currentSiteData;
    const wellKnownEnabled = data.wellknown_enabled === 1 || data.wellknown_enabled === '1';
    
    return `
        <div class="editor-panel">
            <h3>🌐 Well-Known Files</h3>
            <p>Manage robots.txt, security.txt, humans.txt, and ads.txt for this site</p>
            
            <div class="form-group" style="background: var(--bg-secondary); padding: 1rem; border-radius: 8px; border: 1px solid var(--border);">
                <label style="display: flex; align-items: center; gap: 1rem; cursor: pointer; margin: 0;">
                    <input type="checkbox" 
                           id="wellknownEnabled" 
                           ${wellKnownEnabled ? 'checked' : ''} 
                           data-field="wellknown_enabled"
                           style="width: 20px; height: 20px; cursor: pointer;">
                    <div>
                        <strong style="color: var(--text-primary);">Enable Site-Specific Well-Known Files</strong>
                        <div style="font-size: 0.9em; color: var(--text-muted); margin-top: 0.25rem;">
                            When disabled, global defaults from Settings will be used for all well-known files.
                        </div>
                    </div>
                </label>
            </div>
            
            <div class="alert" style="background: #17a2b8; color: white; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                <strong>ℹ️ Info:</strong> These files control search engine crawling, security disclosure, team credits, and advertising.
                Site-specific files override global defaults. Leave blank to use global settings.
            </div>
        </div>
        
        <div class="editor-panel">
            <h3>📄 File Editor</h3>
            
            <div class="form-group">
                <label>Select File:</label>
                <select id="wellKnownFileSelector" class="form-input" onchange="loadSelectedWellKnownFile()">
                    <option value="robots" selected>robots.txt - Search Engine Rules</option>
                    <option value="security">security.txt - Security Contact</option>
                    <option value="humans">humans.txt - Team Credits</option>
                    <option value="ads">ads.txt - Authorized Sellers</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>
                    <span id="wellKnownFileLabel">robots.txt Content</span>
                    <span id="wellKnownFileStatus" style="margin-left: 1rem;"></span>
                </label>
                <textarea id="wellKnownFileContent" class="form-input code-editor" rows="15" placeholder="User-agent: *
Allow: /

Sitemap: https://example.com/sitemap.xml"></textarea>
                <small id="wellKnownFileHelp" style="color: var(--text-muted);">Controls which pages search engines can crawl</small>
            </div>
            
            <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                <button class="btn-primary" onclick="saveCurrentWellKnownFile()">
                    <span>💾</span>
                    <span>Save File</span>
                </button>
                <button class="btn-secondary" onclick="deleteCurrentWellKnownFile()">
                    <span>🗑️</span>
                    <span>Use Global Default</span>
                </button>
                <button class="btn-secondary" onclick="loadWellKnownTemplate()">
                    <span>📋</span>
                    <span>Load Template</span>
                </button>
                <button class="btn-secondary" onclick="viewGlobalWellKnown()">
                    <span>🌍</span>
                    <span>View Global Default</span>
                </button>
            </div>
        </div>
        
        <div class="editor-panel">
            <h3>📚 File Information</h3>
            <div id="wellKnownFileInfo">
                <!-- Dynamic file information -->
            </div>
        </div>
    `;
}

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
            protocol: port === 443 ? 'https' : 'http',
            port: port,
            websocket_enabled: false,
            websocket_protocol: port === 443 ? 'wss' : 'ws',
            websocket_port: port,
            websocket_path: '/',
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
                <label style="display:block; margin-bottom: 0.25rem;">Protocol</label>
                <div style="display:flex; gap: 0.75rem; align-items:center;">
                    <select class="form-input backend-protocol" data-id="${backend.id}" onchange="updateEditorBackend(${backend.id})">
                        <option value="http" ${(!backend.protocol || backend.protocol === 'http') ? 'selected' : ''}>HTTP</option>
                        <option value="https" ${backend.protocol === 'https' ? 'selected' : ''}>HTTPS</option>
                    </select>
                    <div style="min-width:160px;">
                        <label style="display:block; margin:0 0 0.25rem 0; font-size:0.9rem; color:var(--text-muted);">Port</label>
                        <input type="number" class="form-input backend-port" data-id="${backend.id}" value="${backend.port || (backend.protocol === 'https' ? 443 : 80)}" min="1" max="65535" onchange="updateEditorBackend(${backend.id})">
                    </div>
                </div>
                <small style="color: var(--text-muted); display:block; margin-top:0.5rem;">Set the protocol and port used to contact this backend</small>
            </div>

            <div class="form-group" style="margin-top:0.5rem;">
                <label style="display:block; margin-bottom: 0.25rem;">WebSocket</label>
                <div style="display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap;">
                    <label class="checkbox-label" style="margin:0; display:flex; align-items:center; gap:0.5rem;">
                        <input type="checkbox" class="backend-websocket-enabled" data-id="${backend.id}" ${backend.websocket_enabled ? 'checked' : ''} onchange="updateEditorBackend(${backend.id})">
                        <span>Enable WebSocket</span>
                    </label>
                    <select class="form-input backend-websocket-protocol" data-id="${backend.id}" onchange="updateEditorBackend(${backend.id})">
                        <option value="ws" ${(!backend.websocket_protocol || backend.websocket_protocol === 'ws') ? 'selected' : ''}>WS</option>
                        <option value="wss" ${backend.websocket_protocol === 'wss' ? 'selected' : ''}>WSS</option>
                    </select>
                    <input type="number" class="form-input backend-websocket-port" data-id="${backend.id}" value="${backend.websocket_port || (backend.websocket_protocol === 'wss' ? 443 : 80)}" min="1" max="65535" onchange="updateEditorBackend(${backend.id})" style="width:120px;">
                    <input type="text" class="form-input backend-websocket-path" data-id="${backend.id}" value="${backend.websocket_path || '/'}" onchange="updateEditorBackend(${backend.id})" style="min-width:120px;" placeholder="/">
                </div>
                <small style="color: var(--text-muted); display:block; margin-top:0.5rem;">Enable WS/WSS support and configure port/path</small>
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
        protocol: 'http',
        port: 80,
        websocket_enabled: false,
        websocket_protocol: 'ws',
        websocket_port: 80,
        websocket_path: '/',
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



function updateEditorBackend(id) {
    const backend = editorBackends.find(b => b.id === id);
    if (!backend) return;
    
    backend.address = document.querySelector(`.backend-address[data-id="${id}"]`)?.value || '';
    backend.weight = parseInt(document.querySelector(`.backend-weight[data-id="${id}"]`)?.value) || 1;
    backend.max_fails = parseInt(document.querySelector(`.backend-max-fails[data-id="${id}"]`)?.value) || 3;
    backend.fail_timeout = parseInt(document.querySelector(`.backend-fail-timeout[data-id="${id}"]`)?.value) || 30;
    backend.backup = document.querySelector(`.backend-backup[data-id="${id}"]`)?.checked || false;
    backend.down = document.querySelector(`.backend-down[data-id="${id}"]`)?.checked || false;
    
    // Single protocol + port
    backend.protocol = document.querySelector(`.backend-protocol[data-id="${id}"]`)?.value || 'http';
    backend.port = parseInt(document.querySelector(`.backend-port[data-id="${id}"]`)?.value) || (backend.protocol === 'https' ? 443 : 80);

    // WebSocket settings
    backend.websocket_enabled = document.querySelector(`.backend-websocket-enabled[data-id="${id}"]`)?.checked || false;
    backend.websocket_protocol = document.querySelector(`.backend-websocket-protocol[data-id="${id}"]`)?.value || 'ws';
    backend.websocket_port = parseInt(document.querySelector(`.backend-websocket-port[data-id="${id}"]`)?.value) || (backend.websocket_protocol === 'wss' ? 443 : 80);
    backend.websocket_path = document.querySelector(`.backend-websocket-path[data-id="${id}"]`)?.value || '/';
    
    // Extract WebSocket settings from first enabled backend and set at site level
    const wsBackend = editorBackends.find(b => b.websocket_enabled);
    if (wsBackend) {
        // Update site-level WebSocket fields
        autoSaveField('websocket_enabled', 1);
        autoSaveField('websocket_protocol', wsBackend.websocket_protocol);
        autoSaveField('websocket_port', wsBackend.websocket_port);
        autoSaveField('websocket_path', wsBackend.websocket_path);
    } else {
        // No WebSocket enabled backends
        autoSaveField('websocket_enabled', 0);
    }
    
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
// legacy toggleBackendProtocol removed - protocol is now a single select per backend

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
        
        if (!response || response.error || response.exists === false) {
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
                
                <div style="margin-top: 0.5rem; display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                    <button class="btn-secondary" onclick="renewCertificate('${domain}')" style="width: 100%;">
                        <span>\ud83d\udd04</span>
                        <span>Renew Certificate</span>
                    </button>
                    <button class="btn-secondary" onclick="rescanCertificate('${domain}')" style="width: 100%;">
                        <span>\ud83d\udd0d</span>
                        <span>Rescan Certificate</span>
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
            showToast(`Certificate file selected: ${file.name}`, 'success');
        } else {
            keyFile = file;
            showToast(`Private key file selected: ${file.name}`, 'success');
        }
    }
}

async function uploadCustomCertificate() {
    if (!certFile || !keyFile) {
        showToast('Please select both certificate and private key files', 'error');
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
        
        showToast('Certificate uploaded successfully! NGINX will reload.', 'success');
        certFile = null;
        keyFile = null;
        
    } catch (error) {
        console.error('Error uploading certificate:', error);
        showToast('Failed to upload certificate', 'error');
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
        showToast('✅ Site saved successfully! Changes will apply in ~5 seconds.', 'success');
        
        // Auto-rescan certificates if SSL settings changed
        if (formData.ssl_enabled || formData.ssl_challenge_type) {
            try {
                setTimeout(async () => {
                    await apiRequest(`/certificates/rescan/${currentSiteData.domain}`, 'POST');
                }, 3000); // Wait 3s for config to apply
            } catch (error) {
                console.log('Certificate rescan skipped:', error.message);
            }
        }
        
        // Dispatch event for tab switching
        window.dispatchEvent(new Event('siteSaved'));
        
        // Reload site data after longer delay to see toast
        setTimeout(() => loadSiteEditor(currentSiteId), 2000);
        
    } catch (error) {
        console.error('Error saving site:', error);
        showToast('Failed to save site', 'error');
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
        showToast(`❌ ${error.message}`, 'error');
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
            showToast('Please fill in domain and backend server', 'warning');
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
        showToast('Site added successfully!', 'success');
        
        // Clear form data
        currentSiteData = null;
        
        // Navigate back to sites page
        setTimeout(() => {
            navigateToPage('sites');
            loadSites();
        }, 1000);
        
    } catch (error) {
        console.error('Error adding site:', error);
        showToast('Failed to add site', 'error');
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
        showToast('Exporting sites...', 'info');
        
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
        
        showToast('✅ Sites exported successfully!', 'success');
    } catch (error) {
        console.error('Error exporting sites:', error);
        showToast('❌ Failed to export sites', 'error');
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
            showToast('⚠️ Please provide JSON data', 'warning');
            return;
        }
        
        // Validate JSON
        let jsonData;
        try {
            jsonData = JSON.parse(jsonText);
        } catch (e) {
            showToast('❌ Invalid JSON format', 'error');
            return;
        }
        
        if (!jsonData.sites || !Array.isArray(jsonData.sites)) {
            showToast('❌ Invalid format: Expected {sites: [...]}', 'error');
            return;
        }
        
        // Confirm replace mode
        if (mode === 'replace' && !dryRun) {
            if (!confirm('⚠️ REPLACE MODE will delete all existing sites and import new ones. A backup will be created. Continue?')) {
                return;
            }
        }
        
        showToast(`${dryRun ? 'Validating' : 'Importing'} ${jsonData.sites.length} sites...`, 'info');
        
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
            showToast('✅ Validation complete - Review results above', 'success');
        } else {
            showToast(`✅ Import complete! ${result.imported} added, ${result.updated} updated`, 'success');
            
            // Refresh sites list
            setTimeout(() => {
                closeModal('importModal');
                loadSites();
            }, 2000);
        }
        
    } catch (error) {
        console.error('Error importing sites:', error);
        showToast(`❌ Import failed: ${error.message}`, 'error');
    }
};

// Auto-detect Cloudflare zones for sites
window.detectCloudflareZones = async function(siteId = null, force = false) {
    try {
        const loadingMsg = siteId ? 'Detecting Cloudflare zone...' : 'Detecting Cloudflare zones for all sites...';
        showToast(loadingMsg, 'info');
        
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
                showToast('⚠️ Cloudflare API credentials not configured. Set CLOUDFLARE_API_TOKEN in docker-compose.yml', 'warning');
                
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
        
        showToast(resultMsg, result.failed > 0 ? 'warning' : 'success');
        
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
        showToast(`❌ ${error.message}`, 'error');
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
        showToast('Failed to load block rules', 'error');
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
            showToast('Rule not found', 'error');
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
        showToast('Failed to load rule details', 'error');
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
        showToast('Name and pattern are required', 'error');
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
            showToast('Block rule updated successfully', 'success');
        } else {
            // Create new rule
            await apiRequest('/custom-block-rules', 'POST', data);
            showToast('Block rule created successfully', 'success');
        }
        
        closeModal('blockRuleModal');
        await loadBlockRules();
    } catch (error) {
        console.error('Error saving block rule:', error);
        showToast(error.message || 'Failed to save block rule', 'error');
    }
}

async function toggleBlockRule(id, enabled) {
    try {
        await apiRequest(`/custom-block-rules/${id}`, 'PUT', { enabled: enabled ? 1 : 0 });
        showToast(`Rule ${enabled ? 'enabled' : 'disabled'}`, 'success');
        // Reload to update the rules
        await loadBlockRules();
    } catch (error) {
        console.error('Error toggling block rule:', error);
        showToast('Failed to toggle rule', 'error');
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
        showToast('Block rule deleted successfully', 'success');
        await loadBlockRules();
    } catch (error) {
        console.error('Error deleting block rule:', error);
        showToast('Failed to delete block rule', 'error');
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
        showToast('No site selected', 'error');
        return;
    }

    showToast('Fetching generated NGINX config...', 'info');
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
        showToast(`Failed to fetch config: ${err.message}`, 'error');
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

async function saveDevModeSetting() {
    const enabled = document.getElementById('dev_mode_headers')?.checked;
    
    try {
        const response = await apiRequest('/settings', {
            method: 'POST',
            body: JSON.stringify({
                dev_mode_headers: enabled ? '1' : '0'
            })
        });
        
        showToast(
            enabled 
                ? '✅ Development mode enabled! Regenerate site configs to apply.' 
                : '✅ Development mode disabled! Regenerate site configs to apply.',
            'success'
        );
        
        // Show reminder to regenerate configs
        if (enabled || !enabled) {
            setTimeout(() => {
                showToast('💡 Remember to regenerate all site configs for changes to take effect', 'info');
            }, 2000);
        }
    } catch (error) {
        console.error('Error saving dev mode setting:', error);
        showToast('Failed to save development mode setting', 'error');
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
    
    const mergeMode = document.getElementById('merge_mode').value;
    
    // Extra warning for clear mode
    if (mergeMode === 'clear') {
        const confirmClear = confirm('⚠️ WARNING: CLEAR mode will DELETE ALL existing data, logs, sites, and telemetry before importing!\n\nThis action cannot be undone. Are you absolutely sure?');
        if (!confirmClear) return;
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
            
            if (result.results && result.results.cleared) {
                message += '\n\n✅ All existing data was cleared before import';
            }
            
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
        if (response.settings && Array.isArray(response.settings)) {
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
        showToast('❌ No site data to verify', 'error');
        return;
    }
    
    const toastId = showToast('🔍 Verifying configuration...', 'info', 10000);
    
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

// ==========================================
// Custom Error Pages Management
// ==========================================

let currentErrorPages = {};
let currentErrorCode = '404';

// Load all error pages for current site
async function loadErrorPages() {
    if (!currentSiteData?.id) return;
    
    try {
        const data = await apiRequest(`/error-pages/site/${currentSiteData.id}`);
        
        if (data && data.success) {
            currentErrorPages = data.pages || {};
            currentSiteData.custom_error_pages_enabled = data.custom_enabled;
            
            // Update checkbox
            const checkbox = document.getElementById('edit_custom_error_pages_enabled');
            if (checkbox) {
                checkbox.checked = data.custom_enabled;
            }
            
            // Load the currently selected error page
            loadSelectedErrorPage();
        }
    } catch (error) {
        console.error('Error loading error pages:', error);
        showToast('Failed to load error pages', 'error');
    }
}

// Load selected error page into editor
async function loadSelectedErrorPage() {
    const selector = document.getElementById('errorCodeSelector');
    const textarea = document.getElementById('errorPageHtml');
    const label = document.getElementById('errorPageLabel');
    const status = document.getElementById('errorPageStatus');
    
    if (!selector || !textarea || !currentSiteData?.id) return;
    
    currentErrorCode = selector.value;
    
    try {
        const data = await apiRequest(`/error-pages/site/${currentSiteData.id}/${currentErrorCode}`);
        
        if (data && data.success) {
            textarea.value = data.html || '';
            label.textContent = `Custom ${currentErrorCode} HTML`;
            
            if (data.is_custom) {
                status.textContent = '✅ Custom page active';
                status.style.color = 'var(--success)';
            } else {
                status.textContent = '📋 Using template';
                status.style.color = 'var(--text-muted)';
            }
        }
    } catch (error) {
        console.error('Error loading error page:', error);
        showToast('Failed to load error page', 'error');
    }
}

// Save current error page
async function saveCurrentErrorPage() {
    const textarea = document.getElementById('errorPageHtml');
    if (!textarea || !currentSiteData?.id) return;
    
    const html = textarea.value;
    
    if (!html.trim()) {
        showToast('Please enter HTML content', 'error');
        return;
    }
    
    try {
        const data = await apiRequest(`/error-pages/site/${currentSiteData.id}/${currentErrorCode}`, {
            method: 'PUT',
            body: JSON.stringify({ html })
        });
        
        if (data && data.success) {
            showToast(`${currentErrorCode} error page saved successfully`, 'success');
            loadSelectedErrorPage(); // Reload to update status
        }
    } catch (error) {
        console.error('Error saving error page:', error);
        showToast('Failed to save error page', 'error');
    }
}

// Delete current error page (revert to template)
async function deleteCurrentErrorPage() {
    if (!currentSiteData?.id) return;
    
    if (!confirm(`Revert ${currentErrorCode} error page to default template?`)) return;
    
    try {
        const data = await apiRequest(`/error-pages/site/${currentSiteData.id}/${currentErrorCode}`, {
            method: 'DELETE'
        });
        
        if (data && data.success) {
            showToast(`Reverted to default ${currentErrorCode} template`, 'success');
            loadSelectedErrorPage(); // Reload to show template
        }
    } catch (error) {
        console.error('Error deleting error page:', error);
        showToast('Failed to delete error page', 'error');
    }
}

// Preview error page in new window
function previewErrorPage() {
    const textarea = document.getElementById('errorPageHtml');
    if (!textarea) return;
    
    const html = textarea.value || '<!DOCTYPE html><html><body><h1>Empty</h1></body></html>';
    const blob = new Blob([html], { type: 'text/html' });
    const url = URL.createObjectURL(blob);
    window.open(url, '_blank');
}

// Load default template
async function loadErrorPageTemplate() {
    try {
        const data = await apiRequest(`/error-pages/templates/default`);
        
        if (data && data.success && data.template) {
            const htmlField = `html_${currentErrorCode}`;
            const html = data.template[htmlField];
            
            if (html) {
                const textarea = document.getElementById('errorPageHtml');
                if (textarea) {
                    textarea.value = html;
                    showToast('Template loaded', 'success');
                }
            } else {
                showToast('Template not found for this error code', 'error');
            }
        }
    } catch (error) {
        console.error('Error loading template:', error);
        showToast('Failed to load template', 'error');
    }
}

// ==========================================
// Well-Known Files Management
// ==========================================

let currentWellKnownFiles = {};
let currentWellKnownType = 'robots';

// Load all well-known files for current site
async function loadWellKnownFiles() {
    if (!currentSiteData?.id) return;
    
    loadSelectedWellKnownFile();
    updateWellKnownFileInfo();
}

// Load selected well-known file
async function loadSelectedWellKnownFile() {
    const selector = document.getElementById('wellKnownFileSelector');
    const textarea = document.getElementById('wellKnownFileContent');
    const label = document.getElementById('wellKnownFileLabel');
    const status = document.getElementById('wellKnownFileStatus');
    const help = document.getElementById('wellKnownFileHelp');
    
    if (!selector || !textarea || !currentSiteData?.id) return;
    
    currentWellKnownType = selector.value;
    
    const fileLabels = {
        robots: 'robots.txt',
        security: 'security.txt',
        humans: 'humans.txt',
        ads: 'ads.txt'
    };
    
    const fileHelp = {
        robots: 'Controls which pages search engines can crawl',
        security: 'Security vulnerability disclosure contact information (RFC 9116)',
        humans: 'Credits, team information, and technology stack',
        ads: 'Authorized digital sellers declaration (prevents ad fraud)'
    };
    
    label.textContent = `${fileLabels[currentWellKnownType]} Content`;
    help.textContent = fileHelp[currentWellKnownType];
    
    try {
        const data = await apiRequest(`/wellknown/site/${currentSiteData.id}/${currentWellKnownType}`);
        
        if (data && data.success) {
            textarea.value = data.content || '';
            
            if (data.is_custom) {
                status.textContent = '✅ Site-specific';
                status.style.color = 'var(--success)';
            } else {
                status.textContent = '🌍 Using global default';
                status.style.color = 'var(--text-muted)';
            }
        }
    } catch (error) {
        console.error('Error loading well-known file:', error);
        showToast('Failed to load file', 'error');
    }
    
    updateWellKnownFileInfo();
}

// Save current well-known file
async function saveCurrentWellKnownFile() {
    const textarea = document.getElementById('wellKnownFileContent');
    if (!textarea || !currentSiteData?.id) return;
    
    const content = textarea.value;
    
    try {
        const data = await apiRequest(`/wellknown/site/${currentSiteData.id}/${currentWellKnownType}`, {
            method: 'PUT',
            body: JSON.stringify({ content })
        });
        
        if (data && data.success) {
            showToast(`${currentWellKnownType}.txt saved successfully`, 'success');
            loadSelectedWellKnownFile(); // Reload to update status
        }
    } catch (error) {
        console.error('Error saving well-known file:', error);
        showToast('Failed to save file', 'error');
    }
}

// Delete current well-known file (use global)
async function deleteCurrentWellKnownFile() {
    if (!currentSiteData?.id) return;
    
    if (!confirm(`Remove site-specific ${currentWellKnownType}.txt and use global default?`)) return;
    
    try {
        const data = await apiRequest(`/wellknown/site/${currentSiteData.id}/${currentWellKnownType}`, {
            method: 'DELETE'
        });
        
        if (data && data.success) {
            showToast(`Now using global ${currentWellKnownType}.txt`, 'success');
            loadSelectedWellKnownFile(); // Reload to show global
        }
    } catch (error) {
        console.error('Error deleting well-known file:', error);
        showToast('Failed to delete file', 'error');
    }
}

// Load well-known file template
function loadWellKnownTemplate() {
    const textarea = document.getElementById('wellKnownFileContent');
    if (!textarea) return;
    
    const templates = {
        robots: `# Robots.txt for ${currentSiteData?.domain || 'example.com'}
User-agent: *
Allow: /

# Disallow admin areas
Disallow: /admin/
Disallow: /api/
Disallow: /.well-known/

# Sitemap
Sitemap: https://${currentSiteData?.domain || 'example.com'}/sitemap.xml`,
        
        security: `Contact: mailto:security@${currentSiteData?.domain || 'example.com'}
Expires: ${new Date(Date.now() + 365 * 24 * 60 * 60 * 1000).toISOString()}
Preferred-Languages: en
Canonical: https://${currentSiteData?.domain || 'example.com'}/.well-known/security.txt`,
        
        humans: `/* TEAM */
Webmaster: Your Name
Contact: admin@${currentSiteData?.domain || 'example.com'}
Location: Your City, Country

/* SITE */
Last update: ${new Date().toISOString().split('T')[0]}
Standards: HTML5, CSS3
Components: NGINX, CatWAF
Software: ${currentSiteData?.domain || 'example.com'}`,
        
        ads: `# Ads.txt for ${currentSiteData?.domain || 'example.com'}
# Add your advertising partners here
# Format: domain.com, publisher-account-id, DIRECT/RESELLER, [TAG-ID]
# Example:
# google.com, pub-0000000000000000, DIRECT, f08c47fec0942fa0`
    };
    
    textarea.value = templates[currentWellKnownType] || '';
    showToast('Template loaded', 'success');
}

// View global well-known file
async function viewGlobalWellKnown() {
    try {
        const data = await apiRequest(`/wellknown/global/${currentWellKnownType}`);
        
        if (data && data.success) {
            const content = data.content || '(No global default set)';
            alert(`Global ${currentWellKnownType}.txt:\n\n${content}`);
        }
    } catch (error) {
        console.error('Error loading global well-known file:', error);
        showToast('Failed to load global file', 'error');
    }
}

// Update well-known file information panel
function updateWellKnownFileInfo() {
    const infoEl = document.getElementById('wellKnownFileInfo');
    if (!infoEl) return;
    
    const fileInfo = {
        robots: {
            title: 'robots.txt',
            url: `https://${currentSiteData?.domain || 'example.com'}/robots.txt`,
            description: 'Search engines check this file to know which pages they can crawl and index.',
            tips: [
                'Use "User-agent: *" to apply rules to all search engines',
                'Use "Disallow:" to block specific paths',
                'Always include your sitemap URL',
                'Test with Google Search Console'
            ]
        },
        security: {
            title: 'security.txt',
            url: `https://${currentSiteData?.domain || 'example.com'}/.well-known/security.txt`,
            description: 'Provides security researchers with contact information for reporting vulnerabilities (RFC 9116).',
            tips: [
                'Include a valid contact email or URL',
                'Set an expiration date (max 1 year)',
                'Optionally include PGP key for encrypted reports',
                'Keep it up to date!'
            ]
        },
        humans: {
            title: 'humans.txt',
            url: `https://${currentSiteData?.domain || 'example.com'}/humans.txt`,
            description: 'Credits the people and technologies behind your website.',
            tips: [
                'List team members and their roles',
                'Include contact information',
                'Mention technologies and tools used',
                'Keep it fun and personal!'
            ]
        },
        ads: {
            title: 'ads.txt',
            url: `https://${currentSiteData?.domain || 'example.com'}/ads.txt`,
            description: 'Declares authorized digital sellers to prevent ad fraud and domain spoofing.',
            tips: [
                'List all authorized ad networks and resellers',
                'Format: domain, publisher-id, DIRECT/RESELLER, [TAG-ID]',
                'Keep updated when you change ad partners',
                'Helps protect your ad revenue'
            ]
        }
    };
    
    const info = fileInfo[currentWellKnownType];
    
    infoEl.innerHTML = `
        <h4>${info.title}</h4>
        <p><strong>URL:</strong> <a href="${info.url}" target="_blank">${info.url}</a></p>
        <p>${info.description}</p>
        <p><strong>Tips:</strong></p>
        <ul>
            ${info.tips.map(tip => `<li>${tip}</li>`).join('')}
        </ul>
    `;
}

// ==========================================
// Global Well-Known Files (Settings Page)
// ==========================================

let globalWellKnownType = 'robots';
let globalWellKnownData = {};

// Load global well-known file
async function loadGlobalWellKnownFile() {
    const selector = document.getElementById('globalWellKnownSelector');
    const textarea = document.getElementById('globalWellKnownContent');
    const label = document.getElementById('globalWellKnownLabel');
    const help = document.getElementById('globalWellKnownHelp');
    
    if (!selector || !textarea) return;
    
    globalWellKnownType = selector.value;
    
    const fileLabels = {
        robots: 'robots.txt Content',
        security: 'security.txt Content',
        humans: 'humans.txt Content',
        ads: 'ads.txt Content'
    };
    
    const fileHelp = {
        robots: 'Global default robots.txt for all sites',
        security: 'Global security contact information (RFC 9116)',
        humans: 'Global team credits and site information',
        ads: 'Global authorized digital sellers declaration'
    };
    
    label.textContent = fileLabels[globalWellKnownType];
    help.textContent = fileHelp[globalWellKnownType];
    
    try {
        const data = await apiRequest(`/wellknown/global/${globalWellKnownType}`);
        
        if (data && data.success) {
            textarea.value = data.content || '';
        }
    } catch (error) {
        console.error('Error loading global well-known file:', error);
        showToast('Failed to load global file', 'error');
    }
}

// Save global well-known file
async function saveGlobalWellKnownFile() {
    const textarea = document.getElementById('globalWellKnownContent');
    if (!textarea) return;
    
    const content = textarea.value;
    
    try {
        const data = await apiRequest(`/wellknown/global/${globalWellKnownType}`, {
            method: 'PUT',
            body: JSON.stringify({ content })
        });
        
        if (data && data.success) {
            showToast(`Global ${globalWellKnownType}.txt saved successfully`, 'success');
        }
    } catch (error) {
        console.error('Error saving global well-known file:', error);
        showToast('Failed to save global file', 'error');
    }
}

// Load global well-known template
function loadGlobalWellKnownTemplate() {
    const textarea = document.getElementById('globalWellKnownContent');
    if (!textarea) return;
    
    const templates = {
        robots: `# Global Robots.txt Default
User-agent: *
Allow: /

# Block admin and API areas
Disallow: /admin/
Disallow: /api/
Disallow: /.well-known/

# Sitemap (update per site)
Sitemap: https://example.com/sitemap.xml`,
        
        security: `Contact: mailto:security@example.com
Expires: ${new Date(Date.now() + 365 * 24 * 60 * 60 * 1000).toISOString()}
Preferred-Languages: en
Canonical: https://example.com/.well-known/security.txt
Policy: https://example.com/security-policy`,
        
        humans: `/* TEAM */
Webmaster: Your Name
Contact: admin@example.com
Location: Your City, Country

/* SITE */
Last update: ${new Date().toISOString().split('T')[0]}
Standards: HTML5, CSS3
Components: NGINX, CatWAF
Software: CatWAF`,
        
        ads: `# Global Ads.txt Default
# Add your advertising partners here
# Format: domain.com, publisher-account-id, DIRECT/RESELLER, [TAG-ID]
# Example:
# google.com, pub-0000000000000000, DIRECT, f08c47fec0942fa0
# doubleclick.net, pub-0000000000000000, DIRECT, f08c47fec0942fa0`
    };
    
    textarea.value = templates[globalWellKnownType] || '';
    showToast('Template loaded', 'success');
}

// Auto-load global well-known files when settings page opens
let globalWellKnownObserver = null;
document.addEventListener('DOMContentLoaded', () => {
    if (globalWellKnownObserver) globalWellKnownObserver.disconnect();
    globalWellKnownObserver = new MutationObserver(() => {
        const wellKnownEl = document.getElementById('globalWellKnownContent');
        if (wellKnownEl && !wellKnownEl.dataset.loaded) {
            wellKnownEl.dataset.loaded = 'true';
            loadGlobalWellKnownFile();
        }
    });
    globalWellKnownObserver.observe(document.body, { childList: true, subtree: true });
});

// ============================================================
// BOT WHITELIST MANAGEMENT
// ============================================================

// Load bot whitelist
async function loadBotWhitelist() {
    try {
        const filter = document.getElementById('botActionFilter')?.value || '';
        const response = await apiRequest('/bot-whitelist');
        let bots = response?.bots || [];
        
        // Filter by action if selected
        if (filter) {
            bots = bots.filter(bot => bot.action === filter);
        }
        
        const tbody = document.getElementById('botWhitelistBody');
        if (!tbody) return;
        
        if (bots.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-muted);">No bot rules found</td></tr>';
            return;
        }
        
        tbody.innerHTML = '';
        
        bots.forEach(bot => {
            const row = document.createElement('tr');
            
            // Action badge color
            let actionClass = 'badge-info';
            if (bot.action === 'allow') actionClass = 'badge-success';
            if (bot.action === 'block') actionClass = 'badge-danger';
            if (bot.action === 'flag') actionClass = 'badge-warning';
            
            row.innerHTML = `
                <td style="text-align: center;">
                    <label class="toggle" style="margin: 0;">
                        <input type="checkbox" ${bot.enabled ? 'checked' : ''} onchange="toggleBotRule(${bot.id}, this.checked)">
                        <span class="toggle-slider"></span>
                    </label>
                </td>
                <td><code style="font-size: 0.85rem; color: var(--primary);">${escapeHtml(bot.pattern)}</code></td>
                <td>
                    <span class="status-badge ${actionClass}" style="text-transform: capitalize;">
                        ${bot.action}
                    </span>
                </td>
                <td style="text-align: center; font-weight: 600;">${bot.priority}</td>
                <td>${escapeHtml(bot.description || '-')}</td>
                <td>
                    <div style="display: flex; gap: 6px; justify-content: flex-end;">
                        <button class="btn-icon btn-success" onclick="changeBotAction(${bot.id}, 'allow')" title="Allow">
                            ✓
                        </button>
                        <button class="btn-icon btn-warning" onclick="changeBotAction(${bot.id}, 'flag')" title="Flag">
                            ⚠
                        </button>
                        <button class="btn-icon btn-danger" onclick="changeBotAction(${bot.id}, 'block')" title="Block">
                            ✕
                        </button>
                        <button class="btn-icon" onclick="editBotRule(${bot.id})" title="Edit">
                            ✏️
                        </button>
                        <button class="btn-icon btn-danger" onclick="deleteBotRule(${bot.id})" title="Delete">
                            🗑️
                        </button>
                    </div>
                </td>
            `;
            
            tbody.appendChild(row);
        });
    } catch (error) {
        console.error('Error loading bot whitelist:', error);
        showToast('Failed to load bot whitelist', 'error');
    }
}

// Show add bot rule modal
function showAddBotRuleModal() {
    document.getElementById('botRuleModalTitle').textContent = 'Add Bot Rule';
    document.getElementById('botRuleId').value = '';
    document.getElementById('botRulePattern').value = '';
    document.getElementById('botRuleAction').value = 'allow';
    document.getElementById('botRulePriority').value = '100';
    document.getElementById('botRuleDescription').value = '';
    document.getElementById('botRuleEnabled').checked = true;
    
    openModal('botRuleModal');
}

// Edit bot rule
async function editBotRule(id) {
    try {
        const response = await apiRequest(`/bot-whitelist/${id}`);
        const bot = response?.bot;
        
        if (!bot) {
            showToast('Bot rule not found', 'error');
            return;
        }
        
        document.getElementById('botRuleModalTitle').textContent = 'Edit Bot Rule';
        document.getElementById('botRuleId').value = bot.id;
        document.getElementById('botRulePattern').value = bot.pattern;
        document.getElementById('botRuleAction').value = bot.action;
        document.getElementById('botRulePriority').value = bot.priority;
        document.getElementById('botRuleDescription').value = bot.description || '';
        document.getElementById('botRuleEnabled').checked = bot.enabled == 1;
        
        openModal('botRuleModal');
    } catch (error) {
        console.error('Error loading bot rule:', error);
        showToast('Failed to load bot rule', 'error');
    }
}

// Save bot rule
async function saveBotRule() {
    const id = document.getElementById('botRuleId').value;
    const pattern = document.getElementById('botRulePattern').value.trim();
    const action = document.getElementById('botRuleAction').value;
    const priority = parseInt(document.getElementById('botRulePriority').value);
    const description = document.getElementById('botRuleDescription').value.trim();
    const enabled = document.getElementById('botRuleEnabled').checked;
    
    if (!pattern) {
        showToast('Pattern is required', 'error');
        return;
    }
    
    if (!action) {
        showToast('Action is required', 'error');
        return;
    }
    
    if (!priority || priority < 1) {
        showToast('Priority must be at least 1', 'error');
        return;
    }
    
    try {
        const data = {
            pattern,
            action,
            priority,
            description,
            enabled: enabled ? 1 : 0
        };
        
        const response = id 
            ? await apiRequest(`/bot-whitelist/${id}`, { method: 'PATCH', body: JSON.stringify(data) })
            : await apiRequest('/bot-whitelist', { method: 'POST', body: JSON.stringify(data) });
        
        if (response && response.success) {
            showToast(id ? 'Bot rule updated successfully' : 'Bot rule created successfully', 'success');
            closeModal('botRuleModal');
            loadBotWhitelist();
        }
    } catch (error) {
        console.error('Error saving bot rule:', error);
        showToast('Failed to save bot rule', 'error');
    }
}

// Toggle bot rule enabled/disabled
async function toggleBotRule(id, enabled) {
    try {
        const data = { enabled: enabled ? 1 : 0 };
        const response = await apiRequest(`/bot-whitelist/${id}`, { 
            method: 'PATCH', 
            body: JSON.stringify(data) 
        });
        
        if (response && response.success) {
            showToast(`Bot rule ${enabled ? 'enabled' : 'disabled'}`, 'success');
        }
    } catch (error) {
        console.error('Error toggling bot rule:', error);
        showToast('Failed to update bot rule', 'error');
        loadBotWhitelist(); // Reload to reset checkbox
    }
}

// Change bot action (quick action buttons)
async function changeBotAction(id, action) {
    try {
        const data = { action };
        const response = await apiRequest(`/bot-whitelist/${id}`, { 
            method: 'PATCH', 
            body: JSON.stringify(data) 
        });
        
        if (response && response.success) {
            showToast(`Bot action changed to ${action}`, 'success');
            loadBotWhitelist();
        }
    } catch (error) {
        console.error('Error changing bot action:', error);
        showToast('Failed to change bot action', 'error');
    }
}

// Delete bot rule
async function deleteBotRule(id) {
    if (!confirm('Are you sure you want to delete this bot rule?')) {
        return;
    }
    
    try {
        const response = await apiRequest(`/bot-whitelist/${id}`, { method: 'DELETE' });
        
        if (response && response.success) {
            showToast('Bot rule deleted successfully', 'success');
            loadBotWhitelist();
        }
    } catch (error) {
        console.error('Error deleting bot rule:', error);
        showToast('Failed to delete bot rule', 'error');
    }
}

// Quick action: Allow bot
async function quickAllowBot(botName) {
    const pattern = `~*${botName}`;
    await quickAddBotRule(pattern, 'allow', 100, `${botName} (allowed)`);
}

// Quick action: Flag bot
async function quickFlagBot(botName) {
    const pattern = `~*${botName}`;
    await quickAddBotRule(pattern, 'flag', 150, `${botName} (flagged)`);
}

// Quick action: Block bot
async function quickBlockBot(botName) {
    const pattern = `~*${botName}`;
    await quickAddBotRule(pattern, 'block', 200, `${botName} (blocked)`);
}

// Helper: Add bot rule with confirmation
async function quickAddBotRule(pattern, action, priority, description) {
    if (!confirm(`Add bot rule: ${description}?`)) {
        return;
    }
    
    try {
        const data = {
            pattern: pattern,
            action: action,
            priority: priority,
            description: description,
            enabled: 1
        };
        
        const response = await apiRequest('/bot-whitelist', {
            method: 'POST',
            body: JSON.stringify(data)
        });
        
        if (response && response.success) {
            showToast(`Bot rule added: ${action}`, 'success');
            loadBotWhitelist();
            loadBotDetections();
        }
    } catch (error) {
        console.error('Error adding bot rule:', error);
        showToast('Failed to add bot rule', 'error');
    }
}

// Regenerate bot protection config
async function regenerateBotConfig() {
    if (!confirm('Regenerate bot protection configuration? This will reload NGINX.')) {
        return;
    }
    
    try {
        const response = await apiRequest('/bot-whitelist/regenerate', { method: 'POST' });
        
        if (response && response.success) {
            showToast('Bot config regenerated and NGINX reloaded', 'success');
        }
    } catch (error) {
        console.error('Error regenerating bot config:', error);
        showToast('Failed to regenerate bot config', 'error');
    }
}

// Helper function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
