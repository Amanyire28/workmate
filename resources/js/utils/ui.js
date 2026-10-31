/**
 * Presentation helpers shared across pages.
 * Keeps icon choices and display formatting out of the data store so the
 * data stays exactly as it comes from the API.
 */
import { getCategoryById, statusLabels } from '@/stores/catalog.js';

// Bootstrap Icons class per category id
export const categoryIcons = {
    construction: 'bi-bricks',
    cleaning:     'bi-stars',
    plumbing:     'bi-droplet',
    electrical:   'bi-lightning-charge',
    moving:       'bi-box-seam',
    carpentry:    'bi-hammer',
    painting:     'bi-palette',
    repairs:      'bi-tools',
    gardening:    'bi-flower1',
    driving:      'bi-truck',
};

// Soft tile background + icon colour per category (icons only, never text)
export const categoryColors = {
    plumbing:     { bg: '#EFF6FF', fg: '#1769D2' },
    electrical:   { bg: '#FFF6E5', fg: '#D97706' },
    cleaning:     { bg: '#ECFDF3', fg: '#15803D' },
    moving:       { bg: '#FFF1E7', fg: '#EA580C' },
    carpentry:    { bg: '#F3EEFF', fg: '#7C3AED' },
    painting:     { bg: '#E6FAF8', fg: '#0F9488' },
    repairs:      { bg: '#FDEEF1', fg: '#BE123C' },
    driving:      { bg: '#EEF2FF', fg: '#4338CA' },
    construction: { bg: '#F1F5F9', fg: '#475569' },
    gardening:    { bg: '#ECFDF3', fg: '#16A34A' },
};

export function categoryColor(id) {
    return categoryColors[id] || { bg: '#F1F5F9', fg: '#475569' };
}

export function categoryIcon(id) {
    return categoryIcons[id] || 'bi-briefcase';
}

export function categoryLabel(id) {
    return getCategoryById(id).label;
}

/** "Plumbing", or "Construction, Carpentry +1" for multi-service providers */
export function tradeLabel(provider, max = 2) {
    const labels = provider.services.map(categoryLabel);
    if (labels.length <= max) return labels.join(', ');
    return `${labels.slice(0, max).join(', ')} +${labels.length - max}`;
}

export function displayName(provider) {
    return provider.businessName || provider.name;
}

export function initials(name = '') {
    const parts = name.trim().split(/\s+/).filter(p => /^[A-Za-z]/.test(p));
    return ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase() || '?';
}

export const providerTypeLabels = {
    individual: 'Individual',
    team:       'Team',
    business:   'Business',
};

export function formatPrice(amount) {
    if (amount == null || amount === '') return null;
    return `UGX ${Number(amount).toLocaleString('en-UG')}`;
}

// Map status keys to pill tones
const statusTones = {
    open:        'info',
    waiting:     'warning',
    pending:     'warning',
    in_progress: 'info',
    accepted:    'success',
    completed:   'success',
    cancelled:   'error',
};

export function statusInfo(status) {
    return {
        label: statusLabels[status]?.label || status,
        tone:  statusTones[status] || 'neutral',
    };
}
