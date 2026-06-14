<?php

namespace Struktoria\Sdk\Http;

use Struktoria\Sdk\Exception\TransportException;

/**
 * Low-level HTTP transport built on curl.
 *
 * Deliberately knows NOTHING about Struktoria, auth tokens or Basic auth - it
 * just sends a request and returns a Response. Whatever headers the API needs
 * are passed in by the caller (the auth / client layer). That separation is
 * what makes this class reusable and trivial to reason about.
 */
final class HttpClient
{
    /**
     * @param int $timeout Request timeout in seconds. The old code used 0
     *                     ("wait forever"), which risks hanging the whole app
     *                     when the API stalls - so we default to something sane.
     */
    public function __construct(
        private int $timeout = 30,
    ) {
    }

    /**
     * @param array<string, scalar> $query   Query-string parameters.
     * @param array<string, string> $headers Associative header map, e.g.
     *                                        ['Authorization' => 'Bearer ...'].
     */
    public function get(string $url, array $query = [], array $headers = []): Response
    {
        return $this->send('GET', $url, $query, $headers);
    }

    /**
     * DELETE request (no body).
     */
    public function delete(string $url, array $query = [], array $headers = []): Response
    {
        return $this->send('DELETE', $url, $query, $headers);
    }

    /**
     * POST a JSON body.
     */
    public function postJson(string $url, array $data = [], array $query = [], array $headers = []): Response
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';

        return $this->send('POST', $url, $query, $headers, json_encode($data));
    }

    /**
     * DELETE with a JSON body. The bucket/node delete endpoints take the ids
     * to remove in the request body rather than the URL.
     */
    public function deleteJson(string $url, array $data = [], array $query = [], array $headers = []): Response
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';

        return $this->send('DELETE', $url, $query, $headers, json_encode($data));
    }

    /**
     * PUT a JSON body. Used for the "update info" / "set content" endpoints
     * that the API exposes as idempotent replacements (they answer 204).
     */
    public function putJson(string $url, array $data = [], array $query = [], array $headers = []): Response
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';

        return $this->send('PUT', $url, $query, $headers, json_encode($data));
    }

    /**
     * POST a multipart body (file upload). We intentionally do NOT set
     * Content-Type here - curl must add the multipart boundary itself.
     *
     * @param array<string, mixed> $fields e.g. ['file' => new \CURLFile(...)].
     */
    public function postMultipart(string $url, array $fields, array $query = [], array $headers = []): Response
    {
        return $this->send('POST', $url, $query, $headers, $fields);
    }

    /**
     * The single place where a curl request is actually configured and run.
     * Everything the three public methods share lives here, once.
     *
     * @param array<string, scalar>  $query
     * @param array<string, string>  $headers
     * @param string|array<mixed>|null $body String (JSON) or array (multipart).
     */
    private function send(string $method, string $url, array $query, array $headers, $body = null): Response
    {
        $fullUrl = $url;
        if (! empty($query)) {
            $fullUrl .= '?'.http_build_query($query);
        }

        $options = [
            CURLOPT_URL => $fullUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_ENCODING => 'utf-8',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
        ];

        if (null !== $body) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        $curl = curl_init();
        curl_setopt_array($curl, $options);

        $raw = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if (0 !== $errno) {
            throw new TransportException(
                sprintf('Struktoria SDK transport error (%d): %s', $errno, $error)
            );
        }

        return new Response($status, false === $raw ? null : $raw);
    }

    /**
     * Turn an associative header map into curl's "Name: value" line format.
     * Numeric keys are passed through verbatim, so a raw line still works.
     *
     * @param array<string, string> $headers
     *
     * @return string[]
     */
    private function formatHeaders(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = is_int($name) ? $value : $name.': '.$value;
        }

        return $lines;
    }
}
