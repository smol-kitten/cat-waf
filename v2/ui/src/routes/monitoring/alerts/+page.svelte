<script lang="ts">
	import { createQuery, createMutation, useQueryClient } from '@tanstack/svelte-query';
	import { alertsApi } from '$lib/api';
	import type { AlertRule, AlertHistory } from '$lib/api';
	import {
		Button,
		Input,
		Card,
		Badge,
		Modal,
		Alert,
		Spinner,
		Table,
		TableHeader,
		TableBody,
		TableRow,
		TableHead,
		TableCell,
		Select,
		Switch,
		Tabs,
		TabsList,
		TabsTrigger,
		TabsContent,
		Textarea
	} from '$lib/components/ui';
	import {
		Bell,
		Plus,
		Trash2,
		Edit,
		RefreshCw,
		CheckCircle,
		AlertTriangle,
		Clock,
		Mail
	} from 'lucide-svelte';

	const queryClient = useQueryClient();

	let activeTab = 'rules';
	let showAddRule = false;
	let showEditRule = false;
	let editingRule: AlertRule | null = null;

	// New rule form
	let newRule = {
		name: '',
		type: 'threshold',
		metric: 'error_rate',
		threshold: 10,
		window_minutes: 5,
		enabled: true,
		webhook_url: '',
		email: ''
	};

	// Fetch alert rules
	$: rulesQuery = createQuery({
		queryKey: ['alert-rules'],
		queryFn: () => alertsApi.listRules()
	});

	// Fetch alert history
	$: historyQuery = createQuery({
		queryKey: ['alert-history'],
		queryFn: () => alertsApi.getHistory({ limit: 100 })
	});

	// Mutations
	const createRuleMutation = createMutation({
		mutationFn: (data: Partial<AlertRule>) => alertsApi.createRule(data),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['alert-rules'] });
			showAddRule = false;
			resetNewRule();
		}
	});

	const updateRuleMutation = createMutation({
		mutationFn: ({ id, data }: { id: string; data: Partial<AlertRule> }) =>
			alertsApi.updateRule(id, data),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['alert-rules'] });
			showEditRule = false;
			editingRule = null;
		}
	});

	const deleteRuleMutation = createMutation({
		mutationFn: (id: string) => alertsApi.deleteRule(id),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['alert-rules'] });
		}
	});

	const toggleRuleMutation = createMutation({
		mutationFn: ({ id, enabled }: { id: string; enabled: boolean }) =>
			alertsApi.toggleRule(id, enabled),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['alert-rules'] });
		}
	});

	const acknowledgeAlertMutation = createMutation({
		mutationFn: (id: string) => alertsApi.acknowledge(id),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['alert-history'] });
		}
	});

	function resetNewRule() {
		newRule = {
			name: '',
			type: 'threshold',
			metric: 'error_rate',
			threshold: 10,
			window_minutes: 5,
			enabled: true,
			webhook_url: '',
			email: ''
		};
	}

	function startEdit(rule: AlertRule) {
		editingRule = { ...rule };
		showEditRule = true;
	}

	function formatDate(date: string) {
		return new Date(date).toLocaleString();
	}

	const typeOptions = [
		{ value: 'threshold', label: 'Threshold' },
		{ value: 'anomaly', label: 'Anomaly Detection' },
		{ value: 'pattern', label: 'Pattern Match' }
	];

	const metricOptions = [
		{ value: 'error_rate', label: 'Error Rate' },
		{ value: 'request_count', label: 'Request Count' },
		{ value: 'response_time', label: 'Response Time' },
		{ value: 'blocked_requests', label: 'Blocked Requests' },
		{ value: 'cpu_usage', label: 'CPU Usage' },
		{ value: 'memory_usage', label: 'Memory Usage' }
	];
</script>

<svelte:head>
	<title>Alerts - CatWAF</title>
</svelte:head>

