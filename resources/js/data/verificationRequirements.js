/**
 * Document verification requirements per profession (service category).
 *
 * Published versions are stored by the server (GET /api/v1/admin/document-requirements);
 * administrators change them in Admin → Verification → Document Requirements.
 * The National ID isn't listed: every provider always needs it. Applications
 * keep the version they were sent under, so changing requirements never rewrites them.
 */

/** Conditions that make a "conditional" document required. "business" comes from the provider type. */
export const CONDITIONS = {
    own_vehicle: { label: 'Uses their own vehicle for the work', question: 'Do you use your own vehicle for this work?' },
    business:    { label: 'Registering as a business or team', question: 'Are you registering as a business or a team?' },
};

// What the server can read and check (photos are re-drawn as JPEG)
export const FORMAT_OPTIONS = ['jpg', 'png', 'pdf'];
export const MAX_SIZE_MB = 8;
export const MAX_COUNT = 5;

/** Reusable document types; a profession's requirement copies one and adjusts it */
export const DOCUMENT_LIBRARY = {
    driving_permit: {
        name: 'Driving permit', instructions: 'Both sides of your driving permit, showing the classes you are allowed to drive.',
        formats: ['jpg', 'png', 'pdf'], maxSizeMb: 5, count: 1, frontBack: true, multiPage: false, expiry: { required: true, minMonthsValid: 3 },
    },
    vehicle_registration: {
        name: 'Vehicle registration', instructions: 'The registration document (logbook) for the vehicle you use for work. Include every page.',
        formats: ['jpg', 'png', 'pdf'], maxSizeMb: 8, count: 1, frontBack: false, multiPage: true, expiry: { required: false, minMonthsValid: 0 },
    },
    vehicle_insurance: {
        name: 'Vehicle insurance', instructions: 'Your current insurance certificate or sticker for the same vehicle.',
        formats: ['jpg', 'png', 'pdf'], maxSizeMb: 5, count: 1, frontBack: false, multiPage: false, expiry: { required: true, minMonthsValid: 1 },
    },
    trade_certificate: {
        name: 'Trade or skills certificate', instructions: 'A certificate showing your training or qualification in this trade.',
        formats: ['jpg', 'png', 'pdf'], maxSizeMb: 8, count: 1, frontBack: false, multiPage: true, expiry: { required: false, minMonthsValid: 0 },
    },
    business_registration: {
        name: 'Business registration', instructions: 'Your business registration certificate, in the name shown on your WorkMate profile.',
        formats: ['pdf', 'jpg', 'png'], maxSizeMb: 8, count: 1, frontBack: false, multiPage: true, expiry: { required: false, minMonthsValid: 0 },
    },
    reference: {
        name: 'Reference or past work', instructions: 'A reference letter from a past client or employer, or photos of work you have done.',
        formats: ['jpg', 'png', 'pdf'], maxSizeMb: 8, count: 2, frontBack: false, multiPage: true, expiry: { required: false, minMonthsValid: 0 },
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

/** Documents that apply to a provider, given their answers to the condition questions */
export function applicableDocuments(version, answers = {}) {
    return version.documents.filter(d => d.enabled && (d.requirement !== 'conditional' || answers[d.condition]));
}

/** Whether a document must be provided (mandatory, or conditional and the condition applies) */
export function isRequired(doc, answers = {}) {
    return doc.requirement === 'mandatory' || (doc.requirement === 'conditional' && !!answers[doc.condition]);
}
