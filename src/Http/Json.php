<?php

namespace App\Http;

use Psr\Http\Message\ResponseInterface;

final class Json
{
    public static function response(ResponseInterface $response, mixed $data, int $status = 200): ResponseInterface
    {
        $payload = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $response->getBody()->write($payload);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
