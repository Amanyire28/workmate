/**
 * Module definitions by navigation id. Each says which workspace renders it
 * (records, analytics, settings or roles) and supplies its columns, filters,
 * figures, detail sections and permission-checked actions.
 */
import overview from './overview.js';
import marketplace from './marketplace.js';
import location from './location.js';
import trust from './trust.js';
import finance from './finance.js';
import content from './content.js';
import comms from './comms.js';
import analytics from './analytics.js';
import system from './system.js';
import administration from './administration.js';
import verification from './verification.js';
import reputation from './reputation.js';
import usage from './usage.js';
import referrals from './referrals.js';

import { connectActions } from './connected.js';

// Actions listed in connected.js call the Laravel API; others aren't offered yet
export const modules = connectActions({
    ...overview, ...marketplace, ...location, ...trust, ...finance,
    ...content, ...comms, ...analytics, ...system, ...administration,
    ...verification, ...reputation, ...usage, ...referrals,
});
