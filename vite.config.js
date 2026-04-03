import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'path';

export default defineConfig( {
	plugins: [ react() ],

	build: {
		outDir: 'assets/dist',
		emptyOutDir: true,
		rollupOptions: {
			input: {
				'admin-shell':     resolve( __dirname, 'src/admin-shell/main.jsx' ),
				'admin-qr':        resolve( __dirname, 'src/admin-qr/main.js' ),
				'process-editor':  resolve( __dirname, 'src/process-editor/main.jsx' ),
				'handbook-viewer': resolve( __dirname, 'src/handbook-viewer/main.jsx' ),
			},
			// WordPress externals — provided by WP core at runtime, never bundled.
			external: [
				'@wordpress/element',
				'@wordpress/api-fetch',
				'@wordpress/i18n',
				'@wordpress/components',
				'@wordpress/hooks',
				'@wordpress/url',
				'@wordpress/data',
			],
			output: {
				entryFileNames: '[name].js',
				chunkFileNames: 'chunks/[name]-[hash].js',
				assetFileNames: ( assetInfo ) => {
					if ( assetInfo.name?.endsWith( '.css' ) ) {
						return '[name].css';
					}
					return 'assets/[name]-[hash][extname]';
				},
				// Map WP package names to their runtime globals.
				globals: {
					'@wordpress/element':    'wp.element',
					'@wordpress/api-fetch':  'wp.apiFetch',
					'@wordpress/i18n':       'wp.i18n',
					'@wordpress/components': 'wp.components',
					'@wordpress/hooks':      'wp.hooks',
					'@wordpress/url':        'wp.url',
					'@wordpress/data':       'wp.data',
				},
			},
		},
	},

	resolve: {
		alias: {
			// @shared resolves to src/shared/ from any entry point.
			'@shared': resolve( __dirname, 'src/shared' ),
		},
	},

	server: {
		port: 3000,
	},
} );
