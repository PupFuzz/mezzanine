<?xml version="1.0" encoding="UTF-8"?>
<!-- THE FLOOR PLANE — a FIRST-PARTY tileset beside the vendored Kenney bridge (`furniture-kit.tsx`),
     drawn for this repository under docs/design/FLOOR.md § 10.4's art direction at Appendix B row 14
     slice B (operator ruling 2026-09-25, card#7341 c6517): the bridge's `Side/` renders are an
     elevation with no floor plane, and the ruled two-row room stands on one. Each tile is an SVG the
     camera scales without resampling (§ 4.5), which is the property the bridge's PNGs lack. Every file
     here owes a `docs/ATTRIBUTION.md` row (§ 10.1 Gate 1); nothing in `resources/floor/LINEAGE.md`
     vouches for these, because they were not vendored — they were drawn here. -->
<tileset version="1.10" tiledversion="1.10.2" name="floor-plane" tilewidth="216" tileheight="48" tilecount="2" columns="0">
 <grid orientation="orthogonal" width="1" height="1"/>
 <!-- the plane: a seamless plank tile, laid in courses under the whole room -->
 <tile id="0">
  <image source="floor-plane/planks.svg" width="216" height="48"/>
 </tile>
 <!-- scenery on the plane -->
 <tile id="1">
  <image source="floor-plane/rug.svg" width="128" height="40"/>
 </tile>
</tileset>
