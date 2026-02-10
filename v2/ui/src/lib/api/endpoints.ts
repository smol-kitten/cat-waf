// CatWAF v2 - API Endpoints
// Type-safe API functions matching backend modules

import { api } from './client';
import type {
	LoginRequest,
	LoginResponse,
	User,
	Site,
	Backend,
	PathRoute,
	SiteStats,
	BanEntry,
	BanStats,
	SecurityEvent,
	SecuritySummary,
	CustomBlockRule,
	BotStats,
	BotDetection,
	BotWhitelistRule,
	Certificate,
	CertificateStatus,
	AlertRule,
	AlertHistoryEntry,
	SystemSettings,
	BackupInfo,
	DashboardStats,
	TrafficData,
	LogEntry,
	Job,
	Task,
	Router,
	RouterNode,
	RSLRegion,
	SiteRSLConfig,
	TelemetryConfig,
	Metrics,
	InsightsSummary,
	GeoLookupResult
} from './types';

// === Auth ===
export const authApi = {
	login: (data: LoginRequest) => api.post<LoginResponse>('/auth/login', data),
	logout: () => api.post<void>('/auth/logout'),
	me: () => api.get<{ user: User }>('/auth/me'),
	refresh: () => api.post<{ token: string; expiresAt: string }>('/auth/refresh'),
	generateApiKey: (name: string) =>
		api.post<{ key: string; keyPrefix: string }>('/auth/api-key', { name })
};

// === Sites ===
export const sitesApi = {
	list: () => api.get<{ sites: Site[] }>('/sites'),
	get: (id: string) => api.get<{ site: Site }>(`/sites/${id}`),
	create: (site: Partial<Site>) => api.post<{ site: Site }>('/sites', site),
	update: (id: string, site: Partial<Site>) =>
		api.put<{ success: boolean }>(`/sites/${id}`, site),
	delete: (id: string) => api.delete<{ success: boolean }>(`/sites/${id}`),
	duplicate: (id: string, newDomain: string) =>
		api.post<{ id: string; domain: string }>(`/sites/${id}/duplicate`, { newDomain }),
	toggle: (id: string) => api.post<{ success: boolean }>(`/sites/${id}/toggle`),
	stats: (id: string) => api.get<{ stats: SiteStats }>(`/sites/${id}/stats`),
	suggestions: () =>
		api.get<{ suggestions: Array<{ name: string; wafMode: string; rateLimitRps: number }> }>(
			'/sites/suggestions'
		),
	import: (data: unknown) => api.post<{ imported: number }>('/sites/import', data),
	export: () => api.get<{ siteIds: string[]; count: number }>('/sites/export'),
	exportSingle: (id: string) => api.get<{ site: Site }>(`/sites/${id}/export`),

	// Backends
	backends: {
		list: (siteId: string) => api.get<{ backends: Backend[] }>(`/sites/${siteId}/backends`),
		add: (siteId: string, backend: Partial<Backend>) =>
			api.post<{ backend: Backend }>(`/sites/${siteId}/backends`, backend),
		update: (siteId: string, backendId: string, backend: Partial<Backend>) =>
			api.put<{ success: boolean }>(`/sites/${siteId}/backends/${backendId}`, backend),
		delete: (siteId: string, backendId: string) =>
			api.delete<{ success: boolean }>(`/sites/${siteId}/backends/${backendId}`),
		healthCheck: (siteId: string, backendId: string) =>
			api.post<{ backendId: string; healthy: boolean; latency: number }>(
				`/sites/${siteId}/backends/${backendId}/health-check`
			)
	},

	// Path routes
	pathRoutes: {
		list: (siteId: string) => api.get<{ pathRoutes: PathRoute[] }>(`/sites/${siteId}/path-routes`),
		add: (siteId: string, route: Partial<PathRoute>) =>
			api.post<{ pathRoute: PathRoute }>(`/sites/${siteId}/path-routes`, route),
		update: (siteId: string, routeId: string, route: Partial<PathRoute>) =>
			api.put<{ success: boolean }>(`/sites/${siteId}/path-routes/${routeId}`, route),
		delete: (siteId: string, routeId: string) =>
			api.delete<{ success: boolean }>(`/sites/${siteId}/path-routes/${routeId}`)
	},

	// Well-known files
	wellknown: {
		list: (siteId: string) =>
			api.get<{ files: Array<{ id: string; path: string; content: string; contentType: string }> }>(
				`/sites/${siteId}/wellknown`
			),
		add: (siteId: string, file: { path: string; content: string; contentType: string }) =>
			api.post<{ id: string }>(`/sites/${siteId}/wellknown`, file),
		update: (siteId: string, fileId: string, data: { content: string; contentType: string }) =>
			api.put<{ success: boolean }>(`/sites/${siteId}/wellknown/${fileId}`, data),
		delete: (siteId: string, fileId: string) =>
			api.delete<{ success: boolean }>(`/sites/${siteId}/wellknown/${fileId}`)
	},

	// Error pages
	errorPages: {
		list: (siteId: string) => api.get<{ errorPages: Record<string, string> }>(`/sites/${siteId}/error-pages`),
		update: (siteId: string, code: string, template: string) =>
			api.put<{ success: boolean }>(`/sites/${siteId}/error-pages/${code}`, { template })
	}
};

