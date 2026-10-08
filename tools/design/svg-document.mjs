// THE ONE READER OF A STANDALONE SVG DOCUMENT, for every generator that hands the painter one — the creature
// tree (`resources/characters/`, AT-D3-24's `tools/characters/selftest.mjs`) and each floor theme
// (`resources/floor/themes/`, AT-D3-25's `tools/floor-themes/selftest.mjs`). Node, no dependencies.
//
// `wellFormed(doc)` is a strict XML well-formedness reader — what the browser's SVG decoder needs, refused
// by name: every tag closed and nested, every `&` an entity, an `<svg>` root carrying
// `xmlns="http://www.w3.org/2000/svg"`. It answers `null` for a document that passes, else the defect.
// `vectorDefect(doc)` answers what keeps a document from being self-contained vector — an `<image>`, a
// `data:` URI, an `href` or a `url()` that leaves the document — or `null`.

const ENTITY = /^&(?:amp|lt|gt|quot|apos|#\d+|#x[0-9a-fA-F]+);/;
export function wellFormed(doc) {
  const stack = [];
  let i = 0, root = null;
  while (i < doc.length) {
    const lt = doc.indexOf('<', i);
    const text = doc.slice(i, lt < 0 ? doc.length : lt);
    for (let j = text.indexOf('&'); j >= 0; j = text.indexOf('&', j + 1)) {
      if (!ENTITY.test(text.slice(j))) return `an unescaped "&" in text near ${JSON.stringify(text.slice(j, j + 16))}`;
    }
    if (lt < 0) break;
    const gt = doc.indexOf('>', lt);
    if (gt < 0) return 'an unterminated tag';
    const tag = doc.slice(lt + 1, gt);
    if (tag.startsWith('/')) {
      const name = tag.slice(1).trim();
      const open = stack.pop();
      if (open !== name) return open === undefined ? `a closing </${name}> that closes nothing open` : `an unclosed <${open}> before </${name}>`;
    } else {
      const self = tag.endsWith('/');
      const m = tag.replace(/\/$/, '').match(/^([A-Za-z][\w:-]*)((?:\s+[\w:-]+="[^"<]*")*)\s*$/);
      if (!m) return `a malformed tag <${tag.slice(0, 40)}>`;
      for (const [, v] of m[2].matchAll(/="([^"]*)"/g)) {
        for (let j = v.indexOf('&'); j >= 0; j = v.indexOf('&', j + 1)) {
          if (!ENTITY.test(v.slice(j))) return `an unescaped "&" in an attribute of <${m[1]}>`;
        }
      }
      if (root === null) root = { name: m[1], attrs: m[2] };
      if (!self) stack.push(m[1]);
    }
    i = gt + 1;
  }
  if (stack.length) return `unclosed <${stack.join('>, <')}>`;
  if (root === null || root.name !== 'svg') return 'the root is not <svg>';
  if (!/\sxmlns="http:\/\/www\.w3\.org\/2000\/svg"/.test(root.attrs)) return 'the root <svg> carries no xmlns="http://www.w3.org/2000/svg"';
  return null;
}
export const vectorDefect = (doc) => (/<image\b/.test(doc) ? 'an <image>' : /data:/.test(doc) ? 'a data: URI'
  : /href="(?!#)/.test(doc) ? 'an href that leaves the document' : /url\((?!#)/.test(doc) ? 'a url() that leaves the document' : null);
