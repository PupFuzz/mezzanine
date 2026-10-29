<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Support\DrivesTheSeatTokensPage;
use Tests\TestCase;

/**
 * R-srv-1's server half — card#12571, D6 § 4.2 (`docs/design/REPORTER-INSTALL.md`): the issue response
 * carries a *Save token file* button that writes the v2 token file in the browser, and what the server
 * renders for it. The blob itself is built by `public/js/console/seat-token.js`; that the bytes it builds
 * parse through `fleet-reporter/install.js`'s `parseTokenFile` is `tools/design/seat-token-file.selftest.mjs`'s,
 * in CI; a real browser's click and restore are walked by hand with `tools/design/seat-token.browser.mjs`.
 *
 * ⛔ THE INGEST URL IS `app.url`'s, NEVER THE REQUEST'S. A request-derived URL (`url()`, `route()`,
 * `window.location`) follows whatever `Host` the request carried; the file would then point the
 * seat's reporter at that host. Every arm below runs on a request whose host is NOT `app.url`'s, so a
 * render that took the request's host reds: each arm sets `app.url` to a host the test client's
 * requests are not sent to, and the first arm checks that.
 */
class SeatTokenSaveFileTest extends TestCase
{
    use DrivesTheSeatTokensPage;
    use RefreshDatabase;

    /** The one save button a response renders, as its attributes, or null when it renders none. */
    private function saveButtonIn(TestResponse $response): ?array
    {
        $html = (string) $response->getContent();
        $count = preg_match_all('/<button\b[^>]*\bdata-seat-token-save\b[^>]*>/', $html, $m);
        $this->assertLessThanOrEqual(1, $count, 'the response renders more than one save button');
        if ($count === 0) {
            return null;
        }

        preg_match_all('/([a-z-]+)(?:="([^"]*)")?/', $m[0][0], $attrs, PREG_SET_ORDER);

        return collect($attrs)->mapWithKeys(fn (array $a) => [$a[1] => html_entity_decode($a[2] ?? '', ENT_QUOTES)])->all();
    }

    public function test_on_an_https_site_the_issue_response_renders_the_save_button_with_the_config_ingest_url_and_the_seat(): void
    {
        config(['app.url' => 'https://mezzanine.example/']);
        $this->confirmedOperator();

        $response = $this->issueOnPage('aimla', 'aimla-pm')->assertOk();
        $this->assertNotSame('mezzanine.example', request()->getHost(), 'the control: the request is not on app.url\'s host');

        $button = $this->saveButtonIn($response);
        $this->assertNotNull($button, 'the issue response renders no save button on an https site');
        $this->assertSame('https://mezzanine.example/api/ingest/events', $button['data-ingest-url'],
            'the ingest URL is not rtrim(app.url, "/")."/api/ingest/events"');
        $this->assertSame('aimla', $button['data-install-id']);
        $this->assertSame('aimla-pm', $button['data-seat-id']);
        $this->assertSame('button', $button['type'], 'a submit button would post the page\'s form');
        $response->assertSee('Save token file');
    }

    public function test_a_site_under_a_path_keeps_the_path_in_the_ingest_url(): void
    {
        config(['app.url' => 'https://example.org/mezzanine']);
        $this->confirmedOperator();

        $button = $this->saveButtonIn($this->issueOnPage('aimla', 'aimla-pm')->assertOk());

        $this->assertSame('https://example.org/mezzanine/api/ingest/events', $button['data-ingest-url'] ?? null);
    }

