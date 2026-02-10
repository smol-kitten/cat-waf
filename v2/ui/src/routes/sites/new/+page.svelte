<script lang="ts">
	import { goto } from '$app/navigation';
	import { createMutation, useQueryClient } from '@tanstack/svelte-query';
	import { sitesApi } from '$lib/api';
	import type { Site } from '$lib/api';
	import {
		Button,
		Input,
		Card,
		Alert,
		Spinner,
		Switch
	} from '$lib/components/ui';
	import { ArrowLeft, Save, Globe } from 'lucide-svelte';

	const queryClient = useQueryClient();

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
		use_cf_ip_headers: false,
		local_only: false
	};

	let backendAddress = '';
	let backendPort = 80;
	let saveError = '';

	// Create site mutation
	const createMutation = createMutation({
		mutationFn: async (data: Partial<Site>) => {
			// Create site first
			const newSite = await sitesApi.create(data);
			
			// Then add backend if provided
			if (backendAddress) {
				await sitesApi.backends.create(newSite.id, {
					address: backendAddress,
					port: backendPort,
					weight: 1,
					protocol: 'http',
					health_check: true
				});
			}
			
			return newSite;
		},
		onSuccess: (data) => {
			queryClient.invalidateQueries({ queryKey: ['sites'] });
			goto(`/sites/${data.id}`);
		},
		onError: (error) => {
			saveError = error instanceof Error ? error.message : 'Failed to create site';
		}
	});

	function handleSubmit() {
		saveError = '';
		if (!site.domain) {
			saveError = 'Domain is required';
			return;
		}
		$createMutation.mutate(site);
	}
</script>

<svelte:head>
	<title>New Site - CatWAF</title>
</svelte:head>

<div class="max-w-2xl mx-auto space-y-6">
	<!-- Header -->
	<div class="flex items-center gap-4">
		<Button variant="ghost" size="icon" href="/sites">
			<ArrowLeft class="h-4 w-4" />
		</Button>
		<div>
			<h1 class="text-2xl font-bold">New Site</h1>
			<p class="text-muted-foreground">Add a new site to CatWAF</p>
		</div>
	</div>

	{#if saveError}
		<Alert variant="destructive" title="Error">
			{saveError}
		</Alert>
	{/if}

	<Card class="p-6 space-y-6">
		<!-- Domain -->
		<div class="space-y-4">
			<div class="flex items-center gap-2 mb-2">
				<Globe class="h-5 w-5 text-primary" />
				<h2 class="text-lg font-semibold">Domain</h2>
			</div>
			<Input
				label="Domain Name"
				placeholder="example.com"
				bind:value={site.domain}
				required
			/>
			<p class="text-sm text-muted-foreground">
				Enter the primary domain for this site. You can add aliases later.
			</p>
		</div>

		<!-- Backend -->
		<div class="space-y-4 pt-4 border-t">
			<h2 class="text-lg font-semibold">Backend Server</h2>
			<div class="grid grid-cols-2 gap-4">
				<Input
					label="Address"
					placeholder="127.0.0.1 or backend.local"
					bind:value={backendAddress}
				/>
				<Input
					label="Port"
					type="number"
					min="1"
					max="65535"
					bind:value={backendPort}
				/>
			</div>
			<p class="text-sm text-muted-foreground">
				The upstream server to proxy requests to. You can add more backends later.
			</p>
		</div>

		<!-- Quick Settings -->
		<div class="space-y-4 pt-4 border-t">
			<h2 class="text-lg font-semibold">Quick Settings</h2>
			
			<div class="flex items-center justify-between">
				<div>
					<p class="font-medium">Enable Site</p>
					<p class="text-sm text-muted-foreground">Make site live immediately</p>
				</div>
				<Switch bind:checked={site.enabled} />
			</div>

			<div class="flex items-center justify-between">
				<div>
					<p class="font-medium">Web Application Firewall</p>
					<p class="text-sm text-muted-foreground">Enable ModSecurity protection</p>
				</div>
				<Switch bind:checked={site.waf_enabled} />
			</div>

			<div class="flex items-center justify-between">
				<div>
					<p class="font-medium">Block Bad Bots</p>
					<p class="text-sm text-muted-foreground">Block known malicious bots</p>
				</div>
				<Switch bind:checked={site.block_bad_bots} />
			</div>

			<div class="flex items-center justify-between">
				<div>
					<p class="font-medium">Auto SSL</p>
					<p class="text-sm text-muted-foreground">Automatically obtain SSL certificate</p>
				</div>
				<Switch checked={site.ssl_mode === 'auto'} on:click={() => {
					site.ssl_mode = site.ssl_mode === 'auto' ? 'none' : 'auto';
				}} />
			</div>
		</div>
	</Card>

	<!-- Actions -->
	<div class="flex justify-end gap-2">
		<Button variant="outline" href="/sites">Cancel</Button>
		<Button
			on:click={handleSubmit}
			disabled={$createMutation.isPending}
		>
			{#if $createMutation.isPending}
				<Spinner size="sm" class="mr-2" />
			{:else}
				<Save class="h-4 w-4 mr-2" />
			{/if}
			Create Site
		</Button>
	</div>
</div>
