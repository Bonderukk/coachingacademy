import { useEffect, useState, type SubmitEvent } from 'react';
import { url } from '../lib/url';

interface Props {
  /** Form backend URL (Formspree, Web3Forms, …). Without it the form opens the visitor's e-mail app instead. */
  endpoint?: string;
  email: string;
  variant?: 'contact' | 'withdrawal';
  topics?: string[];
  defaultTopic?: string;
}

type Status = 'idle' | 'sending' | 'sent' | 'error';

const input =
  'mt-1.5 block w-full rounded-xl border border-sand-300 bg-white px-4 py-3 text-[16px] text-ink placeholder:text-muted/60 focus:border-brand-700 focus:ring-2 focus:ring-brand-700/15 focus:outline-none';
const label = 'block text-sm font-medium text-brand-950';

export default function ContactForm({ endpoint, email, variant = 'contact', topics = [], defaultTopic = '' }: Props) {
  const [status, setStatus] = useState<Status>('idle');
  const [topic, setTopic] = useState(defaultTopic);

  // Pre-select the topic from links like /kontakt?tema=ICF%20LEVEL%201
  useEffect(() => {
    const fromUrl = new URLSearchParams(window.location.search).get('tema');
    if (fromUrl) setTopic(fromUrl);
  }, []);

  const isWithdrawal = variant === 'withdrawal';
  const options = topic && !topics.includes(topic) ? [topic, ...topics] : topics;

  async function onSubmit(e: SubmitEvent<HTMLFormElement>) {
    e.preventDefault();
    const form = e.currentTarget;
    const data = new FormData(form);
    if (data.get('_gotcha')) return;

    if (!endpoint) {
      const subject = isWithdrawal ? 'Odstúpenie od zmluvy' : `Správa z webu${topic ? `: ${topic}` : ''}`;
      const body = [...data.entries()]
        .filter(([key]) => !key.startsWith('_') && key !== 'suhlas')
        .map(([key, value]) => `${key}: ${value}`)
        .join('\n');
      window.location.href = `mailto:${email}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
      return;
    }

    setStatus('sending');
    try {
      const res = await fetch(endpoint, { method: 'POST', body: data, headers: { Accept: 'application/json' } });
      if (!res.ok) throw new Error(String(res.status));
      form.reset();
      setStatus('sent');
    } catch {
      setStatus('error');
    }
  }

  if (status === 'sent') {
    return (
      <div role="status" className="rounded-3xl border border-sand-200 bg-white p-8 text-center">
        <p className="font-serif text-2xl text-brand-950">Ďakujeme, správa bola odoslaná.</p>
        <p className="mt-2 text-muted">
          {isWithdrawal ? 'Prijatie odstúpenia vám potvrdíme e-mailom.' : 'Ozveme sa vám čo najskôr, zvyčajne do 2 pracovných dní.'}
        </p>
      </div>
    );
  }

  return (
    <form onSubmit={onSubmit} className="grid gap-5 sm:grid-cols-2">
      <input type="hidden" name="_subject" value={isWithdrawal ? 'Odstúpenie od zmluvy' : `Web: ${topic || 'nová správa'}`} />
      <input type="text" name="_gotcha" tabIndex={-1} autoComplete="off" className="hidden" aria-hidden="true" />

      <div>
        <label htmlFor="meno" className={label}>Meno a priezvisko</label>
        <input id="meno" name="meno" required autoComplete="name" className={input} />
      </div>
      <div>
        <label htmlFor="email" className={label}>E-mail</label>
        <input id="email" name="email" type="email" required autoComplete="email" className={input} />
      </div>

      {isWithdrawal ? (
        <>
          <div>
            <label htmlFor="objednavka" className={label}>Číslo objednávky</label>
            <input id="objednavka" name="cislo_objednavky" required className={input} />
          </div>
          <div>
            <label htmlFor="datum" className={label}>Dátum objednávky / prevzatia</label>
            <input id="datum" name="datum" type="date" className={input} />
          </div>
          <div className="sm:col-span-2">
            <label htmlFor="sprava" className={label}>Čoho sa odstúpenie týka</label>
            <textarea
              id="sprava"
              name="sprava"
              rows={4}
              required
              className={input}
              defaultValue="Týmto oznamujem, že odstupujem od zmluvy na tento tovar / službu: "
            />
          </div>
        </>
      ) : (
        <>
          <div>
            <label htmlFor="telefon" className={label}>
              Telefón <span className="font-normal text-muted">(nepovinné)</span>
            </label>
            <input id="telefon" name="telefon" type="tel" autoComplete="tel" className={input} />
          </div>
          <div>
            <label htmlFor="tema" className={label}>Mám záujem o</label>
            <select id="tema" name="tema" value={topic} onChange={(e) => setTopic(e.target.value)} className={input}>
              <option value="">Vyberte…</option>
              {options.map((t) => (
                <option key={t} value={t}>{t}</option>
              ))}
              <option value="Iné">Iné</option>
            </select>
          </div>
          <div className="sm:col-span-2">
            <label htmlFor="sprava" className={label}>Správa</label>
            <textarea id="sprava" name="sprava" rows={5} required className={input} />
          </div>
        </>
      )}

      <label className="flex items-start gap-3 text-sm text-muted sm:col-span-2">
        <input type="checkbox" name="suhlas" required className="mt-0.5 size-4 rounded accent-brand-900" />
        <span>
          Súhlasím so spracovaním osobných údajov za účelom vybavenia mojej požiadavky podľa{' '}
          <a href={url('/gdpr')} className="text-brand-800 underline">zásad ochrany osobných údajov</a>.
        </span>
      </label>

      <div className="flex flex-wrap items-center gap-4 sm:col-span-2">
        <button type="submit" disabled={status === 'sending'} className="btn-primary disabled:opacity-60">
          {status === 'sending' ? 'Odosielam…' : isWithdrawal ? 'Odoslať odstúpenie' : 'Odoslať správu'}
        </button>
        {status === 'error' && (
          <p role="alert" className="text-sm text-brand-700">
            Správu sa nepodarilo odoslať. Skúste to znova alebo napíšte na <a href={`mailto:${email}`} className="underline">{email}</a>.
          </p>
        )}
      </div>
    </form>
  );
}
