<script lang="ts">
	import { createQuery, createMutation, useQueryClient } from '@tanstack/svelte-query';
	import { certificatesApi } from '$lib/api';
	import type { Certificate } from '$lib/api';
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
		Textarea
	} from '$lib/components/ui';
	import {
		Lock,
		Plus,
		Trash2,
		RefreshCw,
		Download,
		Upload,
		CheckCircle,
		AlertTriangle,
		Clock
	} from 'lucide-svelte';

	const queryClient = useQueryClient();

	let showUploadModal = false;
	let showRequestModal = false;
	let selectedCert: Certificate | null = null;
	let showDeleteModal = false;

	// Upload form
	let uploadForm = {
		domain: '',
		certificate: '',
		private_key: ''
	};

	// Request form
	let requestDomain = '';

	// Fetch certificates
	$: certsQuery = createQuery({
		queryKey: ['certificates'],
		queryFn: () => certificatesApi.list()
	});

	// Mutations
	const uploadMutation = createMutation({
		mutationFn: (data: { domain: string; certificate: string; private_key: string }) =>
			certificatesApi.upload(data),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['certificates'] });
			showUploadModal = false;
			uploadForm = { domain: '', certificate: '', private_key: '' };
		}
	});

	const requestMutation = createMutation({
		mutationFn: (domain: string) => certificatesApi.request(domain),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['certificates'] });
			showRequestModal = false;
			requestDomain = '';
		}
	});

	const renewMutation = createMutation({
		mutationFn: (id: string) => certificatesApi.renew(id),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['certificates'] });
		}
	});

	const deleteMutation = createMutation({
		mutationFn: (id: string) => certificatesApi.delete(id),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['certificates'] });
			showDeleteModal = false;
			selectedCert = null;
		}
	});

	function confirmDelete(cert: Certificate) {
		selectedCert = cert;
		showDeleteModal = true;
	}

	function formatDate(date: string) {
		return new Date(date).toLocaleDateString();
	}

	function getDaysUntilExpiry(date: string) {
		const expiry = new Date(date);
		const now = new Date();
		const diff = expiry.getTime() - now.getTime();
		return Math.ceil(diff / (1000 * 60 * 60 * 24));
	}

	function getExpiryStatus(date: string) {
		const days = getDaysUntilExpiry(date);
		if (days < 0) return 'expired';
		if (days < 7) return 'critical';
		if (days < 30) return 'warning';
		return 'valid';
	}

	function getStatusBadge(status: string) {
		switch (status) {
			case 'expired':
				return { variant: 'destructive' as const, text: 'Expired' };
			case 'critical':
				return { variant: 'destructive' as const, text: 'Expiring Soon' };
			case 'warning':
				return { variant: 'warning' as const, text: 'Expiring' };
			default:
				return { variant: 'success' as const, text: 'Valid' };
		}
	}
</script>

<svelte:head>
	<title>Certificates - CatWAF</title>
</svelte:head>

