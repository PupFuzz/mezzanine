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
  an EMPTY cushion, never a sleeper); a **wall clock and day/night windows that step on each
  delivered `feed.heartbeat` and therefore stop when the feed stops** — the driver, the minute
  resolution, the setting rule and the unset render are `docs/design/FLOOR.md` § 6.2 **A17** and
  § 6.5's, and are not restated here; the *simulated feed* control under the room is where you
  watch the room freeze, and why the operator chose that over a timer;
- the **staged communication layer** (thread-as-object with round beads, kind-shaped carriers,
  floor-clipped broadcast pulse, convergence spark, escalation flare) — every element maps to a
  bridge-observable and **ships only behind the `coord.*` event family** (card#7897).

**Two things this file is careful about, because an earlier revision was not and card#7341 is
specified to build FROM it.** `thinking` is **not** a `render_state`: D2 sends the ten members
`FLOOR.md` § 7.1 publishes, and the *think pose* is derived here from § 6.2 **A4**'s condition over
a `working` seat (`open_calls == 0` ∧ `open_turn == true`) — copy the derivation, never a wire
member. And the lobby's per-floor summary iterates § 7.1's **fixed member order** (§ 4.1) rather
than a member set of its own.

The kanban cards carry the full rulings and their reasons: #7341 (floor build + viewport),
#7897 (communication layer + meeting-room triggers), #7898 (art direction + characters),
#7895 (upstream munder-difflin analysis). Sample data throughout; the page says so.
