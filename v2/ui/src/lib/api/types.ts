// CatWAF v2 - TypeScript Types
// Matching backend Go types

// === Common Types ===
export interface ApiResponse<T> {
	success?: boolean;
	data?: T;
	error?: string;
	message?: string;
}

export interface PaginatedResponse<T> {
	items: T[];
	total: number;
	page: number;
	pageSize: number;
	hasMore: boolean;
}

// === Auth ===
export interface LoginRequest {
	apiKey?: string;
	email?: string;
	password?: string;
}

export interface LoginResponse {
	token: string;
	refreshToken: string;
	expiresAt: string;
	user: User;
}

export interface User {
	id: string;
	tenantId: string;
	email: string;
	role: string;
	settings: Record<string, unknown>;
	lastLoginAt?: string;
	createdAt: string;
}

// === Sites ===
export interface Site {
	id: string;
	tenantId?: string;
	domain: string;
	displayName?: string;
	aliases?: string[];
	enabled: boolean;
	
	// SSL/TLS
	sslEnabled?: boolean;
	ssl_enabled?: boolean;
	sslMode?: 'acme' | 'custom' | 'off';
	ssl_mode?: 'auto' | 'custom' | 'none';
	httpsRedirect?: boolean;
	http2Enabled?: boolean;
	certificate_id?: string;
	
	// WAF
	wafEnabled?: boolean;
	waf_enabled?: boolean;
	wafMode?: 'on' | 'off' | 'detect';
	waf_mode?: 'on' | 'detection' | 'off';
	
	// Rate Limiting
	rateLimitEnabled?: boolean;
	rate_limit_enabled?: boolean;
	rateLimitRps?: number;
	rate_limit_requests?: number;
	rate_limit_window?: number;
	
	// Bot Protection
	botProtectionEnabled?: boolean;
	block_bad_bots?: boolean;
	
	// GeoIP
	geoBlockEnabled?: boolean;
	geo_block_enabled?: boolean;
	geoBlockCountries?: string[];
	geo_block_countries?: string[];
	
	// Challenge Mode (DDoS)
	challenge_enabled?: boolean;
	challenge_difficulty?: number;
	challenge_duration?: number;
	challenge_bypass_cf?: boolean;
	
	// Compression
	enable_gzip?: boolean;
	enable_brotli?: boolean;
	compression_level?: number;
	
	// Basic Auth
	enable_basic_auth?: boolean;
	basic_auth_username?: string;
	basic_auth_password?: string;
	
	// Access Control
	ip_whitelist?: string;
	local_only?: boolean;
	use_cf_ip_headers?: boolean;
	wildcard_subdomains?: boolean;
	
	// WebSocket
	websocketEnabled?: boolean;
	websocket_enabled?: boolean;
	websocket_path?: string;
	
	// Load Balancing
	lb_method?: 'round_robin' | 'least_conn' | 'ip_hash' | 'hash';
	
	// NGINX Settings
	client_max_body_size?: string;
	proxy_read_timeout?: number;
	proxy_connect_timeout?: number;
	enable_hsts?: boolean;
	hsts_max_age?: number;
	custom_nginx_directives?: string;
	customNginxConfig?: string;
	
	// Related entities
	backends?: Backend[];
	pathRoutes?: PathRoute[];
	
	// Timestamps
	createdAt: string;
	updatedAt: string;
}

export interface Backend {
	id: string;
	siteId: string;
	address: string;
	port: number;
	weight: number;
	protocol: 'http' | 'https';
	maxFails: number;
	failTimeout: number;
	healthCheckPath?: string;
	healthy: boolean;
	primary: boolean;
}

export interface PathRoute {
	id: string;
	siteId: string;
	path: string;
	matchType: 'prefix' | 'exact' | 'regex';
	backendId?: string;
	redirectUrl?: string;
	redirectCode?: number;
	priority: number;
	enabled: boolean;
}

export interface SiteStats {
	totalRequests: number;
	blockedRequests: number;
	avgResponseTime: number;
	bandwidthGb: number;
}

// === Bans ===
export interface BanEntry {
	id: string;
	tenantId?: string;
	siteId?: string;
	siteDomain?: string;
	ipAddress: string;
	reason: string;
	source: 'manual' | 'fail2ban' | 'waf' | 'bot-protection';
	expiresAt?: string;
	createdAt: string;
}

export interface BanStats {
	total: number;
	permanent: number;
	temporary: number;
	bySource: Record<string, number>;
}

// === Security ===
export interface SecurityEvent {
	id: string;
	siteId: string;
	domain?: string;
	timestamp: string;
	clientIp: string;
	method?: string;
	path?: string;
	ruleId?: string;
	severity: 'low' | 'medium' | 'high' | 'critical';
	message: string;
	actionTaken: string;
	country?: string;
}

export interface SecuritySummary {
	total: number;
	bySeverity: Record<string, number>;
	byType: Record<string, number>;
	topRules: Array<{ ruleId: string; count: number }>;
	topIps: Array<{ ip: string; count: number }>;
}

export interface CustomBlockRule {
	id: string;
	tenantId?: string;
	siteId?: string;
	name: string;
	description?: string;
	ruleType: string;
	condition: Record<string, unknown>;
	action: 'block' | 'log' | 'challenge';
	priority: number;
	enabled: boolean;
	createdAt: string;
}

