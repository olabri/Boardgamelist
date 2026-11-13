<?php

declare(strict_types=1);

use App\Infrastructure\BggClient;
use App\Infrastructure\Database;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
$settings = require dirname(__DIR__) . '/config/settings.php';
$database = new Database($settings['database_path']);
$database->migrate();
$client = new BggClient($settings['bgg_base_url'], $settings['bgg_api_token']);

$options = getopt('', [
    'min-rating::', 'limit::', 'offset::', 'delay::',
    'min-year::', 'max-year::',
    'min-users-rated::', 'missing-image', 'missing-details', 'refresh',
]);
$minRating = max(0, min(10, (float) ($options['min-rating'] ?? 6.6)));
$limit = max(1, min(1000, (int) ($options['limit'] ?? 100)));
$offset = max(0, (int) ($options['offset'] ?? 0));
$delay = max(0, (int) ($options['delay'] ?? 5));
$minYear = queryInteger($options['min-year'] ?? null);
$maxYear = queryInteger($options['max-year'] ?? null);
$minUsersRated = max(0, (int) ($options['min-users-rated'] ?? 500));
$refresh = isset($options['refresh']);
$missingImageOnly = isset($options['missing-image']);
$missingDetails = isset($options['missing-details']);
$ids = $database->findUnenrichedIds(
    $minRating,
    $limit,
    $minYear,
    $maxYear,
    $minUsersRated,
    $refresh,
    $offset,
    $missingImageOnly,
    $missingDetails
);

if ($ids === []) {
    fwrite(STDOUT, "No unenriched games matched the selected filters.\n");
    exit(0);
}

function queryInteger(mixed $value): ?int
{
    return $value !== null && $value !== '' && is_numeric((string) $value)
        ? (int) $value
        : null;
}

$processed = 0;
foreach (array_chunk($ids, 20) as $chunkIndex => $chunk) {
    foreach ($client->getGamesByIds($chunk) as $game) {
        $database->saveGame($game);
        $processed++;
    }

    fwrite(STDOUT, "Enriched $processed of " . count($ids) . " games.\n");
    if ($delay > 0 && $chunkIndex < count(array_chunk($ids, 20)) - 1) {
        sleep($delay);
    }
}

