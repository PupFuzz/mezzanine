<?php

namespace App\Coord;

/**
 * The coordination route's DERIVED counters — `docs/design/EVENT-SCHEMA.md § 18.8.1`'s rows that are not
 * marked *specified, not derived*, stored where `docs/design/FLEET-STATE.md § 7.1` puts them
 * (`global_counters`) and surfaced on `GET /api/fleet/health` (§ 8.2.4). card#12239.
 *
 * The definitions are § 18.8.1's and are not restated here; this class holds the NAMES once so the
 * receipt, the derivation and `App\Read\FleetHealth` cannot spell one differently. The two families
 * carry a suffix: `coord_delivery_received.<hook>` per binding (the binding's public `{hook}` id, r1
 * F13) and `coord_delivery_ignored.<event_type>` per subscribed event type.
 */
final class CoordCounters
{
    public const RECEIVED = 'coord_delivery_received.';

    public const SIGNATURE_INVALID = 'coord_signature_invalid';

    public const DUPLICATE = 'coord_delivery_duplicate';

    public const IGNORED = 'coord_delivery_ignored.';

    public const ATTRIBUTION_UNRESOLVED = 'coord_attribution_unresolved';

    public const NAME_MALFORMED = 'coord_name_malformed';

    public const TO_FROM_LABELS = 'coord_to_from_labels';

    /** The event types the hook subscribes (D1 § 18.8: `issues`, `issue_comment`) and GitHub's `ping`. */
    public const SUBSCRIBED_EVENTS = ['issues', 'issue_comment', 'ping'];
}
