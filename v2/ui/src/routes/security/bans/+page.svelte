<script lang="ts">
	import { createQuery, createMutation, useQueryClient } from '@tanstack/svelte-query';
	import { bansApi } from '$lib/api';
	import type { BanEntry } from '$lib/api';
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
		Select
	} from '$lib/components/ui';
	import { Plus, Trash2, Search, RefreshCw, Shield, Clock, Globe } from 'lucide-svelte';

	const queryClient = useQueryClient();

	let searchQuery = '';
	let showAddModal = false;
	let showBulkModal = false;
	let selectedBan: BanEntry | null = null;
	let showDeleteModal = false;

	// New ban form
	let newBan = {
		ip: '',
		reason: '',
		duration: 'permanent',
		custom_minutes: 60
	};

	// Bulk ban form
	let bulkIps = '';
	let bulkReason = '';

	// Fetch bans
	$: bansQuery = createQuery({
		queryKey: ['bans', searchQuery],
		queryFn: () => bansApi.list({ search: searchQuery || undefined })
	});

	// Mutations
	const addBanMutation = createMutation({
		mutationFn: (data: Partial<BanEntry>) => bansApi.create(data),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['bans'] });
			showAddModal = false;
			newBan = { ip: '', reason: '', duration: 'permanent', custom_minutes: 60 };
		}
	});

	const bulkBanMutation = createMutation({
		mutationFn: (data: { ips: string[]; reason: string }) => bansApi.bulkBan(data.ips, data.reason),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['bans'] });
			showBulkModal = false;
			bulkIps = '';
			bulkReason = '';
		}
	});

	const deleteBanMutation = createMutation({
		mutationFn: (id: string) => bansApi.delete(id),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['bans'] });
			showDeleteModal = false;
			selectedBan = null;
		}
	});

	const clearAllMutation = createMutation({
		mutationFn: () => bansApi.clearAll(),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['bans'] });
		}
	});

	function handleAddBan() {
		let expires_at: string | undefined;
		if (newBan.duration !== 'permanent') {
			const minutes = newBan.duration === 'custom' ? newBan.custom_minutes : parseInt(newBan.duration);
			const date = new Date();
			date.setMinutes(date.getMinutes() + minutes);
			expires_at = date.toISOString();
		}

		$addBanMutation.mutate({
			ip: newBan.ip,
			reason: newBan.reason,
			expires_at
		});
	}

	function handleBulkBan() {
		const ips = bulkIps
			.split('\n')
			.map((ip) => ip.trim())
			.filter((ip) => ip);
		$bulkBanMutation.mutate({ ips, reason: bulkReason });
	}

	function confirmDelete(ban: BanEntry) {
		selectedBan = ban;
		showDeleteModal = true;
	}

	function formatDate(date: string | undefined) {
		if (!date) return 'Permanent';
		return new Date(date).toLocaleString();
	}

	function isExpired(date: string | undefined) {
		if (!date) return false;
		return new Date(date) < new Date();
	}

	const durationOptions = [
		{ value: 'permanent', label: 'Permanent' },
		{ value: '60', label: '1 hour' },
		{ value: '1440', label: '24 hours' },
		{ value: '10080', label: '7 days' },
		{ value: '43200', label: '30 days' },
		{ value: 'custom', label: 'Custom' }
	];
</script>

<svelte:head>
	<title>IP Bans - CatWAF</title>
</svelte:head>

