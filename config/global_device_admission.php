<?php

return [
    // Off by default until Redis, panel and two independent node processes
    // have passed end-to-end admission, expiry and failure-mode acceptance.
    'enabled' => env('GLOBAL_DEVICE_ADMISSION_ENABLED', false),
];
