// Site Editor JavaScript
const API_BASE_URL = '/api';
const API_TOKEN = localStorage.getItem('api_token') || '';
let siteId = null;
let siteData = {};
let allSites = [];

// Initialize
document.addEventListener('DOMContentLoaded', () => {
    // Get site ID from URL
    const params = new URLSearchParams(window.location.search);
    siteId = params.get('id');
    
    if (!siteId) {
        showToast('error', 'No site ID provided');
        setTimeout(() => window.location.href = 'dashboard.html#sites', 2000);
        return;
    }
    
    // Setup tabs
    setupTabs();
    
    // Setup range sliders
    setupRangeSliders();
    
    // Setup rate limit preset selector
    setupRateLimitSelector();
    
    // Setup backends tab
    setupBackendsTab();
    
    // Setup conditional displays
    setupConditionalDisplays();
    
    // Load all sites for switcher
    loadSiteSwitcher();
    
    // Load site data
    loadSiteData();
});

// Load site switcher
async function loadSiteSwitcher() {
    try {
        const response = await fetch(`${API_BASE_URL}/sites`, {
            headers: {
                'Authorization': `Bearer ${API_TOKEN}`
            }
        });
        
        if (!response.ok) {
            throw new Error('Failed to load sites');
        }
        
        const data = await response.json();
        allSites = data.sites || data || [];
        
        const switcher = document.getElementById('siteSwitcher');
        switcher.innerHTML = '';
        
        allSites.forEach(site => {
            const option = document.createElement('option');
            option.value = site.id;
            option.textContent = site.domain;
            option.selected = site.id == siteId;
            switcher.appendChild(option);
        });
    } catch (error) {
        console.error('Error loading sites for switcher:', error);
    }
}

// Switch to different site
function switchSite(newSiteId) {
    if (newSiteId && newSiteId != siteId) {
        // Check if there are unsaved changes
        if (hasUnsavedChanges()) {
            if (!confirm('You have unsaved changes. Do you want to discard them and switch sites?')) {
                // Revert the select back
                document.getElementById('siteSwitcher').value = siteId;
                return;
            }
        }
        
        // Navigate to the new site
        window.location.href = `site-editor.html?id=${newSiteId}`;
    }
}

// Check if there are unsaved changes (simple version)
function hasUnsavedChanges() {
    // This is a simplified check - in production you'd compare current form values with loaded data
    return false; // For now, always allow switching
}

// Setup Tabs
function setupTabs() {
    const tabBtns = document.querySelectorAll('.tab-btn');
    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const tabName = btn.dataset.tab;
            switchTab(tabName);
        });
    });
}

function switchTab(tabName) {
    // Update buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
        if (btn.dataset.tab === tabName) {
            btn.classList.add('active');
        }
    });
    
    // Update content
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    document.getElementById(`${tabName}-tab`).classList.add('active');
}

// Setup Range Sliders
function setupRangeSliders() {
    const compressionLevel = document.getElementById('compression_level');
    const compressionValue = document.getElementById('compression_level_value');
    compressionLevel.addEventListener('input', (e) => {
        compressionValue.textContent = e.target.value;
    });
    
    const imageQuality = document.getElementById('image_quality');
    const qualityValue = document.getElementById('image_quality_value');
    imageQuality.addEventListener('input', (e) => {
        qualityValue.textContent = e.target.value;
    });
}

// Setup Rate Limit Selector
function setupRateLimitSelector() {
    const rateLimitZone = document.getElementById('rate_limit_zone');
    const customRateGroup = document.getElementById('custom_rate_group');
    
    rateLimitZone.addEventListener('change', (e) => {
        if (e.target.value === 'custom') {
            customRateGroup.style.display = 'block';
        } else {
            customRateGroup.style.display = 'none';
        }
    });
}

