<script lang="ts">
	import type { HTMLSelectAttributes } from 'svelte/elements';

	interface Option {
		value: string;
		label: string;
		disabled?: boolean;
	}

	interface $$Props extends HTMLSelectAttributes {
		label?: string;
		error?: string;
		options?: Option[];
		placeholder?: string;
		class?: string;
	}

	export let label: string | undefined = undefined;
	export let error: string | undefined = undefined;
	export let value: string | undefined = undefined;
	export let options: Option[] = [];
	export let placeholder = 'Select an option';

	let className = '';
	export { className as class };

	const selectClasses =
		'flex h-10 w-full items-center justify-between rounded-lg border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50';
</script>

<div class="space-y-2">
	{#if label}
		<label class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70">
			{label}
		</label>
	{/if}
	<select
		bind:value
		class="{selectClasses} {error ? 'border-destructive' : ''} {className}"
		{...$$restProps}
	>
		{#if placeholder}
			<option value="" disabled selected>{placeholder}</option>
		{/if}
		{#each options as option}
			<option value={option.value} disabled={option.disabled}>
				{option.label}
			</option>
		{/each}
		<slot />
	</select>
	{#if error}
		<p class="text-sm text-destructive">{error}</p>
	{/if}
</div>
