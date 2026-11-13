<?php

declare(strict_types=1);

use App\Http\AppException;
use App\Http\Json;
use App\Infrastructure\BggClient;
use App\Infrastructure\Database;
use Dotenv\Dotenv;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
$settings = require dirname(__DIR__) . '/config/settings.php';

$database = new Database($settings['database_path']);
$database->migrate();
$bgg = new BggClient($settings['bgg_base_url'], $settings['bgg_api_token']);

$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

$app->get('/', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
    $html = file_get_contents(dirname(__DIR__) . '/public/index.html');
    $response->getBody()->write($html === false ? '<h1>Boardgamelist</h1>' : $html);

    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

$app->get('/health', function (ServerRequestInterface $request, ResponseInterface $response) use ($settings, $database): ResponseInterface {
    $stats = $database->catalogStats();

    return Json::response($response, [
        'status' => 'ok',
        'environment' => $settings['app_env'],
        'database' => 'sqlite',
        'catalog_total' => $stats['total'],
        'catalog_enriched' => $stats['enriched'],
    ]);
});

$app->get('/api/catalog', function (ServerRequestInterface $request, ResponseInterface $response) use ($database): ResponseInterface {
    $params = $request->getQueryParams();
    $maxWeightInput = $params['max_weight'] ?? null;
    $maxWeightIsUnset = array_key_exists('max_weight', $params)
        && in_array(strtolower(trim((string) $maxWeightInput)), ['', 'null'], true);
    $maxWeight = $maxWeightIsUnset
        ? null
        : max(0.1, (float) ($maxWeightInput ?? 3));
    $minRating = max(0, min(10, (float) ($params['min_rating'] ?? 6.6)));
    $players = max(1, min(99, (int) ($params['players'] ?? 2)));
    $limit = max(1, min(100, (int) ($params['limit'] ?? 50)));
    $offset = max(0, (int) ($params['offset'] ?? 0));
    $minYear = queryInteger($params['min_year'] ?? 2025);
    $maxYear = queryInteger($params['max_year'] ?? null);
    $minUsersRated = max(0, (int) ($params['min_users_rated'] ?? 500));
    $maxPlayers = queryInteger($params['max_players'] ?? null);
    $includeCompilations = filter_var($params['include_compilations'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $includeImplementations = filter_var($params['include_implementations'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $includeAccessories = filter_var($params['include_accessories'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $stats = $database->catalogStats();
    // Fetch one extra row so the client can know whether a next page exists.
    $games = $database->findCatalogGames(
        $maxWeight,
        $minRating,
        $players,
        $limit + 1,
        $offset,
        $minYear,
        $maxYear,
        $minUsersRated,
        $includeCompilations,
        $includeImplementations,
        $includeAccessories,
        $maxPlayers
    );
    $hasMore = count($games) > $limit;
    $games = array_slice($games, 0, $limit);

    return Json::response($response, [
        'data' => array_map('presentGame', $games),
        'meta' => [
            'filters' => [
                'max_weight' => $maxWeight,
                'min_rating' => $minRating,
                'players' => $players,
                'min_year' => $minYear,
                'max_year' => $maxYear,
                'min_users_rated' => $minUsersRated,
                'max_players' => $maxPlayers,
                'include_compilations' => $includeCompilations,
                'include_implementations' => $includeImplementations,
                'include_accessories' => $includeAccessories,
            ],
            'count' => count($games),
            'has_more' => $hasMore,
            'offset' => $offset,
            'limit' => $limit,
            'catalog_total' => $stats['total'],
            'catalog_enriched' => $stats['enriched'],
            'core_source' => 'BGG ranks CSV import',
            'detail_source' => 'BGG XML API cache',
            'is_complete_bgg_listing' => false,
        ],
    ]);
});

$app->get('/api/games', function (ServerRequestInterface $request, ResponseInterface $response) use ($database, $bgg): ResponseInterface {
    $params = $request->getQueryParams();
    $query = trim((string) ($params['query'] ?? ''));
    if ($query === '') {
        return Json::response($response, ['error' => 'The query parameter is required.'], 400);
    }

    $limit = (int) ($params['limit'] ?? 20);
    $limit = max(1, min(20, $limit));
    $exact = filter_var($params['exact'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $refresh = filter_var($params['refresh'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $summaries = $bgg->searchGames($query, $exact);
    $summaries = array_slice($summaries, 0, $limit);
    $ids = array_column($summaries, 'id');
    $cached = [];
    foreach ($database->findGamesByIds($ids) as $game) {
        $cached[(int) $game['bgg_id']] = $game;
    }

    $now = time();
    $missingOrStale = [];
    foreach ($ids as $id) {
        $game = $cached[(int) $id] ?? null;
        $isFresh = $game !== null
            && !empty($game['details_fetched_at'])
            && strtotime((string) $game['details_fetched_at']) >= $now - 86400;
        if ($refresh || !$isFresh) {
            $missingOrStale[] = (int) $id;
        }
    }

    foreach ($bgg->getGamesByIds($missingOrStale) as $game) {
        $database->saveGame($game);
        $cached[(int) $game['bgg_id']] = $game;
    }

    $games = array_values(array_filter(array_map(
        static fn (int $id): ?array => $cached[$id] ?? null,
        array_map('intval', $ids)
    ), static fn (?array $game): bool => $game !== null && matchesFilters($game, $params)));

    return Json::response($response, [
        'data' => array_map('presentGame', $games),
        'meta' => [
            'query' => $query,
            'count' => count($games),
            'limit' => $limit,
            'cached_for_seconds' => 86400,
        ],
    ]);
});

$app->get('/api/games/{id}', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($database, $bgg): ResponseInterface {
    $id = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        return Json::response($response, ['error' => 'The game id must be a positive integer.'], 400);
    }

    $game = $database->findGame($id);
    $params = $request->getQueryParams();
    $refresh = filter_var($params['refresh'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $isFresh = $game !== null
        && !empty($game['details_fetched_at'])
        && strtotime((string) $game['details_fetched_at']) >= time() - 86400;

    if ($refresh || !$isFresh) {
        $details = $bgg->getGamesByIds([$id]);
        if ($details === []) {
            return Json::response($response, ['error' => 'Game not found.'], 404);
        }
        $game = $details[$id];
        $database->saveGame($game);
    }

    return Json::response($response, ['data' => presentGame($game)]);
});

function matchesFilters(array $game, array $params): bool
{
    $filters = [
        ['min_players', 'min_players', '>='],
        ['max_players', 'max_players', '<='],
        ['min_play_time', 'min_play_time', '>='],
        ['max_play_time', 'max_play_time', '<='],
        ['min_year', 'year_published', '>='],
        ['max_year', 'year_published', '<='],
        ['min_rating', 'average_rating', '>='],
    ];

    foreach ($filters as [$input, $field, $operator]) {
        if (!isset($params[$input]) || $params[$input] === '') {
            continue;
        }
        if ($game[$field] === null || !is_numeric((string) $params[$input])) {
            return false;
        }

        $wanted = (float) $params[$input];
        $actual = (float) $game[$field];
        if (($operator === '>=' && $actual < $wanted) || ($operator === '<=' && $actual > $wanted)) {
            return false;
        }
    }

    return true;
}

function presentGame(array $game): array
{
    return [
        'id' => (int) $game['bgg_id'],
        'name' => $game['name'],
        'bgg_url' => 'https://boardgamegeek.com/boardgame/' . (int) $game['bgg_id'],
        'is_enriched' => !empty($game['details_fetched_at']),
        'needs_enrichment' => empty($game['details_fetched_at'])
            || $game['weight'] === null
            || $game['min_players'] === null
            || $game['max_players'] === null
            || (empty($game['image']) && empty($game['thumbnail'])),
        'details_fetched_at' => $game['details_fetched_at'] ?? null,
        'is_expansion' => (int) ($game['is_expansion'] ?? 0),
        'is_accessory' => (int) ($game['is_accessory'] ?? 0),
        'is_compilation' => (int) ($game['is_compilation'] ?? 0),
        'is_implementation' => (int) ($game['is_implementation'] ?? 0),
        'year_published' => nullableNumber($game['year_published']),
        'players' => [
            'min' => nullableNumber($game['min_players']),
            'max' => nullableNumber($game['max_players']),
        ],
        'play_time_minutes' => [
            'min' => nullableNumber($game['min_play_time']),
            'max' => nullableNumber($game['max_play_time']),
        ],
        'rating' => nullableNumber($game['average_rating']),
        'bayes_rating' => nullableNumber($game['bayes_average'] ?? null),
        'users_rated' => nullableNumber($game['users_rated'] ?? null),
        'weight' => nullableNumber($game['weight'] ?? null),
        'rank' => nullableNumber($game['rank']),
        'thumbnail' => $game['thumbnail'],
        'image' => $game['image'],
        'description' => $game['description'],
        'cached_at' => $game['fetched_at'],
    ];
}

function nullableNumber(mixed $value): int|float|null
{
    if ($value === null || $value === '') {
        return null;
    }

    return str_contains((string) $value, '.') ? (float) $value : (int) $value;
}

function queryInteger(mixed $value): ?int
{
    return $value !== null && $value !== '' && is_numeric((string) $value)
        ? (int) $value
        : null;
}

$errorMiddleware = $app->addErrorMiddleware(
    $settings['display_error_details'],
    true,
    true
);
$responseFactory = $app->getResponseFactory();
$errorMiddleware->setDefaultErrorHandler(
    static function (
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ) use ($responseFactory): ResponseInterface {
        $status = $exception instanceof AppException ? $exception->status() : 500;
        $message = $displayErrorDetails ? $exception->getMessage() : 'Internal server error.';

        return Json::response($responseFactory->createResponse(), ['error' => $message], $status);
    }
);

$app->run();
