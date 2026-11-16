import { getCollection } from 'astro:content';
import { book } from '../config/site';

export interface OrderProduct {
  /** "koucing" for the KOUČING book, otherwise the id of an entry in src/content/knihy. */
  id: string;
  title: string;
  variants: { label: string; price: number; digital: boolean }[];
}

/**
 * Everything that can be ordered at /objednavka, with prices. The order form shows it and /catalog.json
 * publishes it, so the form endpoint (server/form.php) can price each order itself instead of trusting the browser.
 * A variant is addressed by its index, so reordering the variants of a book changes what old links point to.
 */
export async function getOrderCatalog(): Promise<{ shipping: number; products: OrderProduct[] }> {
  const ispak = (await getCollection('knihy')).sort((a, b) => a.data.order - b.data.order);
  return {
    // Postage for printed books, charged once per order.
    shipping: book.shipping,
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
