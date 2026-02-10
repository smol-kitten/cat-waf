<script lang="ts">
	import { page } from '$app/stores';
	import { goto } from '$app/navigation';
	import { createQuery, createMutation, useQueryClient } from '@tanstack/svelte-query';
	import { sitesApi, certificatesApi } from '$lib/api';
	import type { Site, Backend, PathRoute, WellknownFile, ErrorPage, Certificate } from '$lib/api';
	import {
		Button,
		Input,
		Select,
		Switch,
		Textarea,
		Tabs,
		TabsList,
		TabsTrigger,
		TabsContent,
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
		TableCell
	} from '$lib/components/ui';
	import {
		ArrowLeft,
		Save,
		Plus,
		Trash2,
		Server,
		Shield,
		Lock,
		FileText,
		Globe,
		Settings,
		Route,
		Gauge,
		AlertTriangle
	} from 'lucide-svelte';

	$: siteId = $page.params.id;
	$: isNew = siteId === 'new';

	const queryClient = useQueryClient();

	// Fetch site data
	$: siteQuery = createQuery({
		queryKey: ['site', siteId],
		queryFn: () => sitesApi.get(siteId),
		enabled: !isNew
	});

	// Fetch certificates for SSL dropdown
	$: certsQuery = createQuery({
		queryKey: ['certificates'],
		queryFn: () => certificatesApi.list()
	});

	// Fetch backends
	$: backendsQuery = createQuery({
		queryKey: ['site', siteId, 'backends'],
		queryFn: () => sitesApi.backends.list(siteId),
		enabled: !isNew
	});

	// Fetch path routes
	$: pathRoutesQuery = createQuery({
		queryKey: ['site', siteId, 'pathRoutes'],
		queryFn: () => sitesApi.pathRoutes.list(siteId),
		enabled: !isNew
	});

	// Fetch wellknown files
	$: wellknownQuery = createQuery({
		queryKey: ['site', siteId, 'wellknown'],
		queryFn: () => sitesApi.wellknown.list(siteId),
		enabled: !isNew
	});

	// Fetch error pages
	$: errorPagesQuery = createQuery({
		queryKey: ['site', siteId, 'errorPages'],
		queryFn: () => sitesApi.errorPages.list(siteId),
		enabled: !isNew
	});

	// Form state
	let site: Partial<Site> = {
		domain: '',
		enabled: true,
		aliases: [],
		ssl_mode: 'auto',
		waf_enabled: true,
		waf_mode: 'on',
		rate_limit_enabled: false,
		rate_limit_requests: 100,
		rate_limit_window: 60,
		block_bad_bots: true,
		geo_block_enabled: false,
		geo_block_countries: [],
		use_cf_ip_headers: false,
		local_only: false,
		// Challenge Mode (DDoS Protection)
		challenge_enabled: false,
		challenge_difficulty: 16,
		challenge_duration: 4,
		challenge_bypass_cf: true,
		// Compression
		enable_gzip: true,
		enable_brotli: true,
		compression_level: 5,
		// Basic Auth
		enable_basic_auth: false,
		basic_auth_username: '',
		basic_auth_password: '',
		// IP Whitelist
		ip_whitelist: '',
		// Load Balancing
		lb_method: 'round_robin',
		// WebSocket
		websocket_enabled: false,
		websocket_path: '/ws',
		// Advanced
		client_max_body_size: '100M',
		proxy_read_timeout: 60,
		proxy_connect_timeout: 60,
		enable_hsts: true,
		hsts_max_age: 31536000,
		wildcard_subdomains: false,
		custom_nginx_directives: ''
	};

	// Watch for site data - reset form when site changes
	$: if ($siteQuery.data?.site) {
		const loadedSite = $siteQuery.data.site;
		site = {
			domain: loadedSite.domain || '',
			enabled: loadedSite.enabled ?? true,
			aliases: loadedSite.aliases || [],
			ssl_mode: loadedSite.ssl_mode || 'auto',
			waf_enabled: loadedSite.waf_enabled ?? true,
			waf_mode: loadedSite.waf_mode || 'on',
			rate_limit_enabled: loadedSite.rate_limit_enabled ?? false,
			rate_limit_requests: loadedSite.rate_limit_requests ?? 100,
			rate_limit_window: loadedSite.rate_limit_window ?? 60,
			block_bad_bots: loadedSite.block_bad_bots ?? true,
			geo_block_enabled: loadedSite.geo_block_enabled ?? false,
			geo_block_countries: loadedSite.geo_block_countries || [],
			use_cf_ip_headers: loadedSite.use_cf_ip_headers ?? false,
			local_only: loadedSite.local_only ?? false,
			challenge_enabled: loadedSite.challenge_enabled ?? false,
			challenge_difficulty: loadedSite.challenge_difficulty ?? 16,
			challenge_duration: loadedSite.challenge_duration ?? 4,
			challenge_bypass_cf: loadedSite.challenge_bypass_cf ?? true,
			enable_gzip: loadedSite.enable_gzip ?? true,
			enable_brotli: loadedSite.enable_brotli ?? true,
			compression_level: loadedSite.compression_level ?? 5,
			enable_basic_auth: loadedSite.enable_basic_auth ?? false,
			basic_auth_username: loadedSite.basic_auth_username || '',
			basic_auth_password: loadedSite.basic_auth_password || '',
			ip_whitelist: loadedSite.ip_whitelist || '',
			lb_method: loadedSite.lb_method || 'round_robin',
			websocket_enabled: loadedSite.websocket_enabled ?? false,
			websocket_path: loadedSite.websocket_path || '/ws',
			client_max_body_size: loadedSite.client_max_body_size || '100M',
			proxy_read_timeout: loadedSite.proxy_read_timeout ?? 60,
			proxy_connect_timeout: loadedSite.proxy_connect_timeout ?? 60,
			enable_hsts: loadedSite.enable_hsts ?? true,
			hsts_max_age: loadedSite.hsts_max_age ?? 31536000,
			wildcard_subdomains: loadedSite.wildcard_subdomains ?? false,
			custom_nginx_directives: loadedSite.custom_nginx_directives || ''
		};
	}

	let activeTab = 'basic';
	let aliasInput = '';
	let geoCountryInput = '';
	let showDeleteModal = false;
	let saveError = '';

	// Add backend state
	let newBackend: Partial<Backend> = {
		address: '',
		port: 80,
		weight: 1,
		protocol: 'http',
		health_check: true
	};
	let showAddBackend = false;

	// Add path route state
	let newPathRoute: Partial<PathRoute> = {
		path: '',
		backend_address: '',
		strip_path: false,
		rewrite_path: ''
	};
	let showAddPathRoute = false;

	// Add wellknown state
	let newWellknown: Partial<WellknownFile> = {
		path: '',
		content: ''
	};
	let showAddWellknown = false;

	// Add error page state
	let newErrorPage: Partial<ErrorPage> = {
		code: 404,
		content: ''
	};
	let showAddErrorPage = false;

	// Mutations
	const createSiteMutation = createMutation({
		mutationFn: (data: Partial<Site>) => sitesApi.create(data),
		onSuccess: (data) => {
			queryClient.invalidateQueries({ queryKey: ['sites'] });
			goto(`/sites/${data.id}`);
		},
		onError: (error) => {
			saveError = error instanceof Error ? error.message : 'Failed to create site';
		}
	});

	const updateSiteMutation = createMutation({
		mutationFn: (data: Partial<Site>) => sitesApi.update(siteId, data),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['site', siteId] });
			queryClient.invalidateQueries({ queryKey: ['sites'] });
			saveError = '';
		},
		onError: (error) => {
			saveError = error instanceof Error ? error.message : 'Failed to update site';
		}
	});

	const deleteSiteMutation = createMutation({
		mutationFn: () => sitesApi.delete(siteId),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['sites'] });
			goto('/sites');
		}
	});

	// Backend mutations
	const addBackendMutation = createMutation({
		mutationFn: (data: Partial<Backend>) => sitesApi.backends.create(siteId, data),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['site', siteId, 'backends'] });
			showAddBackend = false;
			newBackend = { address: '', port: 80, weight: 1, protocol: 'http', health_check: true };
		}
	});

	const deleteBackendMutation = createMutation({
		mutationFn: (backendId: string) => sitesApi.backends.delete(siteId, backendId),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['site', siteId, 'backends'] });
		}
	});

	// Path route mutations
	const addPathRouteMutation = createMutation({
		mutationFn: (data: Partial<PathRoute>) => sitesApi.pathRoutes.create(siteId, data),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['site', siteId, 'pathRoutes'] });
			showAddPathRoute = false;
			newPathRoute = { path: '', backend_address: '', strip_path: false, rewrite_path: '' };
		}
	});

	const deletePathRouteMutation = createMutation({
		mutationFn: (routeId: string) => sitesApi.pathRoutes.delete(siteId, routeId),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['site', siteId, 'pathRoutes'] });
		}
	});

	// Wellknown mutations
	const addWellknownMutation = createMutation({
		mutationFn: (data: Partial<WellknownFile>) => sitesApi.wellknown.create(siteId, data),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['site', siteId, 'wellknown'] });
			showAddWellknown = false;
			newWellknown = { path: '', content: '' };
		}
	});

	const deleteWellknownMutation = createMutation({
		mutationFn: (wellknownId: string) => sitesApi.wellknown.delete(siteId, wellknownId),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['site', siteId, 'wellknown'] });
		}
	});

	// Error page mutations
	const addErrorPageMutation = createMutation({
		mutationFn: (data: Partial<ErrorPage>) => sitesApi.errorPages.create(siteId, data),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['site', siteId, 'errorPages'] });
			showAddErrorPage = false;
			newErrorPage = { code: 404, content: '' };
		}
	});

	const deleteErrorPageMutation = createMutation({
		mutationFn: (errorPageId: string) => sitesApi.errorPages.delete(siteId, errorPageId),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['site', siteId, 'errorPages'] });
		}
	});

	function handleSave() {
		saveError = '';
		if (isNew) {
			$createSiteMutation.mutate(site);
		} else {
			$updateSiteMutation.mutate(site);
		}
	}

	function addAlias() {
		if (aliasInput && !site.aliases?.includes(aliasInput)) {
			site.aliases = [...(site.aliases || []), aliasInput];
			aliasInput = '';
		}
	}

	function removeAlias(alias: string) {
		site.aliases = site.aliases?.filter((a) => a !== alias) || [];
	}

	function addGeoCountry() {
		if (geoCountryInput && !site.geo_block_countries?.includes(geoCountryInput.toUpperCase())) {
			site.geo_block_countries = [...(site.geo_block_countries || []), geoCountryInput.toUpperCase()];
			geoCountryInput = '';
		}
	}

	function removeGeoCountry(country: string) {
		site.geo_block_countries = site.geo_block_countries?.filter((c) => c !== country) || [];
	}

	const sslModeOptions = [
		{ value: 'auto', label: 'Auto (Let\'s Encrypt)' },
		{ value: 'custom', label: 'Custom Certificate' },
		{ value: 'none', label: 'HTTP Only' }
	];

	const wafModeOptions = [
		{ value: 'on', label: 'Enabled (Block)' },
		{ value: 'detection', label: 'Detection Only' },
		{ value: 'off', label: 'Disabled' }
	];

	const protocolOptions = [
		{ value: 'http', label: 'HTTP' },
		{ value: 'https', label: 'HTTPS' }
	];