// Setup Backend Tab
function setupBackendsTab() {
    const lbMethod = document.getElementById('lb_method');
    const hashKeyGroup = document.getElementById('hash_key_group');
    const healthCheckEnabled = document.getElementById('health_check_enabled');
    const healthCheckSettings = document.getElementById('health-check-settings');
    
    if (lbMethod) {
        lbMethod.addEventListener('change', (e) => {
            if (e.target.value === 'hash') {
                hashKeyGroup.style.display = 'block';
            } else {
                hashKeyGroup.style.display = 'none';
            }
        });
    }
    
    if (healthCheckEnabled) {
        healthCheckEnabled.addEventListener('change', (e) => {
            if (e.target.checked) {
                healthCheckSettings.style.display = 'block';
            } else {
                healthCheckSettings.style.display = 'none';
            }
        });
    }
}

// Setup Conditional Displays
function setupConditionalDisplays() {
    // SSL Challenge Type - show Cloudflare fields when dns-01 is selected
    const sslChallengeType = document.getElementById('ssl_challenge_type');
    const cloudflareSettings = document.getElementById('cloudflare-settings');
    
    if (sslChallengeType && cloudflareSettings) {
        sslChallengeType.addEventListener('change', (e) => {
            if (e.target.value === 'dns-01') {
                cloudflareSettings.style.display = 'block';
            } else {
                cloudflareSettings.style.display = 'none';
            }
        });
    }
    
    // Challenge Mode - show challenge settings when enabled
    const challengeEnabled = document.getElementById('challenge_enabled');
    const challengeSettings = document.getElementById('challenge-settings');
    
    if (challengeEnabled && challengeSettings) {
        challengeEnabled.addEventListener('change', (e) => {
            if (e.target.checked) {
                challengeSettings.style.display = 'block';
            } else {
                challengeSettings.style.display = 'none';
            }
        });
    }
    
    // Challenge Difficulty - update value display
    const challengeDifficulty = document.getElementById('challenge_difficulty');
    const difficultyValue = document.getElementById('challenge_difficulty_value');
    
    if (challengeDifficulty && difficultyValue) {
        challengeDifficulty.addEventListener('input', (e) => {
            const levels = ['Very Easy', 'Easy', 'Normal', 'Hard', 'Very Hard'];
            difficultyValue.textContent = levels[parseInt(e.target.value) - 1] || 'Normal';
        });
    }
}

// Load Site Data
async function loadSiteData() {
    try {
        const response = await fetch(`${API_BASE_URL}/sites/${siteId}`, {
            headers: {
                'Authorization': `Bearer ${API_TOKEN}`
            }
        });
        
        if (!response.ok) {
            throw new Error('Failed to load site data');
        }
        
        const data = await response.json();
        siteData = data.site;
        
        populateForm(siteData);
        updateHeader(siteData);
        
        showToast('success', 'Site loaded successfully');
    } catch (error) {
        console.error('Error loading site:', error);
        showToast('error', 'Failed to load site data');
    }
}

