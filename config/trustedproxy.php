<?php

return [
    // Proxies whose X-Forwarded-* headers are believed, as comma-separated
    // addresses or CIDR ranges. Unset trusts none. The public demo sets this to
    // Cloudflare's ranges so the rate limit sees visitors, not Cloudflare.
    'proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', '')),
    ))),
];
