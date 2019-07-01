<?php

namespace Amp\Http\Client\Cache;

/**
 * @se https://tools.ietf.org/html/rfc7234.html#section-5.2.1
 */
final class RequestCacheControlDirective
{
    /** @see https://tools.ietf.org/html/rfc7234.html#section-5.2.1.1 */
    public const MAX_AGE = 'max-age';

    /** @see https://tools.ietf.org/html/rfc7234.html#section-5.2.1.2 */
    public const MAX_STALE = 'max-stale';

    /** @see https://tools.ietf.org/html/rfc7234.html#section-5.2.1.3 */
    public const MIN_FRESH = 'min-fresh';

    /** @see https://tools.ietf.org/html/rfc7234.html#section-5.2.1.4 */
    public const NO_CACHE = 'no-cache';

    /** @see https://tools.ietf.org/html/rfc7234.html#section-5.2.1.5 */
    public const NO_STORE = 'no-store';

    /** @see https://tools.ietf.org/html/rfc7234.html#section-5.2.1.6 */
    public const NO_TRANSFORM = 'no-transform';

    /** @see https://tools.ietf.org/html/rfc7234.html#section-5.2.1.7 */
    public const ONLY_IF_CACHED = 'only-if-cached';
}