// Populate Form
function populateForm(data) {
    // General
    document.getElementById('domain').value = data.domain || '';
    document.getElementById('wildcard_subdomains').checked = data.wildcard_subdomains == 1;
    document.getElementById('backend_url').value = data.backend_url || '';
    document.getElementById('enabled').checked = data.enabled == 1;
    document.getElementById('ssl_enabled').checked = data.ssl_enabled == 1;
    document.getElementById('ssl_challenge_type').value = data.ssl_challenge_type || 'http-01';
    document.getElementById('cf_api_token').value = data.cf_api_token || '';
    document.getElementById('cf_zone_id').value = data.cf_zone_id || '';
    document.getElementById('ssl_cert_path').value = data.ssl_cert_path || '';
    document.getElementById('ssl_key_path').value = data.ssl_key_path || '';
    
    // Security
    document.getElementById('enable_modsecurity').checked = data.enable_modsecurity == 1;
    document.getElementById('enable_bot_protection').checked = data.enable_bot_protection !== 0;
    document.getElementById('bot_protection_level').value = data.bot_protection_level || 'medium';
    document.getElementById('enable_rate_limit').checked = data.enable_rate_limit !== 0;
    document.getElementById('rate_limit_zone').value = data.rate_limit_zone || 'general';
    document.getElementById('rate_limit_burst').value = data.rate_limit_burst || 20;
    
    // Challenge Mode
    document.getElementById('challenge_enabled').checked = data.challenge_enabled == 1;
    document.getElementById('challenge_difficulty').value = data.challenge_difficulty || 3;
    document.getElementById('challenge_duration').value = data.challenge_duration || 3600;
    document.getElementById('challenge_bypass_cf').checked = data.challenge_bypass_cf == 1;
    
    // Performance
    document.getElementById('enable_gzip').checked = data.enable_gzip !== 0;
    document.getElementById('enable_brotli').checked = data.enable_brotli !== 0;
    document.getElementById('compression_level').value = data.compression_level || 6;
    document.getElementById('compression_level_value').textContent = data.compression_level || 6;
    document.getElementById('compression_types').value = data.compression_types || 'text/html text/css text/javascript application/json application/xml';
    document.getElementById('enable_caching').checked = data.enable_caching !== 0;
    document.getElementById('cache_duration').value = data.cache_duration || 3600;
    document.getElementById('cache_static_files').checked = data.cache_static_files !== 0;
    document.getElementById('cache_max_size').value = data.cache_max_size || '1g';
    document.getElementById('cache_path').value = data.cache_path || '/var/cache/nginx';
    document.getElementById('enable_image_optimization').checked = data.enable_image_optimization == 1;
    document.getElementById('image_quality').value = data.image_quality || 85;
    document.getElementById('image_quality_value').textContent = data.image_quality || 85;
    document.getElementById('image_max_width').value = data.image_max_width || 1920;
    document.getElementById('image_webp_conversion').checked = data.image_webp_conversion == 1;
    
    // Access Control
    document.getElementById('enable_geoip_blocking').checked = data.enable_geoip_blocking == 1;
    document.getElementById('blocked_countries').value = data.blocked_countries || '';
    document.getElementById('allowed_countries').value = data.allowed_countries || '';
    document.getElementById('ip_whitelist').value = data.ip_whitelist || '';
    
    // Basic Auth (if custom_config contains it)
    try {
        const customConfig = JSON.parse(data.custom_config || '{}');
        if (customConfig.basic_auth) {
            document.getElementById('enable_basic_auth').checked = true;
            document.getElementById('auth_username').value = customConfig.basic_auth.username || '';
        }
    } catch (e) {
        // Invalid JSON, ignore
    }
    
    // Advanced
    document.getElementById('enable_waf_headers').checked = data.enable_waf_headers !== 0;
    document.getElementById('enable_telemetry').checked = data.enable_telemetry !== 0;
    document.getElementById('custom_headers').value = data.custom_headers || '';
    
    // Error Pages
    document.getElementById('error_page_404').value = data.error_page_404 || '';
    document.getElementById('error_page_403').value = data.error_page_403 || '';
    document.getElementById('error_page_429').value = data.error_page_429 || '';
    document.getElementById('error_page_500').value = data.error_page_500 || '';
    
    // Security.txt
    document.getElementById('security_txt').value = data.security_txt || '';
    
    // Backends
    if (data.backends) {
        try {
            backends = JSON.parse(data.backends);
            if (backends.length === 0 && data.backend_url) {
                // Migrate from single backend_url to backends array
                backends = [{
                    id: 0,
                    address: data.backend_url,
                    weight: 1,
                    max_fails: 3,
                    fail_timeout: 30,
                    backup: false,
                    down: false
                }];
            }
            backendIdCounter = backends.length > 0 ? Math.max(...backends.map(b => b.id || 0)) + 1 : 0;
            loadBackends();
        } catch (e) {
            console.error('Error parsing backends:', e);
            backends = [];
            // Fallback to backend_url if available
            if (data.backend_url) {
                backends = [{
                    id: 0,
                    address: data.backend_url,
                    weight: 1,
                    max_fails: 3,
                    fail_timeout: 30,
                    backup: false,
                    down: false
                }];
                backendIdCounter = 1;
            }
        }
    } else if (data.backend_url) {
        // No backends JSON, use backend_url
        backends = [{
            id: 0,
            address: data.backend_url,
            weight: 1,
            max_fails: 3,
            fail_timeout: 30,
            backup: false,
            down: false
        }];
        backendIdCounter = 1;
    }
    
    document.getElementById('lb_method').value = data.lb_method || 'round_robin';
    document.getElementById('hash_key').value = data.hash_key || '$request_uri';
    document.getElementById('health_check_enabled').checked = data.health_check_enabled == 1;
    document.getElementById('health_check_interval').value = data.health_check_interval || 10;
    document.getElementById('health_check_path').value = data.health_check_path || '/health';
    
    // Trigger changes
    document.getElementById('lb_method').dispatchEvent(new Event('change'));
    document.getElementById('health_check_enabled').dispatchEvent(new Event('change'));
    
    // Trigger rate limit selector change
    document.getElementById('rate_limit_zone').dispatchEvent(new Event('change'));
    
    // Trigger conditional display events
    const sslChallengeType = document.getElementById('ssl_challenge_type');
    if (sslChallengeType) sslChallengeType.dispatchEvent(new Event('change'));
    
    const challengeEnabled = document.getElementById('challenge_enabled');
    if (challengeEnabled) challengeEnabled.dispatchEvent(new Event('change'));
    
    const challengeDifficulty = document.getElementById('challenge_difficulty');
    if (challengeDifficulty) challengeDifficulty.dispatchEvent(new Event('input'));
}

