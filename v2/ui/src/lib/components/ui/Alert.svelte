<script lang="ts">
	import { AlertCircle, CheckCircle2, Info, AlertTriangle } from 'lucide-svelte';

	type Variant = 'default' | 'destructive' | 'success' | 'warning';

	export let variant: Variant = 'default';
	export let title = '';

	let className = '';
	export { className as class };

	const baseClasses =
		'relative w-full rounded-lg border p-4 [&>svg~*]:pl-7 [&>svg]:absolute [&>svg]:left-4 [&>svg]:top-4 [&>svg]:text-foreground';

	const variants: Record<Variant, string> = {
		default: 'bg-background text-foreground',
		destructive:
			'border-destructive/50 text-destructive dark:border-destructive [&>svg]:text-destructive',
		success:
			'border-green-500/50 text-green-700 dark:text-green-400 [&>svg]:text-green-500',
		warning:
			'border-yellow-500/50 text-yellow-700 dark:text-yellow-400 [&>svg]:text-yellow-500'
	};

	const icons = {
		default: Info,
		destructive: AlertCircle,
		success: CheckCircle2,
		warning: AlertTriangle
	};

	$: Icon = icons[variant];
	$: classes = `${baseClasses} ${variants[variant]} ${className}`;
</script>

<div class={classes} role="alert">
	<svelte:component this={Icon} class="h-4 w-4" />
	{#if title}
		<h5 class="mb-1 font-medium leading-none tracking-tight">{title}</h5>
	{/if}
	<div class="text-sm [&_p]:leading-relaxed">
		<slot />
	</div>
</div>
