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
| Course sign-up Google Form                  | `signupUrl` in `src/content/vycviky/*.md` |
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

Books (KOUČING and the iSPAK books) are ordered through our own form at `/objednavka` (`src/components/BookOrderForm.tsx`).
The order arrives by e-mail and Dad replies with the payment details (bank transfer, as the VOP say). Buy buttons link to
`/objednavka?kniha=koucing` or `/objednavka?kniha=<book id>&variant=<index>` to preselect the book.
The Websupport builder stays only because it hosts the @coachingacademy.sk mailboxes.

Without configuration, forms fall back to opening the visitor's e-mail app. Copy `.env.example` to `.env` to switch them on:

- `PUBLIC_FORM_ENDPOINT` is the form backend used by the book order, contact and withdrawal forms: `https://coachacademy.sk/form.php`.
  That is our own PHP script, [server/form.php](server/form.php), uploaded to the Websupport webhosting (service dalibus_1, domain
  `coachacademy.sk`). It e-mails each submission to dalibor@coachingacademy.sk with the visitor as Reply-To, accepts only the
  origins listed at its top, and has a honeypot and a per-IP rate limit. It is not part of the GitHub Pages build: after editing it,
  upload it again by hand. The fields follow Formspree's conventions (`_subject`, `_gotcha`), so Formspree would work as a drop-in too.
- `PUBLIC_STRIPE_BOOK_LINK` (not used for now) is a Stripe Payment Link. If set, the KOUČING buy buttons go to Stripe instead of the order form. In Stripe, enable shipping address collection and set the post-payment redirect to `https://coachingacademy.sk/kniha/dakujeme`.

For the GitHub Pages build, set the same names as repository variables (Settings → Secrets and variables → Actions → Variables).

## JavaScript budget

Pages are static HTML by default. The mobile menu uses the native Popover API (no JS).
React loads only on pages with `ContactForm` (contact, services, withdrawal) or `SpotifyEmbed` (podcast).

## Deployment

Every push to `main` builds the site and deploys it to GitHub Pages (`.github/workflows/deploy-preview.yml`).

**Preview:** while Pages serves the site at https://bonderukk.github.io/coachingacademy/, the build runs in preview mode (`noindex` on all pages).
The subpath comes from `ASTRO_BASE`, which is why internal links go through `url()` from `src/lib/url.ts`. Use `url('/kniha')`, never a bare `href="/kniha"`.

**Production:** once `coachingacademy.sk` is set as the custom domain in Settings → Pages, the base path is empty and the same workflow builds
in production mode (indexable). With `build.format: 'file'` and `trailingSlash: 'never'`, URLs look like `/kniha` (no trailing slash).
DNS at Websupport: only the `coachingacademy.sk` A record and the `www` record change. Never touch MX, `mailin*`, `mail`/`smtp`/`imap`/`pop3`, SPF or DKIM records: the mailboxes live on the Websupport builder service.

Old Websupport URLs are mapped in `redirects` in `astro.config.mjs`. On a plain static host they become meta-refresh pages. For real `301` redirects, deploy with the Netlify, Vercel or Cloudflare adapter, or write them into the host's redirect config.

## Before launch

- [ ] Form endpoint (`PUBLIC_FORM_ENDPOINT`) as a repository variable, test order reaches dalibor@coachingacademy.sk
- [ ] Point the domain (custom domain in GitHub Pages + A/www records at Websupport) and check the old URLs redirect
- [ ] Cookie consent banner before re-adding Google Tag Manager / Smartlook from the old site
- [ ] Content TODOs marked with `<!-- … -->` comments in `src/content` (stale workshop date, a bonus price, a wrong e-mail in VOP)
- [ ] Have the GDPR page updated for the new site (cookies, form provider)
- [ ] Submit `https://coachingacademy.sk/sitemap-index.xml` in Google Search Console
