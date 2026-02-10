<script lang="ts">
	import { createQuery, createMutation, useQueryClient } from '@tanstack/svelte-query';
	import { securityApi } from '$lib/api';
	import type { CustomBlockRule } from '$lib/api';
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
		Textarea,
		Select,
		Switch
	} from '$lib/components/ui';
	import { Plus, Trash2, Edit2, Shield, AlertTriangle, Eye, RefreshCw } from 'lucide-svelte';

	const queryClient = useQueryClient();

	let showAddModal = false;
	let showEditModal = false;
	let showDeleteModal = false;
	let selectedRule: CustomBlockRule | null = null;

	// New rule form
	let newRule: Partial<CustomBlockRule> = {
		name: '',
		description: '',
		ruleType: 'ip',
		condition: {},
		action: 'block',
		priority: 100,
		enabled: true
	};

	// Condition fields based on rule type
	let conditionValue = '';
	let conditionOperator = 'equals';

	// Fetch custom block rules
	$: rulesQuery = createQuery({
		queryKey: ['security', 'rules'],
		queryFn: () => securityApi.rules.list()
	});

	// Mutations
	const createRuleMutation = createMutation({
		mutationFn: (rule: Partial<CustomBlockRule>) => securityApi.rules.create(rule),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['security', 'rules'] });
			showAddModal = false;
			resetForm();
		}
	});

	const updateRuleMutation = createMutation({
		mutationFn: ({ id, rule }: { id: string; rule: Partial<CustomBlockRule> }) =>
			securityApi.rules.update(id, rule),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['security', 'rules'] });
			showEditModal = false;
			selectedRule = null;
		}
	});

	const deleteRuleMutation = createMutation({
		mutationFn: (id: string) => securityApi.rules.delete(id),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['security', 'rules'] });
			showDeleteModal = false;
			selectedRule = null;
		}
	});

	function resetForm() {
		newRule = {
			name: '',
			description: '',
			ruleType: 'ip',
			condition: {},
			action: 'block',
			priority: 100,
			enabled: true
		};
		conditionValue = '';
		conditionOperator = 'equals';
	}

	function handleCreateRule() {
		const rule = {
			...newRule,
			condition: buildCondition()
		};
		$createRuleMutation.mutate(rule);
	}

	function handleUpdateRule() {
		if (!selectedRule) return;
		const rule = {
			...selectedRule,
			condition: buildCondition()
		};
		$updateRuleMutation.mutate({ id: selectedRule.id, rule });
	}

	function buildCondition() {
		return {
			type: newRule.ruleType,
			operator: conditionOperator,
			value: conditionValue
		};
	}

	function openEditModal(rule: CustomBlockRule) {
		selectedRule = { ...rule };
		newRule = { ...rule };
		conditionValue = (rule.condition.value as string) || '';
		conditionOperator = (rule.condition.operator as string) || 'equals';
		showEditModal = true;
	}

	function openDeleteModal(rule: CustomBlockRule) {
		selectedRule = rule;
		showDeleteModal = true;
	}

	function getActionBadge(action: string) {
		switch (action) {
			case 'block':
				return { variant: 'destructive' as const, label: 'Block' };
			case 'log':
				return { variant: 'secondary' as const, label: 'Log Only' };
			case 'challenge':
				return { variant: 'warning' as const, label: 'Challenge' };
			default:
				return { variant: 'outline' as const, label: action };
		}
	}

	function getRuleTypeLabel(type: string) {
		switch (type) {
			case 'ip':
				return 'IP Address';
			case 'ip_range':
				return 'IP Range';
			case 'country':
				return 'Country';
			case 'user_agent':
				return 'User Agent';
			case 'path':
				return 'Request Path';
			case 'header':
				return 'HTTP Header';
			case 'query':
				return 'Query Parameter';
			default:
				return type;
		}
	}

	const ruleTypeOptions = [
		{ value: 'ip', label: 'IP Address' },
		{ value: 'ip_range', label: 'IP Range (CIDR)' },
		{ value: 'country', label: 'Country Code' },
		{ value: 'user_agent', label: 'User Agent' },
		{ value: 'path', label: 'Request Path' },
		{ value: 'header', label: 'HTTP Header' },
		{ value: 'query', label: 'Query Parameter' }
	];

	const operatorOptions = [
		{ value: 'equals', label: 'Equals' },
		{ value: 'contains', label: 'Contains' },
		{ value: 'starts_with', label: 'Starts With' },
		{ value: 'ends_with', label: 'Ends With' },
		{ value: 'regex', label: 'Regex Match' }
	];

	const actionOptions = [
		{ value: 'block', label: 'Block Request' },
		{ value: 'log', label: 'Log Only' },
		{ value: 'challenge', label: 'Challenge (CAPTCHA)' }
	];

	$: rules = $rulesQuery.data?.rules || [];
</script>

<svelte:head>
	<title>Custom Block Rules - CatWAF</title>
</svelte:head>

