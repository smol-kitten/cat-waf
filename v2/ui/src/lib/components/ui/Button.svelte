<script lang="ts">
	import type { HTMLAnchorAttributes, HTMLButtonAttributes } from 'svelte/elements';

	type Variant = 'default' | 'destructive' | 'outline' | 'secondary' | 'ghost' | 'link';
	type Size = 'default' | 'sm' | 'lg' | 'icon';

	interface $$Props extends HTMLButtonAttributes {
		variant?: Variant;
		size?: Size;
		href?: string;
		class?: string;
	}

	export let variant: Variant = 'default';
	export let size: Size = 'default';
	export let href: string | undefined = undefined;

	let className = '';
	export { className as class };

	const baseClasses =
		'inline-flex items-center justify-center whitespace-nowrap rounded-lg font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50';

	const variants: Record<Variant, string> = {
		default: 'bg-primary text-primary-foreground hover:bg-primary/90',
		destructive: 'bg-destructive text-destructive-foreground hover:bg-destructive/90',
		outline: 'border border-input bg-background hover:bg-accent hover:text-accent-foreground',
		secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
		ghost: 'hover:bg-accent hover:text-accent-foreground',
		link: 'text-primary underline-offset-4 hover:underline'
	};

	const sizes: Record<Size, string> = {
		default: 'h-10 px-4 py-2',
		sm: 'h-9 rounded-md px-3',
		lg: 'h-11 rounded-md px-8',
		icon: 'h-10 w-10'
	};

	$: classes = `${baseClasses} ${variants[variant]} ${sizes[size]} ${className}`;
</script>

{#if href}
	<a {href} class={classes}>
		<slot />
	</a>
{:else}
	<button class={classes} {...$$restProps} on:click>
		<slot />
	</button>
{/if}
