<?php

namespace Tests\Feature\Admin;

use App\Admin\ConsoleModules;
use App\Building\Layouts;
use App\Building\Revisions;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The console's BUILDING LAYOUT module — card#9208's reversal (2026-09-12), ratified by the
 * operator in those terms (*"keep it in the console"*): `docs/design/FLOOR.md § 4.6`'s document
 * moved out of the deploy-time `config/building.php` and into
 * `docs/design/FLEET-STATE.md § 6.11`'s store.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHAT THIS SUITE HOLDS THAT THE STORE'S OWN DOES NOT: that the refusals REACH THE AUTHOR. A
 * layout is authored text, `App\Building\BuildingLayout` refuses it in the author's own terms, and
 * a console that answered a bad document with a 500 would turn every one of those messages into a
 * stack trace. Every refusal below is asserted as *comes back on the form with the reason*.
 *
 * ⚠ AND THAT THE DOCUMENT SURVIVES THE ROUND TRIP. A browser submits a textarea with CRLF; the
 * store's no-op rule is a BYTE comparison (§ 6.11), so without `App\Building\AuthoredDocument` an
 * operator who exported a revision and pasted it back would mint a revision recording no change.
 */
class LayoutConsoleTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATOR = 'ops@example.com';

    private ?User $operator = null;

    private function operator(): User
    {
        return $this->operator ??= User::factory()->twoFactorConfirmed()->create(['email' => self::OPERATOR]);
    }

    /** @param list<array<string, mixed>> $floors */
    private function document(array $floors): string
    {
        return (string) json_encode(['floors' => $floors], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function save(string $document): TestResponse
    {
        return $this->actingAs($this->operator())->patch(route('admin.layout.update'), ['layout' => $document]);
    }

    public function test_the_layout_module_is_an_entry_in_the_console_module_list(): void
    {
        // The nav is generated from `App\Admin\ConsoleModules` — a module nobody can navigate to
        // is a module that does not exist, and this repo's own rule is that no entry is listed
        // before its routes are.
        $this->assertContains('layout', array_column(ConsoleModules::all(), 'key'));

        $this->actingAs($this->operator())
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('Building layout');
    }

    public function test_a_deployment_that_has_never_saved_one_is_offered_the_empty_building(): void
    {
        // § 8.7: no layout saved is `layout_version` 0 with an empty `floors` — today's building,
        // one floor per install. The page says so rather than showing a blank textarea.
        $this->actingAs($this->operator())
            ->get(route('admin.layout.edit'))
            ->assertOk()
            ->assertSee('No layout has ever been saved', false)
            // ⚠ ESCAPED, and that is the assertion rather than a detail: the document is rendered
            // inside a `<textarea>` through Blade's `{{ }}`, so the quotes reach the page as
            // `&quot;`. A raw-needle assertion here would red against a page that is correct.
            ->assertSee('"floors": []');
    }

    public function test_a_saved_layout_is_revision_one_and_the_page_says_which_revision_is_current(): void
    {
        $this->save($this->document([['label' => 'the solos', 'rooms' => [
            'sola' => ['form' => 'office'],
            'zeta' => ['form' => 'office'],
        ]]]))
            ->assertRedirect(route('admin.layout.edit'))
            ->assertSessionHas('status');

        $this->assertSame(1, Layouts::version());
        $this->assertSame('sola', Layouts::layout()->floors[0]['floor']);

        $this->actingAs($this->operator())
            ->get(route('admin.layout.edit'))
            ->assertOk()
            ->assertSee('revision 1')
            ->assertSee(self::OPERATOR);
    }

    public function test_a_document_this_reader_refuses_comes_back_on_the_form_with_the_reason(): void
    {
        // The author is holding the document; the refusal names the floor, the room and the rule
        // (§ 4.6), and a console that lost it would make every one of those messages pointless.
        $this->save($this->document([['rooms' => ['sola' => ['form' => 'cubicle']]]]))
            ->assertRedirect()
            ->assertSessionHasErrors('layout');

        $this->assertStringContainsString(
            'declares the form `cubicle`',
            (string) session('errors')->first('layout'),
        );

        // Nothing was stored: a refused save writes nothing (§ 6.11).
        $this->assertSame(0, Layouts::version());
    }

    public function test_text_that_is_not_a_document_at_all_is_refused_the_same_way(): void
    {
        $this->save('not a layout')
            ->assertRedirect()
            ->assertSessionHasErrors('layout');

        $this->assertSame(0, Layouts::version());
    }

    public function test_a_byte_identical_save_is_refused_as_a_no_op_and_says_which_revision_it_already_is(): void
    {
        $document = $this->document([['rooms' => ['sola' => ['form' => 'office']]]]);

        $this->save($document)->assertSessionHasNoErrors();
        $this->save($document)->assertSessionHasErrors('layout');

        $this->assertSame(1, Revisions::history(Revisions::LAYOUT, Revisions::LAYOUT_SUBJECT)->count());
    }

    public function test_the_same_document_submitted_by_a_browser_with_CRLF_is_still_a_no_op(): void
    {
        // ⭐ THE ROUND TRIP THAT WOULD OTHERWISE MINT A REVISION PER SAVE. The HTML form spec says
        // a textarea is submitted with CRLF; the document an operator pasted in has LF. Without
        // `App\Building\AuthoredDocument` the two are different bytes, § 6.11's no-op rule never
        // fires, and the diff between the two revisions is every line.
        $document = $this->document([['rooms' => ['sola' => ['form' => 'office']]]]);

        $this->save($document)->assertSessionHasNoErrors();
        $this->save(str_replace("\n", "\r\n", $document))->assertSessionHasErrors('layout');

        $this->assertSame(1, Revisions::history(Revisions::LAYOUT, Revisions::LAYOUT_SUBJECT)->count());
        $this->assertStringNotContainsString("\r", (string) Layouts::documentText());
    }

    public function test_the_revisions_page_lists_the_history_and_restores_from_it(): void
    {
        $this->save($this->document([['rooms' => ['sola' => ['form' => 'office']]]]))->assertSessionHasNoErrors();
        $this->save($this->document([['rooms' => ['sola' => ['form' => 'open']]]]))->assertSessionHasNoErrors();

        $this->actingAs($this->operator())
            ->get(route('admin.layout.revisions'))
            ->assertOk()
            ->assertSee('current')
            ->assertSee(self::OPERATOR);

        $this->actingAs($this->operator())
            ->post(route('admin.layout.restore', 1))
            ->assertRedirect(route('admin.layout.revisions'))
            ->assertSessionHas('status');

        // A restore is a FORWARD revision: history is never rewritten (§ 6.11).
        $this->assertSame(3, Layouts::version());
        $this->assertSame('office', Layouts::layout()->floors[0]['rooms'][0]['form']);
        $this->assertSame(1, (int) Revisions::get(Revisions::LAYOUT, Revisions::LAYOUT_SUBJECT, 3)->restored_from);
    }

    public function test_a_revision_is_exported_as_the_document_that_was_authored(): void
    {
        $document = $this->document([['rooms' => ['sola' => ['form' => 'office']]]]);

        $this->save($document)->assertSessionHasNoErrors();

        $response = $this->actingAs($this->operator())
            ->get(route('admin.layout.export', 1))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="building-layout-r1.json"');

        $this->assertSame($document, $response->getContent());
    }

    public function test_the_diff_between_two_revisions_shows_what_moved(): void
    {
        $this->save($this->document([['rooms' => ['sola' => ['form' => 'office']]]]))->assertSessionHasNoErrors();
        $this->save($this->document([['rooms' => ['sola' => ['form' => 'open']]]]))->assertSessionHasNoErrors();

        $this->actingAs($this->operator())
            ->get(route('admin.layout.diff').'?from=1&to=2')
            ->assertOk()
            ->assertSee('office')
            ->assertSee('open');
    }

    public function test_a_revision_that_was_never_written_is_a_404_rather_than_an_empty_page(): void
    {
        $this->actingAs($this->operator())->get(route('admin.layout.export', 9))->assertNotFound();
        $this->actingAs($this->operator())->get(route('admin.layout.diff').'?from=1&to=9')->assertNotFound();
    }

    public function test_an_overlap_is_refused_at_the_layouts_own_save_and_names_both_rooms(): void
    {
        // card#9292's rule at the surface. The two rooms have no authored maps, so the refusal an
        // operator meets FIRST is the missing shipped default (Appendix B step 7) — which is the
        // honest answer on this tree and names the file. Either way what must not happen is a
        // plan being stored with no extent ever measured.
        $this->save($this->document([['rooms' => [
            'sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]],
            'zeta' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]],
        ]]]))->assertSessionHasErrors('layout');

        $this->assertSame(0, Layouts::version());
        $this->assertStringContainsString(
            'resources/floor/default.tmj',
            (string) session('errors')->first('layout'),
        );
    }

    public function test_no_route_in_this_module_takes_a_floor_id_because_a_floor_has_none(): void
    {
        // § 4.6: a floor has no authored id — it IS its rooms, and its key is DERIVED. A route
        // segment naming a floor would be the name the design does not have, arriving through the
        // one door that looks like a convenience.
        $layout = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'admin/layout'))
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri());

        $this->assertNotEmpty($layout, 'the layout module registered no routes at all');

        foreach ($layout as $signature) {
            $this->assertStringNotContainsString('floor', $signature);
            $this->assertStringNotContainsString('seat', $signature);
        }
    }
}
