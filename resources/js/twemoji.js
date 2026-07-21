import twemoji from 'twemoji';

// jsdelivr mirrors twemoji's assets reliably; the package's own default base (twemoji.maxcdn.com)
// has a history of downtime, and SVGs stay crisp at any icon size unlike a fixed 72x72 PNG.
const TWEMOJI_OPTIONS = {
  base: 'https://cdn.jsdelivr.net/gh/twitter/twemoji@14.0.2/assets/',
  folder: 'svg',
  ext: '.svg',
  className: 'twemoji-icon',
};

export function parseEmoji(el) {
  twemoji.parse(el, TWEMOJI_OPTIONS);
}

// Applied once on the app root (see App.vue) — re-parses on every re-render so newly-rendered emoji
// (glyphs on items/skills/etc. that mount later) get converted too, without every page needing its
// own opt-in.
export const twemojiDirective = {
  mounted(el) {
    parseEmoji(el);
  },
  updated(el) {
    parseEmoji(el);
  },
};
