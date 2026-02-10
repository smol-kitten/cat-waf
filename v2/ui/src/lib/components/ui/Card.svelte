<script lang="ts">
	import { cn } from '$lib/utils';
	import type { HTMLAttributes } from 'svelte/elements';

	type $$Props = HTMLAttributes<HTMLDivElement> & {
		class?: string;
	};

	let className: string = '';
	export { className as class };
</script>

<div
	class={cn(
		'rounded-lg border border-border bg-card p-6 text-card-foreground shadow-sm',
		className
	)}
	{...$$restProps}
>
	<slot />
</div>
