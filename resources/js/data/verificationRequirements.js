/**
 * Document verification requirements per profession (service category).
 *
 * SAMPLE CONFIGURATION for the prototype. These are not legal requirements:
 * WorkMate administrators set the real ones in Admin → Verification → Document
 * Requirements, which publishes new versions. Applications keep a snapshot of the
 * version they were submitted under, so changing requirements never rewrites them.
 */

/** Conditions that make a "conditional" document required, asked of the provider */
export const CONDITIONS = {
    own_vehicle: { label: 'Uses their own vehicle for the work', question: 'Do you use your own vehicle for this work?' },
    business:    { label: 'Registering as a business or team', question: 'Are you registering as a business or a team?' },
};

export const FORMAT_OPTIONS = ['jpg', 'png', 'pdf', 'heic'];

/** Reusable document types; a profession's requirement copies one and adjusts it */
export const DOCUMENT_LIBRARY = {
    national_id: {
        name: 'National ID', instructions: 'A clear photo of both sides of your national ID. All four corners must be visible and the text readable.',
        formats: ['jpg', 'png', 'pdf'], maxSizeMb: 5, count: 1, frontBack: true, multiPage: false, expiry: { required: true, minMonthsValid: 1 },
    },
    driving_permit: {
        name: 'Driving permit', instructions: 'Both sides of your driving permit, showing the classes you are allowed to drive.',
        formats: ['jpg', 'png', 'pdf'], maxSizeMb: 5, count: 1, frontBack: true, multiPage: false, expiry: { required: true, minMonthsValid: 3 },
    },
    vehicle_registration: {
        name: 'Vehicle registration', instructions: 'The registration document (logbook) for the vehicle you use for work. Include every page.',
        formats: ['jpg', 'png', 'pdf'], maxSizeMb: 10, count: 1, frontBack: false, multiPage: true, expiry: { required: false, minMonthsValid: 0 },
    },
    vehicle_insurance: {
        name: 'Vehicle insurance', instructions: 'Your current insurance certificate or sticker for the same vehicle.',
        formats: ['jpg', 'png', 'pdf'], maxSizeMb: 5, count: 1, frontBack: false, multiPage: false, expiry: { required: true, minMonthsValid: 1 },
    },
    trade_certificate: {
        name: 'Trade or skills certificate', instructions: 'A certificate showing your training or qualification in this trade.',
        formats: ['jpg', 'png', 'pdf'], maxSizeMb: 10, count: 1, frontBack: false, multiPage: true, expiry: { required: false, minMonthsValid: 0 },
    },
    business_registration: {
        name: 'Business registration', instructions: 'Your business registration certificate, in the name shown on your WorkMate profile.',
        formats: ['pdf', 'jpg', 'png'], maxSizeMb: 10, count: 1, frontBack: false, multiPage: true, expiry: { required: false, minMonthsValid: 0 },
    },
    reference: {
        name: 'Reference or past work', instructions: 'A reference letter from a past client or employer, or photos of work you have done.',
        formats: ['jpg', 'png', 'pdf'], maxSizeMb: 10, count: 2, frontBack: false, multiPage: true, expiry: { required: false, minMonthsValid: 0 },
    },
    supporting: {
        name: 'Additional supporting document', instructions: 'Anything else that supports your application.',
        formats: ['jpg', 'png', 'pdf'], maxSizeMb: 10, count: 1, frontBack: false, multiPage: true, expiry: { required: false, minMonthsValid: 0 },
    },
};

/** A requirement entry: a library document plus how this profession uses it */
export function requirement(type, requirementLevel, extra = {}) {
    return {
        id: type, type, ...structuredClone(DOCUMENT_LIBRARY[type]),
        requirement: requirementLevel,           // mandatory | optional | conditional
        condition: null, enabled: true, ...extra,
    };
}

const base = [requirement('national_id', 'mandatory'), requirement('business_registration', 'conditional', { condition: 'business' }), requirement('reference', 'optional')];
const published = (version, daysAgo, by, note, documents) => ({ version, publishedAt: new Date(Date.now() - daysAgo * 86400000).toISOString(), publishedBy: by, note, documents });

export const DEFAULT_VERSIONS = {
    driving: [
        published(1, 140, 'Mubiru Ian', 'First requirements for drivers', [
            requirement('national_id', 'mandatory'), requirement('driving_permit', 'mandatory'),
            requirement('vehicle_registration', 'conditional', { condition: 'own_vehicle' }), requirement('supporting', 'optional'),
        ]),
        published(2, 21, 'Okoth Sharon', 'Added vehicle insurance for drivers using their own vehicle', [
            requirement('national_id', 'mandatory'), requirement('driving_permit', 'mandatory'),
            requirement('vehicle_registration', 'conditional', { condition: 'own_vehicle' }),
            requirement('vehicle_insurance', 'conditional', { condition: 'own_vehicle' }), requirement('supporting', 'optional'),
        ]),
    ],
    electrical: [published(1, 140, 'Mubiru Ian', 'First requirements for electricians', [
        requirement('national_id', 'mandatory'),
        requirement('trade_certificate', 'mandatory', { name: 'Electrical trade or wiring certificate', instructions: 'Your electrical installation certificate or trade test certificate.' }),
        requirement('business_registration', 'conditional', { condition: 'business' }), requirement('reference', 'optional'),
    ])],
    plumbing: [published(1, 140, 'Mubiru Ian', 'First requirements for plumbers', [
        requirement('national_id', 'mandatory'), requirement('trade_certificate', 'optional'),
        requirement('business_registration', 'conditional', { condition: 'business' }), requirement('reference', 'optional'),
    ])],
    construction: [published(1, 140, 'Mubiru Ian', 'First requirements for builders', [
        requirement('national_id', 'mandatory'), requirement('trade_certificate', 'optional'),
        requirement('business_registration', 'conditional', { condition: 'business' }), requirement('reference', 'optional'),
    ])],
    moving: [published(1, 140, 'Mubiru Ian', 'First requirements for movers', [
        requirement('national_id', 'mandatory'),
        requirement('vehicle_registration', 'conditional', { condition: 'own_vehicle' }),
        requirement('business_registration', 'conditional', { condition: 'business' }), requirement('reference', 'optional'),
    ])],
    ...Object.fromEntries(['cleaning', 'carpentry', 'painting', 'repairs', 'gardening'].map(id => [id, [published(1, 140, 'Mubiru Ian', 'First requirements', structuredClone(base))]])),
};

/** Documents that apply to a provider, given their answers to the condition questions */
export function applicableDocuments(version, answers = {}) {
    return version.documents.filter(d => d.enabled && (d.requirement !== 'conditional' || answers[d.condition]));
}

/** Whether a document must be provided (mandatory, or conditional and the condition applies) */
export function isRequired(doc, answers = {}) {
    return doc.requirement === 'mandatory' || (doc.requirement === 'conditional' && !!answers[doc.condition]);
}
