<?php

namespace App\Services\Digest;

use RuntimeException;

/**
 * The digest model could not be asked, or its answer could not be read.
 *
 * Distinct from an empty proposal list, which is the model saying nothing in
 * the slice is worth keeping. Both used to come back as [], so a timeout was
 * recorded as a successful empty digest: the client moved past those turns and
 * a retry was served the empty replay. Nothing is persisted on this path.
 */
class DigestUnavailable extends RuntimeException
{
    /**
     * @param  bool  $retryable  Whether sending the same slice again could succeed:
     *                           true for a timeout, a 429 or a 5xx, false for a
     *                           missing key or any other 4xx.
     */
    public function __construct(string $reason, public readonly bool $retryable)
    {
        parent::__construct($reason);
    }
}
