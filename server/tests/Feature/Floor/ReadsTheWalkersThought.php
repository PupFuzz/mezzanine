<?php

namespace Tests\Feature\Floor;

/**
 * card#13521 — WHICH BUBBLE A TRIP's WALKER CARRIES ON EACH LEG, AS BOTH FLOORS DRAW IT (`docs/design/FLOOR.md` § 6.6 item
 * 4, § 6.7 item 4): one walker's effect of a drawn frame painted through the shipped 2-D painter and placed by the shipped
 * 3-D bodies (`thought-paint-probe.mjs`), held to its line out before its walk home and its return line from that walk's
 * first frame. Shared by AT-D3-32 (the snack trip) and AT-D3-33 (the restroom visit), whose runs supply the frames.
 */
trait ReadsTheWalkersThought
{
    /** The bubble's lines as one text — how the probe reads a painted bubble. */
    private function bubbleText(?array $thought): ?string
    {
        return $thought === null ? null : implode(' ', array_column($thought['lines'], 'text'));
    }

    /**
     * The defects of one walker's effect `$effect` drawn in `$frame`: a 2-D glide whose walk begins before the walk home
     * carrying other than its line out, the walk home's glide other than its return line; a 3-D label, at the middle of
     * each leg still to walk, other than the line out before the walk home and the return line on it. And with
     * `$seated` — the frame that draws the walker back at its desk — any bubble left on either floor there.
     *
     * @return list<string>
     */
    private function thoughtPaintDefects(string $label, array $frame, array $effect, ?array $seated = null, ?string $dir = null): array
    {
        $defects = [];
        $out = $this->bubbleText($effect['thought'] ?? null);
        $back = $this->bubbleText($effect['return_thought'] ?? null);
        $home = end($effect['legs'])['start'];

        if ($out === null || $back === null) {
            return ["{$label}: the walker carries no thought out or no thought home — nothing to read"];
        }

        $paints = [['scene' => $frame['scene'], 'effect' => $effect]];

        if ($seated !== null) {
            $paints[] = ['scene' => $seated['scene'], 'effect' => null];
        }

        $answer = $this->probe(['paints' => $paints], dirname($dir ?? $this->moduleDir()), __DIR__.'/thought-paint-probe.mjs');
        [$walk, $sit] = $answer + [1 => null];

        if (count($walk['glides']) < 2) {
            $defects[] = "{$label}: the 2-D walker is drawn in ".count($walk['glides']).' glide(s), not one per walk leg';
        }

        foreach ($walk['glides'] as $g) {
            $want = $g['from_frame'] >= $home ? $back : $out;

            if ($g['texts'] !== [$want]) {
                $defects[] = "{$label}: the 2-D glide from frame {$g['from_frame']} carries ".json_encode($g['texts']).', not *'.$want.'*';
            }
        }

        if ($walk['placed'] === [] || max(array_column($walk['placed'], 'frame')) < $home) {
            $defects[] = "{$label}: the 3-D walker was never placed on its walk home — nothing to read";
        }

        foreach ($walk['placed'] as $p) {
            $want = $p['frame'] >= $home ? $back : $out;

            if ($p['text'] !== $want) {
                $defects[] = "{$label}: the 3-D walker at frame {$p['frame']} carries ".json_encode($p['text']).', not *'.$want.'*';
            }
        }

        if ($sit !== null && (array_merge(...array_column($sit['glides'], 'texts')) !== [] || array_filter(array_column($sit['placed'], 'text')) !== [])) {
            $defects[] = "{$label}: a bubble is still drawn once the walker is back at its desk";
        }

        return $defects;
    }
}
