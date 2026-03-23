<?php

$config = require base_path('vendor/prism-php/prism/config/prism.php');

$config['request_timeout'] = (int) env('AI_REQUEST_TIMEOUT', env('PRISM_REQUEST_TIMEOUT', 30));
$config['providers']['openai']['url'] = env(
    'OPENAI_BASE_URL',
    env('OPENAI_URL', 'https://api.openai.com/v1')
);

return $config;