// === Bans ===
export const bansApi = {
	list: (params?: { page?: number; pageSize?: number; site?: string }) => {
		const query = new URLSearchParams();
		if (params?.page) query.set('page', String(params.page));
		if (params?.pageSize) query.set('pageSize', String(params.pageSize));
		if (params?.site) query.set('site', params.site);
		return api.get<{ bans: BanEntry[]; total: number }>(`/bans?${query}`);
	},
	get: (id: string) => api.get<{ ban: BanEntry }>(`/bans/${id}`),
	create: (ban: { ipAddress: string; reason: string; siteId?: string; expiresAt?: string }) =>
		api.post<{ ban: BanEntry }>('/bans', ban),
	delete: (id: string) => api.delete<{ success: boolean }>(`/bans/${id}`),
	deleteByIp: (ip: string) => api.delete<{ success: boolean }>(`/bans/ip/${ip}`),
	check: (ip: string) => api.get<{ banned: boolean; ban?: BanEntry }>(`/bans/check/${ip}`),
	bulkCreate: (ips: string[], reason: string) =>
		api.post<{ created: number; failed: number }>('/bans/bulk', { ips, reason }),
	bulkDelete: (ids: string[]) => api.delete<{ deleted: number }>('/bans/bulk'),
	stats: () => api.get<{ stats: BanStats }>('/bans/stats')
};

