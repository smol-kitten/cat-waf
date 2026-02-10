<script lang="ts">
	import { createQuery } from '@tanstack/svelte-query';
	import { statsApi, securityApi, bansApi } from '$lib/api';
	import type { DashboardStats, SecurityEvent } from '$lib/api';
	import { Card, Badge, Spinner } from '$lib/components/ui';
	import { Globe, Shield, Ban, Activity, ArrowUp, ArrowDown, AlertTriangle } from 'lucide-svelte';

	let timeRange = 24;

	// Fetch dashboard stats
	$: dashboardQuery = createQuery({
		queryKey: ['stats', 'dashboard', timeRange],
		queryFn: () => statsApi.dashboard(timeRange)
	});

	// Fetch recent security events
	$: eventsQuery = createQuery({
		queryKey: ['security', 'events', 'recent'],
		queryFn: () => securityApi.events({ limit: 5 })
	});

	// Fetch recent bans
	$: bansQuery = createQuery({
		queryKey: ['bans', 'recent'],
		queryFn: () => bansApi.list({ limit: 5 })
	});

	function formatNumber(num: number) {
		if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
		if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
		return num.toString();
	}

	function formatDate(date: string) {
		const d = new Date(date);
		const now = new Date();
		const diff = now.getTime() - d.getTime();
		const minutes = Math.floor(diff / 60000);
		if (minutes < 1) return 'just now';
		if (minutes < 60) return `${minutes} min ago`;
		const hours = Math.floor(minutes / 60);
		if (hours < 24) return `${hours} hr ago`;
		return d.toLocaleDateString();
	}

	function getSeverityBadge(severity: string) {
		switch (severity) {
			case 'critical':
				return 'destructive';
			case 'high':
				return 'destructive';
			case 'medium':
				return 'warning';
			default:
				return 'secondary';
		}
	}
</script>

<svelte:head>
	<title>Dashboard - CatWAF</title>
</svelte:head>

