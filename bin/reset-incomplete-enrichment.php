<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
$settings = require dirname(__DIR__) . '/config/settings.php';
$database = new Database($settings['database_path']);
$database->migrate();

$database->beginTransaction();
try {
    $reset = $database->resetIncompleteDetails();
    $database->commit();
} catch (Throwable $exception) {
    $database->rollBack();
    throw $exception;
}

fwrite(
    STDOUT,
    "Reset $reset records with fetched timestamps but incomplete weight/player details.\n"
    . "Run bin/enrich-bgg-details.php again to fetch them.\n"
);
