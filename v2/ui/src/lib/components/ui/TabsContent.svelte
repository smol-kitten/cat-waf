<script lang="ts">
	import { getContext } from 'svelte';
	import type { Writable } from 'svelte/store';

	export let value: string;

	let className = '';
	export { className as class };

	const { selected } = getContext<{
		selected: Writable<string>;
	}>('tabs');

	$: isSelected = $selected === value;
</script>

<div
	role="tabpanel"
	class="mt-2 ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 {className}"
	class:hidden={!isSelected}
	aria-hidden={!isSelected}
>
	<slot />
</div>
