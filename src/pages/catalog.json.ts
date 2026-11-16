// Price list read by the form endpoint (server/form.php), which prices every book order from it.
import type { APIRoute } from 'astro';
import { getOrderCatalog } from '../lib/catalog';

export const GET: APIRoute = async () =>
  new Response(JSON.stringify(await getOrderCatalog()), { headers: { 'Content-Type': 'application/json; charset=utf-8' } });
