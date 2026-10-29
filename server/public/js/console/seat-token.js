// The Seat tokens page's one script — card#12331. The issue response carries the plaintext once and is
// `Cache-Control: no-store`, which keeps it out of the HTTP cache; a browser may still restore the page from
// its back/forward cache (a page held in memory, not re-fetched). When it does, `pageshow` fires with
// `persisted` true and the token is blanked, so Back after navigating away does not show it again. The page
// works with this script absent; this only narrows a restore from memory.
//
// card#12571 — the *Save token file* button (D6 § 4.2, `docs/design/REPORTER-INSTALL.md`). The server renders
// it on an `https:` site's issue response only, with the ingest URL (from `app.url`, never the request) and the
// seat's ids as attributes. The click builds the v2 token file here, from those attributes and the token
// element's own text, and downloads it as `mezzanine-reporter-token`: no request, URL, session row or log line
// carries the plaintext. A restore removes the button with the token, so a restored page can neither show nor
// save it.
const BLANKED = '(shown once — issue again if you did not copy it)';
const FILE_NAME = 'mezzanine-reporter-token';

/**
 * The v2 token file, D6 § 4.2: the four keyed lines in this order, each ending LF, no CR, no trailing space.
 * The parser takes the keys in any order; this writer emits one order so a saved file is predictable.
 */
function tokenFileV2({ token, ingestUrl, installId, seatId }) {
  return `token ${token}\ningest_url ${ingestUrl}\ninstall_id ${installId}\nseat_id ${seatId}\n`;
}

function save(button) {
  const token = document.querySelector('[data-seat-token]').textContent.trim();
  const { ingestUrl, installId, seatId } = button.dataset;
  const blob = new Blob([tokenFileV2({ token, ingestUrl, installId, seatId })], { type: 'application/octet-stream' });
  const href = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = href;
  link.download = FILE_NAME;
  document.body.append(link);
  link.click();
  link.remove();
  // The object URL holds the token for as long as it is alive; it is released once the download has started.
  setTimeout(() => URL.revokeObjectURL(href), 1000);
}

for (const button of document.querySelectorAll('[data-seat-token-save]')) {
  button.addEventListener('click', () => save(button));
}

window.addEventListener('pageshow', (event) => {
  if (!event.persisted) return;
  for (const el of document.querySelectorAll('[data-seat-token]')) el.textContent = BLANKED;
  for (const el of document.querySelectorAll('[data-seat-token-save]')) el.remove();
});
