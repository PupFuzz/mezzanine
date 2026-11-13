<?php

namespace Tests\Feature\Floor;

/**
 * card#12442 and card#12513's walker-body property, shared by `AWalkerKeepsClearOfWallsAndFurnitureTest` (the shipped
 * default and the cubicles) and `TheLoungeFloorRoutesEveryWalkTest` (card#12846's second room layout): § 12's *Walker
 * clearance* read out of the document, and the polyline samplers — a body over a wall, a standing tile or another desk,
 * a seat's feet on its own couch, and an intern's feet on any couch but by its own seat's step from the front
 * (card#13033). Hoisted at its second caller; the property is stated where it is used.
 *
 * The host provides `floorMd()` (`DrivesTheFleetClientModule`).
 */
trait ChecksAWalkersBody
{
    /** @return array{margin: int, depth: int} § 12's *Walker clearance* row */
    protected function clearance(): array
    {
        $this->assertSame(1, preg_match('/^\| \*\*Walker clearance\*\* \| \*\*(\d+) px beside; (\d+) px in depth\*\* \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Walker clearance* row in the form this test reads');

        return ['margin' => (int) $m[1], 'depth' => (int) $m[2]];
    }

    /**
     * A polyline with every interior point that lies on a straight run between its neighbours dropped — the same drawn
     * line, so sampling it every pixel covers the same line at the same density, in as many segments as it has turns.
     *
     * @param  list<array{x: float|int, y: float|int}>  $points
     * @return list<array{x: float|int, y: float|int}>
     */
    protected function straightened(array $points): array
    {
        $out = [];

        foreach ($points as $p) {
            $n = count($out);

            if ($n >= 2) {
                [$a, $b] = [$out[$n - 2], $out[$n - 1]];
                $cross = ($b['x'] - $a['x']) * ($p['y'] - $b['y']) - ($b['y'] - $a['y']) * ($p['x'] - $b['x']);
                $onward = ($b['x'] - $a['x']) * ($p['x'] - $b['x']) + ($b['y'] - $a['y']) * ($p['y'] - $b['y']) > 0;

                if ($cross == 0 && $onward) {
                    $out[$n - 1] = $p;

                    continue;
                }
            }

            $out[] = $p;
        }

        return $out;
    }

    /**
     * Where one desk's walker stands over something it must not, sampled every pixel along its drawn polyline: a wall
     * or standing rect within `$side` px of the feet sideways while the feet are within `$depth` px of its depth span,
     * or another desk's slot or box under the bare body while the feet are inside its depth span.
     *
     * @return list<string> the first defect of the desk, or none
     */
    protected function bodyDefects(array $floor, array $desk, float $side, float $depth): array
    {
        $half = $floor['body']['w'] / 2;
        $rects = [];

        foreach ($floor['obstacles'] as $o) {
            $rects[] = [$o, $side, $depth, $o['kind']];
        }

        foreach ($floor['desks'] as $other) {
            if ($other['key'] !== $desk['key']) {
                foreach ([$other['slot'], $other['box']] as $r) {
                    $rects[] = [$r, $half, 0.0, $other['key']];
                }
            }
        }

        $points = $this->straightened($desk['points'] ?? []);

        for ($i = 1; $i < count($points); $i++) {
            [$a, $b] = [$points[$i - 1], $points[$i]];
            $n = max(1, (int) ceil(hypot($b['x'] - $a['x'], $b['y'] - $a['y'])));
            // Only the rects the segment's own span can reach, in their order — every sample below lies inside it, so the
            // test each sample makes is unchanged, and a floor's hundreds of wall cells are not re-tested at every pixel.
            [$x0, $x1, $y0, $y1] = [min($a['x'], $b['x']), max($a['x'], $b['x']), min($a['y'], $b['y']), max($a['y'], $b['y'])];
            $near = array_filter($rects, static fn ($q) => $x0 - $q[1] < $q[0]['x'] + $q[0]['w'] && $q[0]['x'] < $x1 + $q[1]
                && $q[0]['y'] - $q[2] < $y1 && $y0 < $q[0]['y'] + $q[0]['h'] + $q[2]);

            for ($k = 0; $k <= $n; $k++) {
                $p = ['x' => $a['x'] + ($b['x'] - $a['x']) * $k / $n, 'y' => $a['y'] + ($b['y'] - $a['y']) * $k / $n];

                foreach ($near as [$r, $s, $d, $what]) {
                    if ($p['x'] - $s < $r['x'] + $r['w'] && $r['x'] < $p['x'] + $s && $r['y'] - $d < $p['y'] && $p['y'] < $r['y'] + $r['h'] + $d) {
                        return ["{$desk['key']} ({$desk['clearance']}): feet at ".json_encode($p)." stand within {$s} px beside / {$d} px in depth of {$what} ".json_encode([$r['x'], $r['y'], $r['w'], $r['h']])];
                    }
                }
            }
        }

        return [];
    }

    /**
     * card#12513: where one walk's feet stand on its own desk's side table or its interns' rows (`$solid`, scene
     * coordinates), sampled every pixel along `$points`.
     *
     * @return list<string> the first defect, or none
     */
    protected function couchCrossing(string $what, array $points, array $solid): array
    {
        for ($i = 1; $i < count($points); $i++) {
            [$a, $b] = [$points[$i - 1], $points[$i]];
            $n = max(1, (int) ceil(hypot($b['x'] - $a['x'], $b['y'] - $a['y'])));

            for ($k = 0; $k <= $n; $k++) {
                $p = ['x' => $a['x'] + ($b['x'] - $a['x']) * $k / $n, 'y' => $a['y'] + ($b['y'] - $a['y']) * $k / $n];

                foreach ($solid as $r) {
                    if ($r['x'] < $p['x'] && $p['x'] < $r['x'] + $r['w'] && $r['y'] < $p['y'] && $p['y'] < $r['y'] + $r['h']) {
                        return ["{$what}: feet at ".json_encode($p).' stand on its own side table '.json_encode([$r['x'], $r['y'], $r['w'], $r['h']])];
                    }
                }
            }
        }

        return [];
    }

    /**
     * card#13033 — AN INTERN GETS ON AND OFF ITS COUCH FROM THE FRONT: one intern walk's polyline, sampled every pixel,
     * never has its feet inside any couch footprint (`$couches`, every desk's, scene coordinates) but on its own seat's
     * step — straight in front of its stool's foot (`$foot`), inside its own row's footprint; and where the walk starts or
     * ends at the foot, its first or last segment IS that step, out to a point in front of the footprint's front edge.
     *
     * @param  array{x: float|int, y: float|int}  $foot
     * @param  list<array{x: float|int, y: float|int, w: float|int, h: float|int}>  $couches
     * @return list<string> the first defect of each kind, or none
     */
    protected function couchStepDefects(string $what, array $points, array $foot, array $couches): array
    {
        $eps = 1e-6;
        $at = static fn (array $p, array $q): bool => abs($p['x'] - $q['x']) < $eps && abs($p['y'] - $q['y']) < $eps;
        $inside = static fn (array $p, array $r): bool => $r['x'] + $eps < $p['x'] && $p['x'] < $r['x'] + $r['w'] - $eps
            && $r['y'] + $eps < $p['y'] && $p['y'] < $r['y'] + $r['h'] - $eps;
        $own = array_values(array_filter($couches, static fn (array $r): bool => $r['x'] < $foot['x'] && $foot['x'] < $r['x'] + $r['w']
            && $r['y'] < $foot['y'] && $foot['y'] < $r['y'] + $r['h']));
        $defects = [];

        if (count($own) !== 1) {
            return ["{$what}: its stool's foot ".json_encode($foot).' stands on '.count($own).' couch footprints, not on its own row\'s'];
        }

        $front = $own[0]['y'] + $own[0]['h'];

        foreach ([[0, 1], [count($points) - 1, count($points) - 2]] as [$end, $next]) {
            if ($at($points[$end], $foot) && !(abs($points[$next]['x'] - $foot['x']) < $eps && $points[$next]['y'] >= $front)) {
                $defects[] = "{$what}: it ".($end === 0 ? 'leaves' : 'reaches').' its seat by '.json_encode($points[$next]).', not straight off its front, past the couch\'s front edge at '.$front;
            }
        }

        for ($i = 1; $i < count($points); $i++) {
            [$a, $b] = [$points[$i - 1], $points[$i]];
            $n = max(1, (int) ceil(hypot($b['x'] - $a['x'], $b['y'] - $a['y'])));

            for ($k = 0; $k <= $n; $k++) {
                $p = ['x' => $a['x'] + ($b['x'] - $a['x']) * $k / $n, 'y' => $a['y'] + ($b['y'] - $a['y']) * $k / $n];
                $step = abs($p['x'] - $foot['x']) < $eps && $inside($p, $own[0]);

                foreach ($couches as $r) {
                    if (!$step && $inside($p, $r)) {
                        $defects[] = "{$what}: feet at ".json_encode($p).' stand on a couch footprint '.json_encode([$r['x'], $r['y'], $r['w'], $r['h']]).' off its own seat\'s step';

                        return $defects;
                    }
                }
            }
        }

        return $defects;
    }
}
