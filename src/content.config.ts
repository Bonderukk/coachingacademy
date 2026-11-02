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

const pravne = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/pravne' }),
  schema: z.object({
    title: z.string(),
    description: z.string(),
    order: z.number(),
  }),
});

export const collections = { vycviky, sluzby, referencie, pravne };
