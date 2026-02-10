<script lang="ts">
	let className = '';
	export { className as class };
</script>

<div
	class="inline-flex h-10 items-center justify-center rounded-md bg-muted p-1 text-muted-foreground {className}"
	role="tablist"
>
	<slot />
</div>
