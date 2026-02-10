<script lang="ts">
	import { createQuery, createMutation, useQueryClient } from '@tanstack/svelte-query';
	import { botsApi } from '$lib/api';
	import type { BotStats, WhitelistEntry } from '$lib/api';
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
		Tabs,
		TabsList,
		TabsTrigger,
		TabsContent
	} from '$lib/components/ui';
	import { Bot, Plus, Trash2, Search, RefreshCw, Shield, CheckCircle, XCircle } from 'lucide-svelte';

	const queryClient = useQueryClient();

	let activeTab = 'detections';
	let searchQuery = '';
	let showAddWhitelist = false;

	// New whitelist entry
	let newWhitelist = {
		user_agent: '',
		reason: ''
	};

	// Fetch bot stats
	$: statsQuery = createQuery({
		queryKey: ['bot-stats'],
		queryFn: () => botsApi.getStats()
	});

	// Fetch detections
	$: detectionsQuery = createQuery({
		queryKey: ['bot-detections'],
		queryFn: () => botsApi.listDetections({ limit: 100 })
	});

	// Fetch whitelist
	$: whitelistQuery = createQuery({
		queryKey: ['bot-whitelist'],
		queryFn: () => botsApi.listWhitelist()
	});

	// Mutations
	const addWhitelistMutation = createMutation({
		mutationFn: (data: Partial<WhitelistEntry>) => botsApi.addToWhitelist(data),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['bot-whitelist'] });
			showAddWhitelist = false;
			newWhitelist = { user_agent: '', reason: '' };
		}
	});

	const removeWhitelistMutation = createMutation({
		mutationFn: (id: string) => botsApi.removeFromWhitelist(id),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['bot-whitelist'] });
		}
	});

	const quickBlockMutation = createMutation({
		mutationFn: (userAgent: string) => botsApi.quickBlock(userAgent),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['bot-detections'] });
		}
	});

	const quickAllowMutation = createMutation({
		mutationFn: (userAgent: string) => botsApi.quickAllow(userAgent),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['bot-whitelist'] });
		}
	});

	function formatDate(date: string) {
		return new Date(date).toLocaleString();
	}

	$: filteredDetections = $detectionsQuery.data?.detections?.filter((d) => {
		if (!searchQuery) return true;
		const q = searchQuery.toLowerCase();
		return d.user_agent?.toLowerCase().includes(q) || d.ip?.toLowerCase().includes(q);
	});

	$: filteredWhitelist = $whitelistQuery.data?.rules?.filter((w) => {
		if (!searchQuery) return true;
		return w.user_agent?.toLowerCase().includes(searchQuery.toLowerCase());
	});
</script>

<svelte:head>
	<title>Bot Management - CatWAF</title>
</svelte:head>