</script>

<svelte:head>
	<title>{isNew ? 'New Site' : site.domain || 'Edit Site'} - CatWAF</title>
</svelte:head>

<div class="space-y-6">
	<!-- Header -->
	<div class="flex items-center justify-between">
		<div class="flex items-center gap-4">
			<Button variant="ghost" size="icon" href="/sites">
				<ArrowLeft class="h-4 w-4" />
			</Button>
			<div>
				<h1 class="text-2xl font-bold">
					{isNew ? 'New Site' : site.domain || 'Loading...'}
				</h1>
				<p class="text-muted-foreground">
					{isNew ? 'Create a new site configuration' : 'Edit site configuration'}
				</p>
			</div>
		</div>
		<div class="flex items-center gap-2">
			{#if !isNew}
				<Button variant="destructive" on:click={() => (showDeleteModal = true)}>
					<Trash2 class="h-4 w-4 mr-2" />
					Delete
				</Button>
			{/if}
			<Button
				on:click={handleSave}
				disabled={$createSiteMutation.isPending || $updateSiteMutation.isPending}
			>
				{#if $createSiteMutation.isPending || $updateSiteMutation.isPending}
					<Spinner size="sm" class="mr-2" />
				{:else}
					<Save class="h-4 w-4 mr-2" />
				{/if}
				{isNew ? 'Create' : 'Save'}
			</Button>
		</div>
	</div>

	{#if saveError}
		<Alert variant="destructive" title="Error">
			{saveError}
		</Alert>
	{/if}

	{#if $siteQuery.isLoading && !isNew}
		<div class="flex justify-center py-12">
			<Spinner size="lg" />
		</div>
	{:else}
		<Tabs bind:value={activeTab}>
			<TabsList class="grid w-full grid-cols-5 lg:grid-cols-12">
				<TabsTrigger value="basic">
					<Globe class="h-4 w-4 mr-1 hidden sm:block" />
					Basic
				</TabsTrigger>
				<TabsTrigger value="backends">
					<Server class="h-4 w-4 mr-1 hidden sm:block" />
					Backends
				</TabsTrigger>
				<TabsTrigger value="routes">
					<Route class="h-4 w-4 mr-1 hidden sm:block" />
					Routes
				</TabsTrigger>
				<TabsTrigger value="security">
					<Shield class="h-4 w-4 mr-1 hidden sm:block" />
					Security
				</TabsTrigger>
				<TabsTrigger value="challenge">
					<AlertTriangle class="h-4 w-4 mr-1 hidden sm:block" />
					Challenge
				</TabsTrigger>
				<TabsTrigger value="ratelimit">
					<Gauge class="h-4 w-4 mr-1 hidden sm:block" />
					Rate Limit
				</TabsTrigger>
				<TabsTrigger value="ssl">
					<Lock class="h-4 w-4 mr-1 hidden sm:block" />
					SSL
				</TabsTrigger>
				<TabsTrigger value="errors">
					<AlertTriangle class="h-4 w-4 mr-1 hidden sm:block" />
					Errors
				</TabsTrigger>
				<TabsTrigger value="wellknown">
					<FileText class="h-4 w-4 mr-1 hidden sm:block" />
					.well-known
				</TabsTrigger>
				<TabsTrigger value="advanced">
					<Settings class="h-4 w-4 mr-1 hidden sm:block" />
					Advanced
				</TabsTrigger>
			</TabsList>

			<!-- Basic Settings -->
			<TabsContent value="basic">
				<Card class="p-6 space-y-4">
					<Input
						label="Domain"
						placeholder="example.com"
						bind:value={site.domain}
						required
					/>

					<div class="space-y-2">
						<label class="text-sm font-medium">Aliases</label>
						<div class="flex gap-2">
							<Input
								placeholder="www.example.com"
								bind:value={aliasInput}
								class="flex-1"
								on:keydown={(e) => e.key === 'Enter' && addAlias()}
							/>
							<Button variant="outline" on:click={addAlias}>
								<Plus class="h-4 w-4" />
							</Button>
						</div>
						{#if site.aliases?.length}
							<div class="flex flex-wrap gap-2 mt-2">
								{#each site.aliases as alias}
									<Badge variant="secondary" class="flex items-center gap-1">
										{alias}
										<button
											type="button"
											class="ml-1 hover:text-destructive"
											on:click={() => removeAlias(alias)}
										>
											×
										</button>
									</Badge>
								{/each}
							</div>
						{/if}
					</div>

					<div class="flex items-center justify-between">
						<div>
							<p class="font-medium">Site Enabled</p>
							<p class="text-sm text-muted-foreground">Enable or disable this site</p>
						</div>
						<Switch bind:checked={site.enabled} />
					</div>
				</Card>
			</TabsContent>

			<!-- Backends -->
			<TabsContent value="backends">
				<Card class="p-6 space-y-4">
					<div class="flex items-center justify-between mb-4">
						<div>
							<h3 class="text-lg font-semibold">Backend Servers</h3>
							<p class="text-sm text-muted-foreground">Configure upstream servers</p>
						</div>
						{#if !isNew}
							<Button on:click={() => (showAddBackend = true)}>
								<Plus class="h-4 w-4 mr-2" />
								Add Backend
							</Button>
						{/if}
					</div>

					{#if isNew}
						<Alert variant="warning" title="Save First">
							Please save the site first to add backends.
						</Alert>
					{:else if $backendsQuery.isLoading}
						<div class="flex justify-center py-8">
							<Spinner />
						</div>
					{:else if $backendsQuery.data?.length}
						<Table>
							<TableHeader>
								<TableRow>
									<TableHead>Address</TableHead>
									<TableHead>Port</TableHead>
									<TableHead>Protocol</TableHead>
									<TableHead>Weight</TableHead>
									<TableHead>Health Check</TableHead>
									<TableHead></TableHead>
								</TableRow>
							</TableHeader>
							<TableBody>
								{#each $backendsQuery.data as backend}
									<TableRow>
										<TableCell class="font-mono">{backend.address}</TableCell>
										<TableCell>{backend.port}</TableCell>
										<TableCell>
											<Badge variant="outline">{backend.protocol}</Badge>
										</TableCell>
										<TableCell>{backend.weight}</TableCell>
										<TableCell>
											<Badge variant={backend.health_check ? 'success' : 'secondary'}>
												{backend.health_check ? 'Yes' : 'No'}
											</Badge>
										</TableCell>
										<TableCell>
											<Button
												variant="ghost"
												size="icon"
												on:click={() => $deleteBackendMutation.mutate(backend.id)}
											>
												<Trash2 class="h-4 w-4 text-destructive" />
											</Button>
										</TableCell>
									</TableRow>
								{/each}
							</TableBody>
						</Table>
					{:else}
						<p class="text-center text-muted-foreground py-8">No backends configured</p>
					{/if}
				</Card>
			</TabsContent>

			<!-- Path Routes -->
			<TabsContent value="routes">
				<Card class="p-6 space-y-4">
					<div class="flex items-center justify-between mb-4">
						<div>
							<h3 class="text-lg font-semibold">Path Routes</h3>
							<p class="text-sm text-muted-foreground">Route specific paths to different backends</p>
						</div>
						{#if !isNew}
							<Button on:click={() => (showAddPathRoute = true)}>
								<Plus class="h-4 w-4 mr-2" />
								Add Route
							</Button>
						{/if}
					</div>

					{#if isNew}
						<Alert variant="warning" title="Save First">
							Please save the site first to add path routes.
						</Alert>
					{:else if $pathRoutesQuery.isLoading}
						<div class="flex justify-center py-8">
							<Spinner />
						</div>
					{:else if $pathRoutesQuery.data?.length}
						<Table>
							<TableHeader>
								<TableRow>
									<TableHead>Path</TableHead>
									<TableHead>Backend</TableHead>
									<TableHead>Strip Path</TableHead>
									<TableHead>Rewrite</TableHead>
									<TableHead></TableHead>
								</TableRow>
							</TableHeader>
							<TableBody>
								{#each $pathRoutesQuery.data as route}
									<TableRow>
										<TableCell class="font-mono">{route.path}</TableCell>
										<TableCell class="font-mono">{route.backend_address}</TableCell>
										<TableCell>
											<Badge variant={route.strip_path ? 'success' : 'secondary'}>
												{route.strip_path ? 'Yes' : 'No'}
											</Badge>
										</TableCell>
										<TableCell class="font-mono">{route.rewrite_path || '-'}</TableCell>
										<TableCell>
											<Button
												variant="ghost"
												size="icon"
												on:click={() => $deletePathRouteMutation.mutate(route.id)}
											>
												<Trash2 class="h-4 w-4 text-destructive" />
											</Button>
										</TableCell>
									</TableRow>
								{/each}
							</TableBody>
						</Table>
					{:else}
						<p class="text-center text-muted-foreground py-8">No path routes configured</p>
					{/if}
				</Card>
			</TabsContent>

			<!-- Security / WAF -->
			<TabsContent value="security">
				<Card class="p-6 space-y-6">
					<div class="flex items-center justify-between">
						<div>
							<p class="font-medium">Web Application Firewall</p>
							<p class="text-sm text-muted-foreground">Enable ModSecurity WAF protection</p>
						</div>
						<Switch bind:checked={site.waf_enabled} />
					</div>

					{#if site.waf_enabled}
						<Select
							label="WAF Mode"
							options={wafModeOptions}
							bind:value={site.waf_mode}
						/>
					{/if}

					<div class="flex items-center justify-between">
						<div>
							<p class="font-medium">Block Bad Bots</p>
							<p class="text-sm text-muted-foreground">Block known malicious bot user agents</p>
						</div>
						<Switch bind:checked={site.block_bad_bots} />
					</div>

					<div class="border-t pt-6">
						<div class="flex items-center justify-between mb-4">
							<div>
								<p class="font-medium">GeoIP Blocking</p>
								<p class="text-sm text-muted-foreground">Block requests from specific countries</p>
							</div>
							<Switch bind:checked={site.geo_block_enabled} />
						</div>

						{#if site.geo_block_enabled}
							<div class="space-y-4">
								<div>
									<label class="text-sm font-medium">Blocked Countries</label>
									<div class="flex items-center gap-2 mt-2">
										<Input
											placeholder="Country code (e.g., CN, RU)"
											bind:value={geoCountryInput}
											on:keydown={(e) => e.key === 'Enter' && addGeoCountry()}
										/>
										<Button variant="outline" size="icon" on:click={addGeoCountry}>
											<Plus class="h-4 w-4" />
										</Button>
									</div>
									{#if site.geo_block_countries?.length}
										<div class="flex flex-wrap gap-2 mt-2">
											{#each site.geo_block_countries as country}
												<Badge variant="secondary" class="flex items-center gap-1">
													{country}
													<button
														type="button"
														class="ml-1 hover:text-destructive"
														on:click={() => removeGeoCountry(country)}
													>
														×
													</button>
												</Badge>
											{/each}
										</div>
									{/if}
								</div>
								<p class="text-sm text-muted-foreground">
									Use ISO 3166-1 alpha-2 country codes (e.g., US, GB, CN, RU)
								</p>
							</div>
						{/if}
					</div>
				</Card>
			</TabsContent>

			<!-- Challenge Mode (DDoS Protection) -->
			<TabsContent value="challenge">
				<Card class="p-6 space-y-6">
					<div class="flex items-center justify-between">
						<div>
							<p class="font-medium">Enable Challenge Mode</p>
							<p class="text-sm text-muted-foreground">Require JavaScript proof-of-work to access site</p>
						</div>
						<Switch bind:checked={site.challenge_enabled} />
					</div>

					{#if site.challenge_enabled}
						<div class="grid grid-cols-2 gap-4">
							<div>
								<label class="text-sm font-medium">Difficulty (12-24)</label>
								<Input
									type="number"
									min="12"
									max="24"
									bind:value={site.challenge_difficulty}
									class="mt-1"
								/>
								<p class="text-xs text-muted-foreground mt-1">Higher = harder to solve, more protection</p>
							</div>
							<div>
								<label class="text-sm font-medium">Duration (hours)</label>
								<Input
									type="number"
									min="1"
									max="168"
									step="0.5"
									bind:value={site.challenge_duration}
									class="mt-1"
								/>
								<p class="text-xs text-muted-foreground mt-1">How long solved challenges remain valid</p>
							</div>
						</div>

						<div class="flex items-center justify-between">
							<div>
								<p class="font-medium">Bypass for Cloudflare Traffic</p>
								<p class="text-sm text-muted-foreground">Skip challenge for requests from Cloudflare IPs</p>
							</div>
							<Switch bind:checked={site.challenge_bypass_cf} />
						</div>

						<Alert>
							<AlertTriangle class="h-4 w-4" />
							<div>
								Challenge mode blocks bots and DDoS attacks by requiring clients to solve a computational puzzle.
								This may slightly slow initial page loads for legitimate users.
							</div>
						</Alert>
					{/if}
				</Card>
			</TabsContent>

			<!-- Rate Limiting -->
			<TabsContent value="ratelimit">
				<Card class="p-6 space-y-6">
					<div class="flex items-center justify-between">
						<div>
							<p class="font-medium">Enable Rate Limiting</p>
							<p class="text-sm text-muted-foreground">Limit requests per IP address</p>
						</div>
						<Switch bind:checked={site.rate_limit_enabled} />
					</div>

					{#if site.rate_limit_enabled}
						<div class="grid grid-cols-2 gap-4">
							<Input
								label="Requests"
								type="number"
								min="1"
								bind:value={site.rate_limit_requests}
							/>
							<Input
								label="Window (seconds)"
								type="number"
								min="1"
								bind:value={site.rate_limit_window}
							/>
						</div>
						<p class="text-sm text-muted-foreground">
							Allow {site.rate_limit_requests} requests per {site.rate_limit_window} seconds per IP
						</p>
					{/if}
				</Card>
			</TabsContent>

			<!-- SSL/TLS -->
			<TabsContent value="ssl">
				<Card class="p-6 space-y-6">
					<Select
						label="SSL Mode"
						options={sslModeOptions}
						bind:value={site.ssl_mode}
					/>

					{#if site.ssl_mode === 'custom' && $certsQuery.data?.length}
						<Select
							label="Certificate"
							options={$certsQuery.data.map((c) => ({ value: c.id, label: c.domain }))}
							bind:value={site.certificate_id}
						/>
					{/if}

					{#if site.ssl_mode === 'auto'}
						<Alert title="Automatic SSL">
							SSL certificates will be automatically obtained from Let's Encrypt.
						</Alert>
					{/if}
				</Card>
			</TabsContent>

			<!-- Custom Error Pages -->
			<TabsContent value="errors">
				<Card class="p-6 space-y-4">
					<div class="flex items-center justify-between mb-4">
						<div>
							<h3 class="text-lg font-semibold">Custom Error Pages</h3>
							<p class="text-sm text-muted-foreground">Override default error pages</p>
						</div>
						{#if !isNew}
							<Button on:click={() => (showAddErrorPage = true)}>
								<Plus class="h-4 w-4 mr-2" />
								Add Error Page
							</Button>
						{/if}
					</div>

					{#if isNew}
						<Alert variant="warning" title="Save First">
							Please save the site first to add error pages.
						</Alert>
					{:else if $errorPagesQuery.isLoading}
						<div class="flex justify-center py-8">
							<Spinner />
						</div>
					{:else if $errorPagesQuery.data?.length}
						<Table>
							<TableHeader>
								<TableRow>
									<TableHead>Code</TableHead>
									<TableHead>Preview</TableHead>
									<TableHead></TableHead>
								</TableRow>
							</TableHeader>
							<TableBody>
								{#each $errorPagesQuery.data as errorPage}
									<TableRow>
										<TableCell>
											<Badge variant="outline">{errorPage.code}</Badge>
										</TableCell>
										<TableCell class="truncate max-w-xs">
											{errorPage.content.substring(0, 100)}...
										</TableCell>
										<TableCell>
											<Button
												variant="ghost"
												size="icon"
												on:click={() => $deleteErrorPageMutation.mutate(errorPage.id)}
											>
												<Trash2 class="h-4 w-4 text-destructive" />
											</Button>
										</TableCell>
									</TableRow>
								{/each}
							</TableBody>
						</Table>
					{:else}
						<p class="text-center text-muted-foreground py-8">No custom error pages</p>
					{/if}
				</Card>
			</TabsContent>

			<!-- .well-known Files -->
			<TabsContent value="wellknown">
				<Card class="p-6 space-y-4">
					<div class="flex items-center justify-between mb-4">
						<div>
							<h3 class="text-lg font-semibold">.well-known Files</h3>
							<p class="text-sm text-muted-foreground">Serve files at /.well-known/ path</p>
						</div>
						{#if !isNew}
							<Button on:click={() => (showAddWellknown = true)}>
								<Plus class="h-4 w-4 mr-2" />
								Add File
							</Button>
						{/if}
					</div>

					{#if isNew}
						<Alert variant="warning" title="Save First">
							Please save the site first to add .well-known files.
						</Alert>
					{:else if $wellknownQuery.isLoading}
						<div class="flex justify-center py-8">
							<Spinner />
						</div>
					{:else if $wellknownQuery.data?.length}
						<Table>
							<TableHeader>
								<TableRow>
									<TableHead>Path</TableHead>
									<TableHead>Preview</TableHead>
									<TableHead></TableHead>
								</TableRow>
							</TableHeader>
							<TableBody>
								{#each $wellknownQuery.data as file}
									<TableRow>
										<TableCell class="font-mono">/.well-known/{file.path}</TableCell>
										<TableCell class="truncate max-w-xs">
											{file.content.substring(0, 100)}...
										</TableCell>
										<TableCell>
											<Button
												variant="ghost"
												size="icon"
												on:click={() => $deleteWellknownMutation.mutate(file.id)}
											>
												<Trash2 class="h-4 w-4 text-destructive" />
											</Button>
										</TableCell>
									</TableRow>
								{/each}
							</TableBody>
						</Table>
					{:else}
						<p class="text-center text-muted-foreground py-8">No .well-known files</p>
					{/if}
				</Card>
			</TabsContent>

			<!-- Advanced -->
			<TabsContent value="advanced">
				<div class="space-y-6">
					<!-- Cloudflare & Access -->
					<Card class="p-6 space-y-6">
						<h3 class="text-lg font-semibold">Access Control</h3>
						
						<div class="flex items-center justify-between">
							<div>
								<p class="font-medium">Use Cloudflare IP Headers</p>
								<p class="text-sm text-muted-foreground">Trust CF-Connecting-IP header for real client IP</p>
							</div>
							<Switch bind:checked={site.use_cf_ip_headers} />
						</div>

						<div class="flex items-center justify-between">
							<div>
								<p class="font-medium">Local Only</p>
								<p class="text-sm text-muted-foreground">Only allow access from local network</p>
							</div>
							<Switch bind:checked={site.local_only} />
						</div>

						<div class="flex items-center justify-between">
							<div>
								<p class="font-medium">Wildcard Subdomains</p>
								<p class="text-sm text-muted-foreground">Enable *.domain.com matching</p>
							</div>
							<Switch bind:checked={site.wildcard_subdomains} />
						</div>

						<div>
							<label class="text-sm font-medium">IP Whitelist</label>
							<Input
								placeholder="192.168.1.0/24, 10.0.0.1"
								bind:value={site.ip_whitelist}
								class="mt-1"
							/>
							<p class="text-xs text-muted-foreground mt-1">Comma-separated IPs or CIDR ranges to always allow</p>
						</div>
					</Card>

					<!-- Basic Auth -->
					<Card class="p-6 space-y-6">
						<h3 class="text-lg font-semibold">Basic Authentication</h3>
						
						<div class="flex items-center justify-between">
							<div>
								<p class="font-medium">Enable Basic Auth</p>
								<p class="text-sm text-muted-foreground">Require username/password to access site</p>
							</div>
							<Switch bind:checked={site.enable_basic_auth} />
						</div>

						{#if site.enable_basic_auth}
							<div class="grid grid-cols-2 gap-4">
								<Input label="Username" bind:value={site.basic_auth_username} />
								<Input label="Password" type="password" bind:value={site.basic_auth_password} />
							</div>
						{/if}
					</Card>

					<!-- Compression -->
					<Card class="p-6 space-y-6">
						<h3 class="text-lg font-semibold">Compression</h3>
						
						<div class="flex items-center justify-between">
							<div>
								<p class="font-medium">Enable Gzip</p>
								<p class="text-sm text-muted-foreground">Compress responses with gzip</p>
							</div>
							<Switch bind:checked={site.enable_gzip} />
						</div>

						<div class="flex items-center justify-between">
							<div>
								<p class="font-medium">Enable Brotli</p>
								<p class="text-sm text-muted-foreground">Compress responses with Brotli (better than gzip)</p>
							</div>
							<Switch bind:checked={site.enable_brotli} />
						</div>

						{#if site.enable_gzip || site.enable_brotli}
							<div>
								<label class="text-sm font-medium">Compression Level (1-9)</label>
								<Input
									type="number"
									min="1"
									max="9"
									bind:value={site.compression_level}
									class="mt-1"
								/>
								<p class="text-xs text-muted-foreground mt-1">Higher = better compression but more CPU</p>
							</div>
						{/if}
					</Card>

					<!-- WebSocket -->
					<Card class="p-6 space-y-6">
						<h3 class="text-lg font-semibold">WebSocket</h3>
						
						<div class="flex items-center justify-between">
							<div>
								<p class="font-medium">Enable WebSocket Proxying</p>
								<p class="text-sm text-muted-foreground">Proxy WebSocket connections to backend</p>
							</div>
							<Switch bind:checked={site.websocket_enabled} />
						</div>

						{#if site.websocket_enabled}
							<Input
								label="WebSocket Path"
								placeholder="/ws"
								bind:value={site.websocket_path}
							/>
						{/if}
					</Card>

					<!-- Load Balancing -->
					<Card class="p-6 space-y-6">
						<h3 class="text-lg font-semibold">Load Balancing</h3>
						
						<div>
							<label class="text-sm font-medium">Method</label>
							<select
								class="w-full h-10 rounded-lg border border-input bg-background px-3 mt-1"
								bind:value={site.lb_method}
							>
								<option value="round_robin">Round Robin</option>
								<option value="least_conn">Least Connections</option>
								<option value="ip_hash">IP Hash (sticky sessions)</option>
								<option value="hash">Custom Hash</option>
							</select>
						</div>
					</Card>

					<!-- NGINX Settings -->
					<Card class="p-6 space-y-6">
						<h3 class="text-lg font-semibold">NGINX Settings</h3>
						
						<div class="grid grid-cols-2 gap-4">
							<div>
								<label class="text-sm font-medium">Max Body Size</label>
								<Input
									placeholder="100M"
									bind:value={site.client_max_body_size}
									class="mt-1"
								/>
							</div>
							<div>
								<label class="text-sm font-medium">Read Timeout (sec)</label>
								<Input
									type="number"
									min="1"
									bind:value={site.proxy_read_timeout}
									class="mt-1"
								/>
							</div>
							<div>
								<label class="text-sm font-medium">Connect Timeout (sec)</label>
								<Input
									type="number"
									min="1"
									bind:value={site.proxy_connect_timeout}
									class="mt-1"
								/>
							</div>
						</div>

						<div class="flex items-center justify-between">
							<div>
								<p class="font-medium">Enable HSTS</p>
								<p class="text-sm text-muted-foreground">HTTP Strict Transport Security</p>
							</div>
							<Switch bind:checked={site.enable_hsts} />
						</div>

						{#if site.enable_hsts}
							<div>
								<label class="text-sm font-medium">HSTS Max-Age (seconds)</label>
								<Input
									type="number"
									min="0"
									bind:value={site.hsts_max_age}
									class="mt-1"
								/>
							</div>
						{/if}

						<div>
							<label class="text-sm font-medium">Custom NGINX Directives</label>
							<Textarea
								placeholder="add_header X-Custom-Header 'value';"
								rows={4}
								bind:value={site.custom_nginx_directives}
								class="mt-1 font-mono text-sm"
							/>
							<p class="text-xs text-muted-foreground mt-1">Raw NGINX config to include in server block</p>
						</div>
					</Card>
				</div>
			</TabsContent>
		</Tabs>
	{/if}
</div>

<!-- Delete Confirmation Modal -->
<Modal
	bind:open={showDeleteModal}
	title="Delete Site"
	description="Are you sure you want to delete this site? This action cannot be undone."
>
	<p class="text-muted-foreground">
		This will permanently delete <strong>{site.domain}</strong> and all associated configurations.
	</p>
	<div slot="footer">
		<Button variant="outline" on:click={() => (showDeleteModal = false)}>Cancel</Button>
		<Button
			variant="destructive"
			on:click={() => $deleteSiteMutation.mutate()}
			disabled={$deleteSiteMutation.isPending}
		>
			{#if $deleteSiteMutation.isPending}
				<Spinner size="sm" class="mr-2" />
			{/if}
			Delete
		</Button>
	</div>
</Modal>

<!-- Add Backend Modal -->
<Modal bind:open={showAddBackend} title="Add Backend" size="md">
	<div class="space-y-4">
		<Input label="Address" placeholder="127.0.0.1 or backend.local" bind:value={newBackend.address} />
		<div class="grid grid-cols-2 gap-4">
			<Input label="Port" type="number" min="1" max="65535" bind:value={newBackend.port} />
			<Input label="Weight" type="number" min="1" bind:value={newBackend.weight} />
		</div>
		<Select label="Protocol" options={protocolOptions} bind:value={newBackend.protocol} />
		<div class="flex items-center justify-between">
			<span class="text-sm font-medium">Health Check</span>
			<Switch bind:checked={newBackend.health_check} />
		</div>
	</div>
	<div slot="footer">
		<Button variant="outline" on:click={() => (showAddBackend = false)}>Cancel</Button>
		<Button
			on:click={() => $addBackendMutation.mutate(newBackend)}
			disabled={$addBackendMutation.isPending}
		>
			{#if $addBackendMutation.isPending}
				<Spinner size="sm" class="mr-2" />
			{/if}
			Add
		</Button>
	</div>
</Modal>

<!-- Add Path Route Modal -->
<Modal bind:open={showAddPathRoute} title="Add Path Route" size="md">
	<div class="space-y-4">
		<Input label="Path" placeholder="/api" bind:value={newPathRoute.path} />
		<Input label="Backend Address" placeholder="backend.local:8080" bind:value={newPathRoute.backend_address} />
		<Input label="Rewrite Path (optional)" placeholder="/v1" bind:value={newPathRoute.rewrite_path} />
		<div class="flex items-center justify-between">
			<span class="text-sm font-medium">Strip Path</span>
			<Switch bind:checked={newPathRoute.strip_path} />
		</div>
	</div>
	<div slot="footer">
		<Button variant="outline" on:click={() => (showAddPathRoute = false)}>Cancel</Button>
		<Button
			on:click={() => $addPathRouteMutation.mutate(newPathRoute)}
			disabled={$addPathRouteMutation.isPending}
		>
			{#if $addPathRouteMutation.isPending}
				<Spinner size="sm" class="mr-2" />
			{/if}
			Add
		</Button>
	</div>
</Modal>

<!-- Add Wellknown Modal -->
<Modal bind:open={showAddWellknown} title="Add .well-known File" size="lg">
	<div class="space-y-4">
		<Input label="Path" placeholder="security.txt" bind:value={newWellknown.path} />
		<Textarea label="Content" rows={10} bind:value={newWellknown.content} />
	</div>
	<div slot="footer">
		<Button variant="outline" on:click={() => (showAddWellknown = false)}>Cancel</Button>
		<Button
			on:click={() => $addWellknownMutation.mutate(newWellknown)}
			disabled={$addWellknownMutation.isPending}
		>
			{#if $addWellknownMutation.isPending}
				<Spinner size="sm" class="mr-2" />
			{/if}
			Add
		</Button>
	</div>
</Modal>

<!-- Add Error Page Modal -->
<Modal bind:open={showAddErrorPage} title="Add Error Page" size="lg">
	<div class="space-y-4">
		<Select
			label="Error Code"
			options={[
				{ value: '400', label: '400 - Bad Request' },
				{ value: '401', label: '401 - Unauthorized' },
				{ value: '403', label: '403 - Forbidden' },
				{ value: '404', label: '404 - Not Found' },
				{ value: '500', label: '500 - Internal Server Error' },
				{ value: '502', label: '502 - Bad Gateway' },
				{ value: '503', label: '503 - Service Unavailable' },
				{ value: '504', label: '504 - Gateway Timeout' }
			]}
			bind:value={newErrorPage.code}
		/>
		<Textarea label="HTML Content" rows={15} bind:value={newErrorPage.content} />
	</div>
	<div slot="footer">
		<Button variant="outline" on:click={() => (showAddErrorPage = false)}>Cancel</Button>
		<Button
			on:click={() => $addErrorPageMutation.mutate(newErrorPage)}
			disabled={$addErrorPageMutation.isPending}
		>
			{#if $addErrorPageMutation.isPending}
				<Spinner size="sm" class="mr-2" />
			{/if}
			Add
		</Button>
	</div>
</Modal>