<div class="space-y-6">
	<!-- Header -->
	<div class="flex items-center justify-between">
		<div>
			<h1 class="text-2xl font-bold flex items-center gap-2">
				<Shield class="h-6 w-6" />
				IP Bans
			</h1>
			<p class="text-muted-foreground">Manage blocked IP addresses</p>
		</div>
		<div class="flex items-center gap-2">
			<Button variant="outline" on:click={() => $clearAllMutation.mutate()}>
				<Trash2 class="h-4 w-4 mr-2" />
				Clear All
			</Button>
			<Button variant="outline" on:click={() => (showBulkModal = true)}>
				<Plus class="h-4 w-4 mr-2" />
				Bulk Import
			</Button>
			<Button on:click={() => (showAddModal = true)}>
				<Plus class="h-4 w-4 mr-2" />
				Add Ban
			</Button>
		</div>
	</div>

	<!-- Search -->
	<Card class="p-4">
		<div class="flex gap-4">
			<div class="flex-1 relative">
				<Search class="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-muted-foreground" />
				<Input
					placeholder="Search by IP address..."
					class="pl-10"
					bind:value={searchQuery}
				/>
			</div>
			<Button
				variant="outline"
				on:click={() => queryClient.invalidateQueries({ queryKey: ['bans'] })}
			>
				<RefreshCw class="h-4 w-4" />
			</Button>
		</div>
	</Card>

	<!-- Stats -->
	<div class="grid grid-cols-3 gap-4">
		<Card class="p-4">
			<div class="flex items-center gap-3">
				<div class="p-2 bg-primary/10 rounded-lg">
					<Shield class="h-5 w-5 text-primary" />
				</div>
				<div>
					<p class="text-2xl font-bold">{$bansQuery.data?.bans?.length || 0}</p>
					<p class="text-sm text-muted-foreground">Total Bans</p>
				</div>
			</div>
		</Card>
		<Card class="p-4">
			<div class="flex items-center gap-3">
				<div class="p-2 bg-destructive/10 rounded-lg">
					<Clock class="h-5 w-5 text-destructive" />
				</div>
				<div>
					<p class="text-2xl font-bold">
						{$bansQuery.data?.bans?.filter((b) => !b.expires_at).length || 0}
					</p>
					<p class="text-sm text-muted-foreground">Permanent</p>
				</div>
			</div>
		</Card>
		<Card class="p-4">
			<div class="flex items-center gap-3">
				<div class="p-2 bg-warning/10 rounded-lg">
					<Globe class="h-5 w-5 text-warning" />
				</div>
				<div>
					<p class="text-2xl font-bold">
						{$bansQuery.data?.bans?.filter((b) => b.ip.includes('/')).length || 0}
					</p>
					<p class="text-sm text-muted-foreground">CIDR Ranges</p>
				</div>
			</div>
		</Card>
	</div>

	<!-- Bans Table -->
	<Card>
		{#if $bansQuery.isLoading}
			<div class="flex justify-center py-12">
				<Spinner size="lg" />
			</div>
		{:else if $bansQuery.error}
			<Alert variant="destructive" title="Error" class="m-4">
				Failed to load bans: {$bansQuery.error.message}
			</Alert>
		{:else if $bansQuery.data?.bans?.length}
			<Table>
				<TableHeader>
					<TableRow>
						<TableHead>IP Address</TableHead>
						<TableHead>Reason</TableHead>
						<TableHead>Banned At</TableHead>
						<TableHead>Expires</TableHead>
						<TableHead>Status</TableHead>
						<TableHead></TableHead>
					</TableRow>
				</TableHeader>
				<TableBody>
					{#each $bansQuery.data.bans as ban}
						<TableRow>
							<TableCell class="font-mono font-medium">{ban.ip}</TableCell>
							<TableCell>{ban.reason || '-'}</TableCell>
							<TableCell class="text-muted-foreground">
								{new Date(ban.created_at).toLocaleString()}
							</TableCell>
							<TableCell class="text-muted-foreground">
								{formatDate(ban.expires_at)}
							</TableCell>
							<TableCell>
								{#if isExpired(ban.expires_at)}
									<Badge variant="secondary">Expired</Badge>
								{:else if ban.expires_at}
									<Badge variant="warning">Temporary</Badge>
								{:else}
									<Badge variant="destructive">Permanent</Badge>
								{/if}
							</TableCell>
							<TableCell>
								<Button
									variant="ghost"
									size="icon"
									on:click={() => confirmDelete(ban)}
								>
									<Trash2 class="h-4 w-4 text-destructive" />
								</Button>
							</TableCell>
						</TableRow>
					{/each}
				</TableBody>
			</Table>
		{:else}
			<div class="text-center py-12">
				<Shield class="h-12 w-12 mx-auto text-muted-foreground mb-4" />
				<p class="text-muted-foreground">No IP bans</p>
			</div>
		{/if}
	</Card>
</div>

<!-- Add Ban Modal -->
<Modal bind:open={showAddModal} title="Add IP Ban" size="md">
	<div class="space-y-4">
		<Input
			label="IP Address"
			placeholder="192.168.1.1 or 10.0.0.0/24"
			bind:value={newBan.ip}
		/>
		<Input
			label="Reason"
			placeholder="Reason for ban"
			bind:value={newBan.reason}
		/>
		<Select
			label="Duration"
			options={durationOptions}
			bind:value={newBan.duration}
		/>
		{#if newBan.duration === 'custom'}
			<Input
				label="Minutes"
				type="number"
				min="1"
				bind:value={newBan.custom_minutes}
			/>
		{/if}
	</div>
	<div slot="footer">
		<Button variant="outline" on:click={() => (showAddModal = false)}>Cancel</Button>
		<Button
			on:click={handleAddBan}
			disabled={$addBanMutation.isPending || !newBan.ip}
		>
			{#if $addBanMutation.isPending}
				<Spinner size="sm" class="mr-2" />
			{/if}
			Add Ban
		</Button>
	</div>
</Modal>

<!-- Bulk Import Modal -->
<Modal bind:open={showBulkModal} title="Bulk Import IPs" size="lg">
	<div class="space-y-4">
		<Alert title="Import Format">
			Enter one IP address or CIDR range per line.
		</Alert>
		<Textarea
			label="IP Addresses"
			placeholder="192.168.1.1&#10;10.0.0.0/24&#10;172.16.0.1"
			rows={10}
			bind:value={bulkIps}
		/>
		<Input
			label="Reason"
			placeholder="Reason for all bans"
			bind:value={bulkReason}
		/>
	</div>
	<div slot="footer">
		<Button variant="outline" on:click={() => (showBulkModal = false)}>Cancel</Button>
		<Button
			on:click={handleBulkBan}
			disabled={$bulkBanMutation.isPending || !bulkIps}
		>
			{#if $bulkBanMutation.isPending}
				<Spinner size="sm" class="mr-2" />
			{/if}
			Import
		</Button>
	</div>
</Modal>

<!-- Delete Confirmation Modal -->
<Modal bind:open={showDeleteModal} title="Unban IP">
	{#if selectedBan}
		<p class="text-muted-foreground">
			Are you sure you want to unban <strong class="font-mono">{selectedBan.ip}</strong>?
		</p>
	{/if}
	<div slot="footer">
		<Button variant="outline" on:click={() => (showDeleteModal = false)}>Cancel</Button>
		<Button
			variant="destructive"
			on:click={() => selectedBan && $deleteBanMutation.mutate(selectedBan.id)}
			disabled={$deleteBanMutation.isPending}
		>
			{#if $deleteBanMutation.isPending}
				<Spinner size="sm" class="mr-2" />
			{/if}
			Unban
		</Button>
	</div>
</Modal>
