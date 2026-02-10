<script lang="ts">
	import { createQuery, createMutation, useQueryClient } from '@tanstack/svelte-query';
	import { settingsApi, configApi } from '$lib/api';
	import {
		Button,
		Input,
		Card,
		Badge,
		Modal,
		Alert,
		Spinner,
		Switch,
		Tabs,
		TabsList,
		TabsTrigger,
		TabsContent,
		Textarea
	} from '$lib/components/ui';
	import {
		Settings,
		Save,
		RefreshCw,
		Download,
		Upload,
		Trash2,
		Server,
		Shield,
		Bell,
		Database,
		Key,
		Globe,
		Bot,
		Lock,
		Mail,
		AlertTriangle
	} from 'lucide-svelte';

	const queryClient = useQueryClient();

	let activeTab = 'general';
	let showBackupModal = false;
	let showRestoreModal = false;
	let restoreFile: File | null = null;
	let testingWebhook = false;
	let testingEmail = false;

	// Fetch settings
	$: settingsQuery = createQuery({
		queryKey: ['settings'],
		queryFn: () => settingsApi.getAll()
	});

	// System info
	$: systemInfoQuery = createQuery({
		queryKey: ['system-info'],
		queryFn: () => settingsApi.getSystemInfo()
	});

	// Settings state with all V1 features
	let settings = {
		// General
		instance_name: '',
		admin_email: '',
		
		// Security - WAF
		paranoia_level: 2,
		waf_default_mode: 'on',
		dev_mode_headers: false,
		
		// Security - Auto Ban (Fail2Ban)
		auto_ban_enabled: true,
		auto_ban_threshold: 10,
		auto_ban_duration: 60,
		
		// Security - Rate Limiting
		rate_limit_enabled: true,
		rate_limit_default: 100,
		rate_limit_zone: '100r/s',
		
		// Bot Protection
		bot_protection_enabled: true,
		bot_block_empty_ua: true,
		bot_rate_limit_good: 60,
		bot_rate_limit_bad: 10,
		bot_challenge_mode: 'captcha',
		bot_log_all_requests: false,
		
		// GeoIP
		maxmind_license_key: '',
		geoip_enabled: false,
		
		// Notifications - Webhook
		webhook_enabled: false,
		webhook_url: '',
		discord_webhook_url: '',
		
		// Notifications - Events
		notifications_critical: true,
		notifications_autoban: true,
		notifications_cert_expiry: true,
		notifications_server_down: true,
		notifications_high_delay: true,
		
		// Notifications - Email
		email_enabled: false,
		smtp_host: '',
		smtp_port: '587',
		smtp_username: '',
		smtp_password: '',
		smtp_from_email: '',
		smtp_from_name: 'CatWAF',
		
		// Advanced - Nginx
		nginx_worker_processes: 'auto',
		nginx_worker_connections: '1024',
		
		// Advanced - Data Retention
		log_retention_days: 30,
		task_log_retention_days: 30,
		telemetry_retention_days: 14,
		
		// Advanced - System
		telemetry_enabled: false,
		task_scheduler_enabled: true,
		
		// Backup
		backup_auto_enabled: false,
		backup_retention_days: 30,
		backup_local_only: false
	};

	// Update settings from query
	$: if ($settingsQuery.data?.settings) {
		settings = { ...settings, ...$settingsQuery.data.settings };
	}

	// Mutations
	const updateSettingsMutation = createMutation({
		mutationFn: (data: Record<string, any>) => settingsApi.updateAll(data),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['settings'] });
		}
	});

	const regenerateConfigsMutation = createMutation({
		mutationFn: () => configApi.regenerate(),
		onSuccess: () => {
			// Show success
		}
	});

	const cleanupMutation = createMutation({
		mutationFn: () => settingsApi.cleanup(),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['settings'] });
		}
	});

	async function handleBackup() {
		const data = await settingsApi.backup();
		const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
		const url = URL.createObjectURL(blob);
		const a = document.createElement('a');
		a.href = url;
		a.download = `catwaf-backup-${new Date().toISOString().split('T')[0]}.json`;
		a.click();
		URL.revokeObjectURL(url);
		showBackupModal = false;
	}

	async function handleRestore() {
		if (!restoreFile) return;
		const text = await restoreFile.text();
		const data = JSON.parse(text);
		await settingsApi.restore(data);
		queryClient.invalidateQueries({ queryKey: ['settings'] });
		showRestoreModal = false;
		restoreFile = null;
	}

	function handleSave() {
		$updateSettingsMutation.mutate(settings);
	}

	async function testWebhook() {
		testingWebhook = true;
		try {
			await settingsApi.testNotification('webhook');
		} finally {
			testingWebhook = false;
		}
	}

	async function testEmail() {
		testingEmail = true;
		try {
			await settingsApi.testNotification('email');
		} finally {
			testingEmail = false;
		}
	}
