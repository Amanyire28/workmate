/**
 * Verification wording shared by the provider app and the admin, so both describe
 * an application or a document the same way. [label, tone, provider-facing detail]
 */
export const VERIFICATION_STATUS = {
    draft:           { label: 'Not sent yet', tone: 'neutral', detail: 'Add your National ID and any supporting documents, then send them for review.' },
    pending_review:  { label: 'Awaiting review', tone: 'warning', detail: 'Submitted. A WorkMate reviewer will check each document.' },
    under_review:    { label: 'Under review', tone: 'info', detail: 'A reviewer is checking your documents.' },
    info_requested:  { label: 'More information needed', tone: 'warning', detail: 'Please replace or add the documents marked below.' },
    approved:        { label: 'Verified', tone: 'success', detail: 'Your documents are approved.' },
    rejected:        { label: 'Not approved', tone: 'error', detail: 'Your documents weren’t approved. The reason is shown below. Change your documents and send them again.' },
    expired:         { label: 'Expired', tone: 'neutral', detail: 'A required document has expired. Upload a current one to stay verified.' },
};

export const DOCUMENT_STATUS = {
    not_uploaded:         { label: 'Not uploaded', tone: 'neutral' },
    uploaded:             { label: 'Uploaded', tone: 'info' },
    awaiting_review:      { label: 'Awaiting review', tone: 'warning' },
    accepted:             { label: 'Accepted', tone: 'success' },
    rejected:             { label: 'Rejected', tone: 'error' },
    replacement_required: { label: 'Replacement required', tone: 'warning' },
    replaced:             { label: 'Replaced', tone: 'neutral' },
    expired:              { label: 'Expired', tone: 'neutral' },
};

/** Where a provider is on the road to the WorkMate Verified badge */
export const PROVIDER_STAGES = [
    ['registered', 'Registered account'],
    ['profile', 'Provider profile created'],
    ['submitted', 'Verification submitted'],
    ['under_review', 'Documents under review'],
    ['verified', 'Verified provider'],
];
