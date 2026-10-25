// The Seat tokens page's one script — card#12331. The issue response carries the plaintext once and is
// `Cache-Control: no-store`, which keeps it out of the HTTP cache; a browser may still restore the page from
// its back/forward cache (a page held in memory, not re-fetched). When it does, `pageshow` fires with
// `persisted` true and the token is blanked, so Back after navigating away does not show it again. The page
// works with this script absent; this only narrows a restore from memory.
const BLANKED = '(shown once — issue again if you did not copy it)';

window.addEventListener('pageshow', (event) => {
  if (!event.persisted) return;
  for (const el of document.querySelectorAll('[data-seat-token]')) el.textContent = BLANKED;
});
