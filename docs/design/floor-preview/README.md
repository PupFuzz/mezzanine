# Floor-preview — the RATIFIED initial design for card#7341

**Operator ratification, 2026-08-27:** *"This is a good start. I will make customization in the
future, but let's start with this as the initial design."*

`floor-preview.html` is the self-contained reference artifact the floor build (card#7341) is built
FROM. It encodes, as working code, every design ruling of the 2026-08-26/27 operator sessions:

- the **building cross-section** (floors stacked one per install; the elevator is navigation;
  zoom out = fleet overview, zoom to a floor, wheel/drag/fit) — structure belongs to the building,
  everything inside to the floor's **theme** (palette, desk positions, cabin, furniture set);
- **seeded characters**: 7 silhouettes × 16 hues × 5 sizes + ears/sprout/eyes/mouth/accessory/
  tilt, all drawn from fnv1a32(install/seat) with searched salts (s18/s3) so the known roster is
  all-distinct; interns seed from `seat~internN` — **one sprite per open subagent, cap 8, then +N**;
- **held-loop micro-animation** (blink + wiggle while busy, slumped-asleep idle with z's; stale is
  an EMPTY cushion, never a sleeper); live wall clock + day/night windows from the VIEWER's clock;
- the **staged communication layer** (thread-as-object with round beads, kind-shaped carriers,
  floor-clipped broadcast pulse, convergence spark, escalation flare) — every element maps to a
  bridge-observable and **ships only behind the `coord.*` event family** (card#7897).

The kanban cards carry the full rulings and their reasons: #7341 (floor build + viewport),
#7897 (communication layer + meeting-room triggers), #7898 (art direction + characters),
#7895 (upstream munder-difflin analysis). Sample data throughout; the page says so.
