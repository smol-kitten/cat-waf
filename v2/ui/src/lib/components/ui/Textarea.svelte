<script lang="ts">
	import type { HTMLTextareaAttributes } from 'svelte/elements';

	interface $$Props extends HTMLTextareaAttributes {
		label?: string;
		error?: string;
		class?: string;
	}

	export let label: string | undefined = undefined;
	export let error: string | undefined = undefined;
	export let value: string | undefined = undefined;

	let className = '';
	export { className as class };

	const textareaClasses =
		'flex min-h-[80px] w-full rounded-lg border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50';
</script>

<div class="space-y-2">
	{#if label}
		<label class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70">
			{label}
		</label>
	{/if}
	<textarea
		bind:value
		class="{textareaClasses} {error ? 'border-destructive' : ''} {className}"
		{...$$restProps}
	/>
	{#if error}
		<p class="text-sm text-destructive">{error}</p>
	{/if}
</div>
