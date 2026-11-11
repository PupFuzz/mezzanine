<?php

use App\Building\BuildingLayout;
use App\Building\FloorSlug;
use App\Building\InvalidBuildingLayout;
use App\Building\Layouts;
use App\Building\Revisions;
use App\Feed\BuildingLayoutChanged;
use App\Feed\Outbox;
use App\Fold\Clock;
use App\Read\SeatIdentity;
use App\Read\Snapshot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EVERY FLOOR IS NAMED, AND ITS ADDRESS IS ITS NAME — card#13117 slice S4, `docs/design/FLOOR.md § 4.6` as amended and
 * `docs/design/plan-13117-seat-identity.md § 6.2`'s S4 share and § 6.3.
 *
 * The end state: the stored building layout in the reader's new shape, written as ONE new revision and announced as
 * any save is (`building.layout`). For each floor entry, `name` is its `label` where it had one, else the key it was
 * derived to (the lexically least room on it); `aliases` holds that key when the name's slug differs from it and it
 * is an address, so every link to it still opens the floor. Then every install the SNAPSHOT carries that the layout
 * does not place — the installs both composers drew an implicit floor for until this card — except the virtual
 * install `unassigned`, is placed on a floor of its own named by its id, so no floor's content changes: only the
 * floors' order and numerals do, with the key. The seats in no room are drawn on the Unassigned floor.
 *
 * ⛔ THE PROPERTY, as for the S1 migration (`2026_11_10_000000_identify_a_seat_by_its_seat_id_alone.php`):
 *
 *   1. Every refusal runs FIRST, with nothing written (`preconditions()`): the migrated document is built in memory
 *      and read by the new reader, and a label that would take the address of an unplaced install's new floor is
 *      refused naming both, with the remedy.
 *   2. Each step is guarded by a read of the document it would replace, so a step that applied is skipped.
 *   3. Each step's write is one transaction (`Layouts::serialise()`), so a failure leaves the store as it was.
 *   4. Nothing is written but the layout's current row, one revision and its message: no seat, room or map moves.
 *
 * `Tests\Feature\Building\TheFloorNamingMigrationKeepsEveryFloorTest` drives `steps()` with a failure injected after
 * each step.
 *
 * ⚠ `down()` WRITES A REVISION THE NEW READER REFUSES, BY DESIGN: the pre-card#13117 reader is the one that will read
 * it, and it refuses `name` and `aliases` as unknown members (§ 9 F17 on every page). It copies the document the
 * migration's own revision replaced (`restored_from` naming it) and says what that loses.
 */
