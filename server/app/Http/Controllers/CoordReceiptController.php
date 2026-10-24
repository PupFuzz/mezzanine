<?php

namespace App\Http\Controllers;

use App\Coord\ReceiptPipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/ingest/github/{hook}` — the coordination receipt (D1 § 18.8, card#12239). Authenticated by
 * the HMAC of the binding `{hook}` names and nothing else: no cookie, no session, no bearer token.
 */
class CoordReceiptController extends Controller
{
    public function __construct(private readonly ReceiptPipeline $pipeline) {}

    public function receive(Request $request, string $hook): JsonResponse
    {
        return $this->pipeline->handle($request, $hook);
    }
}
