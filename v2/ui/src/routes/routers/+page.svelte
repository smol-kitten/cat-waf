<script lang="ts">
	import { createQuery, createMutation, useQueryClient } from '@tanstack/svelte-query';
	import { routersApi } from '$lib/api';
	import type { Router } from '$lib/api';
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
		Router as RouterIcon,
		Plus,
		Trash2,
		Edit,
		RefreshCw,
		Play,
		Settings,
		CheckCircle,
		XCircle,
		Wifi
	} from 'lucide-svelte';

	const queryClient = useQueryClient();

	let showModal = false;
	let editingRouter: Router | null = null;

	let routerForm = {
		name: '',
		type: 'mikrotik',
		host: '',
		port: 8728,
		username: '',
		password: '',
		enabled: true,
		sslEnabled: false,
		addressListName: 'catwaf-bans',
		syncOnBan: true,
		syncOnUnban: true
	};

	const routerTypes = [
		{ value: 'mikrotik', label: 'MikroTik RouterOS' },
		{ value: 'opnsense', label: 'OPNsense' },
		{ value: 'pfsense', label: 'pfSense' },
		{ value: 'iptables', label: 'iptables (SSH)' },
		{ value: 'nftables', label: 'nftables (SSH)' }
	];

	$: routersQuery = createQuery({
		queryKey: ['routers'],
		queryFn: () => routersApi.list()
	});

	const saveMutation = createMutation({
		mutationFn: (data: Partial<Router>) => 
			editingRouter 
				? routersApi.update(editingRouter.id, data)
				: routersApi.create(data),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['routers'] });
			showModal = false;
			resetForm();
		}
	});

	const deleteMutation = createMutation({
		mutationFn: (id: string) => routersApi.delete(id),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['routers'] });
		}
	});

	const syncMutation = createMutation({
		mutationFn: (id: string) => routersApi.sync(id),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['routers'] });
		}
	});

	function resetForm() {
		routerForm = {
			name: '',
			type: 'mikrotik',
			host: '',
			port: 8728,
			username: '',
			password: '',
			enabled: true,
			sslEnabled: false,
			addressListName: 'catwaf-bans',
			syncOnBan: true,
			syncOnUnban: true
		};
		editingRouter = null;
	}

	function openEditModal(router: Router) {
		editingRouter = router;
		routerForm = {
			name: router.name,
			type: router.type,
			host: router.host,
			port: router.port || 8728,
			username: router.username || '',
			password: '',
			enabled: router.enabled,
			sslEnabled: router.sslEnabled || false,
			addressListName: router.addressListName || 'catwaf-bans',
			syncOnBan: router.syncOnBan ?? true,
			syncOnUnban: router.syncOnUnban ?? true
		};
		showModal = true;
	}

	function getDefaultPort(type: string) {
		switch (type) {
			case 'mikrotik': return 8728;
			case 'opnsense': return 443;
			case 'pfsense': return 443;
			default: return 22;
		}
	}

	$: if (routerForm.type && !editingRouter) {
		routerForm.port = getDefaultPort(routerForm.type);
	}
</script>

<svelte:head>
	<title>Routers - CatWAF</title>
</svelte:head>

