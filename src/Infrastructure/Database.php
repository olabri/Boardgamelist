<?php

namespace App\Infrastructure;

use PDO;
use PDOStatement;

final class Database
{
    private PDO $connection;
    private ?PDOStatement $catalogStatement = null;
    private ?PDOStatement $catalogIgnoreStatement = null;

    public function __construct(string $path)
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $this->connection = new PDO('sqlite:' . $path);
        $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function migrate(): void
    {
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS games (
                bgg_id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                is_expansion INTEGER NOT NULL DEFAULT 0,
                is_accessory INTEGER NOT NULL DEFAULT 0,
                is_compilation INTEGER NOT NULL DEFAULT 0,
                is_implementation INTEGER NOT NULL DEFAULT 0,
                year_published INTEGER,
                min_players INTEGER,
                max_players INTEGER,
                min_play_time INTEGER,
                max_play_time INTEGER,
                average_rating REAL,
                bayes_average REAL,
                users_rated INTEGER,
                weight REAL,
                rank INTEGER,
                thumbnail TEXT,
                image TEXT,
                description TEXT,
                fetched_at TEXT NOT NULL,
                details_fetched_at TEXT
            )
        SQL);

        foreach ([
            'is_expansion' => 'INTEGER NOT NULL DEFAULT 0',
            'is_accessory' => 'INTEGER NOT NULL DEFAULT 0',
            'is_compilation' => 'INTEGER NOT NULL DEFAULT 0',
            'is_implementation' => 'INTEGER NOT NULL DEFAULT 0',
            'bayes_average' => 'REAL',
            'users_rated' => 'INTEGER',
            'weight' => 'REAL',
            'details_fetched_at' => 'TEXT',
        ] as $column => $type) {
            $this->ensureColumn($column, $type);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function findGamesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->connection->prepare(
            "SELECT * FROM games WHERE bgg_id IN ($placeholders)"
        );
        $statement->execute(array_values($ids));

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findGame(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM games WHERE bgg_id = ?');
        $statement->execute([$id]);
        $game = $statement->fetch();

        return $game === false ? null : $game;
    }

    /** @param array<string, mixed> $game */
    public function saveGame(array $game): void
    {
        $statement = $this->connection->prepare(<<<'SQL'
            INSERT INTO games (
                bgg_id, name, is_expansion, is_accessory, is_compilation, is_implementation,
                year_published, min_players, max_players,
                min_play_time, max_play_time, average_rating, rank,
                bayes_average, users_rated, weight, thumbnail, image,
                description, fetched_at, details_fetched_at
            ) VALUES (
                :bgg_id, :name, :is_expansion, :is_accessory, :is_compilation, :is_implementation,
                :year_published, :min_players, :max_players,
                :min_play_time, :max_play_time, :average_rating, :rank,
                :bayes_average, :users_rated, :weight, :thumbnail, :image,
                :description, :fetched_at, :details_fetched_at
            )
            ON CONFLICT(bgg_id) DO UPDATE SET
                name = excluded.name,
                is_expansion = excluded.is_expansion,
                is_accessory = excluded.is_accessory,
                is_compilation = excluded.is_compilation,
                is_implementation = excluded.is_implementation,
                year_published = excluded.year_published,
                min_players = excluded.min_players,
                max_players = excluded.max_players,
                min_play_time = excluded.min_play_time,
                max_play_time = excluded.max_play_time,
                average_rating = excluded.average_rating,
                bayes_average = excluded.bayes_average,
                users_rated = excluded.users_rated,
                weight = excluded.weight,
                rank = excluded.rank,
                thumbnail = excluded.thumbnail,
                image = excluded.image,
                description = excluded.description,
                fetched_at = excluded.fetched_at,
                details_fetched_at = excluded.details_fetched_at
        SQL);
        $statement->execute($game);
    }

    /** @param array<string, mixed> $game */
    public function saveCatalogGame(array $game, bool $ignoreConflict = false): void
    {
        if ($ignoreConflict) {
            $this->catalogIgnoreStatement ??= $this->connection->prepare(<<<'SQL'
                INSERT OR IGNORE INTO games (
                    bgg_id, name, is_expansion, is_accessory, is_compilation, is_implementation,
                    year_published, average_rating, bayes_average,
                    users_rated, rank, weight, min_players, max_players,
                    min_play_time, max_play_time, thumbnail, image, description,
                    fetched_at, details_fetched_at
                ) VALUES (
                    :bgg_id, :name, :is_expansion, :is_accessory, :is_compilation, :is_implementation,
                    :year_published, :average_rating, :bayes_average,
                    :users_rated, :rank, :weight, :min_players, :max_players,
                    :min_play_time, :max_play_time, :thumbnail, :image, :description,
                    :fetched_at, :details_fetched_at
                )
            SQL);
            $this->catalogIgnoreStatement->execute($game);

            return;
        }

        $this->catalogStatement ??= $this->connection->prepare(<<<'SQL'
            INSERT INTO games (
                bgg_id, name, is_expansion, is_accessory, is_compilation, is_implementation,
                year_published, average_rating, bayes_average,
                users_rated, rank, weight, min_players, max_players,
                min_play_time, max_play_time, thumbnail, image, description,
                fetched_at, details_fetched_at
            ) VALUES (
                :bgg_id, :name, :is_expansion, :is_accessory, :is_compilation, :is_implementation,
                :year_published, :average_rating, :bayes_average,
                :users_rated, :rank, :weight, :min_players, :max_players,
                :min_play_time, :max_play_time, :thumbnail, :image, :description,
                :fetched_at, :details_fetched_at
            )
            ON CONFLICT(bgg_id) DO UPDATE SET
                name = excluded.name,
                is_expansion = excluded.is_expansion,
                year_published = excluded.year_published,
                average_rating = excluded.average_rating,
                bayes_average = excluded.bayes_average,
                users_rated = excluded.users_rated,
                rank = excluded.rank,
                fetched_at = excluded.fetched_at
        SQL);
        $this->catalogStatement->execute($game);
    }

    public function beginTransaction(): void
    {
        $this->connection->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollBack(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function findCatalogGames(
        ?float $maxWeight,
        float $minRating,
        int $players,
        int $limit,
        int $offset = 0,
        ?int $minYear = null,
        ?int $maxYear = null,
        int $minUsersRated = 500,
        bool $includeCompilations = false,
        bool $includeImplementations = false,
        bool $includeAccessories = false,
        ?int $maxPlayers = null
    ): array {
        $conditions = [
            'is_expansion = 0',
            'average_rating > :min_rating',
            'users_rated >= :min_users_rated',
            '(min_players IS NULL OR max_players IS NULL OR '
                . '(min_players <= :players AND max_players >= :players))',
        ];
        if ($maxWeight !== null) {
            $conditions[] = '(weight IS NULL OR weight < :max_weight)';
        }
        if (!$includeCompilations) {
            $conditions[] = 'COALESCE(is_compilation, 0) = 0';
        }
        if (!$includeImplementations) {
            $conditions[] = 'COALESCE(is_implementation, 0) = 0';
        }
        if (!$includeAccessories) {
            $conditions[] = 'COALESCE(is_accessory, 0) = 0';
        }
        if ($maxPlayers !== null) {
            $conditions[] = '(max_players IS NULL OR max_players <= :max_players_filter)';
        }
        if ($minYear !== null) {
            $conditions[] = 'year_published >= :min_year';
        }
        if ($maxYear !== null) {
            $conditions[] = 'year_published <= :max_year';
        }

        $statement = $this->connection->prepare(
            'SELECT * FROM games WHERE ' . implode(' AND ', $conditions)
            . ' ORDER BY average_rating DESC, rank IS NULL, rank ASC, name ASC'
            . ' LIMIT :limit OFFSET :offset'
        );
        if ($maxWeight !== null) {
            $statement->bindValue(':max_weight', $maxWeight);
        }
        $statement->bindValue(':min_rating', $minRating);
        $statement->bindValue(':players', $players, PDO::PARAM_INT);
        $statement->bindValue(':min_users_rated', $minUsersRated, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        if ($maxPlayers !== null) {
            $statement->bindValue(':max_players_filter', $maxPlayers, PDO::PARAM_INT);
        }
        if ($minYear !== null) {
            $statement->bindValue(':min_year', $minYear, PDO::PARAM_INT);
        }
        if ($maxYear !== null) {
            $statement->bindValue(':max_year', $maxYear, PDO::PARAM_INT);
        }
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @return array{total: int, enriched: int} */
    public function catalogStats(): array
    {
        $statement = $this->connection->query(<<<'SQL'
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN weight IS NOT NULL
                    AND min_players IS NOT NULL
                    AND max_players IS NOT NULL THEN 1 ELSE 0 END) AS enriched
            FROM games
        SQL);
        $stats = $statement->fetch();

        return [
            'total' => (int) ($stats['total'] ?? 0),
            'enriched' => (int) ($stats['enriched'] ?? 0),
        ];
    }

    public function resetIncompleteDetails(): int
    {
        $statement = $this->connection->prepare(<<<'SQL'
            UPDATE games
            SET details_fetched_at = NULL
            WHERE details_fetched_at IS NOT NULL
              AND is_expansion = 0
              AND COALESCE(is_accessory, 0) = 0
              AND COALESCE(is_compilation, 0) = 0
              AND COALESCE(is_implementation, 0) = 0
              AND (
                  weight IS NULL
                  OR min_players IS NULL
                  OR max_players IS NULL
              )
        SQL);
        $statement->execute();

        return $statement->rowCount();
    }

    /** @return array<int, int> */
    public function findUnenrichedIds(
        float $minRating,
        int $limit,
        ?int $minYear = null,
        ?int $maxYear = null,
        int $minUsersRated = 500,
        bool $refresh = false,
        int $offset = 0,
        bool $missingImageOnly = false,
        bool $missingDetails = false
    ): array
    {
        $conditions = [
            'average_rating > :min_rating',
            'users_rated >= :min_users_rated',
            'is_expansion = 0',
            'COALESCE(is_accessory, 0) = 0',
            'COALESCE(is_compilation, 0) = 0',
            'COALESCE(is_implementation, 0) = 0',
        ];
        if (!$refresh) {
            if ($missingDetails) {
                $conditions[] = '(details_fetched_at IS NULL OR weight IS NULL OR min_players IS NULL '
                    . 'OR max_players IS NULL OR (image IS NULL AND thumbnail IS NULL))';
            } elseif ($missingImageOnly) {
                $conditions[] = '(details_fetched_at IS NULL OR (image IS NULL AND thumbnail IS NULL))';
            } else {
                $conditions[] = 'details_fetched_at IS NULL';
            }
        }
        if ($minYear !== null) {
            $conditions[] = 'year_published >= :min_year';
        }
        if ($maxYear !== null) {
            $conditions[] = 'year_published <= :max_year';
        }

        $statement = $this->connection->prepare(
            'SELECT bgg_id FROM games WHERE ' . implode(' AND ', $conditions)
            . ' ORDER BY rank IS NULL, rank ASC, average_rating DESC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':min_rating', $minRating);
        $statement->bindValue(':min_users_rated', $minUsersRated, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        if ($minYear !== null) {
            $statement->bindValue(':min_year', $minYear, PDO::PARAM_INT);
        }
        if ($maxYear !== null) {
            $statement->bindValue(':max_year', $maxYear, PDO::PARAM_INT);
        }
        $statement->execute();

        return array_map('intval', array_column($statement->fetchAll(), 'bgg_id'));
    }

    private function ensureColumn(string $column, string $type): void
    {
        $statement = $this->connection->query('PRAGMA table_info(games)');
        $columns = array_column($statement->fetchAll(), 'name');
        if (!in_array($column, $columns, true)) {
            $this->connection->exec("ALTER TABLE games ADD COLUMN $column $type");
        }
    }
}
