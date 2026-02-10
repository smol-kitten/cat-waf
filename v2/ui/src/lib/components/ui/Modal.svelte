<script lang="ts">
	import { createEventDispatcher } from 'svelte';
	import { fly, fade } from 'svelte/transition';
	import { X } from 'lucide-svelte';

	export let open = false;
	export let title = '';
	export let description = '';
	export let size: 'sm' | 'md' | 'lg' | 'xl' | 'full' = 'md';

	const dispatch = createEventDispatcher();

	function close() {
		open = false;
		dispatch('close');
	}

	function handleKeydown(e: KeyboardEvent) {
		if (e.key === 'Escape') close();
	}

	const sizes = {
		sm: 'max-w-sm',
		md: 'max-w-md',
		lg: 'max-w-lg',
		xl: 'max-w-xl',
		full: 'max-w-4xl'
	};
</script>

<svelte:window on:keydown={handleKeydown} />

{#if open}
	<div
		class="fixed inset-0 z-50 flex items-center justify-center"
		role="dialog"
		aria-modal="true"
		aria-labelledby="modal-title"
	>
		<!-- Backdrop -->
		<div
			class="fixed inset-0 bg-black/50 backdrop-blur-sm"
			transition:fade={{ duration: 150 }}
			on:click={close}
			on:keydown={handleKeydown}
			role="button"
			tabindex="-1"
		/>

		<!-- Modal -->
		<div
			class="relative z-50 w-full {sizes[size]} mx-4 bg-background rounded-lg shadow-lg border"
			transition:fly={{ y: 10, duration: 200 }}
		>
			<!-- Header -->
			<div class="flex items-center justify-between p-4 border-b">
				<div>
					{#if title}
						<h2 id="modal-title" class="text-lg font-semibold">{title}</h2>
					{/if}
					{#if description}
						<p class="text-sm text-muted-foreground mt-1">{description}</p>
					{/if}
				</div>
				<button
					type="button"
					class="rounded-sm opacity-70 ring-offset-background transition-opacity hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
					on:click={close}
				>
					<X class="h-4 w-4" />
					<span class="sr-only">Close</span>
				</button>
			</div>

			<!-- Content -->
			<div class="p-4">
				<slot />
			</div>

			<!-- Footer -->
			{#if $$slots.footer}
				<div class="flex items-center justify-end gap-2 p-4 border-t">
					<slot name="footer" />
				</div>
			{/if}
		</div>
	</div>
{/if}
