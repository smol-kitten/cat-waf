import { writable, derived } from 'svelte/store';
import { browser } from '$app/environment';
import { dev } from '$app/environment';

// DEV_MODE: Set to true to bypass authentication in development
const DEV_MODE = true;
const DEV_TOKEN = 'dev-token-for-local-development';

export interface User {
	id: string;
	email: string;
	name: string;
	role: 'admin' | 'user' | 'viewer';
	tenantId: string;
}

export interface AuthState {
	user: User | null;
	token: string | null;
	isLoading: boolean;
	error: string | null;
}

// In DEV_MODE, ensure token is set in localStorage
if (browser && DEV_MODE && dev) {
	if (!localStorage.getItem('catwaf_token')) {
		localStorage.setItem('catwaf_token', DEV_TOKEN);
	}
}

const initialState: AuthState = {
	user: DEV_MODE && dev ? {
		id: '00000000-0000-0000-0000-000000000001',
		email: 'admin@localhost',
		name: 'Admin',
		role: 'admin',
		tenantId: '00000000-0000-0000-0000-000000000001'
	} : null,
	token: browser ? localStorage.getItem('catwaf_token') : null,
	isLoading: false,
	error: null
};

function createAuthStore() {
	const { subscribe, set, update } = writable<AuthState>(initialState);

	return {
		subscribe,

		// Check if user is authenticated
		get isAuthenticated() {
			let state: AuthState;
			subscribe((s) => (state = s))();
			return !!state!.token;
		},

		// Login with API key
		async loginWithApiKey(apiKey: string): Promise<boolean> {
			update((state) => ({ ...state, isLoading: true, error: null }));

			try {
				const response = await fetch('/api/v2/auth/login', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json'
					},
					body: JSON.stringify({ apiKey })
				});

				if (!response.ok) {
					throw new Error('Invalid API key');
				}

				const data = await response.json();

				if (browser) {
					localStorage.setItem('catwaf_token', data.token);
				}

				update((state) => ({
					...state,
					user: data.user,
					token: data.token,
					isLoading: false,
					error: null
				}));

				return true;
			} catch (error) {
				update((state) => ({
					...state,
					isLoading: false,
					error: error instanceof Error ? error.message : 'Login failed'
				}));
				return false;
			}
		},

		// Login with credentials
		async login(email: string, password: string): Promise<boolean> {
			update((state) => ({ ...state, isLoading: true, error: null }));

			try {
				const response = await fetch('/api/v2/auth/login', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json'
					},
					body: JSON.stringify({ email, password })
				});

				if (!response.ok) {
					throw new Error('Invalid credentials');
				}

				const data = await response.json();

				if (browser) {
					localStorage.setItem('catwaf_token', data.token);
				}

				update((state) => ({
					...state,
					user: data.user,
					token: data.token,
					isLoading: false,
					error: null
				}));

				return true;
			} catch (error) {
				update((state) => ({
					...state,
					isLoading: false,
					error: error instanceof Error ? error.message : 'Login failed'
				}));
				return false;
			}
		},

		// Logout
		logout() {
			if (browser) {
				localStorage.removeItem('catwaf_token');
			}
			set(initialState);
		},

		// Refresh user data
		async refreshUser() {
			update((state) => ({ ...state, isLoading: true }));

			try {
				const response = await fetch('/api/v2/auth/me', {
					headers: {
						Authorization: `Bearer ${initialState.token}`
					}
				});

				if (!response.ok) {
					throw new Error('Session expired');
				}

				const user = await response.json();

				update((state) => ({
					...state,
					user,
					isLoading: false
				}));
			} catch {
				// Session expired, logout
				if (browser) {
					localStorage.removeItem('catwaf_token');
				}
				set(initialState);
			}
		},

		// Clear error
		clearError() {
			update((state) => ({ ...state, error: null }));
		}
	};
}

export const authStore = createAuthStore();

// Derived store for checking authentication
export const isAuthenticated = derived(authStore, ($auth) => !!$auth.token);

// Derived store for current user
export const currentUser = derived(authStore, ($auth) => $auth.user);
