<script lang="ts">
	import { createQuery, createMutation, useQueryClient } from '@tanstack/svelte-query';
	import { configApi } from '$lib/api';
	import type { Task } from '$lib/api';
	import {
		Card,
		Badge,
		Spinner,
		Button,
		Modal,
		Input,
		Select,
		Switch,
		Table,
		TableHeader,
		TableBody,
		TableRow,
		TableHead,
		TableCell,
		Alert
	} from '$lib/components/ui';
	import {
		Clock,
		Play,
		Pause,
		RefreshCw,
		Plus,
		CheckCircle,
		XCircle,
		AlertTriangle,
		Calendar
	} from 'lucide-svelte';

	const queryClient = useQueryClient();

	$: tasksQuery = createQuery({
		queryKey: ['config', 'tasks'],
		queryFn: () => configApi.tasks.list()
	});

	const runTaskMutation = createMutation({
		mutationFn: (id: string) => configApi.tasks.run(id),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['config', 'tasks'] });
		}
	});

	// Default scheduled tasks from v1
	const defaultTasks = [
		{ id: 'ssl_renewal', name: 'SSL Certificate Renewal', schedule: '0 0 * * *', description: 'Check and renew expiring SSL certificates' },
		{ id: 'log_cleanup', name: 'Log Cleanup', schedule: '0 2 * * *', description: 'Clean old access and error logs' },
		{ id: 'backup', name: 'Automatic Backup', schedule: '0 3 * * 0', description: 'Create weekly backup' },
		{ id: 'health_check', name: 'Health Check', schedule: '*/5 * * * *', description: 'Check backend health every 5 minutes' },
		{ id: 'ban_sync', name: 'Ban Sync', schedule: '*/10 * * * *', description: 'Sync bans to routers' },
		{ id: 'geoip_update', name: 'GeoIP Update', schedule: '0 4 1 * *', description: 'Update GeoIP database monthly' }
	];

	function formatDate(date: string) {
		return new Date(date).toLocaleString();
	}

	function getStatusBadge(status: string) {
		switch (status) {
			case 'running':
				return { variant: 'secondary' as const, label: 'Running' };
			case 'success':
				return { variant: 'success' as const, label: 'Success' };
			case 'failed':
				return { variant: 'destructive' as const, label: 'Failed' };
			default:
				return { variant: 'outline' as const, label: 'Pending' };
		}
	}

	function parseCron(cron: string) {
		const parts = cron.split(' ');
		if (parts.length !== 5) return cron;
		
		// Simple human-readable conversions
		if (cron === '*/5 * * * *') return 'Every 5 minutes';
		if (cron === '*/10 * * * *') return 'Every 10 minutes';
		if (cron === '*/15 * * * *') return 'Every 15 minutes';
		if (cron === '0 * * * *') return 'Every hour';
		if (cron.match(/^0 \d+ \* \* \*$/)) return `Daily at ${parts[1]}:00`;
		if (cron.match(/^0 \d+ \* \* 0$/)) return `Weekly on Sunday at ${parts[1]}:00`;
		if (cron.match(/^0 \d+ 1 \* \*$/)) return `Monthly on 1st at ${parts[1]}:00`;
		
		return cron;
	}
</script>

<svelte:head>
	<title>Scheduled Tasks - CatWAF</title>
</svelte:head>

<div class="space-y-6">
	<!-- Header -->
	<div class="flex items-center justify-between">
		<div>
			<h1 class="text-2xl font-bold flex items-center gap-2">
				<Clock class="h-6 w-6" />
				Scheduled Tasks
			</h1>
			<p class="text-muted-foreground">Automated maintenance and monitoring jobs</p>
		</div>
		<Button variant="outline" on:click={() => $tasksQuery.refetch()}>
			<RefreshCw class="h-4 w-4 mr-2" />
			Refresh
		</Button>
	</div>

	<!-- Tasks Table -->
	<Card>
		{#if $tasksQuery.isLoading}
			<div class="flex justify-center py-12">
				<Spinner size="lg" />
			</div>
		{:else if $tasksQuery.data?.tasks?.length}
			<Table>
				<TableHeader>
					<TableRow>
						<TableHead>Task</TableHead>
						<TableHead>Schedule</TableHead>
						<TableHead>Last Run</TableHead>
						<TableHead>Status</TableHead>
						<TableHead>Enabled</TableHead>
						<TableHead>Actions</TableHead>
					</TableRow>
				</TableHeader>
				<TableBody>
					{#each $tasksQuery.data.tasks as task}
						{@const status = getStatusBadge(task.lastStatus || 'pending')}
						<TableRow>
							<TableCell>
								<div>
									<p class="font-medium">{task.name}</p>
									{#if task.description}
										<p class="text-xs text-muted-foreground">{task.description}</p>
									{/if}
								</div>
							</TableCell>
							<TableCell>
								<div>
									<span class="font-mono text-xs">{task.schedule}</span>
									<p class="text-xs text-muted-foreground">{parseCron(task.schedule)}</p>
								</div>
							</TableCell>
							<TableCell class="text-sm text-muted-foreground">
								{task.lastRunAt ? formatDate(task.lastRunAt) : 'Never'}
							</TableCell>
							<TableCell>
								<Badge variant={status.variant}>{status.label}</Badge>
							</TableCell>
							<TableCell>
								{#if task.enabled}
									<CheckCircle class="h-5 w-5 text-green-500" />
								{:else}
									<XCircle class="h-5 w-5 text-muted-foreground" />
								{/if}
							</TableCell>
							<TableCell>
								<Button
									variant="outline"
									size="sm"
									on:click={() => $runTaskMutation.mutate(task.id)}
									disabled={$runTaskMutation.isPending}
								>
									<Play class="h-4 w-4 mr-1" />
									Run Now
								</Button>
							</TableCell>
						</TableRow>
					{/each}
				</TableBody>
			</Table>
		{:else}
			<!-- Show default tasks if none from API -->
			<Table>
				<TableHeader>
					<TableRow>
						<TableHead>Task</TableHead>
						<TableHead>Schedule</TableHead>
						<TableHead>Description</TableHead>
						<TableHead>Actions</TableHead>
					</TableRow>
				</TableHeader>
				<TableBody>
					{#each defaultTasks as task}
						<TableRow>
							<TableCell class="font-medium">{task.name}</TableCell>
							<TableCell>
								<div>
									<span class="font-mono text-xs">{task.schedule}</span>
									<p class="text-xs text-muted-foreground">{parseCron(task.schedule)}</p>
								</div>
							</TableCell>
							<TableCell class="text-muted-foreground">{task.description}</TableCell>
							<TableCell>
								<Button
									variant="outline"
									size="sm"
									on:click={() => $runTaskMutation.mutate(task.id)}
									disabled={$runTaskMutation.isPending}
								>
									<Play class="h-4 w-4 mr-1" />
									Run Now
								</Button>
							</TableCell>
						</TableRow>
					{/each}
				</TableBody>
			</Table>
		{/if}
	</Card>

	<!-- Info -->
	<Alert title="About Scheduled Tasks">
		Tasks run automatically based on their cron schedule. You can also trigger them manually.
		Common tasks include SSL certificate renewal, log cleanup, and health checks.
	</Alert>
</div>
