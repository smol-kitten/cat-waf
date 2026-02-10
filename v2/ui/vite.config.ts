import { sveltekit } from '@sveltejs/kit/vite';
import { defineConfig } from 'vitest/config';

export default defineConfig({
	plugins: [sveltekit()],
	
	test: {
		include: ['src/**/*.{test,spec}.{js,ts}'],
		environment: 'jsdom',
		globals: true,
		setupFiles: ['./tests/setup.ts'],
		coverage: {
			reporter: ['text', 'json', 'html'],
			exclude: [
				'node_modules/',
				'src/routes/**',
				'**/*.d.ts',
				'**/*.config.*'
			]
		}
	},
	
	server: {
		port: 3000,
		proxy: {
			'/api': {
				target: process.env.VITE_API_URL || 'http://localhost:8080',
				changeOrigin: true
			}
		}
	},
	
	preview: {
		port: 3000
	}
});
