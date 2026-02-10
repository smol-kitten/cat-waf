<script lang="ts">
	import { getContext } from 'svelte';
	import type { Writable } from 'svelte/store';

	export let value: string;

	let className = '';
	export { className as class };

	const { selected, select } = getContext<{
		selected: Writable<string>;
		select: (value: string) => void;
	}>('tabs');

	$: isSelected = $selected === value;
</script>

<button
	type="button"
	role="tab"
	aria-selected={isSelected}
	class="inline-flex items-center justify-center whitespace-nowrap rounded-sm px-3 py-1.5 text-sm font-medium ring-offset-background transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50
		{isSelected
		? 'bg-background text-foreground shadow-sm'
		: 'hover:bg-background/50 hover:text-foreground'}
		{className}"
	on:click={() => select(value)}
>
	<slot />
</button>
