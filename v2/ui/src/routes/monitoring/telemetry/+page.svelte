<script lang="ts">
	import { createQuery } from '@tanstack/svelte-query';
	import { telemetryApi, insightsApi } from '$lib/api';
	import {
		Card,
		Badge,
		Spinner,
		Button,
		Select,
		Table,
		TableHeader,
		TableBody,
		TableRow,
		TableHead,
		TableCell
	} from '$lib/components/ui';
	import {
		Clock,
		Activity,
		AlertTriangle,
		TrendingUp,
		Gauge,
		Server,
		RefreshCw
	} from 'lucide-svelte';

	let timeRange = '24';

	$: metricsQuery = createQuery({
		queryKey: ['telemetry', 'metrics'],
		queryFn: () => telemetryApi.metrics()
	});

	$: insightsQuery = createQuery({
		queryKey: ['insights', 'summary', timeRange],
		queryFn: () => insightsApi.summary(parseInt(timeRange))
	});

	$: responseTimesQuery = createQuery({
		queryKey: ['insights', 'response-times', timeRange],
		queryFn: () => insightsApi.responseTimes(parseInt(timeRange))
	});

	$: endpointsQuery = createQuery({
		queryKey: ['insights', 'endpoints'],
		queryFn: () => insightsApi.endpoints(20)
	});

	function formatMs(ms: number) {
		if (ms >= 1000) return (ms / 1000).toFixed(2) + 's';
		return Math.round(ms) + 'ms';
	}

	function formatPercent(value: number) {
		return (value * 100).toFixed(1) + '%';
	}

	function getLatencyClass(ms: number) {
		if (ms < 100) return 'text-green-500';
		if (ms < 500) return 'text-yellow-500';
		return 'text-red-500';
	}
</script>

<svelte:head>
	<title>Telemetry - CatWAF</title>
</svelte:head>

