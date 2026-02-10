<script lang="ts">
	import { createQuery, useQueryClient } from '@tanstack/svelte-query';
	import { insightsApi, geoipApi } from '$lib/api';
	import {
		Button,
		Card,
		Badge,
		Spinner,
		Select,
		Alert,
		Tabs,
		TabsList,
		TabsTrigger,
		TabsContent,
		Table,
		TableHeader,
		TableBody,
		TableRow,
		TableHead,
		TableCell
	} from '$lib/components/ui';
	import {
		TrendingUp,
		RefreshCw,
		Globe,
		BarChart3,
		Activity,
		Shield,
		Users,
		Clock,
		MapPin
	} from 'lucide-svelte';

	const queryClient = useQueryClient();

	let activeTab = 'overview';
	let timeRange = '24h';

	// Convert time range to hours
	function getHours(range: string): number {
		switch (range) {
			case '1h': return 1;
			case '6h': return 6;
			case '24h': return 24;
			case '7d': return 168;
			case '30d': return 720;
			default: return 24;
		}
	}

	// Fetch insights data
	$: insightsQuery = createQuery({
		queryKey: ['insights', timeRange],
		queryFn: () => insightsApi.summary(getHours(timeRange))
	});

	// Fetch top endpoints
	$: endpointsQuery = createQuery({
		queryKey: ['insights', 'endpoints', timeRange],
		queryFn: () => insightsApi.endpoints(20)
	});

	// Fetch geographic data
	$: geoQuery = createQuery({
		queryKey: ['insights', 'geo', timeRange],
		queryFn: () => insightsApi.geographic()
	});

	// Fetch traffic over time
	$: trafficQuery = createQuery({
		queryKey: ['insights', 'traffic', timeRange],
		queryFn: () => insightsApi.hourly(getHours(timeRange))
	});

	function formatNumber(num: number) {
		if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
		if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
		return num.toString();
	}

	function formatBytes(bytes: number) {
		if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
		if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
		if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
		return bytes + ' B';
	}

	function formatDuration(ms: number) {
		if (ms >= 1000) return (ms / 1000).toFixed(2) + 's';
		return ms.toFixed(0) + 'ms';
	}

	const timeRangeOptions = [
		{ value: '1h', label: 'Last hour' },
		{ value: '6h', label: 'Last 6 hours' },
		{ value: '24h', label: 'Last 24 hours' },
		{ value: '7d', label: 'Last 7 days' },
		{ value: '30d', label: 'Last 30 days' }
	];
</script>

<svelte:head>
	<title>Insights - CatWAF</title>
</svelte:head>