</script>

<svelte:head>
	<title>Settings - CatWAF</title>
</svelte:head>

<div class="space-y-6">
	<!-- Header -->
	<div class="flex items-center justify-between">
		<div>
			<h1 class="text-2xl font-bold flex items-center gap-2">
				<Settings class="h-6 w-6" />
				Settings
			</h1>
			<p class="text-muted-foreground">Configure CatWAF system settings</p>
		</div>
		<div class="flex items-center gap-2">
			<Button variant="outline" on:click={() => (showBackupModal = true)}>
				<Download class="h-4 w-4 mr-2" />
				Backup
			</Button>
			<Button variant="outline" on:click={() => (showRestoreModal = true)}>
				<Upload class="h-4 w-4 mr-2" />
				Restore
			</Button>
			<Button
				on:click={handleSave}
				disabled={$updateSettingsMutation.isPending}
			>
				{#if $updateSettingsMutation.isPending}
					<Spinner size="sm" class="mr-2" />
				{:else}
					<Save class="h-4 w-4 mr-2" />
				{/if}
				Save
			</Button>
		</div>
	</div>

	{#if $updateSettingsMutation.isSuccess}
		<Alert variant="success" title="Success">
			Settings saved successfully.
		</Alert>
	{/if}

	{#if $updateSettingsMutation.isError}
		<Alert variant="destructive" title="Error">
			Failed to save settings.
		</Alert>
	{/if}

	<!-- System Info -->
	{#if $systemInfoQuery.data?.info}
		<div class="grid grid-cols-4 gap-4">
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-primary/10 rounded-lg">
						<Server class="h-5 w-5 text-primary" />
					</div>
					<div>
						<p class="text-sm text-muted-foreground">Version</p>
						<p class="font-semibold">{$systemInfoQuery.data.info.version || 'v2.0.0'}</p>
					</div>
				</div>
			</Card>
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-green-500/10 rounded-lg">
						<Globe class="h-5 w-5 text-green-500" />
					</div>
					<div>
						<p class="text-sm text-muted-foreground">Sites</p>
						<p class="font-semibold">{$systemInfoQuery.data.info.total_sites || 0}</p>
					</div>
				</div>
			</Card>
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-blue-500/10 rounded-lg">
						<Database class="h-5 w-5 text-blue-500" />
					</div>
					<div>
						<p class="text-sm text-muted-foreground">Database</p>
						<p class="font-semibold">{$systemInfoQuery.data.info.db_size || '0 MB'}</p>
					</div>
				</div>
			</Card>
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-yellow-500/10 rounded-lg">
						<Shield class="h-5 w-5 text-yellow-500" />
					</div>
					<div>
						<p class="text-sm text-muted-foreground">Uptime</p>
						<p class="font-semibold">{$systemInfoQuery.data.info.uptime || '0d'}</p>
					</div>
				</div>
			</Card>
		</div>
	{/if}

	{#if $settingsQuery.isLoading}
		<div class="flex justify-center py-12">
			<Spinner size="lg" />
		</div>
	{:else}
		<Tabs bind:value={activeTab}>
			<TabsList class="flex flex-wrap justify-start gap-1 h-auto p-1 w-full">
				<TabsTrigger value="general" class="flex-shrink-0">
					<Settings class="h-4 w-4 mr-2" />
					General
				</TabsTrigger>
				<TabsTrigger value="security" class="flex-shrink-0">
					<Shield class="h-4 w-4 mr-2" />
					Security
				</TabsTrigger>
				<TabsTrigger value="bot" class="flex-shrink-0">
					<Bot class="h-4 w-4 mr-2" />
					Bot Protection
				</TabsTrigger>
				<TabsTrigger value="geoip" class="flex-shrink-0">
					<Globe class="h-4 w-4 mr-2" />
					GeoIP
				</TabsTrigger>
				<TabsTrigger value="notifications" class="flex-shrink-0">
					<Bell class="h-4 w-4 mr-2" />
					Notifications
				</TabsTrigger>
				<TabsTrigger value="advanced" class="flex-shrink-0">
					<Server class="h-4 w-4 mr-2" />
					Advanced
				</TabsTrigger>
				<TabsTrigger value="maintenance" class="flex-shrink-0">
					<Database class="h-4 w-4 mr-2" />
					Maintenance
				</TabsTrigger>
			</TabsList>

			<!-- General Settings -->
			<TabsContent value="general">
				<Card class="p-6 space-y-6">
					<div>
						<h3 class="text-lg font-semibold mb-4">Instance Settings</h3>
						<div class="space-y-4">
							<Input
								label="Instance Name"
								placeholder="My CatWAF Instance"
								bind:value={settings.instance_name}
							/>
							<Input
								label="Admin Email"
								type="email"
								placeholder="admin@example.com"
								bind:value={settings.admin_email}
							/>
						</div>
					</div>
				</Card>
			</TabsContent>

			<!-- Security Settings -->
			<TabsContent value="security">
				<div class="space-y-6">
					<!-- WAF Settings -->
					<Card class="p-6 space-y-6">
						<h3 class="text-lg font-semibold">WAF Settings</h3>
						
						<div class="space-y-4">
							<div>
								<label class="text-sm font-medium">Paranoia Level</label>
								<p class="text-sm text-muted-foreground mb-2">Higher levels detect more attacks but may cause more false positives</p>
								<select
									class="w-full h-10 rounded-lg border border-input bg-background px-3"
									bind:value={settings.paranoia_level}
								>
									<option value={1}>Level 1 - Basic (minimal false positives)</option>
									<option value={2}>Level 2 - Standard (recommended)</option>
									<option value={3}>Level 3 - Strict (more detection)</option>
									<option value={4}>Level 4 - Maximum (paranoid mode)</option>
								</select>
							</div>

							<div>
								<label class="text-sm font-medium">Default WAF Mode</label>
								<select
									class="w-full h-10 rounded-lg border border-input bg-background px-3 mt-2"
									bind:value={settings.waf_default_mode}
								>
									<option value="on">Enabled (Block)</option>
									<option value="detection">Detection Only</option>
									<option value="off">Disabled</option>
								</select>
							</div>

							<div class="flex items-center justify-between">
								<div>
									<p class="font-medium">Development Mode Headers</p>
									<p class="text-sm text-muted-foreground">Show detailed WAF headers for debugging</p>
								</div>
								<Switch bind:checked={settings.dev_mode_headers} />
							</div>
						</div>
					</Card>

					<!-- Auto-Ban (Fail2Ban) Settings -->
					<Card class="p-6 space-y-6">
						<h3 class="text-lg font-semibold">Auto-Ban (Fail2Ban)</h3>
						
						<div class="flex items-center justify-between">
							<div>
								<p class="font-medium">Enable Auto-Ban</p>
								<p class="text-sm text-muted-foreground">Automatically ban IPs that trigger too many security alerts</p>
							</div>
							<Switch bind:checked={settings.auto_ban_enabled} />
						</div>

						{#if settings.auto_ban_enabled}
							<div class="grid grid-cols-2 gap-4">
								<Input
									label="Ban Threshold (violations)"
									type="number"
									min="1"
									max="100"
									bind:value={settings.auto_ban_threshold}
								/>
								<Input
									label="Ban Duration (minutes)"
									type="number"
									min="1"
									max="10080"
									bind:value={settings.auto_ban_duration}
								/>
							</div>
						{/if}
					</Card>

					<!-- Rate Limiting -->
					<Card class="p-6 space-y-6">
						<h3 class="text-lg font-semibold">Rate Limiting</h3>
						
						<div class="flex items-center justify-between">
							<div>
								<p class="font-medium">Enable Rate Limiting</p>
								<p class="text-sm text-muted-foreground">Limit requests per IP to prevent abuse</p>
							</div>
							<Switch bind:checked={settings.rate_limit_enabled} />
						</div>

						{#if settings.rate_limit_enabled}
							<Input
								label="Default Rate Limit (requests/minute)"
								type="number"
								min="1"
								bind:value={settings.rate_limit_default}
							/>
						{/if}
					</Card>
				</div>
			</TabsContent>

			<!-- Bot Protection Settings -->
			<TabsContent value="bot">
				<Card class="p-6 space-y-6">
					<h3 class="text-lg font-semibold">Bot Protection</h3>
					<p class="text-sm text-muted-foreground">Configure how CatWAF handles bot traffic</p>
					
					<div class="flex items-center justify-between">
						<div>
							<p class="font-medium">Enable Bot Protection</p>
							<p class="text-sm text-muted-foreground">Detect and manage bot traffic</p>
						</div>
						<Switch bind:checked={settings.bot_protection_enabled} />
					</div>

					{#if settings.bot_protection_enabled}
						<div class="flex items-center justify-between">
							<div>
								<p class="font-medium">Block Empty User-Agents</p>
								<p class="text-sm text-muted-foreground">Block requests without a User-Agent header</p>
							</div>
							<Switch bind:checked={settings.bot_block_empty_ua} />
						</div>

						<div class="grid grid-cols-2 gap-4">
							<Input
								label="Good Bot Rate Limit (req/min)"
								type="number"
								min="1"
								bind:value={settings.bot_rate_limit_good}
							/>
							<Input
								label="Bad Bot Rate Limit (req/min)"
								type="number"
								min="1"
								bind:value={settings.bot_rate_limit_bad}
							/>
						</div>

						<div>
							<label class="text-sm font-medium">Challenge Mode</label>
							<select
								class="w-full h-10 rounded-lg border border-input bg-background px-3 mt-2"
								bind:value={settings.bot_challenge_mode}
							>
								<option value="none">No Challenge</option>
								<option value="js">JavaScript Challenge</option>
								<option value="captcha">CAPTCHA</option>
								<option value="block">Block All Bots</option>
							</select>
						</div>

						<div class="flex items-center justify-between">
							<div>
								<p class="font-medium">Log All Bot Requests</p>
								<p class="text-sm text-muted-foreground">Enable verbose logging for bot detection</p>
							</div>
							<Switch bind:checked={settings.bot_log_all_requests} />
						</div>
					{/if}
				</Card>
			</TabsContent>

			<!-- GeoIP Settings -->
			<TabsContent value="geoip">
				<Card class="p-6 space-y-6">
					<h3 class="text-lg font-semibold">GeoIP Database</h3>
					<p class="text-sm text-muted-foreground">Configure MaxMind GeoIP database for geographic blocking</p>

					<div class="flex items-center justify-between">
						<div>
							<p class="font-medium">Enable GeoIP</p>
							<p class="text-sm text-muted-foreground">Enable geographic IP blocking capabilities</p>
						</div>
						<Switch bind:checked={settings.geoip_enabled} />
					</div>

					{#if settings.geoip_enabled}
						<div>
							<Input
								label="MaxMind License Key"
								type="password"
								placeholder="Enter your MaxMind license key"
								bind:value={settings.maxmind_license_key}
							/>
							<p class="text-sm text-muted-foreground mt-2">
								Get a free license key from <a href="https://www.maxmind.com/en/geolite2/signup" target="_blank" class="text-primary underline">MaxMind</a>
							</p>
						</div>

						<Alert>
							<Globe class="h-4 w-4" />
							<div>
								After entering your license key, the GeoIP database will be downloaded automatically.
								Per-site blocking can be configured in each site's security settings.
							</div>
						</Alert>
					{/if}
				</Card>
			</TabsContent>

			<!-- Notification Settings -->
			<TabsContent value="notifications">
				<div class="space-y-6">
					<!-- Webhook Notifications -->
					<Card class="p-6 space-y-6">
						<h3 class="text-lg font-semibold">Webhook Notifications</h3>
						
						<div class="flex items-center justify-between">
							<div>
								<p class="font-medium">Enable Webhook</p>
								<p class="text-sm text-muted-foreground">Send alerts to webhook endpoints</p>
							</div>
							<Switch bind:checked={settings.webhook_enabled} />
						</div>

						{#if settings.webhook_enabled}
							<Input
								label="Generic Webhook URL"
								placeholder="https://hooks.slack.com/..."
								bind:value={settings.webhook_url}
							/>
							<Input
								label="Discord Webhook URL"
								placeholder="https://discord.com/api/webhooks/..."
								bind:value={settings.discord_webhook_url}
							/>
							<div>
								<Button
									variant="outline"
									size="sm"
									disabled={testingWebhook || (!settings.webhook_url && !settings.discord_webhook_url)}
									on:click={testWebhook}
								>
									{#if testingWebhook}
										<Spinner size="sm" class="mr-2" />
									{:else}
										<Bell class="mr-2 h-4 w-4" />
									{/if}
									Test Webhook
								</Button>
							</div>
						{/if}
					</Card>

					<!-- Email Notifications -->
					<Card class="p-6 space-y-6">
						<h3 class="text-lg font-semibold">Email Notifications (SMTP)</h3>
						
						<div class="flex items-center justify-between">
							<div>
								<p class="font-medium">Enable Email</p>
								<p class="text-sm text-muted-foreground">Receive email alerts for security events</p>
							</div>
							<Switch bind:checked={settings.email_enabled} />
						</div>

						{#if settings.email_enabled}
							<div class="grid grid-cols-2 gap-4">
								<Input label="SMTP Host" placeholder="smtp.gmail.com" bind:value={settings.smtp_host} />
								<Input label="SMTP Port" placeholder="587" bind:value={settings.smtp_port} />
								<Input label="Username" bind:value={settings.smtp_username} />
								<Input label="Password" type="password" bind:value={settings.smtp_password} />
								<Input label="From Email" placeholder="alerts@example.com" bind:value={settings.smtp_from_email} />
								<Input label="From Name" placeholder="CatWAF" bind:value={settings.smtp_from_name} />
							</div>
							<div>
								<Button
									variant="outline"
									size="sm"
									disabled={testingEmail || !settings.smtp_host || !settings.smtp_username}
									on:click={testEmail}
								>
									{#if testingEmail}
										<Spinner size="sm" class="mr-2" />
									{:else}
										<Mail class="mr-2 h-4 w-4" />
									{/if}
									Test Email
								</Button>
							</div>
						{/if}
					</Card>

					<!-- Notification Events -->
					<Card class="p-6 space-y-6">
						<h3 class="text-lg font-semibold">Notification Events</h3>
						<p class="text-sm text-muted-foreground">Choose which events trigger notifications</p>

						<div class="space-y-4">
							<div class="flex items-center justify-between">
								<div>
									<p class="font-medium">Critical Security Events</p>
									<p class="text-sm text-muted-foreground">Severe security incidents (SQL injection, XSS, etc.)</p>
								</div>
								<Switch bind:checked={settings.notifications_critical} />
							</div>

							<div class="flex items-center justify-between">
								<div>
									<p class="font-medium">Auto-Ban Events</p>
									<p class="text-sm text-muted-foreground">When an IP is automatically banned</p>
								</div>
								<Switch bind:checked={settings.notifications_autoban} />
							</div>

							<div class="flex items-center justify-between">
								<div>
									<p class="font-medium">Certificate Expiry</p>
									<p class="text-sm text-muted-foreground">SSL certificates nearing expiration</p>
								</div>
								<Switch bind:checked={settings.notifications_cert_expiry} />
							</div>

							<div class="flex items-center justify-between">
								<div>
									<p class="font-medium">Server Down</p>
									<p class="text-sm text-muted-foreground">Backend server health check failures</p>
								</div>
								<Switch bind:checked={settings.notifications_server_down} />
							</div>

							<div class="flex items-center justify-between">
								<div>
									<p class="font-medium">High Latency</p>
									<p class="text-sm text-muted-foreground">Backend response time exceeds threshold</p>
								</div>
								<Switch bind:checked={settings.notifications_high_delay} />
							</div>
						</div>
					</Card>
				</div>
			</TabsContent>

			<!-- Advanced Settings -->
			<TabsContent value="advanced">
				<div class="space-y-6">
					<Card class="p-6 space-y-6">
						<h3 class="text-lg font-semibold">Nginx Configuration</h3>
						<div class="grid grid-cols-2 gap-4">
							<Input
								label="Worker Processes"
								placeholder="auto"
								bind:value={settings.nginx_worker_processes}
							/>
							<Input
								label="Worker Connections"
								placeholder="1024"
								bind:value={settings.nginx_worker_connections}
							/>
						</div>
					</Card>

					<Card class="p-6 space-y-6">
						<h3 class="text-lg font-semibold">Data Retention</h3>
						<div class="grid grid-cols-3 gap-4">
							<Input
								label="Access Logs (days)"
								type="number"
								min="1"
								max="365"
								bind:value={settings.log_retention_days}
							/>
							<Input
								label="Task Logs (days)"
								type="number"
								min="1"
								max="365"
								bind:value={settings.task_log_retention_days}
							/>
							<Input
								label="Telemetry (days)"
								type="number"
								min="1"
								max="365"
								bind:value={settings.telemetry_retention_days}
							/>
						</div>
					</Card>

					<Card class="p-6 space-y-6">
						<h3 class="text-lg font-semibold">System</h3>

						<div class="flex items-center justify-between">
							<div>
								<p class="font-medium">Task Scheduler</p>
								<p class="text-sm text-muted-foreground">Enable background task processing</p>
							</div>
							<Switch bind:checked={settings.task_scheduler_enabled} />
						</div>

						<div class="flex items-center justify-between">
							<div>
								<p class="font-medium">Telemetry</p>
								<p class="text-sm text-muted-foreground">Send anonymous usage statistics</p>
							</div>
							<Switch bind:checked={settings.telemetry_enabled} />
						</div>
					</Card>

					<Card class="p-6 space-y-6">
						<h3 class="text-lg font-semibold">Backup Settings</h3>

						<div class="flex items-center justify-between">
							<div>
								<p class="font-medium">Auto Backup</p>
								<p class="text-sm text-muted-foreground">Automatically create daily backups</p>
							</div>
							<Switch bind:checked={settings.backup_auto_enabled} />
						</div>

						{#if settings.backup_auto_enabled}
							<Input
								label="Backup Retention (days)"
								type="number"
								min="1"
								max="365"
								bind:value={settings.backup_retention_days}
							/>
						{/if}

						<div class="flex items-center justify-between">
							<div>
								<p class="font-medium">Local Only Access</p>
								<p class="text-sm text-muted-foreground">Restrict backup downloads to local network</p>
							</div>
							<Switch bind:checked={settings.backup_local_only} />
						</div>
					</Card>
				</div>
			</TabsContent>

			<!-- Maintenance -->
			<TabsContent value="maintenance">
				<Card class="p-6 space-y-6">
					<div>
						<h3 class="text-lg font-semibold mb-4">Configuration</h3>
						<div class="flex gap-4">
							<Button
								variant="outline"
								on:click={() => $regenerateConfigsMutation.mutate()}
								disabled={$regenerateConfigsMutation.isPending}
							>
								{#if $regenerateConfigsMutation.isPending}
									<Spinner size="sm" class="mr-2" />
								{:else}
									<RefreshCw class="h-4 w-4 mr-2" />
								{/if}
								Regenerate Nginx Configs
							</Button>
						</div>
						<p class="text-sm text-muted-foreground mt-2">
							Regenerate all nginx configuration files from database.
						</p>
					</div>

					<div>
						<h3 class="text-lg font-semibold mb-4">Cleanup</h3>
						<div class="flex gap-4">
							<Button
								variant="destructive"
								on:click={() => $cleanupMutation.mutate()}
								disabled={$cleanupMutation.isPending}
							>
								{#if $cleanupMutation.isPending}
									<Spinner size="sm" class="mr-2" />
								{:else}
									<Trash2 class="h-4 w-4 mr-2" />
								{/if}
								Run Cleanup
							</Button>
						</div>
						<p class="text-sm text-muted-foreground mt-2">
							Remove old logs, expired bans, and temporary files based on retention settings.
						</p>
					</div>

					{#if $regenerateConfigsMutation.isSuccess}
						<Alert variant="success" title="Success">
							Configuration files regenerated successfully.
						</Alert>
					{/if}

					{#if $cleanupMutation.isSuccess}
						<Alert variant="success" title="Success">
							Cleanup completed successfully.
						</Alert>
					{/if}
				</Card>
			</TabsContent>
		</Tabs>
	{/if}
</div>

<!-- Backup Modal -->
<Modal bind:open={showBackupModal} title="Backup Settings">
	<p class="text-muted-foreground">
		Download a backup of all CatWAF settings. This includes site configurations,
		alert rules, and system settings.
	</p>
	<div slot="footer">
		<Button variant="outline" on:click={() => (showBackupModal = false)}>Cancel</Button>
		<Button on:click={handleBackup}>
			<Download class="h-4 w-4 mr-2" />
			Download Backup
		</Button>
	</div>
</Modal>

<!-- Restore Modal -->
<Modal bind:open={showRestoreModal} title="Restore Settings">
	<div class="space-y-4">
		<Alert variant="warning" title="Warning">
			This will overwrite all current settings with the backup data.
		</Alert>
		<div class="border-2 border-dashed border-input rounded-lg p-8 text-center">
			<input
				type="file"
				accept=".json"
				class="hidden"
				id="restore-file"
				on:change={(e) => {
					const input = e.target;
					if (input.files?.length) {
						restoreFile = input.files[0];
					}
				}}
			/>
			<label for="restore-file" class="cursor-pointer">
				<Upload class="h-8 w-8 mx-auto text-muted-foreground mb-2" />
				<p class="text-sm text-muted-foreground">
					{restoreFile ? restoreFile.name : 'Click to select backup file'}
				</p>
			</label>
		</div>
	</div>
	<div slot="footer">
		<Button variant="outline" on:click={() => (showRestoreModal = false)}>Cancel</Button>
		<Button variant="destructive" on:click={handleRestore} disabled={!restoreFile}>
			<Upload class="h-4 w-4 mr-2" />
			Restore
		</Button>
	</div>
</Modal>
