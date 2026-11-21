import { ref, reactive, watch, nextTick } from 'vue';
import { api } from '@/api/client.js';
import { adjustRanges, encodeMentions, findMentionQuery } from '@/utils/mentions.js';

/** The most people one post or comment can mention (the server checks too) */
export const MAX_MENTIONS = 5;

// Recent suggestions, so going back over a name doesn't ask again
const remembered = new Map();
const REMEMBER = 40;

/**
 * @mentions for one text box: suggestions while typing "@name", keeping the
 * chosen people in place as the text changes, and the text to send.
 *
 * @param {import('vue').Ref<string>} text  the box's v-model
 */
export function useMentions(text, { max = MAX_MENTIONS } = {}) {
  const ranges = ref([]);           // { start, length, userId }
  const state = reactive({
    open: false,
    query: '',
    results: [],
    loading: false,
    active: 0,
    full: false,                    // already mentioning the most people allowed
  });
  let field = null;
  let start = -1;
  let last = text.value || '';
  let timer = null;
  let request = null;

  // Every change to the text, however it happened: mentions move with it, or stop being mentions
  watch(text, (value) => {
    const now = value || '';
    if (now !== last) {
      ranges.value = adjustRanges(ranges.value, last, now);
      last = now;
    }
  }, { flush: 'sync' });

  const people = () => new Set(ranges.value.map(r => r.userId)).size;

  /** The text box (call from a template ref) */
  function bind(el) {
    field = el || null;
  }

  /** After typing or moving the caret: is someone writing "@name"? */
  function update() {
    if (!field || field.selectionStart !== field.selectionEnd) { close(); return; }
    const found = findMentionQuery(text.value || '', field.selectionStart, ranges.value);
    if (!found) { close(); return; }
    start = found.start;
    state.query = found.query;
    state.open = true;
    state.full = people() >= max;
    if (state.full) {
      state.results = [];
      state.loading = false;
      return;
    }
    search(found.query);
  }

  function search(query) {
    clearTimeout(timer);
    request?.abort();
    const key = query.trim().toLowerCase();
    if (remembered.has(key)) {
      show(remembered.get(key), query);
      return;
    }
    state.loading = true;
    // A short pause while typing, and only the latest search counts
    timer = setTimeout(async () => {
      request = new AbortController();
      try {
        const r = await api(`/community/mention-suggestions?q=${encodeURIComponent(query.trim())}`, { signal: request.signal });
        if (remembered.size >= REMEMBER) remembered.delete(remembered.keys().next().value);
        remembered.set(key, r.data);
        if (state.query === query) show(r.data, query);
      } catch (e) {
        if (e?.name === 'AbortError') return;
        if (state.query === query) { state.loading = false; state.results = []; }
      }
    }, 220);
  }

  function show(results, query) {
    state.loading = false;
    // Still nobody with a longer name: they're probably just writing, so get out of the way
    if (!results.length && /\s/.test(query.trim())) { close(); return; }
    state.results = results;
    state.active = 0;
  }

  /** Puts the person chosen in place of the "@name" being typed */
  function choose(person) {
    if (!field || start < 0) return;
    const value = text.value || '';
    const caret = field.selectionStart;
    const before = value.slice(0, start);
    const after = value.slice(caret);
    const label = `@${person.name}`;
    const insert = label + (after.startsWith(' ') ? '' : ' ');
    const next = before + insert + after;

    ranges.value = [
      ...adjustRanges(ranges.value, value, next),
      { start: before.length, length: label.length, userId: person.id },
    ].sort((a, b) => a.start - b.start);
    last = next;
    text.value = next;
    close();
    const el = field;
    nextTick(() => {
      el.focus({ preventScroll: true });
      el.setSelectionRange(before.length + insert.length, before.length + insert.length);
      el.dispatchEvent(new Event('input', { bubbles: true }));
    });
  }

  /** Arrow keys, Enter or Tab to choose, Escape to dismiss; true when it used the key */
  function onKeydown(e) {
    if (!state.open) return false;
    const n = state.results.length;
    if (e.key === 'ArrowDown' && n) { state.active = (state.active + 1) % n; e.preventDefault(); return true; }
    if (e.key === 'ArrowUp' && n) { state.active = (state.active - 1 + n) % n; e.preventDefault(); return true; }
    if ((e.key === 'Enter' || e.key === 'Tab') && n && !e.shiftKey) { e.preventDefault(); choose(state.results[state.active]); return true; }
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); return true; }
    return false;
  }

  function close() {
    clearTimeout(timer);
    request?.abort();
    state.open = false;
    state.loading = false;
    state.results = [];
    state.full = false;
    start = -1;
  }

  /** The text with its mentions, as the server takes it */
  function encoded() {
    return encodeMentions(text.value || '', ranges.value);
  }

  /** Starts from saved text (editing a post) or a prepared one (replying to someone) */
  function load(body, mentions = []) {
    last = body || '';
    text.value = last;
    ranges.value = (mentions || [])
      .filter(m => last.slice(m.start, m.start + 1) === '@' && m.start + m.length <= last.length)
      .map(m => ({ start: m.start, length: m.length, userId: m.user_id }));
    close();
  }

  function reset() {
    ranges.value = [];
    close();
  }

  return { state, ranges, bind, update, choose, onKeydown, close, encoded, load, reset };
}