// === Security ===
export const securityApi = {
	events: {
		list: (params?: { page?: number; pageSize?: number; severity?: string; siteId?: string }) => {
			const query = new URLSearchParams();
			if (params?.page) query.set('page', String(params.page));
			if (params?.pageSize) query.set('pageSize', String(params.pageSize));
			if (params?.severity) query.set('severity', params.severity);
			if (params?.siteId) query.set('siteId', params.siteId);
			return api.get<{ events: SecurityEvent[]; total: number }>(`/security/events?${query}`);
		},
		summary: (hours?: number) =>
			api.get<{ summary: SecuritySummary }>(`/security/events/summary?hours=${hours || 24}`),
		topRules: (limit?: number) =>
			api.get<{ rules: Array<{ ruleId: string; count: number }> }>(
				`/security/events/top-rules?limit=${limit || 10}`
			),
		topIps: (limit?: number) =>
			api.get<{ ips: Array<{ ip: string; count: number }> }>(
				`/security/events/top-ips?limit=${limit || 10}`
			)
	},
	checks: {
		runAll: (siteId?: string) =>
			api.post<{ results: Array<{ check: string; status: string; message: string }> }>(
				'/security/checks/run-all',
				siteId ? { siteId } : {}
			),
		run: (checkId: string) =>
			api.post<{ result: { check: string; status: string; message: string } }>(
				`/security/checks/${checkId}/run`
			),
		list: () =>
			api.get<{ checks: Array<{ id: string; name: string; status: string; lastRun: string; message: string }> }>(
				'/security/checks'
			)
	},
	modsec: {
		stats: () =>
			api.get<{ stats: { totalRules: number; blockedRequests: number; warnings: number; paranoiaLevel: number } }>(
				'/security/modsec/stats'
			),
		events: (params?: { page?: number; pageSize?: number; severity?: string }) => {
			const query = new URLSearchParams();
			if (params?.page) query.set('page', String(params.page));
			if (params?.pageSize) query.set('pageSize', String(params.pageSize));
			if (params?.severity) query.set('severity', params.severity);
			return api.get<{ events: Array<Record<string, unknown>>; total: number }>(`/security/modsec/events?${query}`);
		},
		topRules: (limit?: number) =>
			api.get<{ rules: Array<{ ruleId: string; ruleName: string; count: number }> }>(
				`/security/modsec/top-rules?limit=${limit || 10}`
			)
	},
	scanners: {
		list: () =>
			api.get<{ scanners: Array<{ ip: string; type: string; requests: number; firstSeen: string; lastSeen: string; country: string; blocked: boolean }>; total: number; blocked: number; monitoring: number; totalRequests: number }>(
				'/security/scanners'
			),
		block: (ip: string) => api.post<{ success: boolean }>(`/security/scanners/${ip}/block`)
	},
	bans: {
		create: (ban: { ip: string; reason: string }) =>
			api.post<{ success: boolean }>('/bans', { ipAddress: ban.ip, reason: ban.reason })
	},
	rules: {
		list: () => api.get<{ rules: CustomBlockRule[] }>('/security/block-rules'),
		get: (id: string) => api.get<{ rule: CustomBlockRule }>(`/security/block-rules/${id}`),
		create: (rule: Partial<CustomBlockRule>) =>
			api.post<{ rule: CustomBlockRule }>('/security/block-rules', rule),
		update: (id: string, rule: Partial<CustomBlockRule>) =>
			api.put<{ success: boolean }>(`/security/block-rules/${id}`, rule),
		delete: (id: string) => api.delete<{ success: boolean }>(`/security/block-rules/${id}`)
	}
};

// === Bots ===
export const botsApi = {
	stats: (hours?: number) => api.get<{ stats: BotStats }>(`/bots/stats?hours=${hours || 24}`),
	detections: (params?: { page?: number; pageSize?: number; siteId?: string }) => {
		const query = new URLSearchParams();
		if (params?.page) query.set('page', String(params.page));
		if (params?.pageSize) query.set('pageSize', String(params.pageSize));
		if (params?.siteId) query.set('siteId', params.siteId);
		return api.get<{ detections: BotDetection[]; total: number }>(`/bots/detections?${query}`);
	},
	activity: (hours?: number) =>
		api.get<{ activity: Array<{ hour: string; count: number; blocked: number }> }>(
			`/bots/activity?hours=${hours || 24}`
		),
	whitelist: {
		list: () => api.get<{ rules: BotWhitelistRule[] }>('/bots/whitelist'),
		get: (id: string) => api.get<{ rule: BotWhitelistRule }>(`/bots/whitelist/${id}`),
		create: (rule: Partial<BotWhitelistRule>) =>
			api.post<{ rule: BotWhitelistRule }>('/bots/whitelist', rule),
		update: (id: string, rule: Partial<BotWhitelistRule>) =>
			api.put<{ success: boolean }>(`/bots/whitelist/${id}`, rule),
		delete: (id: string) => api.delete<{ success: boolean }>(`/bots/whitelist/${id}`)
	},
	quickAllow: (ip: string) => api.post<{ success: boolean }>('/bots/quick-allow', { ip }),
	quickBlock: (ip: string) => api.post<{ success: boolean }>('/bots/quick-block', { ip }),
	quickFlag: (ip: string, reason: string) =>
		api.post<{ success: boolean }>('/bots/quick-flag', { ip, reason }),
	regenerate: () => api.post<{ message: string }>('/bots/regenerate')
};