return new class extends Migration
{
    /** The author of the two revisions this migration writes: no operator pressed save. */
    public const BY = 'card#13117 migration';

    public function up(): void
    {
        $this->preconditions();

        foreach ($this->steps('up') as $step) {
            $step();
        }
    }

    public function down(): void
    {
        foreach ($this->steps('down') as $step) {
            $step();
        }
    }

    /**
     * The refusals, before any write.
     *
     * @throws RuntimeException naming the floors and the act that clears it
     */
    public function preconditions(): void
    {
        $migrated = $this->migrated();

        if ($migrated === null) {
            return;
        }

        try {
            BuildingLayout::fromJson($migrated);
        } catch (InvalidBuildingLayout $e) {
            throw new RuntimeException('The stored building layout cannot be given its floors\' names (card#13117), '
                .'because the new reader refuses the result: '.$e->getMessage().$this->remedy(), previous: $e);
        }
    }

    /**
     * The named steps of one direction, in order.
     *
     * @return array<string, Closure(): void>
     */
    public function steps(string $direction): array
    {
        $up = [
            'name_the_floors' => fn () => Layouts::serialise(function () {
                $migrated = $this->migrated();

                if ($migrated !== null) {
                    $this->publish($migrated, null);
                }
            }),
        ];

        $down = [
            'restore_the_labelled_layout' => fn () => Layouts::serialise(fn () => $this->restoreLabelled()),
        ];

        return $direction === 'up' ? $up : $down;
    }

    /**
     * The migrated document's text, or null when there is nothing to write: the stored layout already names its
     * floors, or migrating it changes nothing.
     */
    public function migrated(): ?string
    {
        $text = DB::table('building_layout')->where('id', Layouts::ROW_ID)->value('document');
        $document = $text === null ? (object) ['floors' => []] : json_decode((string) $text, false, 512, JSON_THROW_ON_ERROR);
        $floors = is_array($document->floors ?? null) ? $document->floors : [];

        if ($floors !== [] && array_filter($floors, fn ($entry) => ! property_exists($entry, 'name')) === []) {
            return null;
        }

        $named = [];
        $placed = [];

        foreach ($floors as $entry) {
            $rooms = array_map(strval(...), array_keys(get_object_vars($entry->rooms ?? new stdClass)));
            sort($rooms, SORT_STRING);
            $placed = [...$placed, ...$rooms];

            // The key card#9267 derived: the lexically least room on the floor.
            $key = $rooms[0] ?? '';
            $label = $entry->label ?? null;
            $name = is_string($label) ? $label : $key;

            $out = (object) ['name' => $name];

            if (FloorSlug::of($name) !== $key && FloorSlug::isSlug($key)) {
                $out->aliases = [$key];
            }

            foreach (get_object_vars($entry) as $member => $value) {
                if ($member !== 'label') {
                    $out->{$member} = $value;
                }
            }

            $named[] = $out;
        }

        foreach ($this->unplacedReported($placed) as $install) {
            foreach ($named as $entry) {
                if (FloorSlug::of($entry->name) === FloorSlug::of($install)) {
                    throw new RuntimeException(sprintf(
                        'The floor named `%s` would take the address /floor/%s, which the floor of the unplaced install '
                        .'`%s` needs: from card#13117 an install the layout does not place is given a floor of its own, '
                        .'named by its id, and a floor\'s address is the slug of its name. Rename the label of the floor '
                        .'holding %s in the console\'s layout, or place `%s` on a floor.%s',
                        $entry->name,
                        FloorSlug::of($install),
                        $install,
                        implode(', ', array_keys(get_object_vars($entry->rooms))),
                        $install,
                        $this->remedy(),
                    ));
                }
            }

            $named[] = (object) ['name' => $install, 'rooms' => (object) [$install => (object) ['form' => BuildingLayout::DEFAULT_FORM]]];
        }

        $migrated = (object) ['floors' => $named];

        if ($text !== null && $this->encode($migrated) === $this->encode($document)) {
            return null;
        }

        return $named === [] ? null : $this->encode($migrated);
    }

    /**
     * The installs the snapshot carries that no floor places, by id — `unassigned`, the seats in no room, excepted.
     *
     * @param  list<string>  $placed
     * @return list<string>
     */
    private function unplacedReported(array $placed): array
    {
        $reported = Snapshot::seats()->pluck('install_id')->map(strval(...))->unique()->sort()->values()->all();

        return array_values(array_diff($reported, $placed, [SeatIdentity::UNASSIGNED]));
    }

    /**
     * § 6.5's rollback over the new shape: a new revision copying the document this migration's revision replaced,
     * `restored_from` naming it — `{"floors": []}` where there was none — and the losses printed.
     */
    private function restoreLabelled(): void
    {
        $current = DB::table('building_layout')->where('id', Layouts::ROW_ID)->lockForUpdate()->first();
        $floors = $current === null ? [] : (json_decode((string) $current->document)->floors ?? []);

        // Already in the labelled shape: nothing of this migration's is current.
        if (array_filter($floors, fn ($entry) => property_exists($entry, 'name')) === []) {
            return;
        }

        $ours = DB::table('authored_revisions')
            ->where('kind', Revisions::LAYOUT)->where('subject', Revisions::LAYOUT_SUBJECT)
            ->where('authored_by', self::BY)->whereNull('restored_from')
            ->max('revision');

        $before = $ours === null ? null : DB::table('authored_revisions')
            ->where('kind', Revisions::LAYOUT)->where('subject', Revisions::LAYOUT_SUBJECT)
            ->where('revision', '<', $ours)->orderByDesc('revision')->first(['revision', 'document']);

        $line = sprintf(
            'card#13117 rollback: the building layout is restored to %s as a new revision. Every floor name and alias '
            .'written since layout revision %s is lost, and every floor removed since then is drawn again by the reader '
            .'before card#13117.',
            $before === null ? 'the empty building' : 'revision '.$before->revision,
            $ours ?? '(none)',
        );
        Log::info($line);
        echo $line.PHP_EOL;

        $this->publish($before === null ? '{"floors": []}' : (string) $before->document, $before?->revision);
    }

    /**
     * The three writes `App\Building\Layouts::write()` makes — the revision, the current row, the `building.layout`
     * message — made here because a migration writes a document no reader of the day must judge: `up()` has already
     * judged its own, and `down()`'s is for the reader before this card. Called only inside `Layouts::serialise()`.
     */
    private function publish(string $document, ?int $restoredFrom): void
    {
        $at = Clock::sql(now());
        $revision = Revisions::insert(Revisions::LAYOUT, Revisions::LAYOUT_SUBJECT, $document, $restoredFrom, self::BY, $at);

        DB::table('building_layout')->updateOrInsert(
            ['id' => Layouts::ROW_ID],
            ['document' => $document, 'layout_version' => $revision, 'updated_by' => self::BY, 'updated_at' => $at],
        );

        Outbox::enqueue(new BuildingLayoutChanged($revision, (string) Clock::wire($at)));
    }

    private function encode(stdClass $document): string
    {
        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function remedy(): string
    {
        return ' Nothing was changed. Roll the deploy back to the previous build, do that, then redeploy '
            .'(docs/design/plan-13117-seat-identity.md § 6.5).';
    }
};
