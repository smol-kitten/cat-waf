<script lang="ts">
	import { createQuery, createMutation, useQueryClient } from '@tanstack/svelte-query';
	import { securityApi } from '$lib/api';
	import {
		Card,
		Badge,
		Spinner,
		Button,
		Table,
		TableHeader,
		TableBody,
		TableRow,
		TableHead,
		TableCell,
		Alert
	} from '$lib/components/ui';
	import {
		ShieldCheck,
		RefreshCw,
		Play,
		CheckCircle,
		AlertTriangle,
		XCircle,
		Clock
	} from 'lucide-svelte';

	const queryClient = useQueryClient();

	let isRunning = false;
	let lastResults: Array<{ check: string; status: string; message: string }> = [];

	const runChecksMutation = createMutation({
		mutationFn: () => {
			isRunning = true;
			return securityApi.checks.runAll();
		},
		onSuccess: (data) => {
			isRunning = false;
			lastResults = data.results || [];
		},
		onError: () => {
			isRunning = false;
		}
	});

	function getStatusIcon(status: string) {
		switch (status.toLowerCase()) {
			case 'pass':
			case 'ok':
			case 'healthy':
				return { icon: CheckCircle, class: 'text-green-500' };
			case 'warning':
			case 'warn':
				return { icon: AlertTriangle, class: 'text-yellow-500' };
			case 'fail':
			case 'critical':
			case 'error':
				return { icon: XCircle, class: 'text-red-500' };
			default:
				return { icon: Clock, class: 'text-muted-foreground' };
		}
	}

	function getStatusBadge(status: string) {
		switch (status.toLowerCase()) {
			case 'pass':
			case 'ok':
			case 'healthy':
				return 'success';
			case 'warning':
			case 'warn':
				return 'warning';
			case 'fail':
			case 'critical':
			case 'error':
				return 'destructive';
			default:
				return 'secondary';
		}
	}

	// Built-in security checks
	const defaultChecks = [
		{ id: 'nginx', name: 'NGINX Status', description: 'Check if NGINX is running and responding' },
		{ id: 'ssl', name: 'SSL Certificates', description: 'Check certificate validity and expiration' },
		{ id: 'modsec', name: 'ModSecurity', description: 'Check WAF rules are loaded' },
		{ id: 'fail2ban', name: 'Fail2Ban', description: 'Check ban service status' },
		{ id: 'database', name: 'Database', description: 'Check database connectivity' },
		{ id: 'redis', name: 'Redis Cache', description: 'Check cache connectivity' },
		{ id: 'disk', name: 'Disk Space', description: 'Check available disk space' },
		{ id: 'memory', name: 'Memory Usage', description: 'Check system memory' },
		{ id: 'backends', name: 'Backend Health', description: 'Check backend server availability' }
	];
</script>

<svelte:head>
	<title>Security Checks - CatWAF</title>
</svelte:head>

<div class="space-y-6">
	<!-- Header -->
	<div class="flex items-center justify-between">
		<div>
			<h1 class="text-2xl font-bold flex items-center gap-2">
				<ShieldCheck class="h-6 w-6" />
				Security Checks
			</h1>
			<p class="text-muted-foreground">System health and security validation</p>
		</div>
		<Button
			on:click={() => $runChecksMutation.mutate()}
			disabled={isRunning || $runChecksMutation.isPending}
		>
			{#if isRunning || $runChecksMutation.isPending}
				<Spinner size="sm" class="mr-2" />
				Running...
			{:else}
				<Play class="h-4 w-4 mr-2" />
				Run All Checks
			{/if}
		</Button>
	</div>

	<!-- Summary Cards -->
	{#if lastResults.length > 0}
		{@const passed = lastResults.filter(r => ['pass', 'ok', 'healthy'].includes(r.status.toLowerCase())).length}
		{@const warnings = lastResults.filter(r => ['warning', 'warn'].includes(r.status.toLowerCase())).length}
		{@const failed = lastResults.filter(r => ['fail', 'critical', 'error'].includes(r.status.toLowerCase())).length}
		<div class="grid grid-cols-4 gap-4">
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-blue-500/10 rounded-lg">
						<ShieldCheck class="h-5 w-5 text-blue-500" />
					</div>
					<div>
						<p class="text-2xl font-bold">{lastResults.length}</p>
						<p class="text-sm text-muted-foreground">Total Checks</p>
					</div>
				</div>
			</Card>
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-green-500/10 rounded-lg">
						<CheckCircle class="h-5 w-5 text-green-500" />
					</div>
					<div>
						<p class="text-2xl font-bold">{passed}</p>
						<p class="text-sm text-muted-foreground">Passed</p>
					</div>
				</div>
			</Card>
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-yellow-500/10 rounded-lg">
						<AlertTriangle class="h-5 w-5 text-yellow-500" />
					</div>
					<div>
						<p class="text-2xl font-bold">{warnings}</p>
						<p class="text-sm text-muted-foreground">Warnings</p>
					</div>
				</div>
			</Card>
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-red-500/10 rounded-lg">
						<XCircle class="h-5 w-5 text-red-500" />
					</div>
					<div>
						<p class="text-2xl font-bold">{failed}</p>
						<p class="text-sm text-muted-foreground">Failed</p>
					</div>
				</div>
			</Card>
		</div>
	{/if}

	<!-- Results or Default Checks -->
	<Card>
		<div class="p-4 border-b">
			<h3 class="font-semibold">
				{lastResults.length > 0 ? 'Check Results' : 'Available Checks'}
			</h3>
		</div>
		{#if lastResults.length > 0}
			<Table>
				<TableHeader>
					<TableRow>
						<TableHead class="w-12">Status</TableHead>
						<TableHead>Check</TableHead>
						<TableHead>Message</TableHead>
					</TableRow>
				</TableHeader>
				<TableBody>
					{#each lastResults as result}
						{@const statusInfo = getStatusIcon(result.status)}
						<TableRow>
							<TableCell>
								<svelte:component this={statusInfo.icon} class="h-5 w-5 {statusInfo.class}" />
							</TableCell>
							<TableCell class="font-medium">{result.check}</TableCell>
							<TableCell class="text-muted-foreground">{result.message}</TableCell>
						</TableRow>
					{/each}
				</TableBody>
			</Table>
		{:else}
			<Table>
				<TableHeader>
					<TableRow>
						<TableHead class="w-12">Status</TableHead>
						<TableHead>Check</TableHead>
						<TableHead>Description</TableHead>
					</TableRow>
				</TableHeader>
				<TableBody>
					{#each defaultChecks as check}
						<TableRow>
							<TableCell>
								<Clock class="h-5 w-5 text-muted-foreground" />
							</TableCell>
							<TableCell class="font-medium">{check.name}</TableCell>
							<TableCell class="text-muted-foreground">{check.description}</TableCell>
						</TableRow>
					{/each}
				</TableBody>
			</Table>
		{/if}
	</Card>

	<!-- Info Alert -->
	<Alert title="About Security Checks">
		Security checks validate the health of your CatWAF installation including NGINX status,
		SSL certificates, ModSecurity rules, database connectivity, and backend availability.
		Run checks regularly to ensure your WAF is functioning correctly.
	</Alert>
</div>