// === Bots ===
export interface BotStats {
	totalDetections: number;
	blocked: number;
	allowed: number;
	challenged: number;
	byType: Record<string, number>;
}

export interface BotDetection {
	id: string;
	siteId: string;
	domain?: string;
	timestamp: string;
	clientIp: string;
	userAgent?: string;
	botType: string;
	confidence: number;
	actionTaken: string;
}

export interface BotWhitelistRule {
	id: string;
	tenantId?: string;
	name: string;
	userAgentPattern?: string;
	ipRanges?: string[];
	enabled: boolean;
	createdAt: string;
}

// === Well-Known Files ===
export interface WellknownFile {
	id: string;
	path: string;
	content: string;
	contentType: string;
}

// === Certificates ===
export interface Certificate {
	id: string;
	siteId: string;
	domain: string;
	issuer: string;
	notBefore: string;
	notAfter: string;
	fingerprint: string;
	autoRenew: boolean;
	daysUntilExpiry: number;
	createdAt: string;
}

export interface CertificateStatus {
	total: number;
	valid: number;
	expiring: number;
	expired: number;
}

// === Alerts ===
export interface AlertRule {
	id: string;
	tenantId?: string;
	siteId?: string;
	name: string;
	description?: string;
	condition: Record<string, unknown>;
	actions: AlertAction[];
	cooldownMinutes: number;
	enabled: boolean;
	lastTriggeredAt?: string;
	createdAt: string;
}

export interface AlertAction {
	type: 'email' | 'webhook' | 'slack' | 'discord';
	config: Record<string, unknown>;
}

export interface AlertHistoryEntry {
	id: string;
	alertRuleId: string;
	ruleName?: string;
	triggeredAt: string;
	conditionMet: Record<string, unknown>;
	actionsTaken: string[];
	acknowledged: boolean;
	resolvedAt?: string;
}

// === Settings ===
export interface SystemSettings {
	[key: string]: unknown;
}

export interface BackupInfo {
	id: string;
	filename: string;
	size: number;
	createdAt: string;
}

// === Stats ===
export interface DashboardStats {
	totalRequests: number;
	blockedRequests: number;
	activeSites: number;
	activeBans: number;
	trafficTrend: number;
	blockRateTrend: number;
	recentActivity: RecentActivity[];
}

export interface RecentActivity {
	id: string;
	type: 'security_event' | 'site_change' | 'ban' | 'alert';
	message: string;
	timestamp: string;
}

export interface TrafficData {
	labels: string[];
	requests: number[];
	blocked: number[];
}

// === Logs ===
export interface LogEntry {
	id: string;
	timestamp: string;
	siteId?: string;
	domain?: string;
	type: 'access' | 'error' | 'security' | 'modsec';
	clientIp?: string;
	method?: string;
	path?: string;
	statusCode?: number;
	responseTime?: number;
	userAgent?: string;
	message?: string;
	ruleId?: string;
	severity?: string;
	country?: string;
}

// === Config/Jobs ===
export interface Job {
	id: string;
	type: string;
	status: 'pending' | 'running' | 'completed' | 'failed' | 'cancelled';
	progress: number;
	message?: string;
	result?: unknown;
	createdAt: string;
	updatedAt: string;
}

export interface Task {
	id: string;
	name: string;
	schedule: string;
	enabled: boolean;
	lastRun?: string;
	nextRun?: string;
	lastStatus?: string;
}

// === Routers/RSL ===
export interface Router {
	id: string;
	tenantId?: string;
	name: string;
	description?: string;
	endpoint: string;
	enabled: boolean;
	lastSync?: string;
	status: 'online' | 'offline' | 'syncing';
	nodes?: RouterNode[];
	createdAt: string;
}

export interface RouterNode {
	id: string;
	routerId: string;
	hostname: string;
	ipAddress: string;
	weight: number;
	healthy: boolean;
	region?: string;
}

export interface RSLRegion {
	id: string;
	tenantId?: string;
	name: string;
	code: string;
	countries?: string[];
	enabled: boolean;
}

export interface SiteRSLConfig {
	siteId: string;
	enabled: boolean;
	defaultRegion?: string;
	regionBackends?: Record<string, string[]>;
	failover: boolean;
}

// === Telemetry ===
export interface TelemetryConfig {
	enabled: boolean;
	collectionLevel: 'basic' | 'detailed' | 'full';
	retentionDays: number;
	anonymizeIps: boolean;
	prometheusEnabled: boolean;
	prometheusPath: string;
}

export interface Metrics {
	requestsPerSecond: number;
	activeConnections: number;
	memoryUsageMb: number;
	cpuPercent: number;
	uptimeSeconds: number;
}

export interface InsightsSummary {
	totalRequests: number;
	blockedRequests: number;
	uniqueVisitors: number;
	avgResponseTime: number;
	bandwidthBytes: number;
	topCountries: Array<{ country: string; count: number }>;
	topPaths: Array<{ path: string; count: number }>;
}

export interface GeoLookupResult {
	ip: string;
	country: string;
	countryName: string;
	region?: string;
	city?: string;
	latitude?: number;
	longitude?: number;
	asn?: number;
	asnOrg?: string;
}
