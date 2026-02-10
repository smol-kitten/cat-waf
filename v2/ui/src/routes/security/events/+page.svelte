<script lang="ts">
	import { createQuery, useQueryClient } from '@tanstack/svelte-query';
	import { securityApi, sitesApi } from '$lib/api';
	import type { SecurityEvent } from '$lib/api';
	import {
		Button,
		Input,
		Card,
		Badge,
		Select,
		Spinner,
		Table,
		TableHeader,
		TableBody,
		TableRow,
		TableHead,
		TableCell,
		Alert
	} from '$lib/components/ui';
	import { Search, RefreshCw, AlertTriangle, Shield, Filter } from 'lucide-svelte';

	const queryClient = useQueryClient();

	let searchQuery = '';
	let siteFilter = '';
	let severityFilter = '';
	let limit = 100;

	// Fetch sites for filter dropdown
	$: sitesQuery = createQuery({
		queryKey: ['sites'],
		queryFn: () => sitesApi.list()
	});

	// Fetch security events
	$: eventsQuery = createQuery({
		queryKey: ['security-events', { site: siteFilter, severity: severityFilter, limit }],
		queryFn: () =>
			securityApi.events.list({
				siteId: siteFilter || undefined,
				severity: severityFilter || undefined,
				pageSize: limit
			})
	});

	function getSeverityColor(severity: string) {
		switch (severity?.toLowerCase()) {
			case 'critical':
				return 'destructive';
			case 'warning':
				return 'warning';
			case 'info':
				return 'secondary';
			default:
				return 'outline';
		}
	}

	function formatDate(date: string) {
		return new Date(date).toLocaleString();
	}

	const severityOptions = [
		{ value: '', label: 'All Severities' },
		{ value: 'critical', label: 'Critical' },
		{ value: 'warning', label: 'Warning' },
		{ value: 'info', label: 'Info' }
	];

	const limitOptions = [
		{ value: '50', label: '50' },
		{ value: '100', label: '100' },
		{ value: '250', label: '250' },
		{ value: '500', label: '500' }
	];

	$: filteredEvents = $eventsQuery.data?.events?.filter((event) => {
		if (!searchQuery) return true;
		const q = searchQuery.toLowerCase();
		return (
			event.ip?.toLowerCase().includes(q) ||
			event.message?.toLowerCase().includes(q) ||
			event.uri?.toLowerCase().includes(q)
		);
	});
</script>

<svelte:head>
	<title>Security Events - CatWAF</title>
</svelte:head>

<div class="space-y-6">
	<!-- Header -->
	<div class="flex items-center justify-between">
		<div>
			<h1 class="text-2xl font-bold flex items-center gap-2">
				<AlertTriangle class="h-6 w-6" />
				Security Events
			</h1>
			<p class="text-muted-foreground">View WAF and security events</p>
		</div>
		<Button
			variant="outline"
			on:click={() => queryClient.invalidateQueries({ queryKey: ['security-events'] })}
		>
			<RefreshCw class="h-4 w-4 mr-2" />
			Refresh
		</Button>
	</div>

	<!-- Filters -->
	<Card class="p-4">
		<div class="flex flex-wrap gap-4">
			<div class="flex-1 min-w-[200px] relative">
				<Search class="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-muted-foreground" />
				<Input
					placeholder="Search by IP, message, URI..."
					class="pl-10"
					bind:value={searchQuery}
				/>
			</div>
			<Select
				options={[
					{ value: '', label: 'All Sites' },
					...($sitesQuery.data?.sites?.map((s) => ({ value: s.id, label: s.domain })) || [])
				]}
				bind:value={siteFilter}
				class="w-48"
			/>
			<Select
				options={severityOptions}
				bind:value={severityFilter}
				class="w-40"
			/>
			<Select
				options={limitOptions}
				bind:value={limit}
				class="w-24"
			/>
		</div>
	</Card>

	<!-- Stats -->
	<div class="grid grid-cols-4 gap-4">
		<Card class="p-4">
			<div class="flex items-center gap-3">
				<div class="p-2 bg-primary/10 rounded-lg">
					<Shield class="h-5 w-5 text-primary" />
				</div>
				<div>
					<p class="text-2xl font-bold">{filteredEvents?.length || 0}</p>
					<p class="text-sm text-muted-foreground">Total Events</p>
				</div>
			</div>
		</Card>
		<Card class="p-4">
			<div class="flex items-center gap-3">
				<div class="p-2 bg-destructive/10 rounded-lg">
					<AlertTriangle class="h-5 w-5 text-destructive" />
				</div>
				<div>
					<p class="text-2xl font-bold">
						{filteredEvents?.filter((e) => e.severity === 'critical').length || 0}
					</p>
					<p class="text-sm text-muted-foreground">Critical</p>
				</div>
			</div>
		</Card>
		<Card class="p-4">
			<div class="flex items-center gap-3">
				<div class="p-2 bg-yellow-500/10 rounded-lg">
					<AlertTriangle class="h-5 w-5 text-yellow-500" />
				</div>
				<div>
					<p class="text-2xl font-bold">
						{filteredEvents?.filter((e) => e.severity === 'warning').length || 0}
					</p>
					<p class="text-sm text-muted-foreground">Warnings</p>
				</div>
			</div>
		</Card>
		<Card class="p-4">
			<div class="flex items-center gap-3">
				<div class="p-2 bg-blue-500/10 rounded-lg">
					<Filter class="h-5 w-5 text-blue-500" />
				</div>
				<div>
					<p class="text-2xl font-bold">
						{[...new Set(filteredEvents?.map((e) => e.ip))].length || 0}
					</p>
					<p class="text-sm text-muted-foreground">Unique IPs</p>
				</div>
			</div>
		</Card>
	</div>

	<!-- Events Table -->
	<Card>
		{#if $eventsQuery.isLoading}
			<div class="flex justify-center py-12">
				<Spinner size="lg" />
			</div>
		{:else if $eventsQuery.error}
			<Alert variant="destructive" title="Error" class="m-4">
				Failed to load events: {$eventsQuery.error.message}
			</Alert>
		{:else if filteredEvents?.length}
			<div class="overflow-x-auto">
				<Table>
					<TableHeader>
						<TableRow>
							<TableHead>Time</TableHead>
							<TableHead>Severity</TableHead>
							<TableHead>IP</TableHead>
							<TableHead>Site</TableHead>
							<TableHead>URI</TableHead>
							<TableHead>Message</TableHead>
							<TableHead>Rule ID</TableHead>
						</TableRow>
					</TableHeader>
					<TableBody>
						{#each filteredEvents as event}
							<TableRow>
								<TableCell class="text-muted-foreground whitespace-nowrap">
									{formatDate(event.created_at)}
								</TableCell>
								<TableCell>
									<Badge variant={getSeverityColor(event.severity)}>
										{event.severity}
									</Badge>
								</TableCell>
								<TableCell class="font-mono">{event.ip}</TableCell>
								<TableCell>{event.site_id || '-'}</TableCell>
								<TableCell class="font-mono max-w-xs truncate" title={event.uri}>
									{event.uri || '-'}
								</TableCell>
								<TableCell class="max-w-md truncate" title={event.message}>
									{event.message}
								</TableCell>
								<TableCell class="font-mono">{event.rule_id || '-'}</TableCell>
							</TableRow>
						{/each}
					</TableBody>
				</Table>
			</div>
		{:else}
			<div class="text-center py-12">
				<Shield class="h-12 w-12 mx-auto text-muted-foreground mb-4" />
				<p class="text-muted-foreground">No security events</p>
			</div>
		{/if}
	</Card>
</div>