<div class="space-y-6">
	<!-- Header -->
	<div class="flex items-center justify-between">
		<div>
			<h1 class="text-2xl font-bold flex items-center gap-2">
				<Lock class="h-6 w-6" />
				SSL Certificates
			</h1>
			<p class="text-muted-foreground">Manage SSL/TLS certificates</p>
		</div>
		<div class="flex items-center gap-2">
			<Button variant="outline" on:click={() => (showUploadModal = true)}>
				<Upload class="h-4 w-4 mr-2" />
				Upload
			</Button>
			<Button on:click={() => (showRequestModal = true)}>
				<Plus class="h-4 w-4 mr-2" />
				Request Certificate
			</Button>
		</div>
	</div>

	<!-- Stats -->
	{#if $certsQuery.data}
		<div class="grid grid-cols-4 gap-4">
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-primary/10 rounded-lg">
						<Lock class="h-5 w-5 text-primary" />
					</div>
					<div>
						<p class="text-2xl font-bold">{$certsQuery.data.length}</p>
						<p class="text-sm text-muted-foreground">Total Certificates</p>
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
							{$certsQuery.data.filter((c) => getExpiryStatus(c.expires_at) === 'valid').length}
						</p>
						<p class="text-sm text-muted-foreground">Valid</p>
					</div>
				</div>
			</Card>
			<Card class="p-4">
				<div class="flex items-center gap-3">
					<div class="p-2 bg-yellow-500/10 rounded-lg">
						<Clock class="h-5 w-5 text-yellow-500" />
					</div>
					<div>
						<p class="text-2xl font-bold">
							{$certsQuery.data.filter((c) => getExpiryStatus(c.expires_at) === 'warning').length}
						</p>
						<p class="text-sm text-muted-foreground">Expiring Soon</p>
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
							{$certsQuery.data.filter(
								(c) => getExpiryStatus(c.expires_at) === 'expired' || getExpiryStatus(c.expires_at) === 'critical'
							).length}
						</p>
						<p class="text-sm text-muted-foreground">Need Attention</p>
					</div>
				</div>
			</Card>
		</div>
	{/if}

	<!-- Certificates Table -->
	<Card>
		{#if $certsQuery.isLoading}
			<div class="flex justify-center py-12">
				<Spinner size="lg" />
			</div>
		{:else if $certsQuery.error}
			<Alert variant="destructive" title="Error" class="m-4">
				Failed to load certificates
			</Alert>
		{:else if $certsQuery.data?.length}
			<Table>
				<TableHeader>
					<TableRow>
						<TableHead>Domain</TableHead>
						<TableHead>Type</TableHead>
						<TableHead>Issuer</TableHead>
						<TableHead>Expires</TableHead>
						<TableHead>Status</TableHead>
						<TableHead>Actions</TableHead>
					</TableRow>
				</TableHeader>
				<TableBody>
					{#each $certsQuery.data as cert}
						{@const status = getExpiryStatus(cert.expires_at)}
						{@const badge = getStatusBadge(status)}
						<TableRow>
							<TableCell class="font-medium">{cert.domain}</TableCell>
							<TableCell>
								<Badge variant="outline">
									{cert.type === 'acme' ? "Let's Encrypt" : 'Custom'}
								</Badge>
							</TableCell>
							<TableCell class="text-muted-foreground">{cert.issuer || '-'}</TableCell>
							<TableCell>
								<div>
									<span class="text-muted-foreground">{formatDate(cert.expires_at)}</span>
									<span class="text-xs text-muted-foreground ml-2">
										({getDaysUntilExpiry(cert.expires_at)} days)
									</span>
								</div>
							</TableCell>
							<TableCell>
								<Badge variant={badge.variant}>{badge.text}</Badge>
							</TableCell>
							<TableCell>
								<div class="flex gap-1">
									{#if cert.type === 'acme'}
										<Button
											variant="outline"
											size="sm"
											on:click={() => $renewMutation.mutate(cert.id)}
											disabled={$renewMutation.isPending}
										>
											<RefreshCw class="h-3 w-3 mr-1" />
											Renew
										</Button>
									{/if}
									<Button
										variant="ghost"
										size="icon"
										on:click={() => confirmDelete(cert)}
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
				<Lock class="h-12 w-12 mx-auto text-muted-foreground mb-4" />
				<p class="text-muted-foreground">No certificates</p>
				<div class="flex justify-center gap-2 mt-4">
					<Button variant="outline" on:click={() => (showUploadModal = true)}>
						<Upload class="h-4 w-4 mr-2" />
						Upload Custom
					</Button>
					<Button on:click={() => (showRequestModal = true)}>
						<Plus class="h-4 w-4 mr-2" />
						Request from Let's Encrypt
					</Button>
				</div>
			</div>
		{/if}
	</Card>
</div>

<!-- Request Certificate Modal -->
<Modal bind:open={showRequestModal} title="Request SSL Certificate" size="md">
	<div class="space-y-4">
		<Alert title="Let's Encrypt">
			A free SSL certificate will be requested from Let's Encrypt.
			The domain must point to this server.
		</Alert>
		<Input
			label="Domain"
			placeholder="example.com"
			bind:value={requestDomain}
		/>
	</div>
	<div slot="footer">
		<Button variant="outline" on:click={() => (showRequestModal = false)}>Cancel</Button>
		<Button
			on:click={() => $requestMutation.mutate(requestDomain)}
			disabled={$requestMutation.isPending || !requestDomain}
		>
			{#if $requestMutation.isPending}
				<Spinner size="sm" class="mr-2" />
			{/if}
			Request
		</Button>
	</div>
</Modal>

<!-- Upload Certificate Modal -->
<Modal bind:open={showUploadModal} title="Upload Certificate" size="lg">
	<div class="space-y-4">
		<Input
			label="Domain"
			placeholder="example.com"
			bind:value={uploadForm.domain}
		/>
		<Textarea
			label="Certificate (PEM)"
			placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----"
			rows={8}
			bind:value={uploadForm.certificate}
		/>
		<Textarea
			label="Private Key (PEM)"
			placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----"
			rows={8}
			bind:value={uploadForm.private_key}
		/>
	</div>
	<div slot="footer">
		<Button variant="outline" on:click={() => (showUploadModal = false)}>Cancel</Button>
		<Button
			on:click={() => $uploadMutation.mutate(uploadForm)}
			disabled={$uploadMutation.isPending || !uploadForm.domain || !uploadForm.certificate}
		>
			{#if $uploadMutation.isPending}
				<Spinner size="sm" class="mr-2" />
			{/if}
			Upload
		</Button>
	</div>
</Modal>

<!-- Delete Confirmation Modal -->
<Modal bind:open={showDeleteModal} title="Delete Certificate">
	{#if selectedCert}
		<p class="text-muted-foreground">
			Are you sure you want to delete the certificate for
			<strong>{selectedCert.domain}</strong>?
		</p>
		<Alert variant="warning" title="Warning" class="mt-4">
			Sites using this certificate will need a new certificate.
		</Alert>
	{/if}
	<div slot="footer">
		<Button variant="outline" on:click={() => (showDeleteModal = false)}>Cancel</Button>
		<Button
			variant="destructive"
			on:click={() => selectedCert && $deleteMutation.mutate(selectedCert.id)}
			disabled={$deleteMutation.isPending}
		>
			{#if $deleteMutation.isPending}
				<Spinner size="sm" class="mr-2" />
			{/if}
			Delete
		</Button>
	</div>
</Modal>
