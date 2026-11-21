<script>
import { h, Fragment } from 'vue';
import { RouterLink } from 'vue-router';
import { mentionSegments } from '@/utils/mentions.js';

/**
 * Community text with its @mentions as links, built from text nodes and
 * links (never HTML), so whatever someone typed stays plain text. Goes inside
 * the element that has the text's own styling (line clamps, line breaks).
 * A tap on a mention opens that person and nothing else (not the post or video).
 */
export default {
  name: 'MentionText',
  props: {
    text: { type: String, default: '' },
    mentions: { type: Array, default: () => [] },
    // On a dark video: a lighter blue
    dark: { type: Boolean, default: false },
  },
  emits: ['navigate'],
  setup(props, { emit }) {
    const linkClass = () => ['wm-mention', props.dark ? 'wm-mention-dark' : ''];
    const stop = (e) => { e.stopPropagation(); emit('navigate'); };

    return () => h(Fragment, mentionSegments(props.text, props.mentions).map((part) => {
      const m = part.mention;
      if (!m) return part.text;
      const to = m.provider_id ? `/provider/${m.provider_id}` : (m.linkable ? `/community/people/${m.user_id}` : null);
      if (!to) return h('span', { class: linkClass() }, part.text);
      return h(RouterLink, { to, class: linkClass(), onClick: stop }, () => part.text);
    }));
  },
};
</script>
