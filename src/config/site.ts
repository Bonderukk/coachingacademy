export const site = {
  name: 'Coaching Academy',
  fullName: 'Koučingová akadémia Dalibor Bednařík',
  url: 'https://coachingacademy.sk',
  description:
    'Medzinárodne ICF akreditované koučovacie výcviky, individuálny, tímový a párový koučing v Bratislave. Vedie psychológ a MCC kouč Dalibor Bednařík.',
  locale: 'sk_SK',
  email: 'dalibor@coachingacademy.sk',
  phone: '+421 902 637 327',
  company: {
    name: 'Coaching Academy s.r.o.',
    ico: '36 674 257',
    street: 'Bajkalská 45E',
    city: 'Bratislava',
    zip: '821 05',
    country: 'SK',
    register: 'Obchodný register Okresného súdu Bratislava I, oddiel Sro, vložka č. 42258/B',
  },
  social: {
    facebook: 'https://www.facebook.com/dalibor.bednarik.9',
    linkedin: 'https://www.linkedin.com/in/dalibor-bedna%C5%99%C3%ADk-pcc-icf-699b2a25/',
    youtube: 'https://www.youtube.com/channel/UC1PFhaUX-lt0k_8D1OJ6JcQ',
    spotify: 'https://open.spotify.com/playlist/2MWQCOEMrzHaShIOXJry0D',
  },
  podcast: {
    spotifyEmbed: 'https://open.spotify.com/embed/playlist/2MWQCOEMrzHaShIOXJry0D',
  },
  // Contact / signup forms post here (Formspree, Web3Forms, ...). Without it, forms fall back to e-mail.
  formEndpoint: import.meta.env.PUBLIC_FORM_ENDPOINT as string | undefined,
} as const;

export const book = {
  title: 'KOUČING – Polarity pre šťastný život a úspech v práci',
  shortTitle: 'KOUČING',
  author: 'Dalibor Bednařík',
  price: 20,
  shipping: 5,
  currency: 'EUR',
  releaseDate: '2026-11',
  releaseLabel: 'november 2026',
  preorder: true,
  // Stripe Payment Link (dashboard → Payment links). Set its success URL to https://coachingacademy.sk/kniha/dakujeme
  stripeLink: import.meta.env.PUBLIC_STRIPE_BOOK_LINK as string | undefined,
} as const;

export type NavItem = { label: string; href: string; children?: { label: string; href: string }[] };

export const nav: NavItem[] = [
  {
    label: 'Výcviky',
    href: '/vycviky',
    children: [
      { label: 'Business & Life Coaching CORE', href: '/vycviky/business-life-coaching-core' },
      { label: 'ICF Business & Life Coaching LEVEL 1', href: '/vycviky/icf-level-1' },
      { label: 'ICF Business & Life Coaching LEVEL 2', href: '/vycviky/icf-level-2' },
      { label: 'Systemický koučing', href: '/vycviky/systemicky-koucing' },
      { label: 'Systémová dynamika v organizáciách', href: '/vycviky/systemova-dynamika-v-organizaciach' },
    ],
  },
  {
    label: 'Služby',
    href: '/sluzby',
    children: [
      { label: 'Individuálny koučing a mentoring', href: '/sluzby/individualny-koucing' },
      { label: 'Tímový koučing a mentoring', href: '/sluzby/timovy-koucing' },
      { label: 'Párový koučing a poradenstvo', href: '/sluzby/parovy-koucing' },
      { label: 'Workshop: Vzťahy – Partnering', href: '/sluzby/vztahy-partnering' },
      { label: 'Authentic Leadership', href: '/sluzby/leadership' },
    ],
  },
  {
    label: 'Knihy',
    href: '/knihy',
    children: [
      { label: 'KOUČING – Dalibor Bednařík', href: '/kniha' },
      { label: 'Organizácia ako živý systém', href: '/knihy/organizacia-ako-zivy-system' },
      { label: 'Aby deti mohli byť deťmi', href: '/knihy/aby-deti-mohli-byt-detmi' },
      { label: 'Princípy systemickej práce', href: '/knihy/principy-systemickej-prace' },
    ],
  },
  { label: 'Podcast', href: '/podcast' },
  { label: 'O mne', href: '/o-mne' },
];

export const formatPrice = (amount: number) =>
  new Intl.NumberFormat('sk-SK', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(amount);
