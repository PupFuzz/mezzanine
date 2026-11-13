<?php

namespace Tests\Feature\Building;

use App\Models\User;

/**
 * Resolve floor-route segments as a viewer's page does — for a test that asserts an address where a viewer meets it.
 * The using test supplies `enrolled()`, the session that reads `GET /api/building`.
 */
trait ResolvesFloorRoutes
{
    abstract protected function enrolled(): User;

    /**
     * Each segment resolved as the floor page resolves it: `GET /api/building`'s served floors and authored rooms,
     * composed by the browser's `floors()` with no seat held, then `floor/floor-screen.js`'s `resolveRoute()`.
     *
     * @param  list<string>  $segments
     * @return array<string, array{floor: string|null, redirect: string|null}>
     */
    protected function resolve(array $segments): array
    {
        $served = $this->actingAs($this->enrolled())->getJson('/api/building')->assertOk()->json();
        $js = realpath(__DIR__.'/../../../public/js');
        $script = sprintf(
            'import { floors } from %s; import { resolveRoute } from %s;'
            .'const composed = floors({ installs: [] }, %s, null, %s);'
            .'console.log(JSON.stringify(Object.fromEntries(%s.map((s) => { const r = resolveRoute(s, composed);'
            .'return [s, { floor: r.floor === null ? null : r.floor.floor, redirect: r.redirect }]; }))));',
            json_encode('file://'.$js.'/lobby/lobby-model.js'),
            json_encode('file://'.$js.'/floor/floor-screen.js'),
            json_encode($served['layout']['floors']),
            json_encode(array_column($served['rooms'], 'install_id')),
            json_encode($segments),
        );
        $out = shell_exec('node --input-type=module -e '.escapeshellarg($script).' 2>&1');
        $decoded = json_decode((string) $out, true);

        $this->assertIsArray($decoded, "node printed something that is not JSON:\n".$out);

        return $decoded;
    }
}
