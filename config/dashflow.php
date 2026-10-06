<?php

return [

    'health' => [
        // Database connection the PostgreSQL health check probes (null = default).
        'connection' => env('HEALTH_DB_CONNECTION'),

        // Port nginx listens on inside the web container (local health check).
        'web_port' => (int) env('DASHFLOW_WEB_PORT', 8080),
    ],

];