// === Certificates ===
export const certificatesApi = {
	list: () => api.get<{ certificates: Certificate[] }>('/certificates'),
	get: (domain: string) => api.get<{ certificate: Certificate }>(`/certificates/${domain}`),
	status: () => api.get<{ status: CertificateStatus }>('/certificates/status'),
	renew: (domain: string) => api.post<{ success: boolean; message: string }>(`/certificates/${domain}/renew`),
	upload: (data: { domain: string; certificatePem: string; privateKeyPem: string }) =>
		api.post<{ success: boolean; message: string }>('/certificates/upload', data),
	delete: (domain: string) => api.delete<{ success: boolean }>(`/certificates/${domain}`),
	ca: {
		list: () => api.get<{ certificates: Certificate[] }>('/certificates/ca'),
		issue: (data: { domain: string; commonName: string; validDays: number }) =>
			api.post<{ success: boolean; message: string }>('/certificates/ca/issue', data)
	}
};

// === Alerts ===
export const alertsApi = {
	list: () => api.get<{ alerts: AlertRule[] }>('/alerts'),
	get: (id: string) => api.get<{ alert: AlertRule }>(`/alerts/${id}`),
	create: (alert: Partial<AlertRule>) => api.post<{ alert: AlertRule }>('/alerts', alert),
	update: (id: string, alert: Partial<AlertRule>) =>
		api.put<{ success: boolean }>(`/alerts/${id}`, alert),
	delete: (id: string) => api.delete<{ success: boolean }>(`/alerts/${id}`),
	toggle: (id: string) => api.post<{ success: boolean; enabled: boolean }>(`/alerts/${id}/toggle`),
	history: (params?: { page?: number; pageSize?: number; alertId?: string }) => {
		const query = new URLSearchParams();
		if (params?.page) query.set('page', String(params.page));
		if (params?.pageSize) query.set('pageSize', String(params.pageSize));
		if (params?.alertId) query.set('alertId', params.alertId);
		return api.get<{ history: AlertHistoryEntry[]; total: number }>(`/alerts/history?${query}`);
	},
	acknowledge: (historyId: string) =>
		api.post<{ success: boolean }>(`/alerts/history/${historyId}/acknowledge`)
};

// === Settings ===
export const settingsApi = {
	// Get all settings
	getAll: () => api.get<{ settings: Record<string, unknown> }>('/settings'),
	// Update all settings at once
	updateAll: (settings: Record<string, unknown>) => api.post<{ success: boolean }>('/settings', settings),
	// Get specific settings by keys
	get: (...keys: string[]) => api.get<{ settings: SystemSettings }>(`/settings?keys=${keys.join(',')}`),
	getOne: (key: string) => api.get<{ key: string; value: unknown }>(`/settings/${key}`),
	set: (settings: SystemSettings) => api.put<{ success: boolean }>('/settings', settings),
	setOne: (key: string, value: unknown) => api.put<{ success: boolean }>(`/settings/${key}`, { value }),
	envDefaults: () => api.get<{ defaults: Record<string, unknown> }>('/settings/env-defaults'),
	// System info
	getSystemInfo: () => api.get<{ info: Record<string, unknown> }>('/settings/system'),
	// Test notifications
	testNotification: (type: string) => api.post<{ success: boolean; message: string }>('/settings/notifications/test', { type }),
	// Cleanup
	cleanup: () => api.post<{ deleted: number }>('/settings/system/cleanup', {}),
	// Backup/restore
	backup: () => api.get<{ data: Record<string, unknown> }>('/settings/backup'),
	restore: (data: Record<string, unknown>) => api.post<{ success: boolean }>('/settings/backup/import', data),
	backupOps: {
		list: () => api.get<{ backups: BackupInfo[] }>('/settings/backup'),
		create: () => api.post<{ backup: BackupInfo }>('/settings/backup/export'),
		restore: (id: string) => api.post<{ success: boolean; message: string }>(`/settings/backup/${id}/restore`),
		delete: (id: string) => api.delete<{ success: boolean }>(`/settings/backup/${id}`),
		download: (id: string) => `/api/v2/settings/backup/${id}/download`
	},
	system: {
		info: () => api.get<{ info: Record<string, unknown> }>('/settings/system'),
		cleanup: (options: { olderThanDays: number; types: string[] }) =>
			api.post<{ deleted: number }>('/settings/system/cleanup', options),
		testNotifications: (type: string) =>
			api.post<{ success: boolean; message: string }>('/settings/notifications/test', { type })
	}
};

