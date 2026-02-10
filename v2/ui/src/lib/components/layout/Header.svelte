<script lang="ts">
	import { page } from '$app/stores';
	import { authStore, currentUser } from '$lib/stores/auth';
	import { Search, Bell, Moon, Sun, LogOut, User, ChevronDown } from 'lucide-svelte';
	import { createEventDispatcher } from 'svelte';

	let isDark = true;
	let showUserMenu = false;

	function toggleTheme() {
		isDark = !isDark;
		document.documentElement.classList.toggle('dark', isDark);
	}

	function handleLogout() {
		authStore.logout();
		window.location.href = '/login';
	}

	// Get page title from URL
	function getPageTitle(pathname: string): string {
		const titles: Record<string, string> = {
			'/': 'Dashboard',
			'/sites': 'Sites',
			'/security/events': 'Security Events',
			'/security/center': 'Security Center',
			'/security/modsecurity': 'ModSecurity',
			'/security/bots': 'Bot Protection',
			'/security/bans': 'IP Bans',
			'/monitoring/insights': 'Insights',
			'/monitoring/alerts': 'Alerts',
			'/monitoring/logs': 'Logs',
			'/settings': 'Settings'
		};
		
		// Check for exact match first
		if (titles[pathname]) return titles[pathname];
		
		// Check for partial matches (e.g., /sites/123)
		for (const [path, title] of Object.entries(titles)) {
			if (pathname.startsWith(path) && path !== '/') {
				return title;
			}
		}
		
		return 'Dashboard';
	}

	$: pageTitle = getPageTitle($page.url.pathname);
</script>

<header class="sticky top-0 z-30 border-b border-border bg-card/95 backdrop-blur supports-[backdrop-filter]:bg-card/60">
	<div class="flex h-16 items-center justify-between px-6">
		<!-- Page Title -->
		<div>
			<h1 class="text-xl font-semibold text-foreground">{pageTitle}</h1>
		</div>

		<!-- Right side actions -->
		<div class="flex items-center gap-4">
			<!-- Search -->
			<div class="relative hidden lg:block">
				<Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
				<input
					type="text"
					placeholder="Search..."
					class="h-9 w-64 rounded-lg border border-input bg-background pl-9 pr-4 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
				/>
				<kbd class="absolute right-3 top-1/2 -translate-y-1/2 rounded border border-border bg-muted px-1.5 text-xs text-muted-foreground">
					⌘K
				</kbd>
			</div>

			<!-- Notifications -->
			<button
				class="relative rounded-lg p-2 text-muted-foreground hover:bg-accent hover:text-accent-foreground"
				aria-label="Notifications"
			>
				<Bell class="h-5 w-5" />
				<span class="absolute right-1 top-1 h-2 w-2 rounded-full bg-destructive"></span>
			</button>

			<!-- Theme toggle -->
			<button
				on:click={toggleTheme}
				class="rounded-lg p-2 text-muted-foreground hover:bg-accent hover:text-accent-foreground"
				aria-label="Toggle theme"
			>
				{#if isDark}
					<Sun class="h-5 w-5" />
				{:else}
					<Moon class="h-5 w-5" />
				{/if}
			</button>

			<!-- User menu -->
			<div class="relative">
				<button
					on:click={() => (showUserMenu = !showUserMenu)}
					class="flex items-center gap-2 rounded-lg px-3 py-2 hover:bg-accent"
				>
					<div class="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-catboy-pink-500 to-catboy-purple-500">
						<User class="h-4 w-4 text-white" />
					</div>
					<span class="hidden text-sm font-medium text-foreground md:block">
						{$currentUser?.name || 'Admin'}
					</span>
					<ChevronDown class="h-4 w-4 text-muted-foreground" />
				</button>

				{#if showUserMenu}
					<!-- svelte-ignore a11y-click-events-have-key-events -->
					<!-- svelte-ignore a11y-no-static-element-interactions -->
					<div
						class="fixed inset-0 z-40"
						on:click={() => (showUserMenu = false)}
					></div>
					<div class="absolute right-0 top-full z-50 mt-2 w-48 rounded-lg border border-border bg-card p-1 shadow-lg">
						<a
							href="/settings/profile"
							class="flex items-center gap-2 rounded-md px-3 py-2 text-sm hover:bg-accent"
							on:click={() => (showUserMenu = false)}
						>
							<User class="h-4 w-4" />
							Profile
						</a>
						<button
							on:click={handleLogout}
							class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm text-destructive hover:bg-destructive/10"
						>
							<LogOut class="h-4 w-4" />
							Logout
						</button>
					</div>
				{/if}
			</div>
		</div>
	</div>
</header>
