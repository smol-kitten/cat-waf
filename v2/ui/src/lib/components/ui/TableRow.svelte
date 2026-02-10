<script lang="ts">
	let className = '';
	export { className as class };
</script>

<tr class="border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted {className}">
	<slot />
</tr>