// === Stats ===
export const statsApi = {
	dashboard: (hours?: number) =>
		api.get<{ stats: DashboardStats }>(`/stats/dashboard?hours=${hours || 24}`),
	traffic: (hours?: number) => api.get<{ traffic: TrafficData }>(`/stats/traffic?hours=${hours || 24}`),
	security: (hours?: number) => api.get<{ security: SecuritySummary }>(`/stats/security?hours=${hours || 24}`),
	sites: () => api.get<{ sites: Array<{ id: string; domain: string; requests: number; blocked: number }> }>('/stats/sites'),
	recentActivity: (limit?: number) =>
		api.get<{ activity: Array<{ id: string; type: string; message: string; timestamp: string }> }>(
			`/stats/recent-activity?limit=${limit || 20}`
		),
	trafficAnalysis: (timestamp: string) =>
		api.get<{ analysis: Record<string, unknown> }>(`/stats/traffic-analysis/${timestamp}`)
};

// === Logs ===
export const logsApi = {
	access: (params?: { domain?: string; limit?: number; offset?: number }) => {
		const query = new URLSearchParams();
		if (params?.domain) query.set('domain', params.domain);
		if (params?.limit) query.set('limit', String(params.limit));
		if (params?.offset) query.set('offset', String(params.offset));
		return api.get<{ logs: LogEntry[]; limit: number; offset: number }>(`/logs/access?${query}`);
	},
	error: (params?: { limit?: number; offset?: number }) => {
		const query = new URLSearchParams();
		if (params?.limit) query.set('limit', String(params.limit));
		if (params?.offset) query.set('offset', String(params.offset));
		return api.get<{ logs: LogEntry[]; limit: number; offset: number }>(`/logs/error?${query}`);
	},
	security: (params?: { severity?: string; limit?: number; offset?: number }) => {
		const query = new URLSearchParams();
		if (params?.severity) query.set('severity', params.severity);
		if (params?.limit) query.set('limit', String(params.limit));
		if (params?.offset) query.set('offset', String(params.offset));
		return api.get<{ logs: LogEntry[]; limit: number; offset: number }>(`/logs/security?${query}`);
	},
	modsec: (params?: { limit?: number }) =>
		api.get<{ logs: LogEntry[] }>(`/logs/modsec?limit=${params?.limit || 100}`),
	search: (q: string, type?: string, limit?: number) => {
		const query = new URLSearchParams({ q });
		if (type) query.set('type', type);
		if (limit) query.set('limit', String(limit));
		return api.get<{ results: LogEntry[]; query: string; type: string }>(`/logs/search?${query}`);
	},
	stats: (hours?: number) =>
		api.get<{
			stats: { totalRequests: number; securityEvents: number; uniqueIps: number; errorResponses: number };
		}>(`/logs/stats?hours=${hours || 24}`)
};

