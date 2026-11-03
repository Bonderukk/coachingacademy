// Scroll-triggered motion: cards and [data-reveal] blocks fade up, [data-count] numbers count up.
// Only elements still below the fold get hidden, so nothing visible on load ever flickers.

const REVEAL = '.card:not([data-no-reveal]), [data-reveal]';
const motionOk = window.matchMedia('(prefers-reduced-motion: no-preference)').matches;

function countUp(el: HTMLElement) {
  const target = Number(el.dataset.count);
  const final = el.textContent;
  const suffix = el.dataset.suffix ?? '';
  const start = performance.now();
  const duration = 1400;
  const tick = (now: number) => {
    const t = Math.min((now - start) / duration, 1);
    const eased = 1 - Math.pow(1 - t, 3);
    el.textContent = Math.round(target * eased).toLocaleString('sk-SK') + suffix;
    if (t < 1) requestAnimationFrame(tick);
    else el.textContent = final;
  };
  requestAnimationFrame(tick);
}

if (motionOk && 'IntersectionObserver' in window) {
  const observer = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue;
        const el = entry.target as HTMLElement;
        observer.unobserve(el);
        if (el.dataset.count) {
          countUp(el);
          continue;
        }
        el.classList.add('is-visible');
        // Hand the element back to its own hover transitions once it has arrived
        // (ignoring transitionend events bubbling up from children).
        const done = (e: TransitionEvent) => {
          if (e.target !== el) return;
          el.removeEventListener('transitionend', done);
          el.classList.remove('reveal', 'is-visible');
          el.style.removeProperty('--reveal-delay');
        };
        el.addEventListener('transitionend', done);
      }
    },
    { rootMargin: '0px 0px -8% 0px' },
  );

  const belowFold = (el: Element) => el.getBoundingClientRect().top > window.innerHeight;

  for (const el of document.querySelectorAll<HTMLElement>(REVEAL)) {
    if (!belowFold(el)) continue;
    // Stagger siblings that enter together (cards in a grid, testimonials in columns).
    const siblings = [...(el.parentElement?.children ?? [])].filter((s) => s.matches(REVEAL));
    el.style.setProperty('--reveal-delay', `${Math.min(siblings.indexOf(el), 5) * 90}ms`);
    el.classList.add('reveal');
    observer.observe(el);
  }

  // Counters run even when visible on load – swapping a number is not a jarring flicker.
  for (const el of document.querySelectorAll<HTMLElement>('[data-count]')) observer.observe(el);
}
