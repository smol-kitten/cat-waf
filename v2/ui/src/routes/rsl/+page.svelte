<script lang="ts">
	import { createQuery, createMutation, useQueryClient } from '@tanstack/svelte-query';
	import { rslApi } from '$lib/api';
	import {
		Card,
		Badge,
		Spinner,
		Button,
		Modal,
		Input,
		Textarea,
		Select,
		Switch,
		Tabs,
		TabsList,
		TabsTrigger,
		TabsContent,
		Table,
		TableHeader,
		TableBody,
		TableRow,
		TableHead,
		TableCell,
		Alert
	} from '$lib/components/ui';
	import {
		Key,
		FileText,
		Users,
		Coins,
		Plus,
		Trash2,
		Edit,
		Eye,
		RefreshCw,
		Settings,
		Copy
	} from 'lucide-svelte';

	const queryClient = useQueryClient();
	let activeTab = 'licenses';

	// Modal states
	let showLicenseModal = false;
	let showViewModal = false;
	let editingLicense: any = null;
	let viewingXml = '';

	// License form
	let licenseForm = {
		name: '',
		description: '',
		scope: 'site',
		urlPattern: '',
		permits: [] as string[],
		prohibits: [] as string[],
		enabled: true
	};

	const permitOptions = ['display', 'index', 'cache', 'train', 'derive'];

	// Queries
	$: licensesQuery = createQuery({
		queryKey: ['rsl', 'licenses'],
		queryFn: () => rslApi.licenses.list()
	});

	$: statsQuery = createQuery({
		queryKey: ['rsl', 'stats'],
		queryFn: () => rslApi.stats()
	});

	$: clientsQuery = createQuery({
		queryKey: ['rsl', 'clients'],
		queryFn: () => rslApi.clients.list()
	});

	$: tokensQuery = createQuery({
		queryKey: ['rsl', 'tokens'],
		queryFn: () => rslApi.tokens.list()
	});

	// Reactive stats
	$: stats = $statsQuery.data?.stats ?? { licenses: 0, clients: 0, tokens: 0, requests: 0 };

	// Mutations
	const createLicenseMutation = createMutation({
		mutationFn: (data: any) => rslApi.licenses.create(data),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['rsl'] });
			showLicenseModal = false;
			resetLicenseForm();
		}
	});

	const deleteLicenseMutation = createMutation({
		mutationFn: (id: string) => rslApi.licenses.delete(id),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['rsl'] });
		}
	});

	function resetLicenseForm() {
		licenseForm = {
			name: '',
			description: '',
			scope: 'site',
			urlPattern: '',
			permits: [],
			prohibits: [],
			enabled: true
		};
		editingLicense = null;
	}

	function openEditModal(license: any) {
		editingLicense = license;
		licenseForm = { ...license };
		showLicenseModal = true;
	}

	function togglePermit(permit: string) {
		if (licenseForm.permits.includes(permit)) {
			licenseForm.permits = licenseForm.permits.filter(p => p !== permit);
		} else {
			licenseForm.permits = [...licenseForm.permits, permit];
		}
	}

	function toggleProhibit(prohibit: string) {
		if (licenseForm.prohibits.includes(prohibit)) {
			licenseForm.prohibits = licenseForm.prohibits.filter(p => p !== prohibit);
		} else {
			licenseForm.prohibits = [...licenseForm.prohibits, prohibit];
		}
	}
</script>

<svelte:head>
	<title>RSL Licensing - CatWAF</title>
</svelte:head>