<div class="space-y-6">
	<!-- Header -->
	<div class="flex items-center justify-between">
		<div>
			<h1 class="text-2xl font-bold">Custom Block Rules</h1>
			<p class="text-muted-foreground">Create and manage custom security rules</p>
		</div>
		<div class="flex items-center gap-2">
			<Button variant="outline" on:click={() => $rulesQuery.refetch()}>
				<RefreshCw class="mr-2 h-4 w-4" />
				Refresh
			</Button>
			<Button on:click={() => (showAddModal = true)}>
				<Plus class="mr-2 h-4 w-4" />
				Add Rule
			</Button>
		</div>
	</div>

	<!-- Stats Cards -->
	<div class="grid gap-4 md:grid-cols-4">
		<Card class="p-4">
			<div class="flex items-center gap-2">
				<Shield class="h-5 w-5 text-primary" />
				<span class="text-sm text-muted-foreground">Total Rules</span>
			</div>
			<div class="mt-2 text-2xl font-bold">{rules.length}</div>
		</Card>
		<Card class="p-4">
			<div class="flex items-center gap-2">
				<AlertTriangle class="h-5 w-5 text-destructive" />
				<span class="text-sm text-muted-foreground">Blocking</span>
			</div>
			<div class="mt-2 text-2xl font-bold">
				{rules.filter((r) => r.action === 'block' && r.enabled).length}
			</div>
		</Card>
		<Card class="p-4">
			<div class="flex items-center gap-2">
				<Eye class="h-5 w-5 text-yellow-500" />
				<span class="text-sm text-muted-foreground">Logging</span>
			</div>
			<div class="mt-2 text-2xl font-bold">
				{rules.filter((r) => r.action === 'log' && r.enabled).length}
			</div>
		</Card>
		<Card class="p-4">
			<div class="flex items-center gap-2">
				<Shield class="h-5 w-5 text-muted-foreground" />
				<span class="text-sm text-muted-foreground">Disabled</span>
			</div>
			<div class="mt-2 text-2xl font-bold">{rules.filter((r) => !r.enabled).length}</div>
		</Card>
	</div>

	<!-- Rules Table -->
	<Card>
		{#if $rulesQuery.isLoading}
			<div class="flex items-center justify-center p-8">
				<Spinner class="h-8 w-8" />
			</div>
		{:else if $rulesQuery.error}
			<Alert variant="destructive" class="m-4">
				Failed to load rules: {$rulesQuery.error instanceof Error
					? $rulesQuery.error.message
					: 'Unknown error'}
			</Alert>
		{:else if rules.length === 0}
			<div class="flex flex-col items-center justify-center p-8 text-center">
				<Shield class="h-12 w-12 text-muted-foreground" />
				<h3 class="mt-4 text-lg font-semibold">No Custom Rules</h3>
				<p class="mt-2 text-muted-foreground">
					Create your first custom block rule to enhance security.
				</p>
				<Button class="mt-4" on:click={() => (showAddModal = true)}>
					<Plus class="mr-2 h-4 w-4" />
					Add Rule
				</Button>
			</div>
		{:else}
			<Table>
				<TableHeader>
					<TableRow>
						<TableHead>Status</TableHead>
						<TableHead>Name</TableHead>
						<TableHead>Type</TableHead>
						<TableHead>Condition</TableHead>
						<TableHead>Action</TableHead>
						<TableHead>Priority</TableHead>
						<TableHead class="text-right">Actions</TableHead>
					</TableRow>
				</TableHeader>
				<TableBody>
					{#each rules.sort((a, b) => a.priority - b.priority) as rule (rule.id)}
						<TableRow>
							<TableCell>
								<Badge variant={rule.enabled ? 'default' : 'secondary'}>
									{rule.enabled ? 'Active' : 'Disabled'}
								</Badge>
							</TableCell>
							<TableCell>
								<div>
									<div class="font-medium">{rule.name}</div>
									{#if rule.description}
										<div class="text-sm text-muted-foreground">{rule.description}</div>
									{/if}
								</div>
							</TableCell>
							<TableCell>{getRuleTypeLabel(rule.ruleType)}</TableCell>
							<TableCell>
								<code class="rounded bg-muted px-1 py-0.5 text-sm">
									{rule.condition.operator || '='} {rule.condition.value || ''}
								</code>
							</TableCell>
							<TableCell>
								<Badge variant={getActionBadge(rule.action).variant}>
									{getActionBadge(rule.action).label}
								</Badge>
							</TableCell>
							<TableCell>{rule.priority}</TableCell>
							<TableCell class="text-right">
								<div class="flex items-center justify-end gap-2">
									<Button variant="ghost" size="sm" on:click={() => openEditModal(rule)}>
										<Edit2 class="h-4 w-4" />
									</Button>
									<Button variant="ghost" size="sm" on:click={() => openDeleteModal(rule)}>
										<Trash2 class="h-4 w-4 text-destructive" />
									</Button>
								</div>
							</TableCell>
						</TableRow>
					{/each}
				</TableBody>
			</Table>
		{/if}
	</Card>
</div>

<!-- Add Rule Modal -->
<Modal bind:open={showAddModal} title="Add Custom Block Rule">
	<div class="space-y-4">
		<div>
			<label for="name" class="text-sm font-medium">Rule Name</label>
			<Input id="name" bind:value={newRule.name} placeholder="e.g., Block known bad IP" />
		</div>

		<div>
			<label for="description" class="text-sm font-medium">Description (optional)</label>
			<Textarea
				id="description"
				bind:value={newRule.description}
				placeholder="Describe what this rule does"
				rows={2}
			/>
		</div>

		<div class="grid gap-4 md:grid-cols-2">
			<div>
				<label for="ruleType" class="text-sm font-medium">Rule Type</label>
				<Select bind:value={newRule.ruleType} options={ruleTypeOptions} />
			</div>

			<div>
				<label for="action" class="text-sm font-medium">Action</label>
				<Select bind:value={newRule.action} options={actionOptions} />
			</div>
		</div>

		<div class="grid gap-4 md:grid-cols-2">
			<div>
				<label for="operator" class="text-sm font-medium">Operator</label>
				<Select bind:value={conditionOperator} options={operatorOptions} />
			</div>

			<div>
				<label for="value" class="text-sm font-medium">Value</label>
				<Input id="value" bind:value={conditionValue} placeholder="Enter match value" />
			</div>
		</div>

		<div>
			<label for="priority" class="text-sm font-medium">Priority (lower = higher priority)</label>
			<Input id="priority" type="number" bind:value={newRule.priority} min={1} max={1000} />
		</div>

		<div class="flex items-center gap-2">
			<Switch bind:checked={newRule.enabled} />
			<span class="text-sm">Enable rule immediately</span>
		</div>
	</div>

	<div slot="footer" class="flex justify-end gap-2">
		<Button variant="outline" on:click={() => (showAddModal = false)}>Cancel</Button>
		<Button on:click={handleCreateRule} disabled={$createRuleMutation.isPending}>
			{#if $createRuleMutation.isPending}
				<Spinner class="mr-2 h-4 w-4" />
			{/if}
			Create Rule
		</Button>
	</div>
</Modal>

<!-- Edit Rule Modal -->
<Modal bind:open={showEditModal} title="Edit Custom Block Rule">
	{#if selectedRule}
		<div class="space-y-4">
			<div>
				<label for="edit-name" class="text-sm font-medium">Rule Name</label>
				<Input id="edit-name" bind:value={newRule.name} />
			</div>

			<div>
				<label for="edit-description" class="text-sm font-medium">Description</label>
				<Textarea id="edit-description" bind:value={newRule.description} rows={2} />
			</div>

			<div class="grid gap-4 md:grid-cols-2">
				<div>
					<label for="edit-ruleType" class="text-sm font-medium">Rule Type</label>
					<Select bind:value={newRule.ruleType} options={ruleTypeOptions} />
				</div>

				<div>
					<label for="edit-action" class="text-sm font-medium">Action</label>
					<Select bind:value={newRule.action} options={actionOptions} />
				</div>
			</div>

			<div class="grid gap-4 md:grid-cols-2">
				<div>
					<label for="edit-operator" class="text-sm font-medium">Operator</label>
					<Select bind:value={conditionOperator} options={operatorOptions} />
				</div>

				<div>
					<label for="edit-value" class="text-sm font-medium">Value</label>
					<Input id="edit-value" bind:value={conditionValue} />
				</div>
			</div>

			<div>
				<label for="edit-priority" class="text-sm font-medium">Priority</label>
				<Input id="edit-priority" type="number" bind:value={newRule.priority} min={1} max={1000} />
			</div>

			<div class="flex items-center gap-2">
				<Switch bind:checked={newRule.enabled} />
				<span class="text-sm">Enabled</span>
			</div>
		</div>
	{/if}

	<div slot="footer" class="flex justify-end gap-2">
		<Button variant="outline" on:click={() => (showEditModal = false)}>Cancel</Button>
		<Button on:click={handleUpdateRule} disabled={$updateRuleMutation.isPending}>
			{#if $updateRuleMutation.isPending}
				<Spinner class="mr-2 h-4 w-4" />
			{/if}
			Update Rule
		</Button>
	</div>
</Modal>

<!-- Delete Confirmation Modal -->
<Modal bind:open={showDeleteModal} title="Delete Rule">
	{#if selectedRule}
		<p>Are you sure you want to delete the rule "{selectedRule.name}"?</p>
		<p class="mt-2 text-sm text-muted-foreground">This action cannot be undone.</p>
	{/if}

	<div slot="footer" class="flex justify-end gap-2">
		<Button variant="outline" on:click={() => (showDeleteModal = false)}>Cancel</Button>
		<Button
			variant="destructive"
			on:click={() => selectedRule && $deleteRuleMutation.mutate(selectedRule.id)}
			disabled={$deleteRuleMutation.isPending}
		>
			{#if $deleteRuleMutation.isPending}
				<Spinner class="mr-2 h-4 w-4" />
			{/if}
			Delete
		</Button>
	</div>
</Modal>
