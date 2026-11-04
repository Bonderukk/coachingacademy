import { site } from '../config/site';

/** Pre-filled order e-mail, used until a book (or one of its formats) has a Stripe Payment Link. */
export function bookOrderMailto(title: string, { variant, digital = false }: { variant?: string; digital?: boolean } = {}) {
  const what = variant ? `${title} (${variant})` : title;
  const body = [
    'Dobrý deň,',
    '',
    `mám záujem o knihu ${what}.`,
    '',
    'Počet kusov: 1',
    'Meno a priezvisko:',
    ...(digital ? [] : ['Doručovacia adresa:']),
    'Telefón:',
    '',
    'Ďakujem',
  ].join('\n');
  return `mailto:${site.email}?subject=${encodeURIComponent(`Objednávka knihy ${what}`)}&body=${encodeURIComponent(body)}`;
}