<div class="space-y-6">
	<!-- Header -->
	<div class="flex items-center justify-between">
		<div>
			<h1 class="text-2xl font-bold flex items-center gap-2">
				<Key class="h-6 w-6" />
				RSL License Server
			</h1>
			<p class="text-muted-foreground">Really Simple Licensing - OLP Protocol Implementation</p>
		</div>
		<Button on:click={() => showLicenseModal = true}>
			<Plus class="h-4 w-4 mr-2" />
			Add License
		</Button>
	</div>

	<!-- Stats -->
	<div class="grid grid-cols-4 gap-4">
		<Card class="p-4">
			<div class="flex items-center gap-3">
				<div class="p-2 bg-blue-500/10 rounded-lg">
					<FileText class="h-5 w-5 text-blue-500" />
				</div>
				<div>
					<p class="text-2xl font-bold">{$licensesQuery.data?.licenses?.length || stats.licenses}</p>
					<p class="text-sm text-muted-foreground">Licenses</p>
				</div>
			</div>
		</Card>
		<Card class="p-4">
			<div class="flex items-center gap-3">
				<div class="p-2 bg-green-500/10 rounded-lg">
					<Users class="h-5 w-5 text-green-500" />
				</div>
				<div>
					<p class="text-2xl font-bold">{$clientsQuery.data?.clients?.length || stats.clients}</p>
					<p class="text-sm text-muted-foreground">Clients</p>
				</div>
			</div>
		</Card>
		<Card class="p-4">
			<div class="flex items-center gap-3">
				<div class="p-2 bg-purple-500/10 rounded-lg">
					<Key class="h-5 w-5 text-purple-500" />
				</div>
				<div>
					<p class="text-2xl font-bold">{$tokensQuery.data?.tokens?.length || stats.tokens}</p>
					<p class="text-sm text-muted-foreground">Active Tokens</p>
				</div>
			</div>
		</Card>
		<Card class="p-4">
			<div class="flex items-center gap-3">
				<div class="p-2 bg-orange-500/10 rounded-lg">
					<Coins class="h-5 w-5 text-orange-500" />
				</div>
				<div>
					<p class="text-2xl font-bold">{stats.requests.toLocaleString()}</p>
					<p class="text-sm text-muted-foreground">Total Requests</p>
				</div>
			</div>
		</Card>
	</div>

	<!-- Tabs -->
	<Tabs bind:value={activeTab}>
		<TabsList>
			<TabsTrigger value="licenses">
				<FileText class="h-4 w-4 mr-2" />
				Licenses
			</TabsTrigger>
			<TabsTrigger value="clients">
				<Users class="h-4 w-4 mr-2" />
				Clients
			</TabsTrigger>
			<TabsTrigger value="tokens">
				<Key class="h-4 w-4 mr-2" />
				Tokens
			</TabsTrigger>
			<TabsTrigger value="settings">
				<Settings class="h-4 w-4 mr-2" />
				Settings
			</TabsTrigger>
		</TabsList>

		<TabsContent value="licenses">
			<Card>
				{#if $regionsQuery.isLoading}
					<div class="flex justify-center py-12">
						<Spinner size="lg" />
					</div>
				{:else if $regionsQuery.data?.regions?.length}
					<Table>
						<TableHeader>
							<TableRow>
								<TableHead>Name</TableHead>
								<TableHead>Scope</TableHead>
								<TableHead>URL Pattern</TableHead>
								<TableHead>Permits</TableHead>
								<TableHead>Status</TableHead>
								<TableHead>Actions</TableHead>
							</TableRow>
						</TableHeader>
						<TableBody>
							{#each $regionsQuery.data.regions as license}
								<TableRow>
									<TableCell>
										<div>
											<p class="font-medium">{license.name}</p>
											{#if license.description}
												<p class="text-xs text-muted-foreground">{license.description}</p>
											{/if}
										</div>
									</TableCell>
									<TableCell>
										<Badge variant="outline">{license.scope || 'site'}</Badge>
									</TableCell>
									<TableCell class="font-mono text-xs">{license.urlPattern || '*'}</TableCell>
									<TableCell>
										{#if license.permits?.length}
											<div class="flex gap-1 flex-wrap">
												{#each license.permits as permit}
													<Badge variant="secondary" class="text-xs">{permit}</Badge>
												{/each}
											</div>
										{:else}
											<span class="text-muted-foreground">-</span>
										{/if}
									</TableCell>
									<TableCell>
										<Badge variant={license.enabled ? 'success' : 'secondary'}>
											{license.enabled ? 'Active' : 'Disabled'}
										</Badge>
									</TableCell>
									<TableCell>
										<div class="flex gap-1">
											<Button variant="ghost" size="icon" on:click={() => openEditModal(license)}>
												<Edit class="h-4 w-4" />
											</Button>
											<Button 
												variant="ghost" 
												size="icon"
												on:click={() => $deleteLicenseMutation.mutate(license.id)}
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
						<FileText class="h-12 w-12 mx-auto text-muted-foreground mb-4" />
						<p class="text-muted-foreground mb-4">No licenses configured</p>
						<Button on:click={() => showLicenseModal = true}>
							<Plus class="h-4 w-4 mr-2" />
							Create License
						</Button>
					</div>
				{/if}
			</Card>
		</TabsContent>

		<TabsContent value="clients">
			<Card class="p-8 text-center">
				<Users class="h-12 w-12 mx-auto text-muted-foreground mb-4" />
				<p class="text-muted-foreground">
					OLP Clients will appear here when registered
				</p>
			</Card>
		</TabsContent>

		<TabsContent value="tokens">
			<Card class="p-8 text-center">
				<Key class="h-12 w-12 mx-auto text-muted-foreground mb-4" />
				<p class="text-muted-foreground">
					Active tokens will appear here
				</p>
			</Card>
		</TabsContent>

		<TabsContent value="settings">
			<Card class="p-6 space-y-6">
				<div class="flex items-center justify-between">
					<div>
						<h3 class="font-medium">Enable RSL Server</h3>
						<p class="text-sm text-muted-foreground">Enable the RSL license server</p>
					</div>
					<Switch checked={true} />
				</div>
				<div class="flex items-center justify-between">
					<div>
						<h3 class="font-medium">Server Mode</h3>
						<p class="text-sm text-muted-foreground">How licenses are served</p>
					</div>
					<Select value="inject" class="w-40">
						<option value="inject">Inject</option>
						<option value="external">External</option>
					</Select>
				</div>
				<div>
					<h3 class="font-medium mb-2">Default Permits</h3>
					<div class="flex flex-wrap gap-2">
						{#each permitOptions as permit}
							<label class="flex items-center gap-2">
								<input type="checkbox" class="rounded" />
								<span class="capitalize">{permit}</span>
							</label>
						{/each}
					</div>
				</div>
				<Button>Save Settings</Button>
			</Card>
		</TabsContent>
	</Tabs>
</div>

<!-- License Modal -->
<Modal bind:open={showLicenseModal} title={editingLicense ? 'Edit License' : 'Create License'} size="lg">
	<div class="space-y-4">
		<Input
			label="License Name"
			placeholder="My License"
			bind:value={licenseForm.name}
		/>
		<Textarea
			label="Description"
			placeholder="Optional description..."
			rows={2}
			bind:value={licenseForm.description}
		/>
		<Select label="Scope" bind:value={licenseForm.scope}>
			<option value="site">Site</option>
			<option value="global">Global</option>
			<option value="path">Path</option>
		</Select>
		<Input
			label="URL Pattern"
			placeholder="/*"
			bind:value={licenseForm.urlPattern}
		/>
		<div>
			<label class="block text-sm font-medium mb-2">Permits</label>
			<div class="flex flex-wrap gap-2">
				{#each permitOptions as permit}
					<Button
						variant={licenseForm.permits.includes(permit) ? 'default' : 'outline'}
						size="sm"
						on:click={() => togglePermit(permit)}
					>
						{permit}
					</Button>
				{/each}
			</div>
		</div>
		<div>
			<label class="block text-sm font-medium mb-2">Prohibits</label>
			<div class="flex flex-wrap gap-2">
				{#each permitOptions as prohibit}
					<Button
						variant={licenseForm.prohibits.includes(prohibit) ? 'destructive' : 'outline'}
						size="sm"
						on:click={() => toggleProhibit(prohibit)}
					>
						{prohibit}
					</Button>
				{/each}
			</div>
		</div>
		<div class="flex items-center gap-2">
			<Switch bind:checked={licenseForm.enabled} />
			<span>Enabled</span>
		</div>
	</div>
	<div slot="footer">
		<Button variant="outline" on:click={() => { showLicenseModal = false; resetLicenseForm(); }}>
			Cancel
		</Button>
		<Button
			on:click={() => $createLicenseMutation.mutate(licenseForm)}
			disabled={$createLicenseMutation.isPending || !licenseForm.name}
		>
			{#if $createLicenseMutation.isPending}
				<Spinner size="sm" class="mr-2" />
			{/if}
			{editingLicense ? 'Update' : 'Create'}
		</Button>
	</div>
</Modal>
