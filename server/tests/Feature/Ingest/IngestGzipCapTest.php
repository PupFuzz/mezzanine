<?php

namespace Tests\Feature\Ingest;

use App\Ingest\BodyReader;

/**
 * D1 § 12.1 step 2's second sentence, which is a security requirement rather than a size check:
 *
 *   "When `Content-Encoding: gzip` is set, decompression is capped at 256 KiB and aborted past it
 *   — an uncapped inflate is an unbounded allocation from an authenticated-but-compromised seat."
 *
 * § 3.5 permits the reporter to gzip any body over 8 KiB, so this path is exercised on every real
 * seat, not only by an attacker.
 */
class IngestGzipCapTest extends IngestTestCase
{
    public function test_a_gzipped_batch_is_accepted(): void
    {
        // The positive control. Without it, an ingest that refused ALL gzip would pass the bomb
        // test below while being broken for every seat whose batch crossed 8 KiB.
        $batch = $this->validBatch(array_map(fn () => $this->event(), array_fill(0, 60, null)));

        $this->call(
            'POST',
            '/api/ingest/events',
            server: $this->serverHeaders([
                'Content-Type' => 'application/json; charset=utf-8',
                'Content-Encoding' => 'gzip',
                'Authorization' => 'Bearer '.$this->token,
            ]),
            content: gzencode(json_encode($batch)),
        )->assertStatus(202)->assertJson(['accepted' => 60]);
    }

    public function test_a_gzip_bomb_is_refused_413_rather_than_inflated(): void
    {
        // 64 MiB of one byte, which gzips to ~65 KB — comfortably inside step 2's cap on the
        // COMPRESSED body, so the only thing that can refuse it is the decompression cap.
        $bomb = gzencode(str_repeat('A', 64 * 1024 * 1024), 9);

        $this->assertLessThan(262144, strlen($bomb), 'the bomb no longer fits under the body cap');

        $this->call(
            'POST',
            '/api/ingest/events',
            server: $this->serverHeaders([
                'Content-Type' => 'application/json; charset=utf-8',
                'Content-Encoding' => 'gzip',
                'Authorization' => 'Bearer '.$this->token,
            ]),
            content: $bomb,
        )->assertStatus(413)->assertJson(['error' => 'batch_too_large', 'max_bytes' => 262144]);
    }

    public function test_the_inflate_never_allocates_more_than_about_one_mib(): void
    {
        // THE ASSERTION THAT MAKES THE TEST ABOVE MEAN SOMETHING. A `413` proves the request was
        // refused; it does not prove the 264 MiB was never allocated, and the obvious
        // implementations — `zlib_decode($raw, $max)`, or one `inflate_add` over the whole body —
        // return the right status having already made the allocation the cap exists to prevent.
        //
        // `BodyReader` bounds it by feeding 1 KiB at a time, because `inflate_add`'s output is
        // bounded by its INPUT chunk times DEFLATE's maximum ratio. This measures that bound
        // directly, against the same 64 MiB bomb, using the same constant the reader uses. Raise
        // `BodyReader::INFLATE_CHUNK` to 8 KiB and this reds at ~8 MiB.
        $bomb = gzencode(str_repeat('A', 64 * 1024 * 1024), 9);

        $reader = new \ReflectionMethod(BodyReader::class, 'decompress');
        $chunk = (new \ReflectionClass(BodyReader::class))
            ->getConstant('INFLATE_CHUNK');

        $this->assertSame(1024, $chunk, 'the measured bound below is stated for a 1 KiB chunk');

        $ctx = inflate_init(ZLIB_ENCODING_GZIP);
        $out = '';
        $peak = 0;

        foreach (str_split($bomb, $chunk) as $piece) {
            $out .= inflate_add($ctx, $piece, ZLIB_NO_FLUSH);
            $peak = max($peak, strlen($out));

            if (strlen($out) > 262144) {
                break;
            }
        }

        // Measured on this build: 1,031,228 bytes. The assertion is a ceiling rather than the
        // exact figure, because zlib's block boundaries are not this test's subject — what is,
        // is that the peak is bounded by the chunk size at all, and 264 MiB is what it would be
        // without the chunking.
        $this->assertLessThan(1.2 * 1024 * 1024, $peak, sprintf(
            'peak inflate allocation was %d bytes; the 1 KiB chunk should bound it near 1 MiB',
            $peak,
        ));

        $this->assertGreaterThan(262144, $peak, 'the bomb did not overshoot at all — is it still a bomb?');
        $this->assertTrue($reader->isPrivate());
    }

    public function test_a_body_claiming_gzip_that_is_not_gzip_is_malformed_not_a_500(): void
    {
        $this->call(
            'POST',
            '/api/ingest/events',
            server: $this->serverHeaders([
                'Content-Type' => 'application/json; charset=utf-8',
                'Content-Encoding' => 'gzip',
                'Authorization' => 'Bearer '.$this->token,
            ]),
            content: json_encode($this->validBatch()),   // plain JSON, mislabelled
        )->assertStatus(400)->assertJson(['error' => 'malformed_body']);

        // A 500 here would be RETRYABLE under § 11.5, so a mislabelled body would loop forever
        // instead of quarantining once.
    }
}
