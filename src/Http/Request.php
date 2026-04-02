<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Http;

use WeArePlanet\PluginCore\Render\JsonStringableTrait;

class Request
{
    use JsonStringableTrait;
    /** @var array<string, string> Lowercase header keys */
    private array $headers = [];

    private string $rawBody = '';

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $body
     */
    private function __construct(
        array $headers,
        public readonly array $body,
        string $rawBody,
    ) {
        // Store headers with lowercase keys for case-insensitive lookups
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->rawBody = $rawBody;
    }

    public static function fromMagentoRequest(\Magento\Framework\App\RequestInterface $magentoRequest): self
    {
        $headers = $magentoRequest->getHeaders()->toArray();
        $rawBody = (string) $magentoRequest->getContent();
        $body = json_decode($rawBody, true) ?? [];

        return new self($headers, $body, $rawBody);
    }

    public static function fromSymfonyRequest(\Symfony\Component\HttpFoundation\Request $symfonyRequest): self
    {
        $headers = $symfonyRequest->headers->all();
        $rawBody = $symfonyRequest->getContent();
        $body = $symfonyRequest->toArray();

        return new self($headers, $body, $rawBody);
    }

    public static function fromWordPress(): self
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } else {
            foreach ($_SERVER as $key => $value) {
                if (str_starts_with($key, 'HTTP_')) {
                    $headerName = str_replace('_', '-', substr($key, 5));
                    $headers[$headerName] = $value;
                }
            }
        }

        $rawBody = (string) file_get_contents('php://input');
        $body = json_decode($rawBody, true) ?? [];

        return new self($headers, $body, $rawBody);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * Gets a header value by name (case-insensitive).
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function getRawBody(): string
    {
        return $this->rawBody;
    }
}
