<script lang="ts">
	import { createQuery, createMutation, useQueryClient } from '@tanstack/svelte-query';
	import { sitesApi, type Site } from '$lib/api';
	import Card from '$lib/components/ui/Card.svelte';
	import Button from '$lib/components/ui/Button.svelte';
	import Modal from '$lib/components/ui/Modal.svelte';
	import Textarea from '$lib/components/ui/Textarea.svelte';
	import Alert from '$lib/components/ui/Alert.svelte';
	import Spinner from '$lib/components/ui/Spinner.svelte';
	import { Plus, Search, Globe, Shield, Trash2, Copy, Power, ExternalLink, Download, Upload } from 'lucide-svelte';
	import { goto } from '$app/navigation';

	const queryClient = useQueryClient();

	// Fetch sites
	const sitesQuery = createQuery({
		queryKey: ['sites'],
		queryFn: () => sitesApi.list()
	});

	// Delete mutation
	const deleteMutation = createMutation({
		mutationFn: (id: string) => sitesApi.delete(id),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['sites'] });
		}
	});

	// Toggle mutation
	const toggleMutation = createMutation({
		mutationFn: (id: string) => sitesApi.toggle(id),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['sites'] });
		}
	});

	// Import mutation
	const importMutation = createMutation({
		mutationFn: (data: unknown) => sitesApi.import(data),
		onSuccess: (result) => {
			queryClient.invalidateQueries({ queryKey: ['sites'] });
			showImportModal = false;
			importData = '';
			importSuccess = `Successfully imported ${result.imported} sites`;
		},
		onError: (error) => {
			importError = error instanceof Error ? error.message : 'Import failed';
		}
	});

	let searchQuery = '';
	let filterEnabled: 'all' | 'enabled' | 'disabled' = 'all';
	let showImportModal = false;
	let showExportModal = false;
	let importData = '';
	let importError = '';
	let importSuccess = '';
	let exportData = '';

	async function handleExport() {
		try {
			const result = await sitesApi.export();
			exportData = JSON.stringify(result, null, 2);
			showExportModal = true;
		} catch (error) {
			console.error('Export failed:', error);
		}
	}

	function handleImport() {
		importError = '';
		importSuccess = '';
		try {
			const data = JSON.parse(importData);
			$importMutation.mutate(data);
		} catch (e) {
			importError = 'Invalid JSON format';
		}
	}

	function downloadExport() {
		const blob = new Blob([exportData], { type: 'application/json' });
		const url = URL.createObjectURL(blob);
		const a = document.createElement('a');
		a.href = url;
		a.download = `catwaf-sites-export-${new Date().toISOString().split('T')[0]}.json`;
		a.click();
		URL.revokeObjectURL(url);
	}

	$: filteredSites = ($sitesQuery.data?.sites || []).filter((site) => {
		const matchesSearch =
			site.domain.toLowerCase().includes(searchQuery.toLowerCase()) ||
			(site.displayName || '').toLowerCase().includes(searchQuery.toLowerCase());
		const matchesFilter =
			filterEnabled === 'all' ||
			(filterEnabled === 'enabled' && site.enabled) ||
			(filterEnabled === 'disabled' && !site.enabled);
		return matchesSearch && matchesFilter;
	});

	function handleDelete(site: Site) {
		if (confirm(`Are you sure you want to delete ${site.domain}?`)) {
			deleteMutation.mutate(site.id);
		}
	}

	function handleToggle(site: Site) {
		toggleMutation.mutate(site.id);
	}
</script>

<svelte:head>
	<title>Sites - CatWAF</title>
</svelte:head>

