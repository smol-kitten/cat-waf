<script lang="ts">
	let className = '';
	export { className as class };
</script>

<tbody class="[&_tr:last-child]:border-0 {className}">
	<slot />
</tbody>
