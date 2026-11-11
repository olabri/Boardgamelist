<?php

return [
    'app_env' => $_ENV['APP_ENV'] ?? 'local',
    'display_error_details' => filter_var(
        $_ENV['DISPLAY_ERROR_DETAILS'] ?? false,
        FILTER_VALIDATE_BOOLEAN
    ),
    'database_path' => $_ENV['DB_PATH'] ?? dirname(__DIR__) . '/storage/database.sqlite',
    'bgg_base_url' => rtrim(
        $_ENV['BGG_BASE_URL'] ?? 'https://boardgamegeek.com/xmlapi2',
        '/'
    ),
    'bgg_api_token' => $_ENV['API_TOKEN'] ?? '',
];
