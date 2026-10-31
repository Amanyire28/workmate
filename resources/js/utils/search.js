/**
 * Everyday words people type when looking for a provider, per category.
 * Lets "plumber" find Plumbing providers even when the profile only says "plumbing".
 */
export const categoryKeywords = {
    construction: ['builder', 'mason', 'masonry', 'bricklayer', 'roofing', 'tiling', 'plastering', 'renovation'],
    cleaning:     ['cleaner', 'maid', 'housekeeping', 'laundry', 'deep clean'],
    plumbing:     ['plumber', 'pipe', 'leak', 'tap', 'drain', 'blockage', 'water tank'],
    electrical:   ['electrician', 'wiring', 'socket', 'lights', 'solar', 'power'],
    moving:       ['mover', 'movers', 'removal', 'relocation', 'truck', 'loading', 'packing'],
    carpentry:    ['carpenter', 'furniture', 'wardrobe', 'door', 'cabinet', 'woodwork'],
    painting:     ['painter', 'paint', 'decorator'],
    repairs:      ['technician', 'fundi', 'repair', 'fix', 'fridge', 'appliance', 'tv'],
    gardening:    ['gardener', 'landscaping', 'lawn', 'compound'],
    driving:      ['driver', 'delivery', 'transport', 'boda'],
};

/** Category ids whose label or keywords match the query, e.g. "plumber" → ['plumbing'] */
export function categoriesMatching(query, categories) {
    const q = query.trim().toLowerCase();
    if (!q) return [];
    return categories
        .filter(c => {
            const words = [c.label.toLowerCase(), c.id, ...(categoryKeywords[c.id] || [])];
            return words.some(w => w.includes(q) || q.includes(w));
        })
        .map(c => c.id);
}
