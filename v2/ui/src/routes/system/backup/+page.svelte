<script lang="ts">
	import { createQuery, createMutation, useQueryClient } from '@tanstack/svelte-query';
	import { settingsApi } from '$lib/api';
	import type { BackupInfo } from '$lib/api';
	import {
		Button,
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
		Input
	} from '$lib/components/ui';
	import {
		Download,
		Upload,
		Trash2,
		RefreshCw,
		Archive,
		HardDrive,
		Clock,
		AlertTriangle
	} from 'lucide-svelte';

	const queryClient = useQueryClient();

	let showRestoreModal = false;
	let showDeleteModal = false;
	let showUploadModal = false;
	let selectedBackup: BackupInfo | null = null;
	let uploadFile: File | null = null;
	let fileInput: HTMLInputElement;

	// Fetch backups
	$: backupsQuery = createQuery({
		queryKey: ['backups'],
		queryFn: () => settingsApi.backup.list()
	});

	// Mutations
	const createBackupMutation = createMutation({
		mutationFn: () => settingsApi.backup.create(),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['backups'] });
		}
	});

	const restoreBackupMutation = createMutation({
		mutationFn: (id: string) => settingsApi.backup.restore(id),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['backups'] });
			showRestoreModal = false;
			selectedBackup = null;
		}
	});

	const deleteBackupMutation = createMutation({
		mutationFn: (id: string) => settingsApi.backup.delete(id),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['backups'] });
			showDeleteModal = false;
			selectedBackup = null;
		}
	});

	function formatFileSize(bytes: number) {
		if (bytes === 0) return '0 Bytes';
		const k = 1024;
		const sizes = ['Bytes', 'KB', 'MB', 'GB'];
		const i = Math.floor(Math.log(bytes) / Math.log(k));
		return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
	}

	function formatDate(dateStr: string) {
		return new Date(dateStr).toLocaleString();
	}

	function handleDownload(backup: BackupInfo) {
		const url = settingsApi.backup.download(backup.id);
		window.open(url, '_blank');
	}

	function openRestoreModal(backup: BackupInfo) {
		selectedBackup = backup;
		showRestoreModal = true;
	}

	function openDeleteModal(backup: BackupInfo) {
		selectedBackup = backup;
		showDeleteModal = true;
	}

	function handleFileSelect(event: Event) {
		const target = event.target as HTMLInputElement;
		if (target.files && target.files[0]) {
			uploadFile = target.files[0];
		}
	}

	async function handleUpload() {
		if (!uploadFile) return;
		// TODO: Implement file upload
		showUploadModal = false;
		uploadFile = null;
	}

	$: backups = $backupsQuery.data?.backups || [];
	$: totalSize = backups.reduce((sum, b) => sum + b.size, 0);
</script>

<svelte:head>
	<title>Backup & Restore - CatWAF</title>
</svelte:head>

