<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
$settings = require dirname(__DIR__) . '/config/settings.php';
$database = new Database($settings['database_path']);
$database->migrate();

$options = getopt('', ['file::', 'url::', 'ignore-on-conflict']);
$file = $options['file'] ?? ($_ENV['BGG_RANKS_FILE'] ?? null);
$url = $options['url'] ?? ($_ENV['BGG_RANKS_URL'] ?? 'https://boardgamegeek.com/data_dumps/bg_ranks');
$ignoreOnConflict = isset($options['ignore-on-conflict']);

if ($file !== null && !is_file($file)) {
    fwrite(STDERR, "CSV or ZIP file not found: $file\n");
    exit(1);
}

$path = $file ?? downloadDump($url, $settings['bgg_api_token']);
$temporaryPath = $file === null ? $path : null;
$imported = 0;

try {
    $database->beginTransaction();
    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv') {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open CSV file: $path");
        }
        $imported = importCsv($handle, $database, $ignoreOnConflict);
        fclose($handle);
    } else {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to import a ZIP dump.');
        }

        $archive = new ZipArchive();
        if ($archive->open($path) !== true) {
            throw new RuntimeException("Unable to open ZIP file: $path");
        }

        $csvIndex = null;
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = $archive->getNameIndex($index);
            if ($name !== false && str_ends_with(strtolower($name), '.csv')) {
                $csvIndex = $index;
                break;
            }
        }
        if ($csvIndex === null) {
            throw new RuntimeException('The ZIP dump does not contain a CSV file.');
        }

        $handle = $archive->getStream($archive->getNameIndex($csvIndex));
        if ($handle === false) {
            throw new RuntimeException('Unable to read the CSV file inside the ZIP dump.');
        }
        $imported = importCsv($handle, $database, $ignoreOnConflict);
        fclose($handle);
        $archive->close();
    }
    $database->commit();
} catch (Throwable $exception) {
    $database->rollBack();
    throw $exception;
} finally {
    if ($temporaryPath !== null) {
        @unlink($temporaryPath);
    }
}

fwrite(STDOUT, "Imported $imported games into SQLite.\n");

function importCsv($handle, Database $database, bool $ignoreOnConflict): int
{
    $headers = fgetcsv($handle, 0, ',', '"', '');
    if ($headers === false) {
        throw new RuntimeException('The CSV file is empty.');
    }

    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
    $headers = array_map(
        static fn (mixed $header): string => strtolower(trim((string) $header)),
        $headers
    );
    $columns = array_flip($headers);
    if (!isset($columns['id'], $columns['name'])) {
        throw new RuntimeException('The CSV must contain id and name columns.');
    }

    $imported = 0;
    $fetchedAt = gmdate('c');
    while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $id = nullableInt(csvValue($row, $columns, 'id'));
        $name = trim((string) csvValue($row, $columns, 'name'));
        if ($id === null || $name === '') {
            continue;
        }

        $database->saveCatalogGame([
            'bgg_id' => $id,
            'name' => $name,
            'is_expansion' => (int) (csvValue($row, $columns, 'is_expansion') ?? 0),
            'is_accessory' => 0,
            'is_compilation' => 0,
            'is_implementation' => 0,
            'year_published' => nullableInt(csvValue($row, $columns, 'yearpublished')),
            'average_rating' => nullableFloat(csvValue($row, $columns, 'average')),
            'bayes_average' => nullableFloat(csvValue($row, $columns, 'bayesaverage')),
            'users_rated' => nullableInt(csvValue($row, $columns, 'usersrated')),
            'rank' => nullableInt(csvValue($row, $columns, 'rank')),
            'weight' => nullableFloat(csvValueAny($row, $columns, ['weight', 'averageweight'])),
            'min_players' => nullableInt(csvValueAny($row, $columns, ['min_players', 'minplayers'])),
            'max_players' => nullableInt(csvValueAny($row, $columns, ['max_players', 'maxplayers'])),
            'min_play_time' => nullableInt(csvValueAny($row, $columns, ['min_play_time', 'minplaytime'])),
            'max_play_time' => nullableInt(csvValueAny($row, $columns, ['max_play_time', 'maxplaytime'])),
            'thumbnail' => null,
            'image' => null,
            'description' => null,
            'fetched_at' => $fetchedAt,
            'details_fetched_at' => null,
        ], $ignoreOnConflict);

        $imported++;
        if ($imported % 1000 === 0) {
            fwrite(STDERR, "\rImported $imported games...");
        }
    }

    if ($imported >= 1000) {
        fwrite(STDERR, "\n");
    }

    return $imported;
}

function downloadDump(string $url, string $apiToken): string
{
    $body = curlRequest($url, $apiToken);
    $temporaryPath = tempnam(sys_get_temp_dir(), 'bgg-ranks-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Unable to create a temporary download file.');
    }

    $urlPath = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
    if (strncmp($body, 'PK', 2) === 0 || str_ends_with($urlPath, '.zip')) {
        file_put_contents($temporaryPath, $body);
        return $temporaryPath;
    }
    if (str_ends_with($urlPath, '.csv')) {
        $csvPath = $temporaryPath . '.csv';
        file_put_contents($csvPath, $body);
        @unlink($temporaryPath);
        return $csvPath;
    }

    $downloadUrl = findZipLink($body, $url);
    if ($downloadUrl === null) {
        throw new RuntimeException(
            'BGG did not expose a public ZIP link. The dump page requires a logged-in browser session. '
            . 'Download the ZIP from BGG, place it in the project, and pass --file=/var/www/html/<dump>.zip.'
        );
    }

    file_put_contents($temporaryPath, curlRequest($downloadUrl, $apiToken));

    return $temporaryPath;
}

function curlRequest(string $url, string $apiToken): string
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING => '',
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => array_filter([
            'Accept: text/html, application/zip, text/csv, application/octet-stream',
            $apiToken !== '' ? 'Authorization: Bearer ' . trim($apiToken) : null,
        ]),
        CURLOPT_USERAGENT => 'Boardgamelist catalog importer/1.0',
    ]);

    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);

    if ($body === false) {
        throw new RuntimeException("Download failed: $error");
    }
    if ($status >= 400) {
        throw new RuntimeException("BGG dump returned HTTP $status.");
    }

    return $body;
}

function findZipLink(string $html, string $baseUrl): ?string
{
    preg_match_all('/(?:href|src)=["\']([^"\']+\.zip(?:\?[^"\']*)?)["\']/i', $html, $matches);
    foreach ($matches[1] ?? [] as $link) {
        $link = html_entity_decode($link, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (str_starts_with($link, 'http://') || str_starts_with($link, 'https://')) {
            return $link;
        }

        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            continue;
        }
        $root = $parts['scheme'] . '://' . $parts['host'];
        return str_starts_with($link, '/') ? $root . $link : rtrim(dirname($baseUrl), '/') . '/' . $link;
    }

    return null;
}

function csvValue(array $row, array $columns, string $column): ?string
{
    if (!isset($columns[$column])) {
        return null;
    }

    return isset($row[$columns[$column]]) ? trim((string) $row[$columns[$column]]) : null;
}

function csvValueAny(array $row, array $columns, array $possibleColumns): ?string
{
    foreach ($possibleColumns as $column) {
        $value = csvValue($row, $columns, $column);
        if ($value !== null && $value !== '') {
            return $value;
        }
    }

    return null;
}

function nullableInt(?string $value): ?int
{
    return $value !== null && is_numeric($value) ? (int) $value : null;
}

function nullableFloat(?string $value): ?float
{
    return $value !== null && is_numeric($value) ? (float) $value : null;
}
