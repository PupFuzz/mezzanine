<?php

namespace App\Coord;

/**
 * An operator act on a hook binding that the store's rules refuse — named, so the command can say
 * which rule (COORD-STORE.md § 3.2). It carries no secret and no ciphertext, ever.
 */
final class BindingRefused extends \RuntimeException {}
