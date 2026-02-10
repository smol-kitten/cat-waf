<script lang="ts">
	import { createQuery } from '@tanstack/svelte-query';
	import { securityApi, logsApi } from '$lib/api';
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
		TableCell,
		Alert
	} from '$lib/components/ui';
	import { Shield, AlertTriangle, RefreshCw, Filter, ChevronDown, Globe } from 'lucide-svelte';

	let severityFilter = 'all';
	let limit = 50;

	$: eventsQuery = createQuery({
		queryKey: ['security', 'events', severityFilter, limit],
		queryFn: () => securityApi.events.list({ 
			severity: severityFilter === 'all' ? undefined : severityFilter,
			pageSize: limit 
		})
	});

	$: summaryQuery = createQuery({
		queryKey: ['security', 'summary'],
		queryFn: () => securityApi.events.summary(24)
	});

	$: topRulesQuery = createQuery({
		queryKey: ['security', 'top-rules'],
		queryFn: () => securityApi.events.topRules(10)
	});

	function formatDate(date: string) {
		return new Date(date).toLocaleString();
	}

	function getSeverityBadge(severity: string) {
		switch (severity.toLowerCase()) {
			case 'critical':
				return 'destructive';
			case 'high':
			case 'error':
				return 'destructive';
			case 'medium':
			case 'warning':
				return 'warning';
			default:
				return 'secondary';
		}
	}
</script>

<svelte:head>
	<title>ModSecurity WAF - CatWAF</title>
</svelte:head>

<div class="space-y-6">
	<!-- Header -->
	<div class="flex items-center justify-between">
		<div>
			<h1 class="text-2xl font-bold flex items-center gap-2">
				<Shield class="h-6 w-6" />
				ModSecurity WAF
			</h1>
			<p class="text-muted-foreground">Web Application Firewall events and rules</p>
		</div>
		<Button variant="outline" on:click={() => $eventsQuery.refetch()}>
			<RefreshCw class="h-4 w-4 mr-2" />
			Refresh
		</Button>
	</div>

	<!-- Stats Cards -->
	{#if $summaryQuery.isLoading}
		<div class="flex justify-center py-8">
			<Spinner size="lg" />
		</div>
	{:else if $summaryQuery.data?.summary}
		{@const summary = $summaryQuery.data.summary}
		<div class="grid grid-cols-4 gap-4">
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-blue-500/10 rounded-lg">
						<Shield class="h-5 w-5 text-blue-500" />
					</div>
					<div>
						<p class="text-2xl font-bold">{summary.rulesLoaded || '-'}</p>
						<p class="text-sm text-muted-foreground">Rules Loaded</p>
					</div>
				</div>
			</Card>
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-red-500/10 rounded-lg">
						<AlertTriangle class="h-5 w-5 text-red-500" />
					</div>
					<div>
						<p class="text-2xl font-bold">{summary.blockedToday || summary.totalBlocked || 0}</p>
						<p class="text-sm text-muted-foreground">Blocks Today</p>
					</div>
				</div>
			</Card>
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-yellow-500/10 rounded-lg">
						<AlertTriangle class="h-5 w-5 text-yellow-500" />
					</div>
					<div>
						<p class="text-2xl font-bold">{summary.warningsToday || summary.totalWarnings || 0}</p>
						<p class="text-sm text-muted-foreground">Warnings Today</p>
					</div>
				</div>
			</Card>
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-purple-500/10 rounded-lg">
						<Shield class="h-5 w-5 text-purple-500" />
					</div>
					<div>
						<p class="text-2xl font-bold">{summary.paranoiaLevel || 1}</p>
						<p class="text-sm text-muted-foreground">Paranoia Level</p>
					</div>
				</div>
			</Card>
		</div>
	{/if}

	<div class="grid grid-cols-3 gap-6">
		<!-- Top Triggered Rules -->
		<Card>
			<div class="p-4 border-b">
				<h3 class="font-semibold">Top Triggered Rules</h3>
			</div>
			{#if $topRulesQuery.isLoading}
				<div class="flex justify-center py-8">
					<Spinner size="md" />
				</div>
			{:else if $topRulesQuery.data?.rules?.length}
				<div class="divide-y">
					{#each $topRulesQuery.data.rules as rule}
						<div class="p-3 flex items-center justify-between">
							<div>
								<span class="font-mono text-sm">{rule.ruleId}</span>
							</div>
							<Badge variant="secondary">{rule.count}</Badge>
						</div>
					{/each}
				</div>
			{:else}
				<div class="text-center py-8 text-muted-foreground">No rules triggered</div>
			{/if}
		</Card>

		<!-- Events Table (2 cols) -->
		<Card class="col-span-2">
			<div class="p-4 border-b flex items-center justify-between">
				<h3 class="font-semibold">Recent Events</h3>
				<div class="flex items-center gap-2">
					<Select bind:value={severityFilter} class="w-32">
						<option value="all">All</option>
						<option value="critical">Critical</option>
						<option value="high">High</option>
						<option value="medium">Medium</option>
						<option value="low">Low</option>
					</Select>
					<Select bind:value={limit} class="w-24">
						<option value={25}>25</option>
						<option value={50}>50</option>
						<option value={100}>100</option>
					</Select>
				</div>
			</div>
			{#if $eventsQuery.isLoading}
				<div class="flex justify-center py-8">
					<Spinner size="md" />
				</div>
			{:else if $eventsQuery.data?.events?.length}
				<div class="max-h-[500px] overflow-y-auto">
					<Table>
						<TableHeader>
							<TableRow>
								<TableHead>Time</TableHead>
								<TableHead>IP</TableHead>
								<TableHead>Rule</TableHead>
								<TableHead>Severity</TableHead>
								<TableHead>Message</TableHead>
							</TableRow>
						</TableHeader>
						<TableBody>
							{#each $eventsQuery.data.events as event}
								<TableRow>
									<TableCell class="text-xs text-muted-foreground whitespace-nowrap">
										{formatDate(event.timestamp)}
									</TableCell>
									<TableCell>
										<span class="font-mono text-xs">{event.ip}</span>
										{#if event.country}
											<span class="ml-1 text-xs">{event.country}</span>
										{/if}
									</TableCell>
									<TableCell class="font-mono text-xs">{event.ruleId || '-'}</TableCell>
									<TableCell>
										<Badge variant={getSeverityBadge(event.severity)}>
											{event.severity}
										</Badge>
									</TableCell>
									<TableCell class="text-xs truncate max-w-[200px]">
										{event.message || event.type}
									</TableCell>
								</TableRow>
							{/each}
						</TableBody>
					</Table>
				</div>
			{:else}
				<div class="text-center py-8 text-muted-foreground">No events found</div>
			{/if}
		</Card>
	</div>
</div>
