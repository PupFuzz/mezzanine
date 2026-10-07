#!/usr/bin/env node
// THE COLOURS A DESK CAN STAND ON, FOR EVERY SHIPPED THEME — `docs/design/FLOOR.md § 10.6` item 1 (card#11046,
// Appendix B row 22). Node, no dependencies, no network.
//
//   node tools/floor-themes/surfaces.mjs            → {"<theme>": {"<surface>": "#rrggbb", …}, …} on stdout
//
// It imports the theme registry and every theme it names, exactly as the page does, and prints each theme's
// `surfaces()`. This is the ONE step between the themes and `tools/design/state-chip-colours.py`, which reads
// this output and never parses a module — so the chip's colours are measured over the colours the themes
// compute, never over a transcription of them.

import { join, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const REPO = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const THEMES = join(REPO, 'resources', 'floor', 'themes');
const registry = await import(pathToFileURL(join(THEMES, 'index.js')).href);
const out = {};

for (const name of registry.THEMES) {
    const theme = await import(pathToFileURL(join(THEMES, name, 'theme.js')).href);

    out[name] = theme.surfaces();
}

process.stdout.write(`${JSON.stringify(out)}\n`);
