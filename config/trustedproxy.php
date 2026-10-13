<?php

return [
    // Proxies whose X-Forwarded-* headers are believed, as comma-separated
    // addresses or CIDR ranges. Unset is null, which leaves Laravel's own
    // default in force (it trusts '*' on Laravel Cloud and *.on-forge.com
    // hosts). The public demo sets this to Cloudflare's ranges so the rate
    // limit sees visitors, not Cloudflare.
    'proxies' => (function () {
        $list = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', '')),
        )));

        return $list === [] ? null : $list;
    })(),
];