<div class="space-y-6">
	<!-- Header -->
	<div class="flex items-center justify-between">
		<div>
			<h1 class="text-2xl font-bold">Backup & Restore</h1>
			<p class="text-muted-foreground">Manage system backups and restore configurations</p>
		</div>
		<div class="flex items-center gap-2">
			<Button variant="outline" on:click={() => $backupsQuery.refetch()}>
				<RefreshCw class="mr-2 h-4 w-4" />
				Refresh
			</Button>
			<Button variant="outline" on:click={() => (showUploadModal = true)}>
				<Upload class="mr-2 h-4 w-4" />
				Upload
			</Button>
			<Button on:click={() => $createBackupMutation.mutate()} disabled={$createBackupMutation.isPending}>
				{#if $createBackupMutation.isPending}
					<Spinner class="mr-2 h-4 w-4" />
				{:else}
					<Archive class="mr-2 h-4 w-4" />
				{/if}
				Create Backup
			</Button>
		</div>
	</div>

	<!-- Stats Cards -->
	<div class="grid gap-4 md:grid-cols-3">
		<Card class="p-4">
			<div class="flex items-center gap-2">
				<Archive class="h-5 w-5 text-primary" />
				<span class="text-sm text-muted-foreground">Total Backups</span>
			</div>
			<div class="mt-2 text-2xl font-bold">{backups.length}</div>
		</Card>
		<Card class="p-4">
			<div class="flex items-center gap-2">
				<HardDrive class="h-5 w-5 text-blue-500" />
				<span class="text-sm text-muted-foreground">Total Size</span>
			</div>
			<div class="mt-2 text-2xl font-bold">{formatFileSize(totalSize)}</div>
		</Card>
		<Card class="p-4">
			<div class="flex items-center gap-2">
				<Clock class="h-5 w-5 text-green-500" />
				<span class="text-sm text-muted-foreground">Latest Backup</span>
			</div>
			<div class="mt-2 text-2xl font-bold">
				{#if backups.length > 0}
					{formatDate(backups[0].createdAt)}
				{:else}
					Never
				{/if}
			</div>
		</Card>
	</div>

	<!-- Info Alert -->
	<Alert>
		<AlertTriangle class="h-4 w-4" />
		<div>
			<strong>Important:</strong> Backups include all sites, settings, certificates, and security rules.
			Restoring a backup will overwrite current configuration. Make sure to create a backup before
			restoring.
		</div>
	</Alert>

	<!-- Backups Table -->
	<Card>
		{#if $backupsQuery.isLoading}
			<div class="flex items-center justify-center p-8">
				<Spinner class="h-8 w-8" />
			</div>
		{:else if $backupsQuery.error}
			<Alert variant="destructive" class="m-4">
				Failed to load backups: {$backupsQuery.error instanceof Error
					? $backupsQuery.error.message
					: 'Unknown error'}
			</Alert>
		{:else if backups.length === 0}
			<div class="flex flex-col items-center justify-center p-8 text-center">
				<Archive class="h-12 w-12 text-muted-foreground" />
				<h3 class="mt-4 text-lg font-semibold">No Backups</h3>
				<p class="mt-2 text-muted-foreground">
					Create your first backup to protect your configuration.
				</p>
				<Button
					class="mt-4"
					on:click={() => $createBackupMutation.mutate()}
					disabled={$createBackupMutation.isPending}
				>
					<Archive class="mr-2 h-4 w-4" />
					Create Backup
				</Button>
			</div>
		{:else}
			<Table>
				<TableHeader>
					<TableRow>
						<TableHead>Filename</TableHead>
						<TableHead>Size</TableHead>
						<TableHead>Created</TableHead>
						<TableHead class="text-right">Actions</TableHead>
					</TableRow>
				</TableHeader>
				<TableBody>
					{#each backups as backup (backup.id)}
						<TableRow>
							<TableCell>
								<div class="flex items-center gap-2">
									<Archive class="h-4 w-4 text-muted-foreground" />
									<span class="font-mono text-sm">{backup.filename}</span>
								</div>
							</TableCell>
							<TableCell>{formatFileSize(backup.size)}</TableCell>
							<TableCell>{formatDate(backup.createdAt)}</TableCell>
							<TableCell class="text-right">
								<div class="flex items-center justify-end gap-2">
									<Button variant="ghost" size="sm" on:click={() => handleDownload(backup)}>
										<Download class="h-4 w-4" />
									</Button>
									<Button variant="ghost" size="sm" on:click={() => openRestoreModal(backup)}>
										<Upload class="h-4 w-4" />
									</Button>
									<Button variant="ghost" size="sm" on:click={() => openDeleteModal(backup)}>
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

<!-- Restore Confirmation Modal -->
<Modal bind:open={showRestoreModal} title="Restore Backup">
	{#if selectedBackup}
		<div class="space-y-4">
			<Alert variant="warning">
				<AlertTriangle class="h-4 w-4" />
				<div>
					<strong>Warning:</strong> This will overwrite your current configuration with the backup data.
				</div>
			</Alert>
			<p>Are you sure you want to restore from backup "{selectedBackup.filename}"?</p>
			<p class="text-sm text-muted-foreground">
				Created: {formatDate(selectedBackup.createdAt)} | Size: {formatFileSize(selectedBackup.size)}
			</p>
		</div>
	{/if}

	<div slot="footer" class="flex justify-end gap-2">
		<Button variant="outline" on:click={() => (showRestoreModal = false)}>Cancel</Button>
		<Button
			variant="destructive"
			on:click={() => selectedBackup && $restoreBackupMutation.mutate(selectedBackup.id)}
			disabled={$restoreBackupMutation.isPending}
		>
			{#if $restoreBackupMutation.isPending}
				<Spinner class="mr-2 h-4 w-4" />
			{/if}
			Restore Backup
		</Button>
	</div>
</Modal>

<!-- Delete Confirmation Modal -->
<Modal bind:open={showDeleteModal} title="Delete Backup">
	{#if selectedBackup}
		<p>Are you sure you want to delete the backup "{selectedBackup.filename}"?</p>
		<p class="mt-2 text-sm text-muted-foreground">This action cannot be undone.</p>
	{/if}

	<div slot="footer" class="flex justify-end gap-2">
		<Button variant="outline" on:click={() => (showDeleteModal = false)}>Cancel</Button>
		<Button
			variant="destructive"
			on:click={() => selectedBackup && $deleteBackupMutation.mutate(selectedBackup.id)}
			disabled={$deleteBackupMutation.isPending}
		>
			{#if $deleteBackupMutation.isPending}
				<Spinner class="mr-2 h-4 w-4" />
			{/if}
			Delete
		</Button>
	</div>
</Modal>

<!-- Upload Backup Modal -->
<Modal bind:open={showUploadModal} title="Upload Backup">
	<div class="space-y-4">
		<p class="text-sm text-muted-foreground">
			Upload a previously downloaded backup file to restore.
		</p>
		<div>
			<label for="backup-file" class="text-sm font-medium">Backup File</label>
			<Input
				id="backup-file"
				type="file"
				accept=".zip,.tar.gz,.json"
				bind:this={fileInput}
				on:change={handleFileSelect}
			/>
		</div>
		{#if uploadFile}
			<p class="text-sm">
				Selected: {uploadFile.name} ({formatFileSize(uploadFile.size)})
			</p>
		{/if}
	</div>

	<div slot="footer" class="flex justify-end gap-2">
		<Button variant="outline" on:click={() => (showUploadModal = false)}>Cancel</Button>
		<Button on:click={handleUpload} disabled={!uploadFile}>
			<Upload class="mr-2 h-4 w-4" />
			Upload
		</Button>
	</div>
</Modal>
