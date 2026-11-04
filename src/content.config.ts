import { defineCollection } from 'astro:content';
import { glob, file } from 'astro/loaders';
import { z } from 'astro/zod';

const vycviky = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/vycviky' }),
  schema: ({ image }) =>
    z.object({
      title: z.string(),
      eyebrow: z.string(),
      // <title> and meta description — written for Google, keep description under ~155 chars
      metaTitle: z.string(),
      description: z.string(),
      summary: z.string(),
      order: z.number(),
      badge: image().optional(),
      image: image().optional(),
      facts: z.array(z.object({ label: z.string(), value: z.string() })),
      prices: z.array(z.object({ label: z.string(), amount: z.string(), note: z.string().optional() })),
      schedules: z
        .array(
          z.object({
            title: z.string(),
            note: z.string().optional(),
            items: z.array(z.object({ name: z.string(), date: z.string() })),
          }),
        )
        .default([]),
      startDate: z.coerce.date().optional(),
      priceFrom: z.number().optional(),
      lecturers: z.array(z.string()).default(['Dalibor Bednařík']),
    }),
});

const sluzby = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/sluzby' }),
  schema: ({ image }) =>
    z.object({
      title: z.string(),
      eyebrow: z.string().default('Služby'),
      metaTitle: z.string(),
      description: z.string(),
      summary: z.string(),
      order: z.number(),
      image: image(),
      imageAlt: z.string(),
      // CSS object-position for cropped previews, e.g. 'top' so a face isn't cut off on the 3:2 service card
      imagePosition: z.string().default('center'),
      cta: z.string().default('Mám záujem'),
    }),
});

const referencie = defineCollection({
  loader: file('./src/content/referencie.yaml'),
  schema: ({ image }) =>
    z.object({
      name: z.string(),
      role: z.string(),
      quote: z.string(),
      photo: image().optional(),
      featured: z.boolean().default(false),
    }),
});

// Books from the iSPAK store sold here as well. The KOUČING book has its own page (src/pages/kniha.astro).
const knihy = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/knihy' }),
  schema: ({ image }) =>
    z.object({
      title: z.string(),
      subtitle: z.string(),
      authors: z.array(z.string()),
      publisher: z.string().default('iSPAK – Inštitút systemickej práce a konštelácií'),
      metaTitle: z.string(),
      description: z.string(),
      summary: z.string(),
      order: z.number(),
      image: image(),
      imageAlt: z.string(),
      gallery: z.array(z.object({ src: image(), alt: z.string() })).default([]),
      // Each format can have its own Stripe Payment Link; without one the order goes by e-mail.
      variants: z.array(
        z.object({
          label: z.string(),
          price: z.number(),
          digital: z.boolean().default(false),
          stripeLink: z.url().optional(),
        }),
      ),
    }),
});

// Curriculum vitae on /o-mne, grouped by field.
const cv = defineCollection({
  loader: file('./src/content/cv.yaml'),
  schema: z.object({
    title: z.string(),
    order: z.number(),
    items: z.array(z.object({ year: z.string().optional(), text: z.string() })),
  }),
});

const pravne = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/pravne' }),
  schema: z.object({
    title: z.string(),
    description: z.string(),
    order: z.number(),
  }),
});

export const collections = { vycviky, sluzby, referencie, knihy, cv, pravne };