<div class="space-y-6">
	<!-- Header -->
	<div class="flex items-center justify-between">
		<div>
			<h1 class="text-2xl font-bold flex items-center gap-2">
				<Bell class="h-6 w-6" />
				Alerts
			</h1>
			<p class="text-muted-foreground">Configure alert rules and view history</p>
		</div>
		<Button
			variant="outline"
			on:click={() => {
				queryClient.invalidateQueries({ queryKey: ['alert-rules'] });
				queryClient.invalidateQueries({ queryKey: ['alert-history'] });
			}}
		>
			<RefreshCw class="h-4 w-4 mr-2" />
			Refresh
		</Button>
	</div>

	<!-- Stats -->
	<div class="grid grid-cols-4 gap-4">
		<Card class="p-4">
			<div class="flex items-center gap-3">
				<div class="p-2 bg-primary/10 rounded-lg">
					<Bell class="h-5 w-5 text-primary" />
				</div>
				<div>
					<p class="text-2xl font-bold">{$rulesQuery.data?.alerts?.length || 0}</p>
					<p class="text-sm text-muted-foreground">Alert Rules</p>
				</div>
			</div>
		</Card>
		<Card class="p-4">
			<div class="flex items-center gap-3">
				<div class="p-2 bg-green-500/10 rounded-lg">
					<CheckCircle class="h-5 w-5 text-green-500" />
				</div>
				<div>
					<p class="text-2xl font-bold">
						{$rulesQuery.data?.alerts?.filter((r) => r.enabled).length || 0}
					</p>
					<p class="text-sm text-muted-foreground">Active Rules</p>
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
						{$historyQuery.data?.history?.filter((h) => !h.acknowledged).length || 0}
					</p>
					<p class="text-sm text-muted-foreground">Unacknowledged</p>
				</div>
			</div>
		</Card>
		<Card class="p-4">
			<div class="flex items-center gap-3">
				<div class="p-2 bg-blue-500/10 rounded-lg">
					<Clock class="h-5 w-5 text-blue-500" />
				</div>
				<div>
					<p class="text-2xl font-bold">{$historyQuery.data?.history?.length || 0}</p>
					<p class="text-sm text-muted-foreground">Total Alerts</p>
				</div>
			</div>
		</Card>
	</div>

	<!-- Main Content -->
	<Tabs bind:value={activeTab}>
		<div class="flex items-center justify-between mb-4">
			<TabsList>
				<TabsTrigger value="rules">
					<Bell class="h-4 w-4 mr-2" />
					Rules
				</TabsTrigger>
				<TabsTrigger value="history">
					<Clock class="h-4 w-4 mr-2" />
					History
				</TabsTrigger>
			</TabsList>
			{#if activeTab === 'rules'}
				<Button on:click={() => (showAddRule = true)}>
					<Plus class="h-4 w-4 mr-2" />
					Add Rule
				</Button>
			{/if}
		</div>

		<!-- Rules Tab -->
		<TabsContent value="rules">
			<Card>
				{#if $rulesQuery.isLoading}
					<div class="flex justify-center py-12">
						<Spinner size="lg" />
					</div>
				{:else if $rulesQuery.error}
					<Alert variant="destructive" title="Error" class="m-4">
						Failed to load rules
					</Alert>
				{:else if $rulesQuery.data?.alerts?.length}
					<Table>
						<TableHeader>
							<TableRow>
								<TableHead>Name</TableHead>
								<TableHead>Type</TableHead>
								<TableHead>Metric</TableHead>
								<TableHead>Threshold</TableHead>
								<TableHead>Window</TableHead>
								<TableHead>Status</TableHead>
								<TableHead>Actions</TableHead>
							</TableRow>
						</TableHeader>
						<TableBody>
							{#each $rulesQuery.data.alerts as rule}
								<TableRow>
									<TableCell class="font-medium">{rule.name}</TableCell>
									<TableCell>
										<Badge variant="outline">{rule.type}</Badge>
									</TableCell>
									<TableCell>{rule.metric}</TableCell>
									<TableCell>{rule.threshold}</TableCell>
									<TableCell>{rule.window_minutes} min</TableCell>
									<TableCell>
										<Switch
											checked={rule.enabled}
											on:click={() =>
												$toggleRuleMutation.mutate({ id: rule.id, enabled: !rule.enabled })}
										/>
									</TableCell>
									<TableCell>
										<div class="flex gap-1">
											<Button variant="ghost" size="icon" on:click={() => startEdit(rule)}>
												<Edit class="h-4 w-4" />
											</Button>
											<Button
												variant="ghost"
												size="icon"
												on:click={() => $deleteRuleMutation.mutate(rule.id)}
											>
												<Trash2 class="h-4 w-4 text-destructive" />
											</Button>
										</div>
									</TableCell>
								</TableRow>
							{/each}
						</TableBody>
					</Table>
				{:else}
					<div class="text-center py-12">
						<Bell class="h-12 w-12 mx-auto text-muted-foreground mb-4" />
						<p class="text-muted-foreground">No alert rules configured</p>
						<Button class="mt-4" on:click={() => (showAddRule = true)}>
							<Plus class="h-4 w-4 mr-2" />
							Create First Rule
						</Button>
					</div>
				{/if}
			</Card>
		</TabsContent>

		<!-- History Tab -->
		<TabsContent value="history">
			<Card>
				{#if $historyQuery.isLoading}
					<div class="flex justify-center py-12">
						<Spinner size="lg" />
					</div>
				{:else if $historyQuery.error}
					<Alert variant="destructive" title="Error" class="m-4">
						Failed to load history
					</Alert>
				{:else if $historyQuery.data?.history?.length}
					<Table>
						<TableHeader>
							<TableRow>
								<TableHead>Time</TableHead>
								<TableHead>Rule</TableHead>
								<TableHead>Message</TableHead>
								<TableHead>Value</TableHead>
								<TableHead>Status</TableHead>
								<TableHead>Actions</TableHead>
							</TableRow>
						</TableHeader>
						<TableBody>
							{#each $historyQuery.data.history as alert}
								<TableRow>
									<TableCell class="text-muted-foreground whitespace-nowrap">
										{formatDate(alert.created_at)}
									</TableCell>
									<TableCell class="font-medium">{alert.rule_name}</TableCell>
									<TableCell class="max-w-md truncate">{alert.message}</TableCell>
									<TableCell class="font-mono">{alert.value}</TableCell>
									<TableCell>
										{#if alert.acknowledged}
											<Badge variant="secondary">Acknowledged</Badge>
										{:else}
											<Badge variant="destructive">Active</Badge>
										{/if}
									</TableCell>
									<TableCell>
										{#if !alert.acknowledged}
											<Button
												variant="outline"
												size="sm"
												on:click={() => $acknowledgeAlertMutation.mutate(alert.id)}
											>
												<CheckCircle class="h-3 w-3 mr-1" />
												Ack
											</Button>
										{/if}
									</TableCell>
								</TableRow>
							{/each}
						</TableBody>
					</Table>
				{:else}
					<div class="text-center py-12">
						<CheckCircle class="h-12 w-12 mx-auto text-muted-foreground mb-4" />
						<p class="text-muted-foreground">No alerts triggered</p>
					</div>
				{/if}
			</Card>
		</TabsContent>
	</Tabs>
</div>

<!-- Add Rule Modal -->
<Modal bind:open={showAddRule} title="Create Alert Rule" size="lg">
	<div class="space-y-4">
		<Input label="Name" placeholder="High Error Rate" bind:value={newRule.name} />
		<div class="grid grid-cols-2 gap-4">
			<Select label="Type" options={typeOptions} bind:value={newRule.type} />
			<Select label="Metric" options={metricOptions} bind:value={newRule.metric} />
		</div>
		<div class="grid grid-cols-2 gap-4">
			<Input label="Threshold" type="number" bind:value={newRule.threshold} />
			<Input label="Window (minutes)" type="number" bind:value={newRule.window_minutes} />
		</div>
		<div class="space-y-2">
			<p class="text-sm font-medium">Notifications</p>
			<Input label="Webhook URL" placeholder="https://..." bind:value={newRule.webhook_url} />
			<Input label="Email" placeholder="alerts@example.com" bind:value={newRule.email} />
		</div>
		<div class="flex items-center justify-between">
			<span class="text-sm font-medium">Enabled</span>
			<Switch bind:checked={newRule.enabled} />
		</div>
	</div>
	<div slot="footer">
		<Button variant="outline" on:click={() => (showAddRule = false)}>Cancel</Button>
		<Button
			on:click={() => $createRuleMutation.mutate(newRule)}
			disabled={$createRuleMutation.isPending || !newRule.name}
		>
			{#if $createRuleMutation.isPending}
				<Spinner size="sm" class="mr-2" />
			{/if}
			Create
		</Button>
	</div>
</Modal>

<!-- Edit Rule Modal -->
<Modal bind:open={showEditRule} title="Edit Alert Rule" size="lg">
	{#if editingRule}
		<div class="space-y-4">
			<Input label="Name" bind:value={editingRule.name} />
			<div class="grid grid-cols-2 gap-4">
				<Select label="Type" options={typeOptions} bind:value={editingRule.type} />
				<Select label="Metric" options={metricOptions} bind:value={editingRule.metric} />
			</div>
			<div class="grid grid-cols-2 gap-4">
				<Input label="Threshold" type="number" bind:value={editingRule.threshold} />
				<Input label="Window (minutes)" type="number" bind:value={editingRule.window_minutes} />
			</div>
			<div class="space-y-2">
				<p class="text-sm font-medium">Notifications</p>
				<Input label="Webhook URL" bind:value={editingRule.webhook_url} />
				<Input label="Email" bind:value={editingRule.email} />
			</div>
			<div class="flex items-center justify-between">
				<span class="text-sm font-medium">Enabled</span>
				<Switch bind:checked={editingRule.enabled} />
			</div>
		</div>
	{/if}
	<div slot="footer">
		<Button variant="outline" on:click={() => (showEditRule = false)}>Cancel</Button>
		<Button
			on:click={() =>
				editingRule && $updateRuleMutation.mutate({ id: editingRule.id, data: editingRule })}
			disabled={$updateRuleMutation.isPending}
		>
			{#if $updateRuleMutation.isPending}
				<Spinner size="sm" class="mr-2" />
			{/if}
			Save
		</Button>
	</div>
</Modal>
