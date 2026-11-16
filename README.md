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
Payment is by bank transfer, as the VOP say: Dad replies to the customer with the payment details and uses the order number as the
payment reference. Buy buttons link to `/objednavka?kniha=koucing` or `/objednavka?kniha=<book id>&variant=<index>` to preselect the book.
The Websupport builder stays only because it hosts the @coachingacademy.sk mailboxes.

Without configuration, forms fall back to opening the visitor's e-mail app. Copy `.env.example` to `.env` to switch them on:

- `PUBLIC_FORM_ENDPOINT` is the form backend used by the book order, contact and withdrawal forms: `https://coachacademy.sk/form.php` (see below).
- `PUBLIC_STRIPE_BOOK_LINK` (not used for now) is a Stripe Payment Link. If set, the KOUČING buy buttons go to Stripe instead of the order form. In Stripe, enable shipping address collection and set the post-payment redirect to `https://coachingacademy.sk/kniha/dakujeme`.

For the GitHub Pages build, set the same names as repository variables (Settings → Secrets and variables → Actions → Variables).

## Form endpoint and order administration (`server/`)

The site is static, so its forms post to PHP on the Websupport webhosting (service dalibus_1, domain `coachacademy.sk`, folder
`coachacademy.sk/web/`). It is **not** part of the GitHub Pages build: after changing anything in `server/`, upload it again over FTP.

| File | What it does |
| --- | --- |
| `form.php` | Receives the forms. Accepts only the origins listed at its top; honeypot and per-IP rate limit. |
| `lib/app.php` | Shared code: settings, database, encryption, e-mail (SMTP), price list. |
| `admin/` | Order administration at `https://coachacademy.sk/admin/` (sign-in, list, detail, statuses, CSV export, erasure). |
| `form-config.php` | Settings and secrets. Git-ignored; lives only on your computer and on the server. Template: `form-config.example.php`. |
| `tools/setup.php` | Run locally (`php server/tools/setup.php`) to create the encryption key and set an admin name and password. |

**How an order flows.** The form sends the book id, variant and quantity. `form.php` prices the order from the site's own
`/catalog.json` (generated from `src/lib/catalog.ts`, cached 10 minutes), so a price changed in the browser has no effect. If the
total the customer saw differs, or the price list cannot be loaded, the order is flagged for a manual check. The order is stored in
MySQL, e-mailed to the addresses in `mail_to`, and the customer sees the order number. If the database is down the order still
goes out by e-mail, so it is never lost. Contact and withdrawal messages are only e-mailed, never stored.

**Security and GDPR, by design.**

- Customer data (name, e-mail, phone, address, note) is stored only encrypted (AES-256-GCM, key `data_key` in `form-config.php`).
  **Losing `data_key` makes stored customer data unreadable**, so keep a copy of `form-config.php`.
- Admin passwords are stored as hashes. Sign-in is limited to 5 failed attempts per 15 minutes per IP address. Sessions use a
  `__Host-` cookie (Secure, HttpOnly, SameSite=Lax), expire after 1 hour idle or 12 hours, and every change is a POST with a CSRF token.
  The admin has no JavaScript and a strict Content-Security-Policy.
- Storage limitation: personal data is erased automatically `retention_months` (36) after an order was shipped and
  `retention_cancelled_months` (6) after it was cancelled. The anonymous rest (book, amount, dates) stays. An admin can erase a
  customer's data or a whole order at any time (right to erasure), search by e-mail and export to CSV (right of access).
- The activity log (sign-ins, exports, status changes, erasures) never contains customer data. Sign-in records are deleted after 12 months.
- IP addresses are never stored, only keyed hashes for throttling, kept for minutes to a day.
- `'mail_order_details' => false` in `form-config.php` makes the notification e-mail carry no personal data, only the order number and a link.
- The GDPR page (`src/content/pravne/gdpr.md`) describes all of this. Keep the two in step when changing retention or recipients.

**Setting it up on the server.** Create a MySQL database in Webadmin, fill in `db` in `form-config.php`, run
`php server/tools/setup.php`, then upload the contents of `server/` to `coachacademy.sk/web/`. The tables are created on first use.

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
- [ ] Dad reads the updated GDPR page (orders section, cookies section) and confirms the retention periods
- [ ] Submit `https://coachingacademy.sk/sitemap-index.xml` in Google Search Console