<div class="space-y-6">
	<!-- Welcome message -->
	<div class="rounded-lg gradient-bg p-6">
		<h2 class="text-2xl font-bold gradient-text">Welcome back! 🐱</h2>
		<p class="mt-1 text-muted-foreground">
			Here's what's happening with your sites today.
		</p>
	</div>

	<!-- Stats Grid -->
	{#if $dashboardQuery.isLoading}
		<div class="flex justify-center py-8">
			<Spinner size="lg" />
		</div>
	{:else if $dashboardQuery.data?.stats}
		{@const stats = $dashboardQuery.data.stats}
		<div class="grid gap-6 md:grid-cols-2 lg:grid-cols-4">
			<Card class="card-hover">
				<div class="flex items-start justify-between">
					<div>
						<p class="text-sm font-medium text-muted-foreground">Total Requests</p>
						<p class="mt-2 text-3xl font-bold text-foreground">{formatNumber(stats.totalRequests)}</p>
						{#if stats.trafficTrend !== 0}
							<div class="mt-2 flex items-center gap-1 text-sm">
								{#if stats.trafficTrend > 0}
									<ArrowUp class="h-4 w-4 text-green-500" />
									<span class="text-green-500">+{stats.trafficTrend}%</span>
								{:else}
									<ArrowDown class="h-4 w-4 text-red-500" />
									<span class="text-red-500">{stats.trafficTrend}%</span>
								{/if}
								<span class="text-muted-foreground">vs prev period</span>
							</div>
						{/if}
					</div>
					<div class="rounded-lg bg-muted p-3">
						<Activity class="h-5 w-5 text-blue-500" />
					</div>
				</div>
			</Card>
			<Card class="card-hover">
				<div class="flex items-start justify-between">
					<div>
						<p class="text-sm font-medium text-muted-foreground">Blocked Attacks</p>
						<p class="mt-2 text-3xl font-bold text-foreground">{formatNumber(stats.blockedRequests)}</p>
						{#if stats.blockRateTrend !== 0}
							<div class="mt-2 flex items-center gap-1 text-sm">
								{#if stats.blockRateTrend < 0}
									<ArrowDown class="h-4 w-4 text-green-500" />
									<span class="text-green-500">{stats.blockRateTrend}%</span>
								{:else}
									<ArrowUp class="h-4 w-4 text-red-500" />
									<span class="text-red-500">+{stats.blockRateTrend}%</span>
								{/if}
								<span class="text-muted-foreground">attack rate</span>
							</div>
						{/if}
					</div>
					<div class="rounded-lg bg-muted p-3">
						<Shield class="h-5 w-5 text-catboy-pink-500" />
					</div>
				</div>
			</Card>
			<Card class="card-hover">
				<div class="flex items-start justify-between">
					<div>
						<p class="text-sm font-medium text-muted-foreground">Active Sites</p>
						<p class="mt-2 text-3xl font-bold text-foreground">{stats.activeSites}</p>
					</div>
					<div class="rounded-lg bg-muted p-3">
						<Globe class="h-5 w-5 text-green-500" />
					</div>
				</div>
			</Card>
			<Card class="card-hover">
				<div class="flex items-start justify-between">
					<div>
						<p class="text-sm font-medium text-muted-foreground">Active Bans</p>
						<p class="mt-2 text-3xl font-bold text-foreground">{stats.activeBans}</p>
					</div>
					<div class="rounded-lg bg-muted p-3">
						<Ban class="h-5 w-5 text-orange-500" />
					</div>
				</div>
			</Card>
		</div>
	{/if}

	<!-- Charts Row -->
	<div class="grid gap-6 lg:grid-cols-2">
		<!-- Request Graph -->
		<Card>
			<div class="mb-4 flex items-center justify-between">
				<h3 class="text-lg font-semibold">Request Traffic</h3>
				<select class="rounded-md border border-input bg-background px-3 py-1 text-sm">
					<option>Last 24 hours</option>
					<option>Last 7 days</option>
					<option>Last 30 days</option>
				</select>
			</div>
			<div class="h-64 flex items-center justify-center text-muted-foreground">
				Chart will be rendered here with Chart.js
			</div>
		</Card>

		<!-- Attack Types -->
		<Card>
			<div class="mb-4 flex items-center justify-between">
				<h3 class="text-lg font-semibold">Attack Distribution</h3>
			</div>
			<div class="h-64 flex items-center justify-center text-muted-foreground">
				Pie chart will be rendered here
			</div>
		</Card>
	</div>

	<!-- Recent Activity -->
	<div class="grid gap-6 lg:grid-cols-2">
		<!-- Recent Security Events -->
		<Card>
			<div class="mb-4 flex items-center justify-between">
				<h3 class="text-lg font-semibold">Recent Security Events</h3>
				<a href="/security/events" class="text-sm text-primary hover:underline">View all</a>
			</div>
			{#if $eventsQuery.isLoading}
				<div class="flex justify-center py-8">
					<Spinner size="md" />
				</div>
			{:else if $eventsQuery.data?.events?.length}
				<div class="space-y-3">
					{#each $eventsQuery.data.events as event}
						<div class="flex items-center gap-3 rounded-lg bg-muted/50 p-3">
							<AlertTriangle class="h-4 w-4 text-destructive" />
							<div class="flex-1 min-w-0">
								<p class="text-sm font-medium truncate">{event.type.replace(/_/g, ' ')}</p>
								<p class="text-xs text-muted-foreground">{event.ip} • {formatDate(event.timestamp)}</p>
							</div>
							<Badge variant={getSeverityBadge(event.severity)}>{event.severity.toUpperCase()}</Badge>
						</div>
					{/each}
				</div>
			{:else}
				<div class="text-center py-8 text-muted-foreground">No recent events</div>
			{/if}
		</Card>

		<!-- Recent Bans -->
		<Card>
			<div class="mb-4 flex items-center justify-between">
				<h3 class="text-lg font-semibold">Recent Bans</h3>
				<a href="/security/bans" class="text-sm text-primary hover:underline">View all</a>
			</div>
			{#if $bansQuery.isLoading}
				<div class="flex justify-center py-8">
					<Spinner size="md" />
				</div>
			{:else if $bansQuery.data?.bans?.length}
				<div class="space-y-3">
					{#each $bansQuery.data.bans as ban}
						<div class="flex items-center justify-between rounded-lg bg-muted/50 p-3">
							<div class="flex items-center gap-3">
								<span class="font-mono text-sm">{ban.ip}</span>
								{#if ban.country}
									<span class="rounded bg-muted px-2 py-0.5 text-xs">{ban.country}</span>
								{/if}
							</div>
							<span class="text-sm text-muted-foreground">{formatDate(ban.created_at)}</span>
						</div>
					{/each}
				</div>
			{:else}
				<div class="text-center py-8 text-muted-foreground">No recent bans</div>
			{/if}
		</Card>
	</div>
</div>
