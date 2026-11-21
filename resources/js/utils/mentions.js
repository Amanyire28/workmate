/**
 * @mentions in community text.
 *
 * The server stores plain text plus where each mention sits in it: start and
 * length in UTF-16 code units (JavaScript string positions). While someone
 * writes, the app keeps those places up to date as the text around them
 * changes; on sending, each mention still intact becomes a token, @[u:<id>],
 * which the server checks and turns back into the person's name. A mention
 * whose text is changed or partly deleted stops being a mention (it's just
 * text again), so a mention is always exactly what the person chose.
 */

/** What the server understands as "this person" */
export const mentionToken = (userId) => `@[u:${userId}]`;

/**
 * The text cut into plain parts and mentions, for showing it. Places that
 * don't fit the text (or overlap) are ignored, leaving the text as it is.
 *
 * @returns {Array<{ text: string, mention?: object }>}
 */
export function mentionSegments(text, mentions) {
  const body = text || '';
  const parts = [];
  let at = 0;
  const sorted = [...(mentions || [])].sort((a, b) => a.start - b.start);
  for (const m of sorted) {
    const end = m.start + m.length;
    if (!Number.isInteger(m.start) || !Number.isInteger(m.length) || m.length < 2 || m.start < at || end > body.length) continue;
    if (body[m.start] !== '@') continue;
    if (m.start > at) parts.push({ text: body.slice(at, m.start) });
    parts.push({ text: body.slice(m.start, end), mention: m });
    at = end;
  }
  if (at < body.length) parts.push({ text: body.slice(at) });
  return parts;
}

/**
 * Keeps mention places right after the text changed from `before` to `after`:
 * mentions after the change move with it; one the change touched is dropped.
 */
export function adjustRanges(ranges, before, after) {
  if (!ranges.length || before === after) return ranges;
  let prefix = 0;
  const max = Math.min(before.length, after.length);
  while (prefix < max && before[prefix] === after[prefix]) prefix++;
  let suffix = 0;
  while (suffix < max - prefix && before[before.length - 1 - suffix] === after[after.length - 1 - suffix]) suffix++;
  const changedEnd = before.length - suffix;   // in `before`
  const delta = after.length - before.length;

  return ranges.flatMap((r) => {
    const end = r.start + r.length;
    if (end <= prefix) return [r];                         // before the change
    if (r.start >= changedEnd) return [{ ...r, start: r.start + delta }];   // after it
    // Typing just before a mention, at its very start, moves it along
    if (r.start === prefix && changedEnd === prefix) return [{ ...r, start: r.start + delta }];
    return [];                                              // touched: no longer a mention
  });
}

/** The text to send: each mention still intact becomes its token */
export function encodeMentions(text, ranges) {
  let out = text;
  const sorted = [...ranges].sort((a, b) => b.start - a.start);
  for (const r of sorted) {
    if (out.slice(r.start, r.start + 1) !== '@') continue;
    out = out.slice(0, r.start) + mentionToken(r.userId) + out.slice(r.start + r.length);
  }
  return out;
}

/**
 * The "@name" being typed just before the caret, or null. An @ counts at the
 * start or after a space or bracket (so email addresses don't), and a name
 * may have up to two spaces in it ("@Sarah Na…").
 */
export function findMentionQuery(text, caret, ranges = []) {
  const from = Math.max(0, caret - 41);
  const at = text.slice(from, caret).lastIndexOf('@');
  if (at < 0) return null;
  const start = from + at;
  if (start > 0 && !/[\s([{"'“‘]/.test(text[start - 1])) return null;
  const query = text.slice(start + 1, caret);
  if (!query || /^\s/.test(query) || /[\n@]/.test(query) || (query.match(/ /g) || []).length > 2) return null;
  // Inside a mention already chosen
  if (ranges.some(r => start >= r.start && start < r.start + r.length)) return null;
  return { start, query };
}
