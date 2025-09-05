<?php

return [
    'enabled' => true,
    'default_currency' => 'NZD',
    'default_auto_release_hours' => 48,
    'default_auto_renew' => true,
    'default_capture_rule' => 'manual', // manual | auto_if_unpaid
    'webhook_header' => 'X-HOLDS-Signature',
    'events' => [
        'created'  => 'hold.created',
        'renewed'  => 'hold.renewed',
        'released' => 'hold.released',
        'captured' => 'hold.captured',
        'failed'   => 'hold.failed',
    ],
];