<div class="space-y-6">
	<!-- Header -->
	<div class="flex items-center justify-between">
		<div>
			<h1 class="text-3xl font-bold gradient-text">Sites</h1>
			<p class="text-muted-foreground mt-1">Manage your protected websites</p>
		</div>
		<div class="flex items-center gap-2">
			<Button variant="outline" on:click={() => (showImportModal = true)}>
				<Upload class="h-4 w-4 mr-2" />
				Import
			</Button>
			<Button variant="outline" on:click={handleExport}>
				<Download class="h-4 w-4 mr-2" />
				Export
			</Button>
			<Button href="/sites/new" class="gap-2">
				<Plus class="h-4 w-4" />
				Add Site
			</Button>
		</div>
	</div>

	<!-- Filters -->
	<Card>
		<div class="flex flex-col gap-4 sm:flex-row sm:items-center">
			<div class="relative flex-1">
				<Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
				<input
					type="text"
					placeholder="Search sites..."
					bind:value={searchQuery}
					class="w-full rounded-lg border border-input bg-background py-2 pl-10 pr-4 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-primary"
				/>
			</div>
			<div class="flex gap-2">
				<button
					class="rounded-lg px-4 py-2 text-sm font-medium transition-colors"
					class:bg-primary={filterEnabled === 'all'}
					class:text-primary-foreground={filterEnabled === 'all'}
					class:bg-muted={filterEnabled !== 'all'}
					on:click={() => (filterEnabled = 'all')}
				>
					All
				</button>
				<button
					class="rounded-lg px-4 py-2 text-sm font-medium transition-colors"
					class:bg-primary={filterEnabled === 'enabled'}
					class:text-primary-foreground={filterEnabled === 'enabled'}
					class:bg-muted={filterEnabled !== 'enabled'}
					on:click={() => (filterEnabled = 'enabled')}
				>
					Enabled
				</button>
				<button
					class="rounded-lg px-4 py-2 text-sm font-medium transition-colors"
					class:bg-primary={filterEnabled === 'disabled'}
					class:text-primary-foreground={filterEnabled === 'disabled'}
					class:bg-muted={filterEnabled !== 'disabled'}
					on:click={() => (filterEnabled = 'disabled')}
				>
					Disabled
				</button>
			</div>
		</div>
	</Card>

	<!-- Sites List -->
	{#if $sitesQuery.isLoading}
		<div class="flex items-center justify-center py-12">
			<div class="h-8 w-8 animate-spin rounded-full border-4 border-primary border-t-transparent"></div>
		</div>
	{:else if $sitesQuery.error}
		<Card class="border-destructive">
			<p class="text-destructive">Failed to load sites. Please try again.</p>
		</Card>
	{:else if filteredSites.length === 0}
		<Card>
			<div class="flex flex-col items-center justify-center py-12 text-center">
				<Globe class="h-12 w-12 text-muted-foreground mb-4" />
				<h3 class="text-lg font-semibold">No sites found</h3>
				<p class="text-muted-foreground mt-1">
					{searchQuery ? 'Try adjusting your search query' : 'Add your first site to get started'}
				</p>
				{#if !searchQuery}
					<Button href="/sites/new" class="mt-4">
						<Plus class="h-4 w-4 mr-2" />
						Add Site
					</Button>
				{/if}
			</div>
		</Card>
	{:else}
		<div class="grid gap-4">
			{#each filteredSites as site (site.id)}
				<Card class="card-hover">
					<div class="flex items-center gap-4">
						<!-- Status indicator -->
						<div class="relative">
							<Globe class="h-10 w-10 text-muted-foreground" />
							<div
								class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-card"
								class:bg-green-500={site.enabled}
								class:bg-gray-400={!site.enabled}
							></div>
						</div>

						<!-- Site info -->
						<div class="flex-1 min-w-0">
							<div class="flex items-center gap-2">
								<h3 class="font-semibold truncate">{site.domain}</h3>
								{#if site.sslEnabled}
									<span class="rounded bg-green-500/10 px-2 py-0.5 text-xs font-medium text-green-500">
										SSL
									</span>
								{/if}
								{#if site.wafEnabled}
									<span class="rounded bg-blue-500/10 px-2 py-0.5 text-xs font-medium text-blue-500">
										WAF
									</span>
								{/if}
								{#if site.botProtectionEnabled}
									<span class="rounded bg-purple-500/10 px-2 py-0.5 text-xs font-medium text-purple-500">
										Bot Protection
									</span>
								{/if}
							</div>
							<div class="flex items-center gap-4 mt-1 text-sm text-muted-foreground">
								{#if site.backends && site.backends.length > 0}
									<span>{site.backends.length} backend{site.backends.length > 1 ? 's' : ''}</span>
								{/if}
								{#if site.aliases && site.aliases.length > 0}
									<span>+{site.aliases.length} alias{site.aliases.length > 1 ? 'es' : ''}</span>
								{/if}
							</div>
						</div>

						<!-- Actions -->
						<div class="flex items-center gap-2">
							<a
								href="https://{site.domain}"
								target="_blank"
								rel="noopener noreferrer"
								class="rounded-lg p-2 text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
								title="Open site"
							>
								<ExternalLink class="h-4 w-4" />
							</a>
							<button
								class="rounded-lg p-2 text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
								title={site.enabled ? 'Disable' : 'Enable'}
								on:click={() => handleToggle(site)}
							>
								<span class:text-green-500={site.enabled}>
									<Power class="h-4 w-4" />
								</span>
							</button>
							<a
								href="/sites/{site.id}"
								class="rounded-lg p-2 text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
								title="Edit"
							>
								<Shield class="h-4 w-4" />
							</a>
							<button
								class="rounded-lg p-2 text-muted-foreground hover:bg-destructive hover:text-destructive-foreground transition-colors"
								title="Delete"
								on:click={() => handleDelete(site)}
							>
								<Trash2 class="h-4 w-4" />
							</button>
						</div>
					</div>
				</Card>
			{/each}
		</div>
	{/if}
</div>
<!-- Import Modal -->
<Modal bind:open={showImportModal} title="Import Sites">
	<div class="space-y-4">
		<p class="text-sm text-muted-foreground">
			Paste a JSON export from CatWAF to import sites.
		</p>

		{#if importError}
			<Alert variant="destructive">{importError}</Alert>
		{/if}

		{#if importSuccess}
			<Alert variant="success">{importSuccess}</Alert>
		{/if}

		<Textarea
			placeholder='{"sites": [...]}'
			bind:value={importData}
			rows={10}
		/>
	</div>

	<div slot="footer" class="flex justify-end gap-2">
		<Button variant="outline" on:click={() => (showImportModal = false)}>Cancel</Button>
		<Button on:click={handleImport} disabled={!importData || $importMutation.isPending}>
			{#if $importMutation.isPending}
				<Spinner class="mr-2 h-4 w-4" />
			{/if}
			Import
		</Button>
	</div>
</Modal>

<!-- Export Modal -->
<Modal bind:open={showExportModal} title="Export Sites">
	<div class="space-y-4">
		<p class="text-sm text-muted-foreground">
			Copy or download the export data to save your site configurations.
		</p>

		<Textarea
			value={exportData}
			rows={10}
			readonly
		/>
	</div>

	<div slot="footer" class="flex justify-end gap-2">
		<Button variant="outline" on:click={() => (showExportModal = false)}>Close</Button>
		<Button variant="outline" on:click={() => navigator.clipboard.writeText(exportData)}>
			<Copy class="mr-2 h-4 w-4" />
			Copy
		</Button>
		<Button on:click={downloadExport}>
			<Download class="mr-2 h-4 w-4" />
			Download
		</Button>
	</div>
</Modal>