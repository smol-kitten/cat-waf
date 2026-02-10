<script lang="ts">
	import '../app.css';
	import { page } from '$app/stores';
	import Sidebar from '$lib/components/layout/Sidebar.svelte';
	import Header from '$lib/components/layout/Header.svelte';
	import { Toaster } from 'svelte-sonner';
	import { QueryClientProvider } from '@tanstack/svelte-query';
	import { queryClient } from '$lib/api/client';
	import { isAuthenticated } from '$lib/stores/auth';

	// Check if user is on auth pages
	$: isAuthPage = $page.url.pathname.startsWith('/login');
	
	// For development, bypass auth check
	const DEV_MODE = true; // TODO: Use environment variable
</script>

<QueryClientProvider client={queryClient}>
	<Toaster richColors position="top-right" />
	
	{#if isAuthPage}
		<slot />
	{:else if DEV_MODE || $isAuthenticated}
		<div class="flex min-h-screen">
			<!-- Sidebar -->
			<Sidebar />
			
			<!-- Main Content -->
			<div class="flex flex-1 flex-col pl-64">
				<!-- Header -->
				<Header />
				
				<!-- Page Content -->
				<main class="flex-1 p-6">
					<slot />
				</main>
			</div>
		</div>
	{:else}
		<slot />
	{/if}
</QueryClientProvider>
