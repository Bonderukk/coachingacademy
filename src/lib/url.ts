// Configured `base` without trailing slash: '' in production, e.g. '/coachingacademy' on GitHub Pages.
const base = import.meta.env.BASE_URL.replace(/\/$/, '');

/** Prefixes a root-relative path with the base so links keep working under a subpath. Idempotent. */
export const url = (path: string) => {
  if (!path.startsWith('/') || (base && (path === base || path.startsWith(base + '/')))) return path;
  return base + path;
};

/** Normalizes Astro.url.pathname to a base-less route: "/kniha.html", "/kniha/" → "/kniha"; "/index.html" → "/". */
export const cleanPath = (pathname: string) => {
  let path = pathname.replace(/(\/index)?\.html$/, '').replace(/\/$/, '');
  if (base && path.startsWith(base)) path = path.slice(base.length);
  return path || '/';
};
