import { reactive } from 'vue';
import { api } from '@/api/client.js';

/**
 * The signed-in person's suggestion cards (which card, if any, each page
 * shows) and their recommendations. The server works out what applies from
 * what's saved, so a card goes away by itself once it's done.
 */
export const engagement = reactive({
  userId: null,
  suggestions: null,      // { cards: { key: card }, pages: { page: key|null } }
  recommendations: null,
});

let pending = null;

/** Fetched each time a page with a card opens (the last answer shows meanwhile) */
export function loadSuggestions(userId) {
  if (!userId) { engagement.userId = null; engagement.suggestions = null; engagement.recommendations = null; return Promise.resolve(null); }
  if (engagement.userId !== userId) { engagement.userId = userId; engagement.suggestions = null; engagement.recommendations = null; }
  if (pending) return pending;
  pending = api('/me/suggestions')
    .then(({ data }) => { if (engagement.userId === userId) engagement.suggestions = data; return data; })
    .catch(() => null)   // no card rather than an error
    .finally(() => { pending = null; });
  return pending;
}

/** After something that may complete a suggestion (a post, a service...) */
export function refreshSuggestions() {
  return engagement.userId ? loadSuggestions(engagement.userId) : Promise.resolve(null);
}

export function suggestionFor(page) {
  const s = engagement.suggestions;
  const key = s && s.pages ? s.pages[page] : null;
  return key ? s.cards[key] || null : null;
}

export async function loadRecommendations() {
  try {
    const { data } = await api('/me/recommendations');
    engagement.recommendations = data;
  } catch {
    engagement.recommendations = { jobs: [], posts: [] };
  }
  return engagement.recommendations;
}
