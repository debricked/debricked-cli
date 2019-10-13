<?php
/**
 * @license
 *
 * Copyright (C) debricked AB
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code (usually found in the root of this application).
 */

namespace App\API;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class API
{
    /**
     * @var HttpClientInterface
     */
    private $debrickedClient;

    /**
     * @var string
     */
    private $password;

    /**
     * @var string
     */
    private $username;

    /**
     * @var string
     */
    private $token;

    public function __construct(HttpClientInterface $debrickedClient, string $username, string $password)
    {
        $this->debrickedClient = $debrickedClient;
        $this->password = $password;
        $this->username = $username;
    }

    /* @noinspection PhpDocMissingThrowsInspection */

    /**
     * Makes an API call to the given URI.
     *
     * @param string $method  HTTP method
     * @param string $uri     call URI
     * @param array  $options request options to apply, see @GuzzleHttp\ClientInterface::request()
     * @param int    $attempt @internal
     *
     * @return ResponseInterface
     *
     * @throws TransportExceptionInterface
     */
    public function makeApiCall(
        string $method,
        string $uri,
        array $options = [],
        int $attempt = 0
    ): ResponseInterface {
        try {
            $response = $this->debrickedClient->request(
                $method,
                $uri,
                \array_merge_recursive(
                    [
                        'headers' => [
                            'Authorization' => "Bearer {$this->token}",
                        ],
                    ],
                    $options
                )
            );
            $response->getContent();
        } catch (TransportExceptionInterface | ClientExceptionInterface | RedirectionExceptionInterface | ServerExceptionInterface $e) {
            if ($e->getCode() === SymfonyResponse::HTTP_UNAUTHORIZED && $attempt === 0) {
                /* @noinspection PhpUnhandledExceptionInspection */
                $this->token = $this->getNewToken();
                $response = $this->makeApiCall($method, $uri, $options, $attempt + 1);
            } else {
                throw $e;
            }
        }

        return $response;
    }

    /**
     * Returns a new token using path "/api/login_check".
     *
     * @return string
     *
     * @throws TransportExceptionInterface
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws \Exception
     */
    private function getNewToken(): string
    {
        $response = $this->debrickedClient->request(
            Request::METHOD_POST,
            '/api/login_check',
            [
                'json' => [
                    '_username' => $this->username,
                    '_password' => $this->password,
                ],
            ]
        );
        $tokenResponse = \json_decode($response->getContent(), true);
        if ($tokenResponse === null) {
            throw new \Exception(
                'Empty response received from server when token expected. Body: '.$response->getContent()
            );
        } else {
            if (\array_key_exists('token', $tokenResponse)) {
                return $tokenResponse['token'];
            } else {
                throw new \Exception('No token received from server. Response: '.\implode(', ', $tokenResponse));
            }
        }
    }
}
