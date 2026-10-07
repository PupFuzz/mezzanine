// THE FURNITURE BOX — `docs/design/FLOOR.md § 10.3`'s `desks` row and Appendix B row 14: the rect
// everything the scene draws for one desk at rest, except the bubble, is laid out INSIDE, at the
// worst case — § 8's cap of stools with the *+N more* tag, D2's bound of badges with its mark, and
// every string cut to fit. A `desks` object at least this big holds one desk; two disjoint objects
// hold two disjoint desks by construction.
//
// ⛔ THIS FILE IS THE ONE SOURCE OF THE BOX, AND IT IS DATA. Two runtimes read it and neither keeps
// a copy: the browser's scene takes it as an input — the painter imports this file by its asset
// route URL, `/art/floor/furniture-box.js`, and the harness imports it from disk — and PHP reads
// it through `App\Floor\FurnitureBox`, which is what the console's refusal and its re-validation
// listing (row 14, § 14 item 28(1)) read. `Tests\Feature\Floor\TheFurnitureBoxHasOneSourceTest`
// reds when the two readers disagree about this file, so PHP's strict parse of the line below
// cannot drift from what `import` sees.
//
// ⛔ THE LINE BELOW IS PARSED BY PHP AS WRITTEN, and its shape is part of the contract: one
// `export const FURNITURE_BOX = Object.freeze({ width: N, height: N });` line, integers, nothing
// computed. An edit that computes either number is refused by name rather than read as a guess.
//
// ⛔ THE BOX IS THE ART's OWN SIZE, so it moves when the art does (§ 14 item 28(1)(iii)): the
// desk's rects (§ 10.6's re-laid table, which the floor's theme draws its furniture into), the
// character's drawn size, the stool, the badge chip and the line the scene lays text on are what
// it must hold, and `Tests\Feature\Floor\SeatFurnitureNeverOverlapsTest` (AT-D3-20)
// reds when what the scene draws at the cap no longer fits it. Changing a number here is changing
// what every stored map is validated against.

export const FURNITURE_BOX = Object.freeze({ width: 440, height: 228 });