// Update Header
function updateHeader(data) {
    document.getElementById('siteDomain').textContent = data.domain;
    const statusDot = document.getElementById('siteStatusDot');
    if (data.enabled == 1) {
        statusDot.classList.add('status-active');
        statusDot.classList.remove('status-inactive');
    } else {
        statusDot.classList.add('status-inactive');
        statusDot.classList.remove('status-active');
    }
}

// Save Site
async function saveSite() {
    try {
        const formData = collectFormData();
        
        const response = await fetch(`${API_BASE_URL}/sites/${siteId}`, {
            method: 'PUT',
            headers: {
                'Authorization': `Bearer ${API_TOKEN}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(formData)
        });
        
        if (!response.ok) {
            const error = await response.json();
            throw new Error(error.error || 'Failed to save site');
        }
        
        showToast('success', '✅ Site saved successfully! Config will reload in ~5 seconds.');
        
        // Reload site data to get updated values
        setTimeout(() => loadSiteData(), 1000);
        
    } catch (error) {
        console.error('Error saving site:', error);
        showToast('error', `Failed to save: ${error.message}`);
    }
}

// Collect Form Data
function collectFormData() {
    // Update backends from UI before collecting
    updateBackendFromUI();
    
    const data = {
        domain: document.getElementById('domain').value,
        wildcard_subdomains: document.getElementById('wildcard_subdomains').checked ? 1 : 0,
        enabled: document.getElementById('enabled').checked ? 1 : 0,
        ssl_enabled: document.getElementById('ssl_enabled').checked ? 1 : 0,
        ssl_challenge_type: document.getElementById('ssl_challenge_type').value,
        cf_api_token: document.getElementById('cf_api_token').value,
        cf_zone_id: document.getElementById('cf_zone_id').value,
        ssl_cert_path: document.getElementById('ssl_cert_path').value,
        ssl_key_path: document.getElementById('ssl_key_path').value,
        
        // Security
        enable_modsecurity: document.getElementById('enable_modsecurity').checked ? 1 : 0,
        enable_bot_protection: document.getElementById('enable_bot_protection').checked ? 1 : 0,
        bot_protection_level: document.getElementById('bot_protection_level').value,
        enable_rate_limit: document.getElementById('enable_rate_limit').checked ? 1 : 0,
        rate_limit_zone: document.getElementById('rate_limit_zone').value,
        rate_limit_burst: parseInt(document.getElementById('rate_limit_burst').value),
        
        // Challenge Mode
        challenge_enabled: document.getElementById('challenge_enabled').checked ? 1 : 0,
        challenge_difficulty: parseInt(document.getElementById('challenge_difficulty').value),
        challenge_duration: parseInt(document.getElementById('challenge_duration').value),
        challenge_bypass_cf: document.getElementById('challenge_bypass_cf').checked ? 1 : 0,
        
        // Performance
        enable_gzip: document.getElementById('enable_gzip').checked ? 1 : 0,
        enable_brotli: document.getElementById('enable_brotli').checked ? 1 : 0,
        compression_level: parseInt(document.getElementById('compression_level').value),
        compression_types: document.getElementById('compression_types').value,
        enable_caching: document.getElementById('enable_caching').checked ? 1 : 0,
        cache_duration: parseInt(document.getElementById('cache_duration').value),
        cache_static_files: document.getElementById('cache_static_files').checked ? 1 : 0,
        cache_max_size: document.getElementById('cache_max_size').value,
        cache_path: document.getElementById('cache_path').value,
        enable_image_optimization: document.getElementById('enable_image_optimization').checked ? 1 : 0,
        image_quality: parseInt(document.getElementById('image_quality').value),
        image_max_width: parseInt(document.getElementById('image_max_width').value),
        image_webp_conversion: document.getElementById('image_webp_conversion').checked ? 1 : 0,
        
        // Access Control
        enable_geoip_blocking: document.getElementById('enable_geoip_blocking').checked ? 1 : 0,
        blocked_countries: document.getElementById('blocked_countries').value,
        allowed_countries: document.getElementById('allowed_countries').value,
        ip_whitelist: document.getElementById('ip_whitelist').value,
        
        // Advanced
        enable_waf_headers: document.getElementById('enable_waf_headers').checked ? 1 : 0,
        enable_telemetry: document.getElementById('enable_telemetry').checked ? 1 : 0,
        custom_headers: document.getElementById('custom_headers').value,
        
        // Error Pages
        error_page_404: document.getElementById('error_page_404').value,
        error_page_403: document.getElementById('error_page_403').value,
        error_page_429: document.getElementById('error_page_429').value,
        error_page_500: document.getElementById('error_page_500').value,
        
        // Security.txt
        security_txt: document.getElementById('security_txt').value
    };
    
    // Handle basic auth in custom_config
    const enableBasicAuth = document.getElementById('enable_basic_auth').checked;
    const authUsername = document.getElementById('auth_username').value;
    const authPassword = document.getElementById('auth_password').value;
    
    if (enableBasicAuth && authUsername) {
        const customConfig = {
            basic_auth: {
                username: authUsername
            }
        };
        if (authPassword) {
            customConfig.basic_auth.password = authPassword;
        }
        data.custom_config = JSON.stringify(customConfig);
    }
    
    // Backends - prioritize backends array, fallback to backend_url for backward compatibility
    updateBackendFromUI(); // Make sure we have latest data
    if (backends.length > 0) {
        data.backends = JSON.stringify(backends);
        // Don't send backend_url if using backends
    } else {
        // No backends configured, use single backend_url
        data.backend_url = document.getElementById('backend_url').value;
    }
    data.lb_method = document.getElementById('lb_method').value;
    data.hash_key = document.getElementById('hash_key')?.value || '$request_uri';
    data.health_check_enabled = document.getElementById('health_check_enabled').checked ? 1 : 0;
    data.health_check_interval = parseInt(document.getElementById('health_check_interval').value) || 10;
    data.health_check_path = document.getElementById('health_check_path').value || '/health';
    
    return data;
}

// Backend Management Functions
let backends = [];
let backendIdCounter = 0;

function loadBackends() {
    const backendsList = document.getElementById('backends-list');
    if (!backendsList) return; // Not on backends tab
    
    backendsList.innerHTML = '';
    
    if (backends.length === 0) {
        backendsList.innerHTML = '<p style="color: #666; text-align: center; padding: 2rem;">No backend servers configured. Add one to get started.</p>';
        return;
    }
    
    backends.forEach((backend, index) => {
        const backendCard = document.createElement('div');
        backendCard.className = 'backend-card';
        backendCard.innerHTML = `
            <div class="backend-header">
                <h3>Backend ${index + 1}</h3>
                <button class="btn-danger-small" onclick="removeBackend(${backend.id})">🗑️ Remove</button>
            </div>
            <div class="backend-body">
                <div class="form-group">
                    <label>Server Address *</label>
                    <input type="text" class="backend-address" data-id="${backend.id}" value="${backend.address || ''}" placeholder="192.168.1.100:8080">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Weight</label>
                        <input type="number" class="backend-weight" data-id="${backend.id}" value="${backend.weight || 1}" min="1" max="100">
                    </div>
                    <div class="form-group">
                        <label>Max Fails</label>
                        <input type="number" class="backend-max-fails" data-id="${backend.id}" value="${backend.max_fails || 3}" min="1" max="10">
                    </div>
                    <div class="form-group">
                        <label>Fail Timeout (s)</label>
                        <input type="number" class="backend-fail-timeout" data-id="${backend.id}" value="${backend.fail_timeout || 30}" min="1" max="300">
                    </div>
                </div>
                <div class="form-row">
                    <label class="checkbox-label">
                        <input type="checkbox" class="backend-backup" data-id="${backend.id}" ${backend.backup ? 'checked' : ''}>
                        <span>Backup Server</span>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" class="backend-down" data-id="${backend.id}" ${backend.down ? 'checked' : ''}>
                        <span>Mark as Down</span>
                    </label>
                </div>
            </div>
        `;
        backendsList.appendChild(backendCard);
    });
    
    // Add event listeners
    document.querySelectorAll('.backend-address').forEach(input => {
        input.addEventListener('input', () => updateBackendFromUI());
    });
    document.querySelectorAll('.backend-weight, .backend-max-fails, .backend-fail-timeout').forEach(input => {
        input.addEventListener('input', () => updateBackendFromUI());
    });
    document.querySelectorAll('.backend-backup, .backend-down').forEach(input => {
        input.addEventListener('change', () => updateBackendFromUI());
    });
}

function updateBackendFromUI() {
    backends = backends.map(backend => {
        const address = document.querySelector(`.backend-address[data-id="${backend.id}"]`)?.value || '';
        const weight = parseInt(document.querySelector(`.backend-weight[data-id="${backend.id}"]`)?.value) || 1;
        const max_fails = parseInt(document.querySelector(`.backend-max-fails[data-id="${backend.id}"]`)?.value) || 3;
        const fail_timeout = parseInt(document.querySelector(`.backend-fail-timeout[data-id="${backend.id}"]`)?.value) || 30;
        const backup = document.querySelector(`.backend-backup[data-id="${backend.id}"]`)?.checked || false;
        const down = document.querySelector(`.backend-down[data-id="${backend.id}"]`)?.checked || false;
        
        return {
            ...backend,
            address,
            weight,
            max_fails,
            fail_timeout,
            backup,
            down
        };
    });
}

function addBackend() {
    const newBackend = {
        id: backendIdCounter++,
        address: '',
        weight: 1,
        max_fails: 3,
        fail_timeout: 30,
        backup: false,
        down: false
    };
    backends.push(newBackend);
    loadBackends();
}

function removeBackend(id) {
    if (confirm('Are you sure you want to remove this backend server?')) {
        backends = backends.filter(b => b.id !== id);
        loadBackends();
        showToast('info', 'Backend removed. Remember to save changes.');
    }
}

// Test Site Config
async function testSite() {
    showToast('info', 'Testing configuration...');
    
    try {
        // For now, just validate form data
        const formData = collectFormData();
        
        if (!formData.domain) {
            throw new Error('Domain is required');
        }
        
        if (!formData.backend_url) {
            throw new Error('Backend URL is required');
        }
        
        // Validate backend URL format
        const backendPattern = /^[a-zA-Z0-9.-]+:[0-9]+$/;
        if (!backendPattern.test(formData.backend_url.replace(/^https?:\/\//, ''))) {
            throw new Error('Backend URL must be in format hostname:port or IP:port');
        }
        
        showToast('success', '✅ Configuration looks good!');
        
    } catch (error) {
        showToast('error', `Validation failed: ${error.message}`);
    }
}

// Toast Notifications
function showToast(type, message) {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        <div class="toast-content">
            <span class="toast-icon">${type === 'success' ? '✅' : type === 'error' ? '❌' : 'ℹ️'}</span>
            <span class="toast-message">${message}</span>
        </div>
        <button class="toast-close" onclick="this.parentElement.remove()">✕</button>
    `;
    
    document.getElementById('toastContainer').appendChild(toast);
    
    setTimeout(() => {
        toast.classList.add('toast-show');
    }, 10);
    
    setTimeout(() => {
        toast.classList.remove('toast-show');
        setTimeout(() => toast.remove(), 300);
    }, 5000);
}
