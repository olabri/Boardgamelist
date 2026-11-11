<?php

namespace App\Infrastructure;

use App\Http\AppException;
use SimpleXMLElement;

final class BggClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiToken
    ) {
    }

    /** @return array<int, array{id: int, name: string, year_published: ?int}> */
    public function searchGames(string $query, bool $exact): array
    {
        $parameters = [
            'query' => $query,
            'type' => 'boardgame',
        ];
        if ($exact) {
            $parameters['exact'] = 1;
        }

        $xml = $this->request('search', $parameters);

        $results = [];
        foreach ($xml->item as $item) {
            $name = $this->primaryName($item);
            if ($name === '') {
                continue;
            }

            $results[] = [
                'id' => (int) $item['id'],
                'name' => $name,
                'year_published' => $this->nullableInt($item->yearpublished['value'] ?? null),
            ];
        }

        return $results;
    }

    /** @return array<int, array<string, mixed>> */
    public function getGamesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $xml = $this->request('thing', [
            'id' => implode(',', array_map('intval', $ids)),
            'stats' => 1,
        ]);

        $games = [];
        foreach ($xml->item as $item) {
            $id = (int) $item['id'];
            $games[$id] = [
                'bgg_id' => $id,
                'name' => $this->primaryName($item),
                'is_expansion' => (string) $item['type'] === 'boardgameexpansion' ? 1 : 0,
                'is_accessory' => (string) $item['type'] === 'boardgameaccessory' ? 1 : 0,
                'is_compilation' => ((string) $item['type'] === 'boardgamecompilation'
                    || $this->hasLinkType($item, 'boardgamecompilation')) ? 1 : 0,
                'is_implementation' => ((string) $item['type'] === 'boardgameimplementation'
                    || $this->hasLinkType($item, 'boardgameimplementation')) ? 1 : 0,
                'year_published' => $this->nullableInt($item->yearpublished['value'] ?? null),
                'min_players' => $this->nullableInt($item->minplayers['value'] ?? null),
                'max_players' => $this->nullableInt($item->maxplayers['value'] ?? null),
                'min_play_time' => $this->nullableInt($item->minplaytime['value'] ?? null),
                'max_play_time' => $this->nullableInt($item->maxplaytime['value'] ?? null),
                'average_rating' => $this->nullableFloat($item->statistics->ratings->average['value'] ?? null),
                'bayes_average' => $this->nullableFloat($item->statistics->ratings->bayesaverage['value'] ?? null),
                'users_rated' => $this->nullableInt($item->statistics->ratings->usersrated['value'] ?? null),
                'weight' => $this->nullableFloat($item->statistics->ratings->averageweight['value'] ?? null),
                'rank' => $this->boardGameRank($item),
                // BGG returns these as element text, unlike most scalar fields
                // which use a value attribute.
                'thumbnail' => $this->nullableString($item->thumbnail),
                'image' => $this->nullableString($item->image),
                'description' => trim((string) $item->description),
                'fetched_at' => gmdate('c'),
                'details_fetched_at' => gmdate('c'),
            ];
        }

        return $games;
    }

    private function request(string $endpoint, array $query): SimpleXMLElement
    {
        if (trim($this->apiToken) === '') {
            throw new AppException('API_TOKEN is not configured.', 500);
        }

        $url = $this->baseUrl . '/' . $endpoint . '?' . http_build_query($query);
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Accept: application/xml',
                'Authorization: Bearer ' . trim($this->apiToken),
            ],
            CURLOPT_USERAGENT => 'Boardgamelist/1.0',
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            throw new AppException('BoardGameGeek request failed: ' . $error, 502);
        }
        if ($status === 401) {
            throw new AppException(
                'BoardGameGeek rejected API_TOKEN. Check that it is an active application token and that the request uses boardgamegeek.com.',
                502
            );
        }
        if ($status >= 400) {
            throw new AppException('BoardGameGeek returned HTTP ' . $status . '.', 502);
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        $xmlErrors = libxml_get_errors();
        libxml_clear_errors();

        if ($xml === false || $xmlErrors !== []) {
            throw new AppException('BoardGameGeek returned invalid XML.', 502);
        }

        return $xml;
    }

    private function primaryName(SimpleXMLElement $item): string
    {
        foreach ($item->name as $name) {
            if ((string) ($name['type'] ?? '') === 'primary') {
                return (string) ($name['value'] ?? '');
            }
        }

        return (string) ($item->name[0]['value'] ?? '');
    }

    private function hasLinkType(SimpleXMLElement $item, string $type): bool
    {
        foreach ($item->links->link ?? [] as $link) {
            if ((string) ($link['type'] ?? '') === $type) {
                return true;
            }
        }

        return false;
    }

    private function boardGameRank(SimpleXMLElement $item): ?int
    {
        foreach ($item->statistics->ratings->ranks->rank ?? [] as $rank) {
            if ((string) ($rank['name'] ?? '') === 'boardgame' && is_numeric((string) $rank['value'])) {
                return (int) $rank['value'];
            }
        }

        return null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric((string) $value) ? (int) $value : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric((string) $value) ? (float) $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