<div class="space-y-6">
	<!-- Header -->
	<div class="flex items-center justify-between">
		<div>
			<h1 class="text-2xl font-bold flex items-center gap-2">
				<Activity class="h-6 w-6" />
				Performance Telemetry
			</h1>
			<p class="text-muted-foreground">Response times, throughput, and error rates</p>
		</div>
		<div class="flex items-center gap-2">
			<Select bind:value={timeRange} class="w-40">
				<option value="1">Last 1 hour</option>
				<option value="6">Last 6 hours</option>
				<option value="24">Last 24 hours</option>
				<option value="168">Last 7 days</option>
			</Select>
			<Button variant="outline" on:click={() => {
				$metricsQuery.refetch();
				$insightsQuery.refetch();
			}}>
				<RefreshCw class="h-4 w-4" />
			</Button>
		</div>
	</div>

	<!-- Stats Cards -->
	{#if $metricsQuery.isLoading || $insightsQuery.isLoading}
		<div class="flex justify-center py-12">
			<Spinner size="lg" />
		</div>
	{:else}
		<div class="grid grid-cols-4 gap-4">
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-blue-500/10 rounded-lg">
						<Clock class="h-5 w-5 text-blue-500" />
					</div>
					<div>
						<p class="text-2xl font-bold">
							{$insightsQuery.data?.insights?.avgResponseTime 
								? formatMs($insightsQuery.data.insights.avgResponseTime) 
								: '-'}
						</p>
						<p class="text-sm text-muted-foreground">Avg Response Time</p>
					</div>
				</div>
			</Card>
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-purple-500/10 rounded-lg">
						<Gauge class="h-5 w-5 text-purple-500" />
					</div>
					<div>
						<p class="text-2xl font-bold">
							{$insightsQuery.data?.insights?.p95ResponseTime 
								? formatMs($insightsQuery.data.insights.p95ResponseTime) 
								: '-'}
						</p>
						<p class="text-sm text-muted-foreground">P95 Response Time</p>
					</div>
				</div>
			</Card>
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-green-500/10 rounded-lg">
						<TrendingUp class="h-5 w-5 text-green-500" />
					</div>
					<div>
						<p class="text-2xl font-bold">
							{$insightsQuery.data?.insights?.requestsPerMinute?.toFixed(1) || '-'}
						</p>
						<p class="text-sm text-muted-foreground">Requests/min</p>
					</div>
				</div>
			</Card>
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-red-500/10 rounded-lg">
						<AlertTriangle class="h-5 w-5 text-red-500" />
					</div>
					<div>
						<p class="text-2xl font-bold">
							{$insightsQuery.data?.insights?.errorRate 
								? formatPercent($insightsQuery.data.insights.errorRate) 
								: '-'}
						</p>
						<p class="text-sm text-muted-foreground">Error Rate</p>
					</div>
				</div>
			</Card>
		</div>
	{/if}

	<div class="grid grid-cols-2 gap-6">
		<!-- Slowest Endpoints -->
		<Card>
			<div class="p-4 border-b">
				<h3 class="font-semibold">Slowest Endpoints</h3>
			</div>
			{#if $endpointsQuery.isLoading}
				<div class="flex justify-center py-8">
					<Spinner size="md" />
				</div>
			{:else if $endpointsQuery.data?.endpoints?.length}
				<Table>
					<TableHeader>
						<TableRow>
							<TableHead>Path</TableHead>
							<TableHead>Avg</TableHead>
							<TableHead>P95</TableHead>
							<TableHead>Hits</TableHead>
						</TableRow>
					</TableHeader>
					<TableBody>
						{#each $endpointsQuery.data.endpoints.slice(0, 10) as endpoint}
							<TableRow>
								<TableCell class="font-mono text-xs truncate max-w-[200px]">
									{endpoint.path || endpoint.endpoint}
								</TableCell>
								<TableCell class={getLatencyClass(endpoint.avgResponseTime || 0)}>
									{formatMs(endpoint.avgResponseTime || 0)}
								</TableCell>
								<TableCell class={getLatencyClass(endpoint.p95ResponseTime || 0)}>
									{formatMs(endpoint.p95ResponseTime || 0)}
								</TableCell>
								<TableCell>{endpoint.count || endpoint.hits || 0}</TableCell>
							</TableRow>
						{/each}
					</TableBody>
				</Table>
			{:else}
				<div class="text-center py-8 text-muted-foreground">No endpoint data</div>
			{/if}
		</Card>

		<!-- Backend Performance -->
		<Card>
			<div class="p-4 border-b">
				<h3 class="font-semibold flex items-center gap-2">
					<Server class="h-4 w-4" />
					Backend Performance
				</h3>
			</div>
			{#if $metricsQuery.isLoading}
				<div class="flex justify-center py-8">
					<Spinner size="md" />
				</div>
			{:else if $metricsQuery.data?.metrics?.backends?.length}
				<Table>
					<TableHeader>
						<TableRow>
							<TableHead>Backend</TableHead>
							<TableHead>Requests</TableHead>
							<TableHead>Avg Response</TableHead>
							<TableHead>Errors</TableHead>
							<TableHead>Status</TableHead>
						</TableRow>
					</TableHeader>
					<TableBody>
						{#each $metricsQuery.data.metrics.backends as backend}
							<TableRow>
								<TableCell class="font-mono text-xs">{backend.host}</TableCell>
								<TableCell>{backend.requestCount}</TableCell>
								<TableCell class={getLatencyClass(backend.avgResponseTime)}>
									{formatMs(backend.avgResponseTime)}
								</TableCell>
								<TableCell class={backend.errorCount > 0 ? 'text-red-500' : ''}>
									{backend.errorCount}
								</TableCell>
								<TableCell>
									<Badge variant={backend.status === 'healthy' ? 'success' : 'destructive'}>
										{backend.status}
									</Badge>
								</TableCell>
							</TableRow>
						{/each}
					</TableBody>
				</Table>
			{:else}
				<div class="text-center py-8 text-muted-foreground">No backend data</div>
			{/if}
		</Card>
	</div>

	<!-- Response Time Distribution -->
	<Card>
		<div class="p-4 border-b">
			<h3 class="font-semibold">Response Time Distribution</h3>
		</div>
		<div class="p-4">
			{#if $responseTimesQuery.isLoading}
				<div class="flex justify-center py-8">
					<Spinner size="md" />
				</div>
			{:else if $responseTimesQuery.data?.responseTimes?.length}
				<div class="space-y-3">
					{#each [
						{ label: '< 100ms', key: 'under100ms', color: 'bg-green-500' },
						{ label: '100-500ms', key: '100to500ms', color: 'bg-yellow-500' },
						{ label: '500ms-1s', key: '500to1000ms', color: 'bg-orange-500' },
						{ label: '> 1s', key: 'over1000ms', color: 'bg-red-500' }
					] as bucket}
						{@const total = $responseTimesQuery.data.responseTimes.reduce((a, r) => a + (r[bucket.key] || 0), 0)}
						{@const grandTotal = $responseTimesQuery.data.responseTimes.reduce((a, r) => 
							a + (r.under100ms || 0) + (r['100to500ms'] || 0) + (r['500to1000ms'] || 0) + (r.over1000ms || 0), 0)}
						{@const percent = grandTotal > 0 ? (total / grandTotal) * 100 : 0}
						<div class="flex items-center gap-4">
							<span class="w-24 text-sm">{bucket.label}</span>
							<div class="flex-1 h-4 bg-muted rounded-full overflow-hidden">
								<div class="{bucket.color} h-full transition-all" style="width: {percent}%"></div>
							</div>
							<span class="w-20 text-sm text-right">{percent.toFixed(1)}%</span>
						</div>
					{/each}
				</div>
			{:else}
				<div class="text-center py-8 text-muted-foreground">
					Response time distribution chart will appear here
				</div>
			{/if}
		</div>
	</Card>
</div>
