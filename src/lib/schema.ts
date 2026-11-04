// schema.org builders for JSON-LD (rich results in Google).
import type { CollectionEntry } from 'astro:content';
import { book, site } from '../config/site';

const abs = (path: string) => new URL(path, site.url).href;

export const organization = {
  '@type': 'EducationalOrganization',
  '@id': abs('/#organization'),
  name: site.company.name,
  alternateName: site.fullName,
  url: site.url,
  logo: abs('/icon-512.png'),
  email: site.email,
  telephone: site.phone.replace(/\s/g, ''),
  foundingDate: '2006',
  address: {
    '@type': 'PostalAddress',
    streetAddress: site.company.street,
    postalCode: site.company.zip,
    addressLocality: site.company.city,
    addressCountry: site.company.country,
  },
  sameAs: [site.social.linkedin, site.social.facebook, site.social.youtube],
};

export const dalibor = {
  '@type': 'Person',
  '@id': abs('/o-mne#dalibor'),
  name: 'Dalibor Bednařík',
  jobTitle: 'Psychológ a profesionálny kouč (ICF MCC)',
  url: abs('/o-mne'),
  worksFor: { '@id': organization['@id'] },
  award: 'Kouč roka 2019',
  sameAs: [site.social.linkedin, site.social.facebook],
};

export function breadcrumbs(items: { name: string; path: string }[]) {
  return {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: [{ name: 'Domov', path: '/' }, ...items].map((item, i) => ({
      '@type': 'ListItem',
      position: i + 1,
      name: item.name,
      item: abs(item.path),
    })),
  };
}

export function course(entry: CollectionEntry<'vycviky'>) {
  const { title, description, priceFrom, startDate } = entry.data;
  return {
    '@context': 'https://schema.org',
    '@type': 'Course',
    name: title,
    description,
    url: abs(`/vycviky/${entry.id}`),
    inLanguage: 'sk',
    provider: organization,
    ...(priceFrom && {
      offers: { '@type': 'Offer', category: 'Paid', price: priceFrom, priceCurrency: 'EUR', url: abs(`/vycviky/${entry.id}`) },
    }),
    hasCourseInstance: {
      '@type': 'CourseInstance',
      courseMode: 'Onsite',
      location: { '@type': 'Place', name: 'Bratislava', address: { '@type': 'PostalAddress', addressLocality: 'Bratislava', addressCountry: 'SK' } },
      instructor: entry.data.lecturers.map((name) => ({ '@type': 'Person', name })),
      ...(startDate && { startDate: startDate.toISOString().slice(0, 10) }),
    },
  };
}

export function service(entry: CollectionEntry<'sluzby'>) {
  return {
    '@context': 'https://schema.org',
    '@type': 'Service',
    name: entry.data.title,
    description: entry.data.description,
    url: abs(`/sluzby/${entry.id}`),
    areaServed: 'SK',
    provider: organization,
  };
}

// Printed books ship within Slovakia for the same flat rate as the KOUČING book.
const shippingDetails = {
  '@type': 'OfferShippingDetails',
  shippingRate: { '@type': 'MonetaryAmount', value: book.shipping, currency: book.currency },
  shippingDestination: { '@type': 'DefinedRegion', addressCountry: 'SK' },
};

export function bookProduct(imageUrl: string, description: string) {
  return {
    '@context': 'https://schema.org',
    '@type': ['Book', 'Product'],
    name: book.title,
    author: dalibor,
    inLanguage: 'sk',
    image: abs(imageUrl),
    description,
    brand: { '@type': 'Brand', name: site.name },
    // TODO: add `isbn` once the book has one.
    offers: {
      '@type': 'Offer',
      url: abs('/kniha'),
      price: book.price,
      priceCurrency: book.currency,
      availability: book.preorder ? 'https://schema.org/PreOrder' : 'https://schema.org/InStock',
      seller: { '@id': organization['@id'] },
      shippingDetails,
    },
  };
}

/** A book from the iSPAK range (src/content/knihy) – one offer per format. */
export function storeBook(entry: CollectionEntry<'knihy'>, imageUrl: string) {
  const { title, subtitle, authors, publisher, description, variants } = entry.data;
  const url = abs(`/knihy/${entry.id}`);
  return {
    '@context': 'https://schema.org',
    '@type': ['Book', 'Product'],
    name: title,
    alternativeHeadline: subtitle,
    author: authors.map((name) => ({ '@type': 'Person', name })),
    publisher: { '@type': 'Organization', name: publisher },
    inLanguage: 'sk',
    image: abs(imageUrl),
    description,
    url,
    offers: variants.map((variant) => ({
      '@type': 'Offer',
      name: variant.label,
      url,
      price: variant.price,
      priceCurrency: book.currency,
      availability: 'https://schema.org/InStock',
      seller: { '@id': organization['@id'] },
      ...(!variant.digital && { shippingDetails }),
    })),
  };
}
