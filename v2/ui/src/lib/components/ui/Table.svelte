<script lang="ts">
	let className = '';
	export { className as class };
</script>

<div class="relative w-full overflow-auto rounded-lg border">
	<table class="w-full caption-bottom text-sm {className}">
		<slot />
	</table>
</div>
