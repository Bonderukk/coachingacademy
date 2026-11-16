import { getCollection } from 'astro:content';
import { book } from '../config/site';

export interface OrderProduct {
  /** "koucing" for the KOUČING book, otherwise the id of an entry in src/content/knihy. */
  id: string;
  title: string;
  variants: { label: string; price: number; digital: boolean }[];
}

/** How a printed book gets to the customer. PDF-only orders skip this and go by e-mail. */
export interface DeliveryOption {
  id: string;
  label: string;
  /** In euros, charged once per order. */
  price: number;
  /** Whether the customer has to give a postal address. */
  address: boolean;
  /** Short explanation shown under the option. */
  note?: string;
}

/**
 * Everything that can be ordered at /objednavka, with prices. The order form shows it and /catalog.json
 * publishes it, so the form endpoint (server/form.php) can price each order itself instead of trusting the browser.
 * A variant is addressed by its index, so reordering the variants of a book changes what old links point to.
 */
export async function getOrderCatalog(): Promise<{ shipping: number; delivery: DeliveryOption[]; products: OrderProduct[] }> {
  const ispak = (await getCollection('knihy')).sort((a, b) => a.data.order - b.data.order);
  return {
    // Postage by post. Kept as its own field for form endpoints that predate `delivery`.
    shipping: book.shipping,
    // The first option is the default. The same two were offered by the old Websupport shop.
    delivery: [
      { id: 'posta', label: 'Poštou na Slovensku', price: book.shipping, address: true },
      { id: 'osobne', label: 'Osobný odber', price: 0, address: false, note: 'Miesto a čas odovzdania si dohodneme e-mailom.' },
    ],
    products: [
      { id: 'koucing', title: book.title, variants: [{ label: 'Tlačená kniha', price: book.price, digital: false }] },
      ...ispak.map((entry) => ({
        id: entry.id,
        title: entry.data.title,
        variants: entry.data.variants.map(({ label, price, digital }) => ({ label, price, digital })),
      })),
    ],
  };
}