<div class="space-y-6">
	<!-- Header -->
	<div class="flex items-center justify-between">
		<div>
			<h1 class="text-2xl font-bold flex items-center gap-2">
				<Bot class="h-6 w-6" />
				Bot Management
			</h1>
			<p class="text-muted-foreground">Manage bot detection and whitelisting</p>
		</div>
		<Button
			variant="outline"
			on:click={() => {
				queryClient.invalidateQueries({ queryKey: ['bot-stats'] });
				queryClient.invalidateQueries({ queryKey: ['bot-detections'] });
				queryClient.invalidateQueries({ queryKey: ['bot-whitelist'] });
			}}
		>
			<RefreshCw class="h-4 w-4 mr-2" />
			Refresh
		</Button>
	</div>

	<!-- Stats -->
	{#if $statsQuery.isLoading}
		<div class="flex justify-center py-4">
			<Spinner />
		</div>
	{:else if $statsQuery.data}
		<div class="grid grid-cols-4 gap-4">
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-primary/10 rounded-lg">
						<Bot class="h-5 w-5 text-primary" />
					</div>
					<div>
						<p class="text-2xl font-bold">{$statsQuery.data.total_detected || 0}</p>
						<p class="text-sm text-muted-foreground">Total Detected</p>
					</div>
				</div>
			</Card>
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-destructive/10 rounded-lg">
						<XCircle class="h-5 w-5 text-destructive" />
					</div>
					<div>
						<p class="text-2xl font-bold">{$statsQuery.data.blocked_today || 0}</p>
						<p class="text-sm text-muted-foreground">Blocked Today</p>
					</div>
				</div>
			</Card>
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-green-500/10 rounded-lg">
						<CheckCircle class="h-5 w-5 text-green-500" />
					</div>
					<div>
						<p class="text-2xl font-bold">{$whitelistQuery.data?.rules?.length || 0}</p>
						<p class="text-sm text-muted-foreground">Whitelisted</p>
					</div>
				</div>
			</Card>
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-yellow-500/10 rounded-lg">
						<Shield class="h-5 w-5 text-yellow-500" />
					</div>
					<div>
						<p class="text-2xl font-bold">{$statsQuery.data.unique_agents || 0}</p>
						<p class="text-sm text-muted-foreground">Unique Agents</p>
					</div>
				</div>
			</Card>
		</div>
	{/if}

	<!-- Main Content -->
	<Tabs bind:value={activeTab}>
		<div class="flex items-center justify-between mb-4">
			<TabsList>
				<TabsTrigger value="detections">
					<XCircle class="h-4 w-4 mr-2" />
					Detections
				</TabsTrigger>
				<TabsTrigger value="whitelist">
					<CheckCircle class="h-4 w-4 mr-2" />
					Whitelist
				</TabsTrigger>
			</TabsList>
			{#if activeTab === 'whitelist'}
				<Button on:click={() => (showAddWhitelist = true)}>
					<Plus class="h-4 w-4 mr-2" />
					Add to Whitelist
				</Button>
			{/if}
		</div>

		<!-- Search -->
		<Card class="p-4 mb-4">
			<div class="relative">
				<Search class="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-muted-foreground" />
				<Input
					placeholder="Search user agents..."
					class="pl-10"
					bind:value={searchQuery}
				/>
			</div>
		</Card>

		<!-- Detections Tab -->
		<TabsContent value="detections">
			<Card>
				{#if $detectionsQuery.isLoading}
					<div class="flex justify-center py-12">
						<Spinner size="lg" />
					</div>
				{:else if $detectionsQuery.error}
					<Alert variant="destructive" title="Error" class="m-4">
						Failed to load detections
					</Alert>
				{:else if filteredDetections?.length}
					<Table>
						<TableHeader>
							<TableRow>
								<TableHead>Time</TableHead>
								<TableHead>IP</TableHead>
								<TableHead>User Agent</TableHead>
								<TableHead>Type</TableHead>
								<TableHead>Actions</TableHead>
							</TableRow>
						</TableHeader>
						<TableBody>
							{#each filteredDetections as detection}
								<TableRow>
									<TableCell class="text-muted-foreground whitespace-nowrap">
										{formatDate(detection.created_at)}
									</TableCell>
									<TableCell class="font-mono">{detection.ip}</TableCell>
									<TableCell class="max-w-md truncate font-mono text-sm" title={detection.user_agent}>
										{detection.user_agent}
									</TableCell>
									<TableCell>
										<Badge variant="destructive">{detection.bot_type || 'Unknown'}</Badge>
									</TableCell>
									<TableCell>
										<div class="flex gap-2">
											<Button
												variant="outline"
												size="sm"
												on:click={() => $quickAllowMutation.mutate(detection.user_agent)}
											>
												<CheckCircle class="h-3 w-3 mr-1" />
												Allow
											</Button>
										</div>
									</TableCell>
								</TableRow>
							{/each}
						</TableBody>
					</Table>
				{:else}
					<div class="text-center py-12">
						<Bot class="h-12 w-12 mx-auto text-muted-foreground mb-4" />
						<p class="text-muted-foreground">No bot detections</p>
					</div>
				{/if}
			</Card>
		</TabsContent>

		<!-- Whitelist Tab -->
		<TabsContent value="whitelist">
			<Card>
				{#if $whitelistQuery.isLoading}
					<div class="flex justify-center py-12">
						<Spinner size="lg" />
					</div>
				{:else if $whitelistQuery.error}
					<Alert variant="destructive" title="Error" class="m-4">
						Failed to load whitelist
					</Alert>
				{:else if filteredWhitelist?.length}
					<Table>
						<TableHeader>
							<TableRow>
								<TableHead>User Agent Pattern</TableHead>
								<TableHead>Reason</TableHead>
								<TableHead>Added</TableHead>
								<TableHead></TableHead>
							</TableRow>
						</TableHeader>
						<TableBody>
							{#each filteredWhitelist as entry}
								<TableRow>
									<TableCell class="font-mono">{entry.user_agent}</TableCell>
									<TableCell>{entry.reason || '-'}</TableCell>
									<TableCell class="text-muted-foreground">
										{formatDate(entry.created_at)}
									</TableCell>
									<TableCell>
										<Button
											variant="ghost"
											size="icon"
											on:click={() => $removeWhitelistMutation.mutate(entry.id)}
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
						<CheckCircle class="h-12 w-12 mx-auto text-muted-foreground mb-4" />
						<p class="text-muted-foreground">No whitelisted user agents</p>
					</div>
				{/if}
			</Card>
		</TabsContent>
	</Tabs>
</div>

<!-- Add Whitelist Modal -->
<Modal bind:open={showAddWhitelist} title="Add to Whitelist" size="md">
	<div class="space-y-4">
		<Input
			label="User Agent Pattern"
			placeholder="Googlebot or *bot*"
			bind:value={newWhitelist.user_agent}
		/>
		<Input
			label="Reason"
			placeholder="Why is this being whitelisted?"
			bind:value={newWhitelist.reason}
		/>
		<Alert title="Pattern Matching">
			Use * as a wildcard to match any characters.
		</Alert>
	</div>
	<div slot="footer">
		<Button variant="outline" on:click={() => (showAddWhitelist = false)}>Cancel</Button>
		<Button
			on:click={() => $addWhitelistMutation.mutate(newWhitelist)}
			disabled={$addWhitelistMutation.isPending || !newWhitelist.user_agent}
		>
			{#if $addWhitelistMutation.isPending}
				<Spinner size="sm" class="mr-2" />
			{/if}
			Add
		</Button>
	</div>
</Modal>
