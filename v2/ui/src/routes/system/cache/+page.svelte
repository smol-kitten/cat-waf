<script lang="ts">
	import { createQuery, createMutation, useQueryClient } from '@tanstack/svelte-query';
	import { configApi } from '$lib/api';
	import {
		Card,
		Badge,
		Spinner,
		Button,
		Modal,
		Input,
		Select,
		Switch,
		Alert
	} from '$lib/components/ui';
	import {
		HardDrive,
		RefreshCw,
		Trash2,
		Flame,
		Image,
		Globe,
		Database,
		CheckCircle,
		AlertTriangle
	} from 'lucide-svelte';

	const queryClient = useQueryClient();

	let showPurgeModal = false;
	let purgeTarget = '';
	let purgeAll = false;

	$: cacheQuery = createQuery({
		queryKey: ['config', 'cache'],
		queryFn: () => configApi.cache.stats()
	});

	const purgeMutation = createMutation({
		mutationFn: (params: { all?: boolean; site?: string }) => 
			configApi.cache.purge(params),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['config', 'cache'] });
			showPurgeModal = false;
			purgeTarget = '';
		}
	});

	const warmMutation = createMutation({
		mutationFn: (site: string) => configApi.cache.warm(site),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['config', 'cache'] });
		}
	});

	// Default empty stats
	const emptyStats = {
		totalSize: 0,
		totalFiles: 0,
		hitRate: 0,
		missRate: 0,
		sites: [] as Array<{ domain: string; size: number; files: number; hitRate: number }>
	};

	$: stats = $cacheQuery.data ?? emptyStats;
	$: hasData = !$cacheQuery.isError && stats.totalFiles > 0;

	function formatBytes(bytes: number) {
		if (bytes === 0) return '0 B';
		const k = 1024;
		const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
		const i = Math.floor(Math.log(bytes) / Math.log(k));
		return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
	}

	function formatPercent(value: number) {
		return (value * 100).toFixed(1) + '%';
	}
</script>

<svelte:head>
	<title>Cache Management - CatWAF</title>
</svelte:head>

