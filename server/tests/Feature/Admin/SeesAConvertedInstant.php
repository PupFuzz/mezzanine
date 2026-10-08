<?php

namespace Tests\Feature\Admin;

use App\Fold\Clock;
use Illuminate\Testing\TestResponse;

/**
 * card#9446 — a console page printed a stored instant through the one converter: machine-readable UTC
 * in a `<time datetime="…Z" data-utc>`, labelled UTC for a page with no JavaScript, which
 * `public/js/local-times.js` rewrites to the viewer's browser zone. The expected markup is written out
 * here from the stored value, never produced by `App\Support\UtcTime`, so a defect there cannot pass
 * by agreeing with itself.
 */
trait SeesAConvertedInstant
{
    protected function assertSeesConvertedInstant(TestResponse $page, string|\DateTimeInterface $stored): void
    {
        if ($stored instanceof \DateTimeInterface) {
            $stored = Clock::sql(\DateTimeImmutable::createFromInterface($stored)->setTimezone(new \DateTimeZone('UTC')));
        }

        $page->assertSee(
            '<time datetime="'.Clock::wire($stored).'" data-utc>'.substr($stored, 0, 19).' UTC</time>',
            escape: false,
        );
    }
}
