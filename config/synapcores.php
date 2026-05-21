<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | SynapCores AIDB connection settings
    |--------------------------------------------------------------------------
    |
    | All values are read from environment variables so that no credentials
    | are stored in source control.  See .env.example for required keys.
    |
    | Auth note: the SDK uses ApiKey auth by default (Authorization: ApiKey).
    | jwt_username / jwt_password are the fallback when no API key is set —
    | they trigger the POST /v1/auth/login exchange and JWT caching path.
    |
    */

    'base_url' => env('SYNAPCORES_BASE_URL', 'http://127.0.0.1:8080'),

    'api_key' => env('SYNAPCORES_API_KEY'),

    'jwt_username' => env('SYNAPCORES_JWT_USERNAME'),

    'jwt_password' => env('SYNAPCORES_JWT_PASSWORD'),

    'default_database' => env('SYNAPCORES_DEFAULT_DATABASE', 'main'),

    'timeout_seconds' => (int) env('SYNAPCORES_TIMEOUT_SECONDS', 30),

    /*
    | When true, the SDK uses SQL CREATE EXPERIMENT / TRAIN / PREDICT USING
    | extensions instead of the REST /v1/automl/* endpoints.  The CE build's
    | SQL engine is unstable (see notes/synapcores-quirks.md), so this
    | defaults to false — REST is the primary path.
    */
    'use_sql_automl' => (bool) env('SYNAPCORES_USE_SQL_AUTOML', false),

];
