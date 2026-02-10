<script lang="ts">
	export let checked = false;
	export let disabled = false;
	export let label = '';

	let className = '';
	export { className as class };

	function toggle() {
		if (!disabled) {
			checked = !checked;
		}
	}

	function handleKeydown(e: KeyboardEvent) {
		if (e.key === ' ' || e.key === 'Enter') {
			e.preventDefault();
			toggle();
		}
	}
</script>

<label class="inline-flex items-center gap-2 cursor-pointer {disabled ? 'opacity-50 cursor-not-allowed' : ''} {className}">
	<button
		type="button"
		role="switch"
		aria-checked={checked}
		{disabled}
		class="peer inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50
			{checked ? 'bg-primary' : 'bg-input'}"
		on:click={toggle}
		on:keydown={handleKeydown}
	>
		<span
			class="pointer-events-none block h-5 w-5 rounded-full bg-background shadow-lg ring-0 transition-transform
				{checked ? 'translate-x-5' : 'translate-x-0'}"
		/>
	</button>
	{#if label}
		<span class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70">
			{label}
		</span>
	{/if}
</label>