<div class="space-y-6">
	<!-- Header -->
	<div class="flex items-center justify-between">
		<div>
			<h1 class="text-2xl font-bold flex items-center gap-2">
				<RouterIcon class="h-6 w-6" />
				Router Integration
			</h1>
			<p class="text-muted-foreground">Sync bans to network firewalls (MikroTik, OPNsense, pfSense)</p>
		</div>
		<Button on:click={() => { resetForm(); showModal = true; }}>
			<Plus class="h-4 w-4 mr-2" />
			Add Router
		</Button>
	</div>

	<!-- Info Alert -->
	<Alert title="Firewall Integration">
		Connect CatWAF to your network routers to automatically sync IP bans.
		Blocked IPs will be added to firewall address lists for network-level blocking.
	</Alert>

	<!-- Routers List -->
	<Card>
		{#if $routersQuery.isLoading}
			<div class="flex justify-center py-12">
				<Spinner size="lg" />
			</div>
		{:else if $routersQuery.data?.routers?.length}
			<Table>
				<TableHeader>
					<TableRow>
						<TableHead>Name</TableHead>
						<TableHead>Type</TableHead>
						<TableHead>Host</TableHead>
						<TableHead>Status</TableHead>
						<TableHead>Rules</TableHead>
						<TableHead>Last Sync</TableHead>
						<TableHead>Actions</TableHead>
					</TableRow>
				</TableHeader>
				<TableBody>
					{#each $routersQuery.data.routers as router}
						<TableRow>
							<TableCell>
								<div class="flex items-center gap-2">
									<Wifi class="h-4 w-4 text-muted-foreground" />
									<span class="font-medium">{router.name}</span>
								</div>
							</TableCell>
							<TableCell>
								<Badge variant="outline">
									{routerTypes.find(t => t.value === router.type)?.label || router.type}
								</Badge>
							</TableCell>
							<TableCell class="font-mono text-sm">
								{router.host}:{router.port}
							</TableCell>
							<TableCell>
								{#if router.enabled}
									{#if router.lastSyncStatus === 'success'}
										<Badge variant="success">
											<CheckCircle class="h-3 w-3 mr-1" />
											Connected
										</Badge>
									{:else if router.lastSyncStatus === 'error'}
										<Badge variant="destructive">
											<XCircle class="h-3 w-3 mr-1" />
											Error
										</Badge>
									{:else}
										<Badge variant="secondary">Pending</Badge>
									{/if}
								{:else}
									<Badge variant="secondary">Disabled</Badge>
								{/if}
							</TableCell>
							<TableCell>{router.cachedRuleCount || 0}</TableCell>
							<TableCell class="text-sm text-muted-foreground">
								{router.lastSyncAt 
									? new Date(router.lastSyncAt).toLocaleString() 
									: 'Never'}
							</TableCell>
							<TableCell>
								<div class="flex gap-1">
									<Button
										variant="ghost"
										size="icon"
										on:click={() => $syncMutation.mutate(router.id)}
										disabled={$syncMutation.isPending}
									>
										<RefreshCw class="h-4 w-4" />
									</Button>
									<Button variant="ghost" size="icon" on:click={() => openEditModal(router)}>
										<Edit class="h-4 w-4" />
									</Button>
									<Button
										variant="ghost"
										size="icon"
										on:click={() => $deleteMutation.mutate(router.id)}
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
				<RouterIcon class="h-12 w-12 mx-auto text-muted-foreground mb-4" />
				<p class="text-muted-foreground mb-4">No routers configured</p>
				<Button on:click={() => { resetForm(); showModal = true; }}>
					<Plus class="h-4 w-4 mr-2" />
					Add Router
				</Button>
			</div>
		{/if}
	</Card>
</div>

<!-- Router Modal -->
<Modal bind:open={showModal} title={editingRouter ? 'Edit Router' : 'Add Router'} size="lg">
	<div class="space-y-4">
		<Input
			label="Router Name"
			placeholder="Main Firewall"
			bind:value={routerForm.name}
		/>
		<Select label="Router Type" bind:value={routerForm.type}>
			{#each routerTypes as type}
				<option value={type.value}>{type.label}</option>
			{/each}
		</Select>
		<div class="grid grid-cols-3 gap-4">
			<div class="col-span-2">
				<Input
					label="Host"
					placeholder="192.168.1.1"
					bind:value={routerForm.host}
				/>
			</div>
			<Input
				label="Port"
				type="number"
				bind:value={routerForm.port}
			/>
		</div>
		<div class="grid grid-cols-2 gap-4">
			<Input
				label="Username"
				placeholder="admin"
				bind:value={routerForm.username}
			/>
			<Input
				label="Password"
				type="password"
				placeholder="••••••••"
				bind:value={routerForm.password}
			/>
		</div>
		<Input
			label="Address List Name"
			placeholder="catwaf-bans"
			bind:value={routerForm.addressListName}
		/>
		<div class="flex items-center gap-6">
			<label class="flex items-center gap-2">
				<Switch bind:checked={routerForm.enabled} />
				<span>Enabled</span>
			</label>
			<label class="flex items-center gap-2">
				<Switch bind:checked={routerForm.sslEnabled} />
				<span>SSL/TLS</span>
			</label>
		</div>
		<div class="flex items-center gap-6">
			<label class="flex items-center gap-2">
				<Switch bind:checked={routerForm.syncOnBan} />
				<span>Sync on Ban</span>
			</label>
			<label class="flex items-center gap-2">
				<Switch bind:checked={routerForm.syncOnUnban} />
				<span>Sync on Unban</span>
			</label>
		</div>
	</div>
	<div slot="footer">
		<Button variant="outline" on:click={() => { showModal = false; resetForm(); }}>
			Cancel
		</Button>
		<Button
			on:click={() => $saveMutation.mutate(routerForm)}
			disabled={$saveMutation.isPending || !routerForm.name || !routerForm.host}
		>
			{#if $saveMutation.isPending}
				<Spinner size="sm" class="mr-2" />
			{/if}
			{editingRouter ? 'Update' : 'Add Router'}
		</Button>
	</div>
</Modal>
