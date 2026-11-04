# coachingacademy.sk

New website for Coaching Academy (Dalibor Bednařík): Astro 7 + Tailwind CSS 4, with React used only for interactive islands.
It replaces the old Websupport builder site.

## Setup

Astro 7 needs **Node ≥ 22.23**. The project pins Node 24 in `.node-version`.

```powershell
fnm install            # reads .node-version
fnm use
npm install
npm run dev            # http://localhost:4321
```

To make fnm switch versions automatically, add this line to your PowerShell `$PROFILE`:
`fnm env --use-on-cd --shell powershell | Out-String | Invoke-Expression`

| Command           | What it does                                    |
| ----------------- | ----------------------------------------------- |
| `npm run dev`     | Dev server with hot reload                      |
| `npm run build`   | Static build into `dist/`                       |
| `npm run preview` | Serve the built `dist/` locally                 |
| `npx astro check` | Type-check `.astro`, `.ts` and `.tsx` files     |

## Where things live

| Change…                                     | Edit                                     |
| ------------------------------------------- | ---------------------------------------- |
| Course texts, prices, dates                 | `src/content/vycviky/*.md`               |
| Service pages                               | `src/content/sluzby/*.md`                |
| Testimonials (`featured: true` = homepage)  | `src/content/referencie.yaml`            |
| iSPAK books: texts, formats, prices         | `src/content/knihy/*.md`                 |
| CV on the About page                        | `src/content/cv.yaml`                    |
| GDPR, terms (VOP)                           | `src/content/pravne/*.md`                |
| Contacts, company info, menu, book price    | `src/config/site.ts`                     |
| Colors, fonts, buttons                      | `src/styles/global.css`, `astro.config.mjs` |
| SEO structured data (schema.org)            | `src/lib/schema.ts`                      |

Frontmatter is validated by the schema in `src/content.config.ts`, so a typo in a price block fails the build instead of shipping.

## Book sales and forms

Both work without configuration: they fall back to e-mail. Copy `.env.example` to `.env` to switch them on:

- `PUBLIC_STRIPE_BOOK_LINK` is a Stripe Payment Link. In Stripe, enable shipping address collection and set the post-payment redirect to `https://coachingacademy.sk/kniha/dakujeme`.
- `PUBLIC_FORM_ENDPOINT` is a form backend (Formspree, Web3Forms, …) used by the contact, sign-up and withdrawal forms.

Set the same variables in the hosting dashboard for production builds.

## JavaScript budget

Pages are static HTML by default. The mobile menu uses the native Popover API (no JS).
React loads only on pages with `ContactForm` (contact, services, withdrawal) or `SpotifyEmbed` (podcast).

## Deployment

**Preview:** every push to `main` builds the site in preview mode (`noindex` on all pages) and deploys it to GitHub Pages at
https://bonderukk.github.io/coachingacademy/ (`.github/workflows/deploy-preview.yml`). The subpath comes from `ASTRO_BASE`, which is why internal
links go through `url()` from `src/lib/url.ts`. Use `url('/kniha')`, never a bare `href="/kniha"`.

**Production:** the output is static `dist/`, so any static host works. With `build.format: 'file'` and `trailingSlash: 'never'`, URLs look like `/kniha` (no trailing slash).

Old Websupport URLs are mapped in `redirects` in `astro.config.mjs`. On a plain static host they become meta-refresh pages. For real `301` redirects, deploy with the Netlify, Vercel or Cloudflare adapter, or write them into the host's redirect config.

## Before launch

- [ ] Choose hosting, point the domain, and verify real 301 redirects from the old URLs
- [ ] Stripe Payment Link + form endpoint in env
- [ ] Cookie consent banner before re-adding Google Tag Manager / Smartlook from the old site
- [ ] Content TODOs marked with `<!-- … -->` comments in `src/content` (stale workshop date, a bonus price, a wrong e-mail in VOP)
- [ ] Have the GDPR page updated for the new site (cookies, Stripe, form provider)
- [ ] Submit `https://coachingacademy.sk/sitemap-index.xml` in Google Search Console
