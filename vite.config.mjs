import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { resolve } from 'node:path';

/**
 * The settings app (resources/react) is built into public/app/settings.js and settings.css and enqueued by
 * src/Service/Assets/AssetManager.php. One IIFE file, no code splitting, fixed names: WordPress adds the cache-busting
 * ?ver from the file's mtime.
 *
 * @wordpress/i18n and @wordpress/api-fetch are never bundled. WordPress ships both, wp_set_script_translations() loads
 * the catalogue into its own wp.i18n, and apiFetch carries the REST nonce. As externals mapped to globals, every call
 * stays a member call in the minified file (`(0,e.__)(`…`,`spamlens`)`), which `wp i18n make-pot` can read, so
 * WordPress.org language packs cover the built file.
 */
export const WORDPRESS_GLOBALS = {
  '@wordpress/i18n': 'wp.i18n',
  '@wordpress/api-fetch': 'wp.apiFetch',
};

/**
 * Compressed and mangled, but with line breaks kept: the minifier drops every comment when it also strips whitespace,
 * and the `/** translators: … *\/` comments (JSDoc style, the only kind the bundler keeps) must reach
 * `wp i18n make-pot` next to their strings. Costs a few kB before gzip.
 */
export const MINIFY = { compress: true, mangle: true, codegen: { removeWhitespace: false } };
export const COMMENTS = { legal: true, annotation: false, jsdoc: true };

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: { alias: { '@': resolve(import.meta.dirname, 'resources/react/src') } },
  define: { 'process.env.NODE_ENV': JSON.stringify('production') },
  root: 'resources/react',
  publicDir: false,
  base: './',
  build: {
    outDir: resolve(import.meta.dirname, 'public/app'),
    emptyOutDir: true,
    cssCodeSplit: false,
    modulePreload: false,
    minify: false,
    cssMinify: true,
    rollupOptions: {
      input: resolve(import.meta.dirname, 'resources/react/src/main.tsx'),
      external: Object.keys(WORDPRESS_GLOBALS),
      output: {
        format: 'iife',
        name: 'spamlensApp',
        globals: WORDPRESS_GLOBALS,
        entryFileNames: 'settings.js',
        minify: MINIFY,
        comments: COMMENTS,
        assetFileNames: (asset) => (/\.css$/.test(asset.names?.[0] ?? '') ? 'settings.css' : 'static/[name]-[hash][extname]'),
      },
    },
  },
  test: {
    root: import.meta.dirname,
    include: ['resources/**/*.test.ts'],
    environment: 'node',
  },
});
