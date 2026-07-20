function escapeHtml(str) {
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/** Renders a chat message body as safe HTML, wrapping @Name mentions in a highlighted span — like
 * Discord, anyone can @ a name and it's highlighted; it's brighter when it matches your own
 * character name so a ping is obvious at a glance.
 *
 * `candidateNames` (the same roster used for the @ autocomplete) lets multi-word names — character
 * names allow spaces, up to 30 chars — get matched as one mention instead of just the first word.
 * Any name not in the roster still falls back to the plain single-word match, so @-ing someone
 * outside the currently known list (e.g. in world chat) still highlights. */
export function renderChatBody(body, myName, candidateNames = []) {
  const escaped = escapeHtml(body ?? '');

  const known = [...new Set([myName, ...candidateNames].filter(Boolean))]
    .sort((a, b) => b.length - a.length)
    .map((name) => name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));

  const pattern = new RegExp(`@(${[...known, '[A-Za-z0-9_]{2,20}'].join('|')})`, 'g');

  return escaped.replace(pattern, (match, name) => {
    const isMe = !!myName && name.toLowerCase() === myName.toLowerCase();
    return `<span class="chat-mention${isMe ? ' chat-mention--me' : ''}">@${name}</span>`;
  });
}

/** Whether a message body contains an @mention of the given character name — used to highlight
 * the whole chat line so a ping doesn't get lost while scrolling past. */
export function mentionsMe(body, myName) {
  if (!myName || !body) return false;
  const escapedName = myName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return new RegExp(`@${escapedName}\\b`, 'i').test(body);
}
