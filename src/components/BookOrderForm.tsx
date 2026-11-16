import { useEffect, useState, type SubmitEvent } from 'react';
import type { DeliveryOption, OrderProduct } from '../lib/catalog';
import { url } from '../lib/url';
import { input, label } from './formStyles';

interface Props {
  /** Form backend URL (server/form.php). Without it the order opens the visitor's e-mail app instead. */
  endpoint?: string;
  email: string;
  products: OrderProduct[];
  /** Delivery choices for printed books; the first one is preselected. */
  delivery: DeliveryOption[];
  /** Per product id: a sentence shown after that book was ordered, e.g. when a pre-ordered book will be sent. */
  notes?: Record<string, string>;
}

type Status = 'idle' | 'sending' | 'sent' | 'error';

const eur = (amount: number) =>
  new Intl.NumberFormat('sk-SK', { style: 'currency', currency: 'EUR', maximumFractionDigits: 2, minimumFractionDigits: 0 }).format(amount);

export default function BookOrderForm({ endpoint, email, products, delivery, notes = {} }: Props) {
  const options = products.flatMap((p) =>
    p.variants.map((v, i) => ({
      value: `${p.id}:${i}`,
      productId: p.id,
      variantIndex: i,
      name: p.variants.length > 1 ? `${p.title} – ${v.label}` : p.title,
      ...v,
    })),
  );

  const [status, setStatus] = useState<Status>('idle');
  const [choice, setChoice] = useState(options[0].value);
  const [quantity, setQuantity] = useState(1);
  const [deliveryId, setDeliveryId] = useState(delivery[0].id);
  const [sentTo, setSentTo] = useState('');
  const [orderNumber, setOrderNumber] = useState('');

  // Pre-select the book from links like /objednavka?kniha=koucing or ?kniha=organizacia-ako-zivy-system&variant=1
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const wanted = `${params.get('kniha')}:${params.get('variant') ?? 0}`;
    if (options.some((o) => o.value === wanted)) setChoice(wanted);
  }, []);

  const item = options.find((o) => o.value === choice) ?? options[0];
  const count = Math.min(Math.max(Math.round(quantity) || 1, 1), 20);
  // A PDF goes by e-mail, so the delivery choice and the address only apply to printed books.
  const chosen = delivery.find((d) => d.id === deliveryId) ?? delivery[0];
  const postage = item.digital ? 0 : chosen.price;
  const needsAddress = !item.digital && chosen.address;
  const deliveryLabel = item.digital ? 'PDF pošleme e-mailom' : chosen.label;
  const total = item.price * count + postage;

  async function onSubmit(e: SubmitEvent<HTMLFormElement>) {
    e.preventDefault();
    const form = e.currentTarget;
    const data = new FormData(form);
    if (data.get('_gotcha')) return;

    if (!endpoint) {
      const body = [...data.entries()]
        .filter(([key]) => !key.startsWith('_') && key !== 'suhlas')
        .map(([key, value]) => `${key}: ${value}`)
        .join('\n');
      window.location.href = `mailto:${email}?subject=${encodeURIComponent(`Objednávka knihy ${item.name}`)}&body=${encodeURIComponent(body)}`;
      return;
    }

    setStatus('sending');
    try {
      const res = await fetch(endpoint, { method: 'POST', body: data, headers: { Accept: 'application/json' } });
      if (!res.ok) throw new Error(String(res.status));
      // The endpoint returns the order number when the order was stored for the administration.
      const reply = await res.json().catch(() => ({}));
      setOrderNumber(typeof reply.order === 'string' ? reply.order : '');
      setSentTo(String(data.get('email') ?? ''));
      form.reset();
      setStatus('sent');
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch {
      setStatus('error');
    }
  }

  if (status === 'sent') {
    return (
      <div role="status" className="rounded-3xl border border-sand-200 bg-white p-8 text-center md:p-12">
        <p className="eyebrow">Objednávka prijatá</p>
        <p className="mt-3 font-serif text-3xl text-brand-950">Ďakujeme za objednávku</p>
        {orderNumber && (
          <p className="mt-4 text-lg text-brand-950">
            Číslo objednávky: <strong>{orderNumber}</strong>
          </p>
        )}
        <p className="mx-auto mt-4 max-w-lg text-muted">
          Potvrdenie objednávky a údaje na platbu prevodom vám pošleme do 2 pracovných dní na {sentTo || 'váš e-mail'}.
          {notes[item.productId] && ` ${notes[item.productId]}`}
        </p>
        <a href={url('/knihy')} className="btn-secondary mt-8">Späť na knihy</a>
      </div>
    );
  }

  return (
    <form onSubmit={onSubmit} className="grid grid-cols-1 gap-5 sm:grid-cols-2">
      {/* `_form` tells the endpoint this is a book order; it prices it from `_produkt` and `_variant`, not from the visible fields. */}
      <input type="hidden" name="_form" value="order" />
      <input type="hidden" name="_produkt" value={item.productId} />
      <input type="hidden" name="_variant" value={item.variantIndex} />
      <input type="hidden" name="_digital" value={item.digital ? '1' : '0'} />
      <input type="hidden" name="_subject" value={`Objednávka knihy: ${item.name}`} />
      {/* Readable copy of the choice for the e-mail fallback; the endpoint uses `_doprava`. */}
      <input type="hidden" name="doprava" value={deliveryLabel} />
      <input type="text" name="_gotcha" tabIndex={-1} autoComplete="off" className="hidden" aria-hidden="true" />

      <div className="flex gap-4 sm:col-span-2">
        <div className="min-w-0 flex-1">
          <label htmlFor="kniha" className={label}>Kniha</label>
          <select id="kniha" value={choice} onChange={(e) => setChoice(e.target.value)} className={input}>
            {options.map((o) => (
              <option key={o.value} value={o.value}>
                {o.name} – {eur(o.price)}
              </option>
            ))}
          </select>
          <input type="hidden" name="kniha" value={item.name} />
        </div>
        <div className="w-24 shrink-0">
          <label htmlFor="pocet" className={label}>Počet kusov</label>
          <input
            id="pocet"
            name="pocet_kusov"
            type="number"
            inputMode="numeric"
            min={1}
            max={20}
            required
            value={quantity}
            onChange={(e) => setQuantity(Number(e.target.value))}
            className={input}
          />
        </div>
      </div>

      {!item.digital && (
        <fieldset className="sm:col-span-2">
          <legend className={label}>Doručenie</legend>
          <div className="mt-1.5 grid gap-3 sm:grid-cols-2">
            {delivery.map((d) => (
              <label
                key={d.id}
                className={`flex cursor-pointer items-start gap-3 rounded-xl border bg-white px-4 py-3 ${
                  d.id === chosen.id ? 'border-brand-700 ring-2 ring-brand-700/15' : 'border-sand-300'
                }`}
              >
                <input
                  type="radio"
                  name="_doprava"
                  value={d.id}
                  checked={d.id === chosen.id}
                  onChange={() => setDeliveryId(d.id)}
                  className="mt-1 size-4 shrink-0 accent-brand-900"
                />
                <span>
                  <span className="block font-medium text-brand-950">
                    {d.label} <span className="font-normal text-muted">– {d.price ? eur(d.price) : 'zadarmo'}</span>
                  </span>
                  {d.note && <span className="mt-0.5 block text-sm text-muted">{d.note}</span>}
                </span>
              </label>
            ))}
          </div>
        </fieldset>
      )}

      <div className="sm:col-span-2">
        <label htmlFor="meno" className={label}>Meno a priezvisko</label>
        <input id="meno" name="meno" required autoComplete="name" className={input} />
      </div>
      <div>
        <label htmlFor="email" className={label}>E-mail</label>
        <input id="email" name="email" type="email" required autoComplete="email" className={input} />
      </div>
      <div>
        <label htmlFor="telefon" className={label}>
          Telefón <span className="font-normal text-muted">(nepovinné)</span>
        </label>
        <input id="telefon" name="telefon" type="tel" autoComplete="tel" className={input} />
      </div>

      {needsAddress && (
        <>
          <div className="sm:col-span-2">
            <label htmlFor="ulica" className={label}>Ulica a číslo domu</label>
            <input id="ulica" name="ulica" required autoComplete="street-address" className={input} />
          </div>
          <div>
            <label htmlFor="psc" className={label}>PSČ</label>
            <input id="psc" name="psc" required autoComplete="postal-code" inputMode="numeric" className={input} />
          </div>
          <div>
            <label htmlFor="mesto" className={label}>Mesto / obec</label>
            <input id="mesto" name="mesto" required autoComplete="address-level2" className={input} />
          </div>
        </>
      )}

      <div className="sm:col-span-2">
        <label htmlFor="poznamka" className={label}>
          Poznámka <span className="font-normal text-muted">(nepovinné, napr. fakturačné údaje firmy)</span>
        </label>
        <textarea id="poznamka" name="poznamka" rows={3} className={input} />
      </div>

      <div className="rounded-2xl bg-sand-100/70 p-5 sm:col-span-2">
        <dl className="space-y-1.5 text-[15px]">
          <div className="flex justify-between gap-4">
            <dt className="text-muted">
              {item.name} × {count}
            </dt>
            <dd>{eur(item.price * count)}</dd>
          </div>
          <div className="flex justify-between gap-4">
            <dt className="text-muted">{deliveryLabel}</dt>
            <dd>{postage ? eur(postage) : 'zadarmo'}</dd>
          </div>
          <div className="flex justify-between gap-4 border-t border-sand-300 pt-2.5 text-lg font-semibold text-brand-950">
            <dt>Spolu</dt>
            <dd>{eur(total)}</dd>
          </div>
        </dl>
        <input type="hidden" name="cena_spolu" value={eur(total)} />
        <p className="mt-3 text-sm text-muted">Platba prevodom. Údaje na platbu vám pošleme e-mailom spolu s potvrdením objednávky.</p>
      </div>

      <label className="flex items-start gap-3 text-sm text-muted sm:col-span-2">
        <input type="checkbox" name="suhlas" required className="mt-0.5 size-4 shrink-0 rounded accent-brand-900" />
        <span>
          Súhlasím so <a href={url('/vop')} className="text-brand-800 underline">všeobecnými obchodnými podmienkami</a> a beriem na vedomie{' '}
          <a href={url('/gdpr')} className="text-brand-800 underline">zásady ochrany osobných údajov</a>.
        </span>
      </label>

      <div className="flex flex-wrap items-center gap-4 sm:col-span-2">
        <button type="submit" disabled={status === 'sending'} className="btn-primary px-7 py-3.5 text-base disabled:opacity-60">
          {status === 'sending' ? 'Odosielam…' : 'Objednať s povinnosťou platby'}
        </button>
        {status === 'error' && (
          <p role="alert" className="text-sm text-brand-700">
            Objednávku sa nepodarilo odoslať. Skúste to znova alebo napíšte na <a href={`mailto:${email}`} className="underline">{email}</a>.
          </p>
        )}
      </div>
    </form>
  );
}