// === Config ===
export const configApi = {
	regenerate: (force?: boolean) =>
		api.post<{ jobId: string; message: string }>('/config/regenerate', { force }),
	regenerateSite: (siteId: string) =>
		api.post<{ jobId: string; siteId: string; message: string }>(`/config/regenerate/${siteId}`),
	preview: (siteId: string) =>
		api.get<{ siteId: string; domain: string; config: string }>(`/config/preview/${siteId}`),
	validate: () => api.get<{ valid: boolean; message: string }>('/config/validate'),
	status: () =>
		api.get<{
			lastRegeneration: string;
			configVersion: number;
			nginxStatus: string;
			pendingChanges: number;
		}>('/config/status'),
	jobs: {
		list: (limit?: number) => api.get<{ jobs: Job[] }>(`/config/jobs?limit=${limit || 50}`),
		get: (id: string) => api.get<{ job: Job }>(`/config/jobs/${id}`),
		cancel: (id: string) => api.post<{ success: boolean }>(`/config/jobs/${id}/cancel`)
	},
	tasks: {
		list: () => api.get<{ tasks: Task[] }>('/config/tasks'),
		run: (id: string) => api.post<{ taskId: string; message: string }>(`/config/tasks/${id}/run`)
	},
	cache: {
		stats: () =>
			api.get<{
				totalSize: number;
				totalFiles: number;
				hitRate: number;
				missRate: number;
				sites: Array<{ domain: string; size: number; files: number; hitRate: number }>;
			}>('/config/cache/stats'),
		purge: (params: { all?: boolean; site?: string }) =>
			api.post<{ success: boolean; message: string }>('/config/cache/purge', params),
		warm: (site: string) =>
			api.post<{ success: boolean; message: string }>(`/config/cache/warm/${site}`)
	}
};

// === Routers ===
export const routersApi = {
	list: () => api.get<{ routers: Router[] }>('/routers'),
	get: (id: string) => api.get<{ router: Router }>(`/routers/${id}`),
	create: (router: Partial<Router>) => api.post<{ router: Router }>('/routers', router),
	update: (id: string, router: Partial<Router>) =>
		api.put<{ success: boolean }>(`/routers/${id}`, router),
	delete: (id: string) => api.delete<{ success: boolean }>(`/routers/${id}`),
	sync: (id: string) => api.post<{ message: string }>(`/routers/${id}/sync`),
	nodes: {
		list: (routerId: string) => api.get<{ nodes: RouterNode[] }>(`/routers/${routerId}/nodes`),
		add: (routerId: string, node: Partial<RouterNode>) =>
			api.post<{ node: RouterNode }>(`/routers/${routerId}/nodes`, node),
		update: (routerId: string, nodeId: string, node: Partial<RouterNode>) =>
			api.put<{ success: boolean }>(`/routers/${routerId}/nodes/${nodeId}`, node),
		delete: (routerId: string, nodeId: string) =>
			api.delete<{ success: boolean }>(`/routers/${routerId}/nodes/${nodeId}`)
	}
};

