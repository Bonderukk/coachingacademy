// @ts-check
import { defineConfig, fontProviders } from 'astro/config';

import react from '@astrojs/react';
import tailwindcss from '@tailwindcss/vite';
import sitemap from '@astrojs/sitemap';

// https://astro.build/config
export default defineConfig({
  // Overridable for preview hosting, e.g. GitHub Pages: ASTRO_SITE=https://bonderukk.github.io ASTRO_BASE=/coachingacademy
  site: process.env.ASTRO_SITE || 'https://coachingacademy.sk',
  base: process.env.ASTRO_BASE || '/',

  // Clean URLs without trailing slash (/kniha, /vycviky/icf-level-1) — same style as the old site.
  trailingSlash: 'never',
  build: { format: 'file' },

  integrations: [
    react(),
    sitemap({
      filter: (page) => !page.includes('/dakujeme') && !page.includes('/zrusenie-objednavky'),
    }),
  ],

  // Downloaded at build time and served from our own domain (no requests to Google from visitors).
  fonts: [
    {
      provider: fontProviders.google(),
      name: 'Fraunces',
      cssVariable: '--font-fraunces',
      weights: ['400 700'],
      styles: ['normal', 'italic'],
      subsets: ['latin', 'latin-ext'],
      fallbacks: ['Georgia', 'serif'],
    },
    {
      provider: fontProviders.google(),
      name: 'Inter',
      cssVariable: '--font-inter',
      weights: ['400 700'],
      styles: ['normal'],
      subsets: ['latin', 'latin-ext'],
      fallbacks: ['system-ui', 'sans-serif'],
    },
  ],

  // Old Websupport URLs -> new URLs, so existing Google rankings and shared links keep working.
  // Static hosting turns these into meta-refresh pages; Netlify/Vercel/Cloudflare adapters emit real 301s.
  redirects: {
    '/o-nás/business-life-coaching-core': '/vycviky/business-life-coaching-core',
    '/o-nás/icf-akreditovaný-koučovací-výcvik-level-1': '/vycviky/icf-level-1',
    '/o-nás/icf-certifikovaný-koučovací-výcvik': '/vycviky/icf-level-1',
    '/o-nás/business-life-coaching-level-2': '/vycviky/icf-level-2',
    '/o-nás/advanced-training': '/vycviky/icf-level-2',
    '/o-nás/systemicky-koucing': '/vycviky/systemicky-koucing',
    '/kurzy': '/vycviky',
    '/workshopy': '/sluzby',
    '/workshopy1/individualny-koucing': '/sluzby/individualny-koucing',
    '/workshopy1/vztahypartnering': '/sluzby/vztahy-partnering',
    '/workshopy1/timovy-koucing': '/sluzby/timovy-koucing',
    '/workshopy1/párový-koučing-a-mentoring': '/sluzby/parovy-koucing',
    '/workshopy1/leadership': '/sluzby/leadership',
    '/prejednotlivcov': '/sluzby',
    '/prekoucov': '/vycviky',
    '/prefirmy': '/sluzby/timovy-koucing',
    '/store': '/kniha',
    '/store/cart': '/kniha',
    '/store/product/kniha-koučing-polarity-pre-šťastný-život-a-úspech-v-práci': '/kniha',
    '/site-231023-cancellation': '/zrusenie-objednavky',
  },

  vite: {
    plugins: [tailwindcss()],
  },
});
