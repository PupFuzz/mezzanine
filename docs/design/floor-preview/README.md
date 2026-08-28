# Floor-preview — the RATIFIED initial design for card#7341

**Operator ratification, 2026-08-27:** *"This is a good start. I will make customization in the
future, but let's start with this as the initial design."*

`floor-preview.html` is the self-contained reference artifact the floor build (card#7341) is built
FROM. It encodes, as working code, every design ruling of the 2026-08-26/27 operator sessions —
**the operator's DESIGN rulings, which is not the same claim as covering D3's render surfaces, and
reading it as one is what this file now refuses to let happen. What of `docs/design/FLOOR.md` this
artifact implements is declared per render surface in its own `D3_SCOPE` table — the answer to
*"what of D3 does this artifact implement?"*, in the artifact, rendered on the page under *What of
FLOOR.md this artifact implements*: each of § 5.4's six membership-tested surfaces is marked
implemented (naming the render table it is implemented by), half-there, or not implemented (naming
why in one clause), and the check below set-differences that table against § 5.4's own six in both
directions on every run. It is a reference implementation of a DECLARED SUBSET — *not implemented*
is a ruling, because the floor is built once, by card#7341.** The design rulings it encodes:

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
- the **thought bubble** as the desk's ONE rendered form of `task` — there is no task chip beside
  it, a null `task` draws nothing, and a desk with no character (`stale` / `offline` / `retired`)
  has nothing to anchor one to. It is **static by rule**: `FLOOR.md` § 5.1 owns the six rules and
  the upstream fade/linger/fade state machine it refuses, and they are not restated here;
- the **staged communication layer** (thread-as-object with round beads, kind-shaped carriers,
  floor-clipped broadcast pulse, convergence spark, escalation flare) — every element maps to a
  bridge-observable and **ships only behind the `coord.*` event family** (card#7897).

- **every `render_state` member has a render, and a member this client does not know renders as
  UNRECOGNISED** — `FLOOR.md` § 7.1's ten, plus § 5.4's eleventh case, in **one table** whose rows
  the chip, the monitor, the pose, the marker, the state line, the desk's character and the lobby's
  summary are all derived from. Copy the table, never one of the derivations: **six** copies of
  one member set are what this replaced — four of them covering only four members (card#7943) —
  and a second member set, in any spelling, is how the unrecognised case gets lost again.

**Two things this file is careful about, because an earlier revision was not and card#7341 is
specified to build FROM it.** `thinking` is **not** a `render_state`: D2 sends the ten members
`FLOOR.md` § 7.1 publishes, and the *think pose* is derived here from § 6.2 **A4**'s condition over
a `working` seat (`open_calls == 0` ∧ `open_turn == true`) — copy the derivation, never a wire
member. And the lobby's per-floor summary iterates § 7.1's **fixed member order** (§ 4.1) rather
than a member set of its own — **then names whatever the floor holds that is in no member set at
all**, because iterating the order alone is a filter that drops an unrecognised seat out of its own
floor's count (AT-D3-15). That undercount was **masked** by the `GL` crash until card#7943 removed
it: the desk loop runs first, so nothing was ever drawn to be wrong.

**The check is `tools/design/floor-preview.selftest.mjs`** (`node`, no dependencies, no network).
It re-derives **three** published tables from `FLOOR.md` rather than storing them, and compares
each **cell by cell**: § 7.1's ten members *with their Label line column*, § 7.1's seven
`unknown_reason` sentences, and § 7.6's twelve `api_error_type` phrases. So **a member added to D3
with no row here reds it — and so does a rendered string that has drifted from D3's**. It drives a
probe seat through all ten members using D3's own worked example values, through the null edges
§ 7.1 and § 5.6 state, through the two sibling membership sets, and through eight values that are
not members. It re-derives § 5.4's **six** render surfaces from that section's own two enumerations
and its count in words, and set-differences them against the artifact's `D3_SCOPE` table **both
ways** — so D3 gaining a seventh surface reds the gate instead of passing silently. It drives a
floor whose map is short of desks, in both shapes that produces (a seat at a desk key the map does
not declare, and an install the theme map has never seen), against § 3.2's overflow rule and § 9
F13 — the labelled overflow row, the *floor map is short N desks* notice, and never a dropped seat;
the sample fleet carries such a seat on the `sola` floor, so the behaviour is visible and not only
claimed. And it holds § 5.6's two intern nulls apart: a null `subagent_type` draws **no type tag**,
a null `title` draws ***untitled***. It proves it can fail with **twelve** anchored source
mutations. ⚠ **It is not visual evidence.** There is no browser and no HTML parser behind it — no layout, no paint, nothing seen.
What it asserts is the values the artifact emits; how any of it *looks* is verified by opening the
file, which is the one check no tool here performs.

The kanban cards carry the full rulings and their reasons: #7341 (floor build + viewport),
#7897 (communication layer + meeting-room triggers), #7898 (art direction + characters),
#7895 (upstream munder-difflin analysis). Sample data throughout; the page says so.
