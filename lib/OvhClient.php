<?php

namespace OvhVps;

use Ovh\Api;

/**
 * Thin domain wrapper around the OVH SDK with normalised error handling and
 * logging. Every call returns the decoded JSON (array/scalar) or throws an
 * {@see OvhApiException} carrying the HTTP status and OVH error message.
 */
class OvhClient
{
    private Api $api;

    /**
     * @param array<string, mixed> $params Server module $params.
     */
    public function __construct(array $params)
    {
        $this->api = Helper::api($params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function fromParams(array $params): self
    {
        return new self($params);
    }

    public function raw(): Api
    {
        return $this->api;
    }

    /**
     * @param array<string, mixed>|null $query
     * @return mixed
     */
    public function get(string $path, ?array $query = null)
    {
        return $this->call('GET', $path, $query);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return mixed
     */
    public function post(string $path, ?array $body = null)
    {
        return $this->call('POST', $path, $body);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return mixed
     */
    public function put(string $path, ?array $body = null)
    {
        return $this->call('PUT', $path, $body);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return mixed
     */
    public function delete(string $path, ?array $body = null)
    {
        return $this->call('DELETE', $path, $body);
    }

    /**
     * GET /me - used by TestConnection.
     *
     * @return array<string, mixed>
     */
    public function me(): array
    {
        return (array) $this->get('/me');
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return mixed
     */
    private function call(string $method, string $path, ?array $payload)
    {
        try {
            switch ($method) {
                case 'GET':
                    $res = $this->api->get($path, $payload);
                    break;
                case 'POST':
                    $res = $this->api->post($path, $payload);
                    break;
                case 'PUT':
                    $res = $this->api->put($path, $payload ?? []);
                    break;
                case 'DELETE':
                    $res = $this->api->delete($path, $payload);
                    break;
                default:
                    throw new \InvalidArgumentException("Unsupported method {$method}");
            }
            Helper::log("api:{$method} {$path}", $payload, $res, true);
            return $res;
        } catch (\Throwable $e) {
            $detail = self::describe($e);
            Helper::log("api:{$method} {$path}", $payload, $detail, false);
            throw new OvhApiException($detail['message'], $detail['status'], $e);
        }
    }

    /**
     * Extract HTTP status + OVH error message from a thrown exception.
     *
     * @return array{status:int, message:string}
     */
    private static function describe(\Throwable $e): array
    {
        $status = 0;
        $message = $e->getMessage();

        if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
            $response = $e->getResponse();
            if ($response !== null) {
                $status = $response->getStatusCode();
                $bodyText = (string) $response->getBody();
                $decoded = json_decode($bodyText, true);
                if (is_array($decoded) && isset($decoded['message'])) {
                    $message = (string) $decoded['message'];
                } elseif ($bodyText !== '') {
                    $message = $bodyText;
                }
            }
        }

        return ['status' => $status, 'message' => $message];
    }
}
