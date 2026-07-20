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
 * character name so a ping is obvious at a glance. */
export function renderChatBody(body, myName) {
  const escaped = escapeHtml(body ?? '');
  return escaped.replace(/@([A-Za-z0-9_]{2,20})/g, (match, name) => {
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
