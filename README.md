# Boardgamelist API

A Slim 4 PHP API that searches BoardGameGeek and caches game details in a temporary SQLite database.

## Execution environment

All PHP, Composer, application scripts, tests, linters, and language-server commands run inside the DDEV web container. Do not run project PHP commands directly on the host. Use `ddev exec`:

```sh
ddev start
ddev exec composer install
ddev exec php -v
ddev exec php bin/enrich-bgg-details.php --limit=100
```

Phpactor is installed into the DDEV web container during the image build. The Zed configuration starts it as:

```sh
ddev exec phpactor language-server
```

Rebuild the web image after changing `.ddev/web-build/Dockerfile`:

```sh
ddev debug rebuild
```

## Requirements

- PHP 8.2+
- Composer
- PHP extensions: `curl`, `dom`, `pdo_sqlite`
- A BoardGameGeek API token

## Run with DDEV

```sh
cp .env.example .env
# Set API_TOKEN in .env
ddev start
ddev exec composer install
```

Open the URL printed by `ddev start`.

The SQLite file is created at `storage/database.sqlite` on first request. Relative `DB_PATH` values are resolved from the project root.

For a Git deployment, configure the web server document root as `public/` and enable Apache overrides/rewrite rules. The included `public/.htaccess` routes `/api/*` and other non-file paths through Slim’s `public/index.php`.

The SQLite database is intentionally excluded from Git. A fresh deployment therefore starts with an empty database. Copy `storage/database.sqlite` to the deployed project separately, or import the BGG ranks dump and enrich it there. Ensure the web-server user can read and write the `storage/` directory and database file.

## Endpoints

`GET /health` checks that the application is running.

`GET /api/catalog` lists the local catalog. Defaults are weight below `3`, rating above `6.6`, playable with `2` players, and at least `1,000` user ratings. Clear the weight field to remove the weight filter entirely. Games with unknown weight or player counts are retained; they appear with `Weight unknown` or `Players unknown` because missing enriched data does not exclude a catalog row. Each result links to its BGG page. Records without detail data or an image show an enrich button that refreshes the game through `GET /api/games/{id}?refresh=true`. The filters can be changed with `max_weight`, `min_rating`, `players`, `min_year`, `max_year`, `min_users_rated`, `limit`, and `offset`. The response includes `meta.has_more` for pagination.

Expansions are excluded by default. The optional boolean filters `include_compilations`, `include_implementations`, and `include_accessories` include BGG compilations/Big Box products, implementations/editions, or accessories respectively.

Classification is learned from enriched XML details. After adding these filters, rerun the ranks import without `--ignore-on-conflict`, then enrich the records you want to classify:

```sh
ddev exec php bin/import-bgg-ranks.php --file=/var/www/html/storage/boardgames_ranks_2026-11-11.zip
ddev exec php bin/enrich-bgg-details.php --min-rating=6.6 --limit=200
```

`GET /api/games` searches BGG and returns up to 20 games. `query` is required.

Supported query parameters:

- `query`: text searched by BGG
- `exact`: `true` or `false`
- `limit`: 1–20, default `20`
- `refresh`: bypass the 24-hour SQLite cache
- `min_players`, `max_players`
- `min_play_time`, `max_play_time` (minutes)
- `min_year`, `max_year`
- `min_rating` (BGG rating, 0–10)

Example:

```sh
curl 'http://127.0.0.1:8080/api/games?query=catan&min_players=3&max_play_time=120'
curl 'http://127.0.0.1:8080/api/games/13'
curl 'http://127.0.0.1:8080/api/catalog?max_weight=3&min_rating=6.6&players=2'
```

## Import the BGG ranks dump

The official BGG ranks dump is the efficient bulk source for game names, IDs, years, ranks, and ratings. It does not contain weight or player-count fields, so those fields are populated when game details are fetched through the XML API. The importer accepts either a downloaded CSV/ZIP file or a BGG dump page/direct download URL:

```sh
ddev exec composer install
# Download the latest ZIP from BGG in a logged-in browser and place it under storage/:
ddev exec php bin/import-bgg-ranks.php --file=/var/www/html/storage/boardgames_ranks_YYYY-MM-DD.zip
# Preserve existing rows instead of updating them:
ddev exec php bin/import-bgg-ranks.php --ignore-on-conflict --file=/var/www/html/storage/boardgames_ranks_YYYY-MM-DD.zip
```

The page download uses a signed, logged-in redirect, so the `API_TOKEN` used for XML API requests cannot authenticate the dump download. A direct signed URL can also be passed with `--url` if you have one.

The frontend outlines the weight and player filters because they depend on enriched XML details. Unknown weight and player values are allowed through the catalog filter and are shown explicitly in result cards. The BGG XML API2 client continues to cache detailed records in SQLite for searched or explicitly requested games.

To populate the default weight/player filters for the highest-rated catalog games, enrich a bounded batch through the API:

```sh
ddev exec php bin/enrich-bgg-details.php --min-rating=6.6 --min-users-rated=1000 --limit=100
# Enrich only games published from 2026 onward:
ddev exec php bin/enrich-bgg-details.php --min-rating=6.6 --min-year=2026 --limit=200
# Enrich a slice starting at offset 1000, up to 1000 matching games:
ddev exec php bin/enrich-bgg-details.php --min-rating=6.6 --min-year=2025 --max-year=2025 --offset=1000 --limit=1000
```

The script requests at most 20 IDs per BGG call and waits five seconds between calls to respect the documented API throttling guidance. `--offset` is applied after year/rating/user-count filters and after excluding already enriched games, unless `--refresh` is used.

Enrichment skips games that already have `details_fetched_at` by default. Re-fetch existing details only when explicitly requested:

```sh
ddev exec php bin/enrich-bgg-details.php --refresh --limit=100
```

### Catalog metadata

The BGG ranks CSV provides the catalog’s basic metadata:

- `bgg_id` — primary key
- `name`
- `is_expansion` — `0` for base games, `1` for expansions
- `is_compilation` — BGG compilation or Big Box-style bundle flag
- `is_implementation` — BGG implementation or edition relationship flag
- `is_accessory` — BGG accessory flag
- `year_published`
- `average_rating`
- `bayes_average`
- `users_rated`
- `rank`
- `fetched_at` — local import timestamp

The following fields are stored in the same SQLite table but are populated only by XML API detail enrichment:

- `weight`
- `min_players`, `max_players`
- `min_play_time`, `max_play_time`
- `thumbnail`, `image`
- `description`
- `details_fetched_at`

After the current CSV import, the database contains 182,829 catalog rows. At that point, the CSV fields are populated, while the detail-enrichment fields remain empty until a valid BGG application token is configured and `bin/enrich-bgg-details.php` succeeds.

The frontend marks core CSV fields and enriched API fields separately. Results using weight or player-count filters are not a complete one-to-one live listing of BGG: they represent only games whose details have been enriched into this local SQLite cache. The catalog’s core fields come from the BGG ranks dump, while detail fields come from cached XML API responses.

The BGG XML API2 search endpoint is used for names, followed by the `thing` endpoint with `stats=1` for game details. Requests send the configured token as `Authorization: Bearer ${API_TOKEN}`.