// === RSL ===
export const rslApi = {
	// Regions (backends endpoints)
	regions: {
		list: () => api.get<{ regions: RSLRegion[] }>('/rsl/regions'),
		create: (region: Partial<RSLRegion>) => api.post<{ region: RSLRegion }>('/rsl/regions', region),
		update: (id: string, region: Partial<RSLRegion>) =>
			api.put<{ success: boolean }>(`/rsl/regions/${id}`, region),
		delete: (id: string) => api.delete<{ success: boolean }>(`/rsl/regions/${id}`)
	},
	// Licenses
	licenses: {
		list: () =>
			api.get<{ licenses: Array<{ id: string; name: string; description: string; scope: string; urlPattern: string; permits: string[]; prohibits: string[]; enabled: boolean; createdAt: string }> }>(
				'/rsl/licenses'
			),
		get: (id: string) => api.get<{ license: Record<string, unknown> }>(`/rsl/licenses/${id}`),
		create: (license: Record<string, unknown>) =>
			api.post<{ license: Record<string, unknown> }>('/rsl/licenses', license),
		update: (id: string, license: Record<string, unknown>) =>
			api.put<{ success: boolean }>(`/rsl/licenses/${id}`, license),
		delete: (id: string) => api.delete<{ success: boolean }>(`/rsl/licenses/${id}`),
		xml: (id: string) => api.get<{ xml: string }>(`/rsl/licenses/${id}/xml`)
	},
	// Clients
	clients: {
		list: () =>
			api.get<{ clients: Array<{ id: string; name: string; userAgent: string; requests: number; lastSeen: string; status: string }> }>(
				'/rsl/clients'
			),
		get: (id: string) => api.get<{ client: Record<string, unknown> }>(`/rsl/clients/${id}`)
	},
	// Tokens
	tokens: {
		list: () =>
			api.get<{ tokens: Array<{ id: string; name: string; token: string; lastUsed: string; uses: number; expiresAt: string; status: string }> }>(
				'/rsl/tokens'
			),
		create: (token: { name: string; expiresAt?: string }) =>
			api.post<{ token: { id: string; token: string } }>('/rsl/tokens', token),
		revoke: (id: string) => api.post<{ success: boolean }>(`/rsl/tokens/${id}/revoke`),
		delete: (id: string) => api.delete<{ success: boolean }>(`/rsl/tokens/${id}`)
	},
	// Stats
	stats: () =>
		api.get<{ stats: { licenses: number; clients: number; tokens: number; requests: number } }>(
			'/rsl/stats'
		),
	// Site config
	site: {
		get: (siteId: string) => api.get<{ config: SiteRSLConfig }>(`/rsl/sites/${siteId}`),
		update: (siteId: string, config: Partial<SiteRSLConfig>) =>
			api.put<{ success: boolean }>(`/rsl/sites/${siteId}`, config)
	},
	// Test
	test: (clientIp: string, domain: string) =>
		api.post<{ clientIp: string; domain: string; detectedRegion: string; selectedBackend: string }>(
			'/rsl/test',
			{ clientIp, domain }
		)
};

// === Telemetry ===
export const telemetryApi = {
	config: {
		get: () => api.get<{ config: TelemetryConfig }>('/telemetry/config'),
		update: (config: Partial<TelemetryConfig>) =>
			api.put<{ success: boolean }>('/telemetry/config', config)
	},
	metrics: () => api.get<{ metrics: Metrics }>('/telemetry/metrics'),
	prometheus: () => '/api/v2/telemetry/prometheus'
};

// === Insights ===
export const insightsApi = {
	summary: (hours?: number) =>
		api.get<{ insights: InsightsSummary; hours: number }>(`/insights?hours=${hours || 24}`),
	hourly: (hours?: number) =>
		api.get<{ data: Array<Record<string, unknown>>; hours: number }>(`/insights/hourly?hours=${hours || 24}`),
	daily: (days?: number) =>
		api.get<{ data: Array<Record<string, unknown>>; days: number }>(`/insights/daily?days=${days || 30}`),
	endpoints: (limit?: number) =>
		api.get<{ endpoints: Array<Record<string, unknown>> }>(`/insights/endpoints?limit=${limit || 20}`),
	geographic: () => api.get<{ countries: Array<Record<string, unknown>> }>('/insights/geographic'),
	responseTimes: (hours?: number) =>
		api.get<{ responseTimes: Array<Record<string, unknown>>; hours: number }>(
			`/insights/response-times?hours=${hours || 24}`
		),
	bandwidth: (hours?: number) =>
		api.get<{ bandwidth: { totalBytes: number; totalGb: number; byHour: Array<Record<string, unknown>> } }>(
			`/insights/bandwidth?hours=${hours || 24}`
		)
};

// === GeoIP ===
export const geoipApi = {
	lookup: (ip: string) => api.get<{ result: GeoLookupResult }>(`/geoip/lookup/${ip}`),
	status: () =>
		api.get<{ status: string; lastUpdate: string; databaseAge: string; type: string }>('/geoip/status'),
	update: () => api.post<{ message: string }>('/geoip/update')
};
