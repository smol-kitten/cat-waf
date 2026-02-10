<script lang="ts">
	import { authStore } from '$lib/stores/auth';
	import { goto } from '$app/navigation';
	import { Eye, EyeOff, Shield } from 'lucide-svelte';

	let apiKey = '';
	let showKey = false;
	let isLoading = false;
	let error = '';

	async function handleSubmit() {
		if (!apiKey.trim()) {
			error = 'Please enter your API key';
			return;
		}

		isLoading = true;
		error = '';

		const success = await authStore.loginWithApiKey(apiKey);

		if (success) {
			goto('/');
		} else {
			error = 'Invalid API key. Please check and try again.';
		}

		isLoading = false;
	}
</script>

<svelte:head>
	<title>Login - CatWAF</title>
</svelte:head>

<div class="flex min-h-screen items-center justify-center bg-background p-4">
	<div class="w-full max-w-md">
		<!-- Logo -->
		<div class="mb-8 text-center">
			<div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-catboy-pink-500 to-catboy-purple-500 shadow-lg shadow-catboy-pink-500/25">
				<span class="text-3xl">🐱</span>
			</div>
			<h1 class="text-3xl font-bold gradient-text">CatWAF</h1>
			<p class="mt-2 text-muted-foreground">Web Application Firewall</p>
		</div>

		<!-- Login Card -->
		<div class="rounded-xl border border-border bg-card p-8 shadow-lg">
			<div class="mb-6 text-center">
				<h2 class="text-xl font-semibold text-foreground">Welcome back</h2>
				<p class="mt-1 text-sm text-muted-foreground">
					Enter your API key to access the dashboard
				</p>
			</div>

			<form on:submit|preventDefault={handleSubmit} class="space-y-6">
				<!-- API Key Input -->
				<div>
					<label for="apiKey" class="mb-2 block text-sm font-medium text-foreground">
						API Key
					</label>
					<div class="relative">
						<div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
							<Shield class="h-5 w-5 text-muted-foreground" />
						</div>
						<input
							id="apiKey"
							type={showKey ? 'text' : 'password'}
							bind:value={apiKey}
							class="block w-full rounded-lg border border-input bg-background py-2.5 pl-10 pr-10 text-foreground placeholder-muted-foreground focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/50"
							placeholder="Enter your API key"
							disabled={isLoading}
						/>
						<button
							type="button"
							on:click={() => (showKey = !showKey)}
							class="absolute inset-y-0 right-0 flex items-center pr-3"
						>
							{#if showKey}
								<EyeOff class="h-5 w-5 text-muted-foreground hover:text-foreground" />
							{:else}
								<Eye class="h-5 w-5 text-muted-foreground hover:text-foreground" />
							{/if}
						</button>
					</div>
				</div>

				<!-- Error Message -->
				{#if error}
					<div class="rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
						{error}
					</div>
				{/if}

				<!-- Submit Button -->
				<button
					type="submit"
					disabled={isLoading}
					class="flex w-full items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-catboy-pink-500 to-catboy-purple-500 px-4 py-2.5 font-medium text-white shadow-lg shadow-catboy-pink-500/25 transition-all hover:shadow-xl hover:shadow-catboy-pink-500/30 focus:outline-none focus:ring-2 focus:ring-catboy-pink-500/50 disabled:cursor-not-allowed disabled:opacity-50"
				>
					{#if isLoading}
						<svg class="h-5 w-5 animate-spin" viewBox="0 0 24 24">
							<circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none" />
							<path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
						</svg>
						Signing in...
					{:else}
						Sign in
					{/if}
				</button>
			</form>

			<p class="mt-6 text-center text-sm text-muted-foreground">
				API key can be found in your <code class="rounded bg-muted px-1">.env</code> file
			</p>
		</div>

		<!-- Footer -->
		<p class="mt-8 text-center text-xs text-muted-foreground">
			CatWAF v2.0.0 • Purr-tecting your sites since 2025
		</p>
	</div>
</div>