<div class="space-y-6">
	<!-- Header -->
	<div class="flex items-center justify-between">
		<div>
			<h1 class="text-2xl font-bold flex items-center gap-2">
				<TrendingUp class="h-6 w-6" />
				Insights
			</h1>
			<p class="text-muted-foreground">Traffic analytics and performance insights</p>
		</div>
		<div class="flex items-center gap-2">
			<Select options={timeRangeOptions} bind:value={timeRange} class="w-40" />
			<Button
				variant="outline"
				on:click={() => {
					queryClient.invalidateQueries({ queryKey: ['insights'] });
				}}
			>
				<RefreshCw class="h-4 w-4" />
			</Button>
		</div>
	</div>

	<!-- Summary Stats -->
	{#if $insightsQuery.isLoading}
		<div class="flex justify-center py-8">
			<Spinner size="lg" />
		</div>
	{:else if $insightsQuery.data}
		<div class="grid grid-cols-2 md:grid-cols-4 gap-4">
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-primary/10 rounded-lg">
						<Activity class="h-5 w-5 text-primary" />
					</div>
					<div>
						<p class="text-2xl font-bold">
							{formatNumber($insightsQuery.data.total_requests || 0)}
						</p>
						<p class="text-sm text-muted-foreground">Total Requests</p>
					</div>
				</div>
			</Card>
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-green-500/10 rounded-lg">
						<Users class="h-5 w-5 text-green-500" />
					</div>
					<div>
						<p class="text-2xl font-bold">
							{formatNumber($insightsQuery.data.unique_visitors || 0)}
						</p>
						<p class="text-sm text-muted-foreground">Unique Visitors</p>
					</div>
				</div>
			</Card>
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-blue-500/10 rounded-lg">
						<BarChart3 class="h-5 w-5 text-blue-500" />
					</div>
					<div>
						<p class="text-2xl font-bold">
							{formatBytes($insightsQuery.data.bandwidth_bytes || 0)}
						</p>
						<p class="text-sm text-muted-foreground">Bandwidth</p>
					</div>
				</div>
			</Card>
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-yellow-500/10 rounded-lg">
						<Clock class="h-5 w-5 text-yellow-500" />
					</div>
					<div>
						<p class="text-2xl font-bold">
							{formatDuration($insightsQuery.data.avg_response_time || 0)}
						</p>
						<p class="text-sm text-muted-foreground">Avg Response</p>
					</div>
				</div>
			</Card>
		</div>

		<!-- Additional Stats -->
		<div class="grid grid-cols-2 md:grid-cols-4 gap-4">
			<Card class="p-4">
				<div class="flex items-center justify-between">
					<span class="text-sm text-muted-foreground">Success Rate</span>
					<Badge variant="success">
						{(($insightsQuery.data.success_rate || 0) * 100).toFixed(1)}%
					</Badge>
				</div>
				<div class="mt-2 h-2 bg-muted rounded-full overflow-hidden">
					<div
						class="h-full bg-green-500 rounded-full"
						style="width: {($insightsQuery.data.success_rate || 0) * 100}%"
					></div>
				</div>
			</Card>
			<Card class="p-4">
				<div class="flex items-center justify-between">
					<span class="text-sm text-muted-foreground">Cache Hit Rate</span>
					<Badge variant="secondary">
						{(($insightsQuery.data.cache_hit_rate || 0) * 100).toFixed(1)}%
					</Badge>
				</div>
				<div class="mt-2 h-2 bg-muted rounded-full overflow-hidden">
					<div
						class="h-full bg-blue-500 rounded-full"
						style="width: {($insightsQuery.data.cache_hit_rate || 0) * 100}%"
					></div>
				</div>
			</Card>
			<Card class="p-4">
				<div class="flex items-center justify-between">
					<span class="text-sm text-muted-foreground">Blocked</span>
					<Badge variant="destructive">
						{formatNumber($insightsQuery.data.blocked_requests || 0)}
					</Badge>
				</div>
			</Card>
			<Card class="p-4">
				<div class="flex items-center justify-between">
					<span class="text-sm text-muted-foreground">Errors</span>
					<Badge variant="warning">
						{formatNumber($insightsQuery.data.errors || 0)}
					</Badge>
				</div>
			</Card>
		</div>
	{/if}

	<!-- Tabs -->
	<Tabs bind:value={activeTab}>
		<TabsList>
			<TabsTrigger value="overview">
				<BarChart3 class="h-4 w-4 mr-2" />
				Traffic
			</TabsTrigger>
			<TabsTrigger value="endpoints">
				<Activity class="h-4 w-4 mr-2" />
				Top Endpoints
			</TabsTrigger>
			<TabsTrigger value="geo">
				<Globe class="h-4 w-4 mr-2" />
				Geography
			</TabsTrigger>
		</TabsList>

		<!-- Traffic Tab -->
		<TabsContent value="overview">
			<Card class="p-6">
				{#if $trafficQuery.isLoading}
					<div class="flex justify-center py-12">
						<Spinner size="lg" />
					</div>
				{:else if $trafficQuery.data?.length}
					<div class="space-y-4">
						<h3 class="text-lg font-semibold">Requests Over Time</h3>
						<!-- Simple bar chart visualization -->
						<div class="flex items-end gap-1 h-48">
							{#each $trafficQuery.data as point}
								{@const max = Math.max(...$trafficQuery.data.map((p) => p.count))}
								{@const height = (point.count / max) * 100}
								<div
									class="flex-1 bg-primary/80 hover:bg-primary rounded-t transition-all cursor-pointer"
									style="height: {height}%"
									title="{point.timestamp}: {point.count} requests"
								></div>
							{/each}
						</div>
						<div class="flex justify-between text-xs text-muted-foreground">
							<span>{$trafficQuery.data[0]?.timestamp || ''}</span>
							<span>{$trafficQuery.data[$trafficQuery.data.length - 1]?.timestamp || ''}</span>
						</div>
					</div>
				{:else}
					<div class="text-center py-12">
						<BarChart3 class="h-12 w-12 mx-auto text-muted-foreground mb-4" />
						<p class="text-muted-foreground">No traffic data available</p>
					</div>
				{/if}
			</Card>
		</TabsContent>

		<!-- Top Endpoints Tab -->
		<TabsContent value="endpoints">
			<Card>
				{#if $endpointsQuery.isLoading}
					<div class="flex justify-center py-12">
						<Spinner size="lg" />
					</div>
				{:else if $endpointsQuery.data?.length}
					<Table>
						<TableHeader>
							<TableRow>
								<TableHead>Endpoint</TableHead>
								<TableHead>Method</TableHead>
								<TableHead class="text-right">Requests</TableHead>
								<TableHead class="text-right">Avg Time</TableHead>
								<TableHead class="text-right">Errors</TableHead>
							</TableRow>
						</TableHeader>
						<TableBody>
							{#each $endpointsQuery.data as endpoint}
								<TableRow>
									<TableCell class="font-mono text-sm">{endpoint.path}</TableCell>
									<TableCell>
										<Badge variant="outline">{endpoint.method}</Badge>
									</TableCell>
									<TableCell class="text-right">
										{formatNumber(endpoint.count)}
									</TableCell>
									<TableCell class="text-right text-muted-foreground">
										{formatDuration(endpoint.avg_time)}
									</TableCell>
									<TableCell class="text-right">
										{#if endpoint.errors > 0}
											<Badge variant="destructive">{endpoint.errors}</Badge>
										{:else}
											<span class="text-muted-foreground">0</span>
										{/if}
									</TableCell>
								</TableRow>
							{/each}
						</TableBody>
					</Table>
				{:else}
					<div class="text-center py-12">
						<Activity class="h-12 w-12 mx-auto text-muted-foreground mb-4" />
						<p class="text-muted-foreground">No endpoint data available</p>
					</div>
				{/if}
			</Card>
		</TabsContent>

		<!-- Geography Tab -->
		<TabsContent value="geo">
			<Card>
				{#if $geoQuery.isLoading}
					<div class="flex justify-center py-12">
						<Spinner size="lg" />
					</div>
				{:else if $geoQuery.data?.length}
					<Table>
						<TableHeader>
							<TableRow>
								<TableHead>Country</TableHead>
								<TableHead class="text-right">Requests</TableHead>
								<TableHead class="text-right">Unique IPs</TableHead>
								<TableHead class="text-right">Blocked</TableHead>
								<TableHead>Distribution</TableHead>
							</TableRow>
						</TableHeader>
						<TableBody>
							{@const totalRequests = $geoQuery.data.reduce((sum, c) => sum + c.requests, 0)}
							{#each $geoQuery.data as country}
								{@const percentage = (country.requests / totalRequests) * 100}
								<TableRow>
									<TableCell class="font-medium">
										<div class="flex items-center gap-2">
											<MapPin class="h-4 w-4 text-muted-foreground" />
											{country.country}
										</div>
									</TableCell>
									<TableCell class="text-right">
										{formatNumber(country.requests)}
									</TableCell>
									<TableCell class="text-right text-muted-foreground">
										{formatNumber(country.unique_ips)}
									</TableCell>
									<TableCell class="text-right">
										{#if country.blocked > 0}
											<Badge variant="destructive">{formatNumber(country.blocked)}</Badge>
										{:else}
											<span class="text-muted-foreground">0</span>
										{/if}
									</TableCell>
									<TableCell>
										<div class="w-32 h-2 bg-muted rounded-full overflow-hidden">
											<div
												class="h-full bg-primary rounded-full"
												style="width: {percentage}%"
											></div>
										</div>
									</TableCell>
								</TableRow>
							{/each}
						</TableBody>
					</Table>
				{:else}
					<div class="text-center py-12">
						<Globe class="h-12 w-12 mx-auto text-muted-foreground mb-4" />
						<p class="text-muted-foreground">No geographic data available</p>
						<p class="text-sm text-muted-foreground mt-2">
							Make sure GeoIP is enabled in settings
						</p>
					</div>
				{/if}
			</Card>
		</TabsContent>
	</Tabs>
</div>
