<script lang="ts">
	import { page } from '$app/stores';
	import { 
		LayoutDashboard, 
		Globe, 
		Shield, 
		Bot, 
		Ban, 
		BarChart3, 
		Bell, 
		FileText, 
		Settings,
		Lock,
		Key,
		ShieldAlert,
		ShieldCheck,
		Radar,
		Activity,
		Clock,
		HardDrive,
		Router,
		Ticket
	} from 'lucide-svelte';

	interface NavItem {
		label: string;
		href: string;
		icon: typeof LayoutDashboard;
	}

	interface NavSection {
		title: string;
		items: NavItem[];
	}

	const navigation: NavSection[] = [
		{
			title: 'Overview',
			items: [
				{ label: 'Dashboard', href: '/', icon: LayoutDashboard },
				{ label: 'Sites', href: '/sites', icon: Globe },
				{ label: 'Certificates', href: '/certificates', icon: Lock }
			]
		},
		{
			title: 'Security',
			items: [
				{ label: 'Events', href: '/security/events', icon: Shield },
				{ label: 'ModSecurity', href: '/security/modsecurity', icon: ShieldAlert },
				{ label: 'Security Checks', href: '/security/checks', icon: ShieldCheck },
				{ label: 'Bots', href: '/security/bots', icon: Bot },
				{ label: 'Scanners', href: '/security/scanners', icon: Radar },
				{ label: 'IP Bans', href: '/security/bans', icon: Ban }
			]
		},
		{
			title: 'Monitoring',
			items: [
				{ label: 'Insights', href: '/monitoring/insights', icon: BarChart3 },
				{ label: 'Telemetry', href: '/monitoring/telemetry', icon: Activity },
				{ label: 'Alerts', href: '/monitoring/alerts', icon: Bell },
				{ label: 'Logs', href: '/monitoring/logs', icon: FileText }
			]
		},
		{
			title: 'Licensing',
			items: [
				{ label: 'RSL Server', href: '/rsl', icon: Ticket }
			]
		},
		{
			title: 'Network',
			items: [
				{ label: 'Routers', href: '/routers', icon: Router }
			]
		},
		{
			title: 'System',
			items: [
				{ label: 'Tasks', href: '/system/tasks', icon: Clock },
				{ label: 'Cache', href: '/system/cache', icon: HardDrive },
				{ label: 'Settings', href: '/settings', icon: Settings }
			]
		}
	];

	function isActive(href: string): boolean {
		if (href === '/') {
			return $page.url.pathname === '/';
		}
		return $page.url.pathname.startsWith(href);
	}
</script>

<aside class="fixed left-0 top-0 z-40 h-screen w-64 border-r border-border bg-card">
	<!-- Logo -->
	<div class="flex h-16 items-center border-b border-border px-4">
		<a href="/" class="flex items-center gap-3">
			<div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gradient-to-br from-catboy-pink-500 to-catboy-purple-500">
				<span class="text-xl">🐱</span>
			</div>
			<div>
				<h1 class="font-semibold text-foreground">CatWAF</h1>
				<p class="text-xs text-muted-foreground">v2.0.0</p>
			</div>
		</a>
	</div>

	<!-- Navigation -->
	<nav class="custom-scrollbar h-[calc(100vh-4rem)] overflow-y-auto p-4">
		{#each navigation as section}
			<div class="mb-6">
				<h2 class="mb-2 px-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
					{section.title}
				</h2>
				<ul class="space-y-1">
					{#each section.items as item}
						<li>
							<a
								href={item.href}
								class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors
									{isActive(item.href)
									? 'bg-primary/10 text-primary'
									: 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'}"
							>
								<svelte:component this={item.icon} class="h-5 w-5" />
								{item.label}
							</a>
						</li>
					{/each}
				</ul>
			</div>
		{/each}
	</nav>
</aside>
