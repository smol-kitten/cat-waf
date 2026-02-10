<script lang="ts">
	let className = '';
	export { className as class };
</script>

<th
	class="h-12 px-4 text-left align-middle font-medium text-muted-foreground [&:has([role=checkbox])]:pr-0 {className}"
>
	<slot />
</th>
