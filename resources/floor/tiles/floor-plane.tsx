<?xml version="1.0" encoding="UTF-8"?>
<!-- THE FLOOR's TILESET — FIRST-PARTY, the one tileset the repository ships (docs/design/FLOOR.md § 10.3,
     § 10.6 item 6; card#11046, Appendix B row 22). EVERY TILE DECLARES A `kind` from the theme registry's
     closed set (`resources/floor/themes/index.js`'s `KINDS`), and the floor draws a placed tile BY ITS KIND
     in the floor's theme and never draws the tile's image: each image is an authoring MARKER for Tiled — a
     plain labelled outline — and the art is the theme's. Two classes: the PLANE kinds (`wall`, `accent`),
     whose cells the scene merges into runs for the grid's plane, and the STANDING kinds, each drawn at its
     tile's cell rect, sized by the tile — so one kind may come at several sizes, a low and a tall bookcase.
     There is no floor kind: every grid's floor is its theme's plane. Tiles 0 and 1 (the plank course and the
     rug) were retired at row 22 — the theme draws the floor, and the operator removed the rug — and their ids
     are not reused, so a stored map that placed them names tiles that draw nothing. Every image here owes a
     `docs/ATTRIBUTION.md` row (§ 10.1 Gate 1). -->
<tileset version="1.10" tiledversion="1.10.2" name="floor-plane" tilewidth="72" tileheight="144" tilecount="23" columns="0">
 <grid orientation="orthogonal" width="1" height="1"/>
 <tile id="2">
  <properties>
   <property name="kind" value="wall"/>
  </properties>
  <image source="floor-plane/wall-strip.svg" width="8" height="8"/>
 </tile>
 <tile id="3">
  <properties>
   <property name="kind" value="accent"/>
  </properties>
  <image source="floor-plane/accent.svg" width="8" height="8"/>
 </tile>
 <tile id="4">
  <properties>
   <property name="kind" value="bookcase"/>
  </properties>
  <image source="floor-plane/bookcase.svg" width="72" height="112"/>
 </tile>
 <tile id="5">
  <properties>
   <property name="kind" value="bookcase"/>
  </properties>
  <image source="floor-plane/bookcase-tall.svg" width="72" height="144"/>
 </tile>
 <tile id="6">
  <properties>
   <property name="kind" value="plant"/>
  </properties>
  <image source="floor-plane/plant.svg" width="56" height="104"/>
 </tile>
 <tile id="7">
  <properties>
   <property name="kind" value="plant"/>
  </properties>
  <image source="floor-plane/plant-narrow.svg" width="28" height="72"/>
 </tile>
 <tile id="8">
  <properties>
   <property name="kind" value="floor-lamp"/>
   <property name="decoration" value="lamp"/>
  </properties>
  <image source="floor-plane/floor-lamp.svg" width="40" height="128"/>
 </tile>
 <tile id="9">
  <properties>
   <property name="kind" value="armchair"/>
  </properties>
  <image source="floor-plane/armchair.svg" width="72" height="64"/>
 </tile>
 <tile id="10">
  <properties>
   <property name="kind" value="cushion"/>
  </properties>
  <image source="floor-plane/cushion.svg" width="24" height="16"/>
 </tile>
 <tile id="11">
  <properties>
   <property name="kind" value="book-pile"/>
  </properties>
  <image source="floor-plane/book-pile.svg" width="48" height="24"/>
 </tile>
 <tile id="12">
  <properties>
   <property name="kind" value="plant"/>
  </properties>
  <image source="floor-plane/plant-large.svg" width="80" height="128"/>
 </tile>
 <tile id="13">
  <properties>
   <property name="kind" value="snack-table"/>
  </properties>
  <image source="floor-plane/snack-table.svg" width="72" height="96"/>
 </tile>
 <tile id="14">
  <properties>
   <property name="kind" value="snack-table-low"/>
  </properties>
  <image source="floor-plane/snack-table-low.svg" width="48" height="64"/>
 </tile>
 <tile id="15">
  <properties>
   <property name="kind" value="restroom-door"/>
  </properties>
  <image source="floor-plane/restroom-door.svg" width="64" height="144"/>
 </tile>
 <tile id="16">
  <properties>
   <property name="kind" value="water-cooler"/>
  </properties>
  <image source="floor-plane/water-cooler.svg" width="40" height="120"/>
 </tile>
 <tile id="17">
  <properties>
   <property name="kind" value="filing-cabinet"/>
  </properties>
  <image source="floor-plane/filing-cabinet-low.svg" width="48" height="72"/>
 </tile>
 <tile id="18">
  <properties>
   <property name="kind" value="filing-cabinet"/>
  </properties>
  <image source="floor-plane/filing-cabinet.svg" width="48" height="120"/>
 </tile>
 <tile id="19">
  <properties>
   <property name="kind" value="copier"/>
  </properties>
  <image source="floor-plane/copier.svg" width="80" height="96"/>
 </tile>
 <tile id="20">
  <properties>
   <property name="kind" value="coat-rack"/>
  </properties>
  <image source="floor-plane/coat-rack.svg" width="40" height="136"/>
 </tile>
 <tile id="21">
  <properties>
   <property name="kind" value="whiteboard"/>
  </properties>
  <image source="floor-plane/whiteboard.svg" width="80" height="128"/>
 </tile>
 <tile id="22">
  <properties>
   <property name="kind" value="aquarium"/>
  </properties>
  <image source="floor-plane/aquarium.svg" width="80" height="104"/>
 </tile>
 <tile id="23">
  <properties>
   <property name="kind" value="bean-bag"/>
  </properties>
  <image source="floor-plane/bean-bag.svg" width="56" height="40"/>
 </tile>
 <tile id="24">
  <properties>
   <property name="kind" value="globe"/>
  </properties>
  <image source="floor-plane/globe.svg" width="40" height="88"/>
 </tile>
</tileset>