    /**
     * Operator Q2 (2026-10-29, "yes, both"): the page gains the button AND keeps showing the plaintext —
     * exactly once. The button carries no copy of it: the script reads the token element's text, so a
     * restore that blanks the element leaves nothing to save.
     */
    public function test_the_plaintext_stays_shown_once_and_the_button_carries_no_copy_of_it(): void
    {
        config(['app.url' => 'https://mezzanine.example']);
        $this->confirmedOperator();

        $response = $this->issueOnPage('aimla', 'aimla-pm')->assertOk();
        $token = $this->plaintextIn($response);

        $this->assertNotNull($token, 'the plaintext is no longer shown');
        $this->assertSame(1, substr_count((string) $response->getContent(), $token), 'the plaintext is rendered more than once');
        $button = $this->saveButtonIn($response);
        $this->assertNotNull($button, 'the control: the button is rendered');
        $this->assertNotContains($token, $button, 'the button carries the plaintext');
    }

    /** An `http:` site renders no button: § 4.2's parser refuses a non-https `ingest_url`, so the file would be refused. */
    public function test_on_an_http_site_no_save_button_is_rendered_and_the_token_is_still_shown(): void
    {
        config(['app.url' => 'http://mezzanine.example']);
        $this->confirmedOperator();

        $response = $this->issueOnPage('aimla', 'aimla-pm')->assertOk();

        $this->assertNull($this->saveButtonIn($response), 'an http site renders the save button');
        $response->assertDontSee('Save token file');
        $this->assertNotNull($this->plaintextIn($response), 'the control: this is the issue response, and it shows the token');
    }

    /** No token, nothing to save: the GET page renders no button. */
    public function test_the_page_without_an_issued_token_renders_no_save_button(): void
    {
        config(['app.url' => 'https://mezzanine.example']);
        $this->confirmedOperator();

        $page = $this->get(route('admin.tokens.index'))->assertOk();

        $this->assertNull($this->saveButtonIn($page));
        $this->assertNull($this->plaintextIn($page));
    }

    /**
     * The `fleet-reporter/…` paths a response names, each checked to be a file in this checkout and not
     * the moved runbook's redirect stub. Returns the paths, so an arm can assert which ones it expects.
     *
     * @return list<string>
     */
    private function guidePathsIn(TestResponse $response): array
    {
        preg_match_all('#fleet-reporter/[A-Za-z0-9_.-]+#', (string) $response->getContent(), $m);
        $paths = array_values(array_unique($m[0]));

        foreach ($paths as $path) {
            $this->assertFileExists(base_path('../'.$path), "the page names $path, which is not in the repository");
            $this->assertNotSame('fleet-reporter/INSTALL-LINUX.md', $path, 'the page names the moved runbook\'s redirect stub');
        }

        return $paths;
    }

    /**
     * Ledger row 16, as the merged guides tell it (`fleet-reporter/AGENT-INSTALL.md` § 2–3): save the
     * file, hand the agent its path, and the agent runs `install.js --token-file <path>`.
     */
    public function test_the_https_issue_response_tells_the_install_the_guides_describe(): void
    {
        config(['app.url' => 'https://mezzanine.example']);
        $this->confirmedOperator();

        $response = $this->issueOnPage('aimla', 'aimla-pm')->assertOk();

        $response->assertSee('node fleet-reporter/install.js --token-file &lt;that path&gt;', false);
        $response->assertSee('Do not paste the token to the agent.');
        $this->assertEqualsCanonicalizing(
            ['fleet-reporter/install.js', 'fleet-reporter/AGENT-INSTALL.md', 'fleet-reporter/INSTALL.md'],
            $this->guidePathsIn($response),
        );
    }

    /** `install.js` refuses a site that is not `https://` (`--site must be an https:// URL`), and the page says so. */
    public function test_the_http_issue_response_says_why_there_is_no_file_and_names_no_stub(): void
    {
        config(['app.url' => 'http://mezzanine.example']);
        $this->confirmedOperator();

        $response = $this->issueOnPage('aimla', 'aimla-pm')->assertOk();

        $response->assertSee('the reporter\'s installer accepts only an', false);
        $response->assertDontSee('--token-file', false);
        $this->assertSame(['fleet-reporter/INSTALL.md'], $this->guidePathsIn($response));
    }
}
