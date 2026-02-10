<script lang="ts" context="module">
	export interface TabsContext {
		selected: string;
		select: (value: string) => void;
	}
</script>

<script lang="ts">
	import { setContext, createEventDispatcher } from 'svelte';
	import { writable } from 'svelte/store';

	export let value = '';

	let className = '';
	export { className as class };

	const dispatch = createEventDispatcher();
	const selected = writable(value);

	setContext('tabs', {
		selected,
		select: (newValue: string) => {
			selected.set(newValue);
			value = newValue;
			dispatch('change', newValue);
		}
	});

	$: selected.set(value);
</script>

<div class="w-full {className}">
	<slot />
</div>
