<?php

namespace App\Http\Controllers\Admin;

use App\Building\FloorEditReport;
use App\Building\FloorEdits;
use App\Building\FloorSlug;
use App\Building\InvalidBuildingLayout;
use App\Building\Layouts;
use App\Building\Rooms;
use App\Floor\FloorThemes;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The console's **Floors** module — card#13117, plan-13117-seat-identity.md § 5.5: name a floor, rename it (the old
 * address kept as a former one, or taken over from another floor), draw it in a theme, forget a former address, create
 * a floor holding a room, and place a room on a floor. Every act is one `App\Building\FloorEdits` closure handed to
 * `Layouts::amend()`, so each is decided against the document the write replaces, under the layout's lock; this
 * controller turns their refusals and their consequences (`FloorEditReport`) into the sentence the operator reads.
 *
 * ⚠ THERE IS NO *REMOVE A FLOOR* ACT, though § 5.5 listed one *refused while it holds rooms*: the reader refuses a floor
 * of no rooms, so every floor holds one and the act could only ever refuse. A floor goes with its last room — placed on
 * another floor, or retired.
 */
class BuildingFloorsController extends Controller
{
    public function index(): View
    {
        // A stored layout a later rule refuses (docs/design/FLOOR.md § 9 F17) still lets this page say so: every act
        // here amends that document, so none is offered, and the building layout editor is where it is repaired.
        try {
            $layout = Layouts::layout();
            $unreadable = null;
        } catch (InvalidBuildingLayout $e) {
            $layout = null;
            $unreadable = $e->getMessage();
        }

        $live = Rooms::live();
        $themes = FloorThemes::current();

        $floors = array_map(fn (array $floor): array => [
            'key' => $floor['floor'],
            'name' => $floor['name'],
            'aliases' => $floor['aliases'],
            'theme' => $floor['theme'] ?? null,
            'planned' => isset($floor['hallway']) || array_filter($floor['rooms'], fn (array $room) => isset($room['origin'])) !== [],
            'rooms' => array_map(fn (array $room): array => [
                'key' => $room['install'],
                'name' => $live[$room['install']] ?? null,
            ], $floor['rooms']),
        ], $layout?->floors ?? []);

        return view('console.building.index', [
            'active' => 'building',
            'unreadable' => $unreadable,
            'floors' => $floors,
            // Every live room, with the address of the floor it is on (null for none) — what a form may place.
            'rooms' => array_map(fn (string $key, ?string $name): array => [
                'key' => $key,
                'name' => $name,
                'floor' => $layout?->floorOf($key),
            ], array_keys($live), $live),
            'themes' => $themes->themes,
            'house' => $themes->house,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string'],
            'theme' => ['nullable', 'string'],
            'room' => ['required', 'string', Rule::in(array_keys(Rooms::live()))],
        ]);

        return $this->act(function () use ($validated, $request): string {
            $report = new FloorEditReport;
            $version = Layouts::amend(
                FloorEdits::create($validated['name'], $validated['theme'] ?? null, $validated['room'], $report),
                self::by($request),
            );

            return sprintf('Floor %s is created, holding room %s, as layout revision %d.%s',
                $validated['name'], $validated['room'], $version, $report->said());
        });
    }

    /**
     * One change per save — the name, the theme, or one former address forgotten — each its own form, so a save carries
     * only the member its operator changed and never re-sends another from the page's rendering.
     */
    public function update(Request $request, string $floor): RedirectResponse
    {
        $members = array_values(array_filter(['name', 'theme', 'forget'], fn (string $member) => $request->exists($member)));

        if (count($members) !== 1) {
            throw ValidationException::withMessages([
                'floor' => 'A floor\'s save changes one thing: its name, its theme, or one former address to forget.',
            ]);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string'],
            'theme' => ['sometimes', 'nullable', 'string'],
            'forget' => ['sometimes', 'required', 'string'],
        ]);

        return $this->act(function () use ($members, $validated, $floor, $request): string {
            $by = self::by($request);

            switch ($members[0]) {
                case 'name':
                    $report = new FloorEditReport;
                    $version = Layouts::amend(FloorEdits::rename($floor, $validated['name'], $report), $by);

                    $address = FloorSlug::of($validated['name']);

                    return sprintf('The floor at /floor/%s is now named %s, as layout revision %d; %s%s', $floor,
                        $validated['name'], $version, $address === $floor ? 'its address is unchanged.'
                            : sprintf('it is at /floor/%s, and its old address redirects there.', $address), $report->said());
                case 'theme':
                    $version = Layouts::amend(FloorEdits::theme($floor, $validated['theme'] ?? null), $by);

                    return sprintf('The floor at /floor/%s is drawn in %s, as layout revision %d.', $floor,
                        ($validated['theme'] ?? null) === null ? 'the house theme' : 'the theme '.$validated['theme'], $version);
                default:
                    $version = Layouts::amend(FloorEdits::forget($floor, $validated['forget']), $by);

                    return sprintf('/floor/%s is forgotten, as layout revision %d: it no longer opens the floor at '
                        .'/floor/%s.', $validated['forget'], $version, $floor);
            }
        });
    }

    public function place(Request $request, string $floor): RedirectResponse
    {
        $validated = $request->validate([
            'room' => ['required', 'string', Rule::in(array_keys(Rooms::live()))],
        ]);

        return $this->act(function () use ($validated, $floor, $request): string {
            $report = new FloorEditReport;
            $version = Layouts::amend(FloorEdits::place($floor, $validated['room'], $report), self::by($request));

            return sprintf('Room %s is on the floor at /floor/%s, as layout revision %d.%s',
                $validated['room'], $floor, $version, $report->said());
        });
    }

    /** @param  callable(): string  $act  answers the status line */
    private function act(callable $act): RedirectResponse
    {
        try {
            $status = $act();
        } catch (InvalidBuildingLayout $e) {
            return redirect()->route('admin.building.index')->withInput()->withErrors(['floor' => $e->getMessage()]);
        }

        return redirect()->route('admin.building.index')->with('status', $status);
    }

    private static function by(Request $request): string
    {
        return (string) $request->user()->email;
    }
}
