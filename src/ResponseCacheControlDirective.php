<?php

namespace Amp\Http\Client\Cache;

/**
 * @se https://tools.ietf.org/html/rfc7234.html#section-5.2.1
 */
final class ResponseCacheControlDirective
{
    /** @see https://tools.ietf.org/html/rfc7234.html#section-5.2.2.1 */
    public const MUST_REVALIDATE = 'must-revalidate';

    /** @see https://tools.ietf.org/html/rfc7234.html#section-5.2.2.2 */
    public const NO_CACHE = 'no-cache';

    /** @see https://tools.ietf.org/html/rfc7234.html#section-5.2.2.3 */
    public const NO_STORE = 'no-store';

    /** @see https://tools.ietf.org/html/rfc7234.html#section-5.2.2.4 */
    public const NO_TRANSFORM = 'no-transform';

    /** @see https://tools.ietf.org/html/rfc7234.html#section-5.2.2.5 */
    public const PUBLIC = 'public';

    /** @see https://tools.ietf.org/html/rfc7234.html#section-5.2.2.6 */
    public const PRIVATE = 'private';

    /** @see https://tools.ietf.org/html/rfc7234.html#section-5.2.2.7 */
    public const PROXY_REVALIDATE = 'proxy-revalidate';

    /** @see https://tools.ietf.org/html/rfc7234.html#section-5.2.2.8 */
    public const MAX_AGE = 'max-age';

    /** @see https://tools.ietf.org/html/rfc7234.html#section-5.2.2.9 */
    public const S_MAXAGE = 's-maxage';
}