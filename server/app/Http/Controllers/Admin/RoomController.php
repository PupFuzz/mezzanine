<?php

namespace App\Http\Controllers\Admin;

use App\Building\BuildingLayout;
use App\Building\InvalidBuildingLayout;
use App\Building\Layouts;
use App\Building\RoomRefused;
use App\Building\Rooms;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The console's **Rooms** module — card#13117, plan-13117-seat-identity.md § 4.2: create a named room, optionally
 * on a floor of the current layout; rename it; retire it. Every act is `App\Building\Rooms`'s, and every one that
 * touches the layout goes through `Layouts::amend()` there; this controller turns their refusals into the
 * sentence the operator reads. Which seat is in which room is the Agents module's *Room* control.
 */
class RoomController extends Controller
{
    public function index(): View
    {
        // A stored layout a later rule refuses (docs/design/FLOOR.md § 9 F17) still lets this page list the rooms:
        // the floor column then says why it is empty rather than the page failing for a column.
        try {
            $layout = Layouts::layout();
            $unreadable = null;
        } catch (InvalidBuildingLayout $e) {
            $layout = null;
            $unreadable = $e->getMessage();
        }

        return view('console.rooms.index', [
            'active' => 'rooms',
            'rooms' => Rooms::listing($layout),
            'floors' => $layout === null ? [] : self::floorChoices($layout),
            'unreadable' => $unreadable,
            'nameMax' => Rooms::NAME_MAX,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string'],
            'name' => ['required', 'string'],
            'floor' => ['nullable', 'string'],
        ]);

        $floor = ($validated['floor'] ?? '') === '' ? null : $validated['floor'];

        return $this->act(function () use ($validated, $floor, $request): string {
            $version = Rooms::create($validated['key'], $validated['name'], $floor, (string) $request->user()->email);

            return $version === null
                ? sprintf('Room %s is created, on no floor. Place seats in it from the Agents page.', $validated['key'])
                : sprintf('Room %s is created and placed on the floor at /floor/%s, as layout revision %d.', $validated['key'], $floor, $version);
        });
    }

    public function update(Request $request, string $room): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string']]);

        return $this->act(fn (): string => Rooms::rename($room, $validated['name'])
            ? sprintf('Room %s is now named %s. Its key, its map and its links are unchanged.', $room, $validated['name'])
            : sprintf('Room %s already had that name; nothing changed.', $room));
    }

    public function retire(Request $request, string $room): RedirectResponse
    {
        return $this->act(function () use ($room, $request): string {
            $version = Rooms::retire($room, (string) $request->user()->email);

            return sprintf('Room %s is retired%s. It is listed here as a record; its map history is kept.', $room,
                $version === null ? '' : sprintf(' and taken off its floor, as layout revision %d', $version));
        });
    }

    /** @param  callable(): string  $act  answers the status line */
    private function act(callable $act): RedirectResponse
    {
        try {
            $status = $act();
        } catch (RoomRefused|InvalidBuildingLayout $e) {
            return redirect()->route('admin.rooms.index')->withInput()->withErrors(['room' => $e->getMessage()]);
        }

        return redirect()->route('admin.rooms.index')->with('status', $status);
    }

    /**
     * The floors a new room may be placed on: each floor the current layout composes, by the address the place act
     * finds it by, and its name.
     *
     * @return list<array{key: string, name: string}>
     */
    private static function floorChoices(BuildingLayout $layout): array
    {
        return array_map(fn (array $floor): array => ['key' => $floor['floor'], 'name' => $floor['name']], $layout->floors);
    }
}
