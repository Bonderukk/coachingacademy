import { useState } from 'react';

interface Props {
  src: string;
  title: string;
}

// Click-to-load: Spotify's player sets third-party cookies, so it only loads after the visitor asks for it.
export default function SpotifyEmbed({ src, title }: Props) {
  const [loaded, setLoaded] = useState(false);

  if (loaded) {
    return (
      <iframe
        src={`${src}?utm_source=generator&theme=0`}
        title={title}
        width="100%"
        height="452"
        loading="lazy"
        allow="autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture"
        className="rounded-3xl border-0"
      />
    );
  }

  return (
    <div className="flex h-[452px] flex-col items-center justify-center gap-5 rounded-3xl bg-brand-950 px-6 text-center text-brand-100">
      <button
        type="button"
        onClick={() => setLoaded(true)}
        className="inline-flex size-20 items-center justify-center rounded-full bg-white text-brand-900 shadow-lg transition-transform hover:scale-105"
      >
        <span className="sr-only">Načítať prehrávač Spotify</span>
        <svg viewBox="0 0 24 24" className="ml-1 size-8" fill="currentColor" aria-hidden="true">
          <polygon points="6 3 20 12 6 21 6 3" />
        </svg>
      </button>
      <div>
        <p className="font-serif text-2xl text-white">Počúvajte epizódy priamo tu</p>
        <p className="mt-2 max-w-sm text-sm text-brand-200">
          Po kliknutí sa načíta prehrávač Spotify, ktorý môže ukladať vlastné cookies.
        </p>
      </div>
    </div>
  );
}
