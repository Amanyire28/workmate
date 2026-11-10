/**
 * One vocabulary for every status in the admin: [label, tone]. Badges, filters and
 * exports all read from here, so the same state always looks and reads the same.
 */
export const STATUS = {
    // accounts
    active: ['Active', 'success'], inactive: ['Inactive', 'neutral'], suspended: ['Suspended', 'error'],
    restricted: ['Restricted', 'warning'], deactivated: ['Deactivated', 'neutral'],
    // provider applications and checks
    submitted: ['Application submitted', 'info'], pending_review: ['Pending review', 'warning'],
    info_requested: ['More info requested', 'warning'], approved: ['Approved', 'success'], rejected: ['Rejected', 'error'],
    added: ['Added', 'success'], declined: ['Declined', 'neutral'],
    // invitations (referrals)
    registered: ['Waiting for email', 'neutral'], review: ['Needs a decision', 'warning'], qualified: ['Counted', 'success'], revoked: ['Revoked', 'error'],
    verified: ['Verified', 'success'], not_provided: ['Not provided', 'neutral'], not_checked: ['Not checked', 'neutral'], failed_check: ['Failed', 'error'],
    // jobs, quotes and bookings
    open: ['Open', 'info'], booked: ['Booked', 'success'], expired: ['Expired', 'neutral'], searching: ['Searching', 'info'],
    matched: ['Matched', 'success'], no_match_yet: ['No match yet', 'warning'], no_match: ['No match', 'warning'],
    requested: ['Requested', 'info'], awaiting_provider: ['Awaiting provider', 'warning'], accepted: ['Accepted', 'success'],
    scheduled: ['Scheduled', 'info'], in_progress: ['In progress', 'info'], completed: ['Completed', 'success'],
    cancelled: ['Cancelled', 'neutral'], disputed: ['Disputed', 'error'], withdrawn: ['Withdrawn', 'neutral'],
    // payments
    pending: ['Pending', 'warning'], settled: ['Settled', 'success'], failed: ['Failed', 'error'], paid: ['Paid', 'success'],
    unmatched: ['Unmatched', 'warning'], duplicate: ['Duplicate', 'error'], refunded: ['Refunded', 'neutral'], processed: ['Processed', 'success'],
    held: ['On hold', 'warning'], exception: ['Exception', 'error'], partial: ['Partial period', 'info'], closed: ['Closed', 'neutral'],
    // cases and moderation
    new: ['New', 'info'], under_review: ['Under review', 'info'], awaiting_information: ['Awaiting information', 'warning'],
    escalated: ['Escalated', 'error'], resolved: ['Resolved', 'success'], acknowledged: ['Acknowledged', 'info'], ignored: ['Ignored', 'neutral'],
    published: ['Published', 'success'], hidden: ['Hidden', 'neutral'], reported: ['Reported', 'warning'], removed: ['Removed', 'error'],
    uploading: ['Uploading', 'info'], processing: ['Processing', 'info'], pending_moderation: ['Pending moderation', 'warning'],
    processing_failed: ['Processing failed', 'error'], draft: ['Draft', 'neutral'],
    // translations, coverage, system
    missing: ['Missing', 'warning'], needs_review: ['Needs review', 'info'],
    gap: ['Coverage gap', 'error'], thin: ['Thin coverage', 'warning'], covered: ['Covered', 'success'], no_demand: ['No demand yet', 'neutral'],
    sample_ok: ['Sample: OK', 'neutral'], degraded: ['Sample: degraded', 'warning'], not_connected: ['Not connected', 'neutral'],
    sent: ['Sent', 'success'], sending: ['Sending', 'info'], delivered: ['Delivered', 'success'], undelivered: ['Undelivered', 'error'], unknown: ['Unknown', 'neutral'], not_sent: ['Not sent', 'error'],
    // document verification
    none: ['Not applied', 'neutral'], awaiting_review: ['Awaiting review', 'warning'],
    replacement_required: ['Replacement required', 'warning'], not_uploaded: ['Not uploaded', 'neutral'], uploaded: ['Uploaded', 'info'],
    // blue tick
    awarded: ['Awarded', 'info'], eligible: ['Eligible', 'info'], approaching: ['Approaching', 'neutral'], not_eligible: ['Not eligible', 'neutral'],
    // presence and availability (separate things)
    online: ['Online', 'success'], recently_active: ['Recently active', 'info'], offline: ['Offline', 'neutral'],
    available: ['Available', 'success'], busy: ['Busy', 'warning'], unavailable: ['Unavailable', 'neutral'],
    // rule results
    pass: ['Passed', 'success'], fail: ['Failed', 'error'], not_evaluated: ['Not evaluated', 'neutral'],
    // severity and outcome
    critical: ['Critical', 'error'], high: ['High', 'error'], medium: ['Medium', 'warning'], low: ['Low', 'neutral'],
    warning: ['Warning', 'warning'], info: ['Info', 'neutral'], success: ['Succeeded', 'success'], failure: ['Failed', 'error'],
};

export function statusLabel(key) {
    return STATUS[key]?.[0] || String(key).replace(/_/g, ' ').replace(/^\w/, c => c.toUpperCase());
}

/** Filter options for a list of status keys */
export function statusOptions(keys) {
    return keys.map(k => [k, statusLabel(k)]);
}
