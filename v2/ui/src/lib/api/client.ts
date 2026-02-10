import { QueryClient } from '@tanstack/svelte-query';
import { browser } from '$app/environment';

// Create a query client with default options
export const queryClient = new QueryClient({
	defaultOptions: {
		queries: {
			staleTime: 1000 * 60 * 5, // 5 minutes
			gcTime: 1000 * 60 * 30, // 30 minutes (formerly cacheTime)
			retry: 1,
			refetchOnWindowFocus: false
		}
	}
});

// API base URL
export const API_BASE_URL = '/api/v2';

// Get auth token from localStorage
function getToken(): string | null {
	if (!browser) return null;
	return localStorage.getItem('catwaf_token');
}

// API error class
export class ApiError extends Error {
	constructor(
		public status: number,
		message: string,
		public data?: unknown
	) {
		super(message);
		this.name = 'ApiError';
	}
}

// Base fetch function with auth
export async function apiFetch<T>(
	endpoint: string,
	options: RequestInit = {}
): Promise<T> {
	const token = getToken();

	const headers: Record<string, string> = {
		'Content-Type': 'application/json',
		...(options.headers as Record<string, string>)
	};

	if (token) {
		headers['Authorization'] = `Bearer ${token}`;
	}

	const response = await fetch(`${API_BASE_URL}${endpoint}`, {
		...options,
		headers
	});

	if (!response.ok) {
		const data = await response.json().catch(() => null);
		throw new ApiError(
			response.status,
			data?.message || response.statusText,
			data
		);
	}

	// Handle 204 No Content
	if (response.status === 204) {
		return {} as T;
	}

	return response.json();
}

// HTTP method helpers
export const api = {
	get: <T>(endpoint: string) => apiFetch<T>(endpoint, { method: 'GET' }),

	post: <T>(endpoint: string, data?: unknown) =>
		apiFetch<T>(endpoint, {
			method: 'POST',
			body: data ? JSON.stringify(data) : undefined
		}),

	put: <T>(endpoint: string, data?: unknown) =>
		apiFetch<T>(endpoint, {
			method: 'PUT',
			body: data ? JSON.stringify(data) : undefined
		}),

	patch: <T>(endpoint: string, data?: unknown) =>
		apiFetch<T>(endpoint, {
			method: 'PATCH',
			body: data ? JSON.stringify(data) : undefined
		}),

	delete: <T>(endpoint: string) =>
		apiFetch<T>(endpoint, { method: 'DELETE' })
};

// Query key factory for consistent cache keys
export const queryKeys = {
	// Sites
	sites: {
		all: ['sites'] as const,
		list: (params?: Record<string, unknown>) =>
			['sites', 'list', params] as const,
		detail: (id: string) => ['sites', 'detail', id] as const
	},

	// Security
	security: {
		events: (params?: Record<string, unknown>) =>
			['security', 'events', params] as const,
		summary: () => ['security', 'summary'] as const
	},

	// Bots
	bots: {
		all: ['bots'] as const,
		detections: (params?: Record<string, unknown>) =>
			['bots', 'detections', params] as const,
		rules: () => ['bots', 'rules'] as const
	},

	// Bans
	bans: {
		all: ['bans'] as const,
		list: (params?: Record<string, unknown>) =>
			['bans', 'list', params] as const
	},

	// Insights
	insights: {
		basic: (range?: string) => ['insights', 'basic', range] as const,
		extended: (range?: string) => ['insights', 'extended', range] as const,
		config: () => ['insights', 'config'] as const
	},

	// Alerts
	alerts: {
		all: ['alerts'] as const,
		rules: () => ['alerts', 'rules'] as const,
		history: (params?: Record<string, unknown>) =>
			['alerts', 'history', params] as const
	},

	// Settings
	settings: {
		all: ['settings'] as const,
		notifications: () => ['settings', 'notifications'] as const
	},

	// System
	system: {
		health: () => ['system', 'health'] as const,
		info: () => ['system', 'info'] as const
	}
};