<div class="space-y-6">
	<!-- Header -->
	<div class="flex items-center justify-between">
		<div>
			<h1 class="text-2xl font-bold flex items-center gap-2">
				<HardDrive class="h-6 w-6" />
				Cache Management
			</h1>
			<p class="text-muted-foreground">Manage nginx proxy cache and static assets</p>
		</div>
		<div class="flex gap-2">
			<Button variant="outline" on:click={() => $cacheQuery.refetch()}>
				<RefreshCw class="h-4 w-4 mr-2" />
				Refresh
			</Button>
			<Button variant="destructive" on:click={() => { purgeAll = true; showPurgeModal = true; }}>
				<Trash2 class="h-4 w-4 mr-2" />
				Purge All
			</Button>
		</div>
	</div>

	<!-- Stats -->
	<div class="grid grid-cols-1 md:grid-cols-4 gap-4">
		<Card class="p-4">
			<div class="flex items-center gap-2 text-muted-foreground mb-1">
				<Database class="h-4 w-4" />
				<span class="text-sm">Total Size</span>
			</div>
			<p class="text-2xl font-bold">{formatBytes(stats.totalSize)}</p>
		</Card>
		<Card class="p-4">
			<div class="flex items-center gap-2 text-muted-foreground mb-1">
				<HardDrive class="h-4 w-4" />
				<span class="text-sm">Cached Files</span>
			</div>
			<p class="text-2xl font-bold">{stats.totalFiles.toLocaleString()}</p>
		</Card>
		<Card class="p-4">
			<div class="flex items-center gap-2 text-muted-foreground mb-1">
				<CheckCircle class="h-4 w-4" />
				<span class="text-sm">Hit Rate</span>
			</div>
			<p class="text-2xl font-bold text-green-500">{formatPercent(stats.hitRate)}</p>
		</Card>
		<Card class="p-4">
			<div class="flex items-center gap-2 text-muted-foreground mb-1">
				<AlertTriangle class="h-4 w-4" />
				<span class="text-sm">Miss Rate</span>
			</div>
			<p class="text-2xl font-bold text-yellow-500">{formatPercent(stats.missRate)}</p>
		</Card>
	</div>

	<!-- Per-Site Cache -->
	<Card>
		<div class="p-4 border-b">
			<h2 class="font-semibold">Cache by Site</h2>
		</div>
		{#if $cacheQuery.isLoading}
			<div class="flex justify-center py-12">
				<Spinner size="lg" />
			</div>
		{:else if $cacheQuery.isError}
			<div class="p-8 text-center">
				<AlertTriangle class="h-12 w-12 mx-auto text-muted-foreground mb-4" />
				<p class="text-muted-foreground">Failed to load cache statistics.</p>
				<p class="text-sm text-muted-foreground mt-1">The cache stats endpoint may not be available.</p>
				<Button variant="outline" size="sm" class="mt-4" on:click={() => $cacheQuery.refetch()}>
					Try Again
				</Button>
			</div>
		{:else if stats.sites.length === 0}
			<div class="p-8 text-center">
				<HardDrive class="h-12 w-12 mx-auto text-muted-foreground mb-4" />
				<p class="text-muted-foreground">No cache data available.</p>
				<p class="text-sm text-muted-foreground mt-1">Cache statistics will appear here once sites have cached content.</p>
			</div>
		{:else}
			<div class="divide-y">
				{#each stats.sites as site}
					<div class="p-4 flex items-center justify-between">
						<div class="flex items-center gap-4">
							<Globe class="h-5 w-5 text-muted-foreground" />
							<div>
								<p class="font-medium">{site.domain}</p>
								<p class="text-sm text-muted-foreground">
									{formatBytes(site.size)} • {site.files.toLocaleString()} files
								</p>
							</div>
						</div>
						<div class="flex items-center gap-4">
							<Badge variant={site.hitRate >= 0.9 ? 'success' : site.hitRate >= 0.8 ? 'secondary' : 'warning'}>
								{formatPercent(site.hitRate)} hit rate
							</Badge>
							<div class="flex gap-2">
								<Button
									variant="outline"
									size="sm"
									on:click={() => $warmMutation.mutate(site.domain)}
									disabled={$warmMutation.isPending}
								>
									<Flame class="h-4 w-4 mr-1" />
									Warm
								</Button>
								<Button
									variant="outline"
									size="sm"
									on:click={() => { purgeTarget = site.domain; purgeAll = false; showPurgeModal = true; }}
								>
									<Trash2 class="h-4 w-4 mr-1" />
									Purge
								</Button>
							</div>
						</div>
					</div>
				{/each}
			</div>
		{/if}
	</Card>

	<!-- Quick Actions -->
	<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
		<Card class="p-4">
			<div class="flex items-center gap-3 mb-3">
				<div class="p-2 rounded-lg bg-blue-500/10">
					<Image class="h-5 w-5 text-blue-500" />
				</div>
				<h3 class="font-medium">Image Optimization</h3>
			</div>
			<p class="text-sm text-muted-foreground mb-3">
				Optimize cached images to reduce bandwidth and improve loading times.
			</p>
			<Button variant="outline" size="sm" class="w-full">
				Optimize Images
			</Button>
		</Card>

		<Card class="p-4">
			<div class="flex items-center gap-3 mb-3">
				<div class="p-2 rounded-lg bg-green-500/10">
					<Flame class="h-5 w-5 text-green-500" />
				</div>
				<h3 class="font-medium">Cache Warming</h3>
			</div>
			<p class="text-sm text-muted-foreground mb-3">
				Pre-populate cache by crawling popular pages from sitemap.
			</p>
			<Button variant="outline" size="sm" class="w-full">
				Warm All Sites
			</Button>
		</Card>

		<Card class="p-4">
			<div class="flex items-center gap-3 mb-3">
				<div class="p-2 rounded-lg bg-red-500/10">
					<Trash2 class="h-5 w-5 text-red-500" />
				</div>
				<h3 class="font-medium">Purge Stale</h3>
			</div>
			<p class="text-sm text-muted-foreground mb-3">
				Remove expired and stale cache entries to free up disk space.
			</p>
			<Button variant="outline" size="sm" class="w-full">
				Purge Stale Cache
			</Button>
		</Card>
	</div>
</div>

<!-- Purge Confirmation Modal -->
<Modal bind:open={showPurgeModal} title={purgeAll ? 'Purge All Cache' : `Purge Cache for ${purgeTarget}`}>
	<div class="space-y-4">
		<p class="text-muted-foreground">
			{#if purgeAll}
				Are you sure you want to purge all cached content? This will temporarily increase load on backend servers.
			{:else}
				Are you sure you want to purge all cached content for <strong>{purgeTarget}</strong>?
			{/if}
		</p>
		<div class="flex gap-2 justify-end">
			<Button variant="outline" on:click={() => showPurgeModal = false}>
				Cancel
			</Button>
			<Button
				variant="destructive"
				on:click={() => $purgeMutation.mutate(purgeAll ? { all: true } : { site: purgeTarget })}
				disabled={$purgeMutation.isPending}
			>
				{$purgeMutation.isPending ? 'Purging...' : 'Purge Cache'}
			</Button>
		</div>
	</div>
</Modal>
