<script lang="ts">
	let className = '';
	export { className as class };
</script>

<td class="p-4 align-middle [&:has([role=checkbox])]:pr-0 {className}">
	<slot />
</td>
