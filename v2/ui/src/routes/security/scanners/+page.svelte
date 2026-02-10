<script lang="ts">
	import { createQuery, createMutation, useQueryClient } from '@tanstack/svelte-query';
	import { securityApi } from '$lib/api';
	import {
		Card,
		Badge,
		Spinner,
		Button,
		Modal,
		Input,
		Table,
		TableHeader,
		TableBody,
		TableRow,
		TableHead,
		TableCell,
		Alert
	} from '$lib/components/ui';
	import {
		Radar,
		RefreshCw,
		Shield,
		Ban,
		Eye,
		Calendar,
		MapPin,
		AlertTriangle
	} from 'lucide-svelte';

	const queryClient = useQueryClient();

	$: scannersQuery = createQuery({
		queryKey: ['security', 'scanners'],
		queryFn: () => securityApi.scanners.list()
	});

	const blockScannerMutation = createMutation({
		mutationFn: (ip: string) => securityApi.bans.create({ ip, reason: 'Scanner detected' }),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['security'] });
		}
	});

	// Reactive scanner data
	$: scannerData = $scannersQuery.data ?? {
		scanners: [] as Array<{ ip: string; type: string; requests: number; firstSeen: string; lastSeen: string; country: string; blocked: boolean }>,
		total: 0,
		blocked: 0,
		monitoring: 0,
		totalRequests: 0
	};

	function formatDate(date: string) {
		return new Date(date).toLocaleString();
	}

	function getScannerBadgeVariant(type: string) {
		switch (type.toLowerCase()) {
			case 'nikto':
			case 'acunetix':
			case 'sqlmap':
				return 'destructive' as const;
			case 'nmap':
			case 'burp suite':
				return 'warning' as const;
			default:
				return 'secondary' as const;
		}
	}
</script>

<svelte:head>
	<title>Scanner Detection - CatWAF</title>
</svelte:head>

<div class="space-y-6">
	<!-- Header -->
	<div class="flex items-center justify-between">
		<div>
			<h1 class="text-2xl font-bold flex items-center gap-2">
				<Radar class="h-6 w-6" />
				Scanner Detection
			</h1>
			<p class="text-muted-foreground">Detect and block vulnerability scanners and automated tools</p>
		</div>
		<Button variant="outline" on:click={() => $scannersQuery.refetch()}>
			<RefreshCw class="h-4 w-4 mr-2" />
			Refresh
		</Button>
	</div>

	<!-- Stats -->
	<div class="grid grid-cols-1 md:grid-cols-4 gap-4">
		<Card class="p-4">
			<div class="flex items-center gap-2 text-muted-foreground mb-1">
				<Radar class="h-4 w-4" />
				<span class="text-sm">Detected</span>
			</div>
			<p class="text-2xl font-bold">{scannerData.total}</p>
		</Card>
		<Card class="p-4">
			<div class="flex items-center gap-2 text-muted-foreground mb-1">
				<Ban class="h-4 w-4" />
				<span class="text-sm">Blocked</span>
			</div>
			<p class="text-2xl font-bold text-red-500">{scannerData.blocked}</p>
		</Card>
		<Card class="p-4">
			<div class="flex items-center gap-2 text-muted-foreground mb-1">
				<Eye class="h-4 w-4" />
				<span class="text-sm">Monitoring</span>
			</div>
			<p class="text-2xl font-bold text-yellow-500">{scannerData.monitoring}</p>
		</Card>
		<Card class="p-4">
			<div class="flex items-center gap-2 text-muted-foreground mb-1">
				<AlertTriangle class="h-4 w-4" />
				<span class="text-sm">Total Requests</span>
			</div>
			<p class="text-2xl font-bold">{scannerData.totalRequests.toLocaleString()}</p>
		</Card>
	</div>

	<!-- Scanners Table -->
	<Card>
		<div class="p-4 border-b">
			<h2 class="font-semibold">Detected Scanners</h2>
		</div>
		{#if $scannersQuery.isLoading}
			<div class="flex justify-center py-12">
				<Spinner size="lg" />
			</div>
		{:else if $scannersQuery.isError}
			<div class="p-8 text-center">
				<AlertTriangle class="h-12 w-12 mx-auto text-muted-foreground mb-4" />
				<p class="text-muted-foreground">Failed to load scanner data.</p>
				<p class="text-sm text-muted-foreground mt-1">The scanner detection endpoint may not be available.</p>
				<Button variant="outline" size="sm" class="mt-4" on:click={() => $scannersQuery.refetch()}>
					Try Again
				</Button>
			</div>
		{:else if scannerData.scanners.length === 0}
			<div class="p-8 text-center">
				<Shield class="h-12 w-12 mx-auto text-muted-foreground mb-4" />
				<p class="text-muted-foreground">No scanners detected.</p>
				<p class="text-sm text-muted-foreground mt-1">Vulnerability scanners and automated tools will appear here when detected.</p>
			</div>
		{:else}
			<Table>
				<TableHeader>
					<TableRow>
						<TableHead>IP Address</TableHead>
						<TableHead>Scanner Type</TableHead>
						<TableHead>Requests</TableHead>
						<TableHead>Country</TableHead>
						<TableHead>First Seen</TableHead>
						<TableHead>Last Seen</TableHead>
						<TableHead>Status</TableHead>
						<TableHead>Actions</TableHead>
					</TableRow>
				</TableHeader>
				<TableBody>
					{#each scannerData.scanners as scanner}
						<TableRow>
							<TableCell class="font-mono">{scanner.ip}</TableCell>
							<TableCell>
								<Badge variant={getScannerBadgeVariant(scanner.type)}>{scanner.type}</Badge>
							</TableCell>
							<TableCell>{scanner.requests.toLocaleString()}</TableCell>
							<TableCell>
								<div class="flex items-center gap-1">
									<MapPin class="h-3 w-3" />
									{scanner.country}
								</div>
							</TableCell>
							<TableCell class="text-sm text-muted-foreground">
								{formatDate(scanner.firstSeen)}
							</TableCell>
							<TableCell class="text-sm text-muted-foreground">
								{formatDate(scanner.lastSeen)}
							</TableCell>
							<TableCell>
								{#if scanner.blocked}
									<Badge variant="destructive">Blocked</Badge>
								{:else}
									<Badge variant="warning">Monitoring</Badge>
								{/if}
							</TableCell>
							<TableCell>
								{#if !scanner.blocked}
									<Button
										variant="destructive"
										size="sm"
										on:click={() => $blockScannerMutation.mutate(scanner.ip)}
										disabled={$blockScannerMutation.isPending}
									>
										<Ban class="h-4 w-4 mr-1" />
										Block
									</Button>
								{:else}
									<Button variant="outline" size="sm" disabled>
										Blocked
									</Button>
								{/if}
							</TableCell>
						</TableRow>
					{/each}
				</TableBody>
			</Table>
		{/if}
	</Card>

	<!-- Alert -->
	<Alert title="Scanner Detection">
		CatWAF automatically identifies common vulnerability scanners like Nikto, Nmap, Acunetix, SQLMap, 
		and Burp Suite based on request patterns and user-agent analysis. Detected scanners can be 
		automatically blocked or monitored.
	</Alert>
</div>
