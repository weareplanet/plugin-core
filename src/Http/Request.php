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
     * Initializes a new Request instance.
     * The constructor is private to force the use of static factory methods
     * for different environments (Magento, Symfony, WordPress, etc.).
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $body
     * @param string $rawBody
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

    /**
     * Creates a new Request instance with the provided data.
     * Use this method when you need to manually construct a request object
     * without relying on specific framework implementations.
     *
     * @param array<string, string> $headers The HTTP headers.
     * @param array<string, mixed> $body The parsed request body.
     * @param string $rawBody The raw, unparsed request body.
     * @return self The new Request instance.
     */
    public static function create(array $headers, array $body, string $rawBody): self
    {
        // We use the private constructor to initialize the request object with custom data.
        return new self($headers, $body, $rawBody);
    }

    /**
     * Creates a Request instance from a Magento Request object.
     * This is used when the plugin is running within a Magento environment.
     *
     * @param \Magento\Framework\App\RequestInterface $magentoRequest
     * @return self
     */
    public static function fromMagentoRequest(\Magento\Framework\App\RequestInterface $magentoRequest): self
    {
        $headers = $magentoRequest->getHeaders()->toArray();
        $rawBody = (string) $magentoRequest->getContent();
        $body = json_decode($rawBody, true) ?? [];

        return new self($headers, $body, $rawBody);
    }

    /**
     * Creates a Request instance from a Symfony Request object.
     * This allows the core logic to be compatible with Symfony-based frameworks (like PrestaShop).
     *
     * @param \Symfony\Component\HttpFoundation\Request $symfonyRequest
     * @return self
     */
    public static function fromSymfonyRequest(\Symfony\Component\HttpFoundation\Request $symfonyRequest): self
    {
        $headers = $symfonyRequest->headers->all();
        $rawBody = $symfonyRequest->getContent();
        $body = $symfonyRequest->toArray();

        return new self($headers, $body, $rawBody);
    }

    /**
     * Creates a Request instance from PHP globals.
     * This is useful for WordPress or other custom environments where we don't have
     * a formal request object provided by the framework.
     *
     * @return self
     */
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

    /**
     * Retrieves a value from the parsed request body.
     *
     * @param string $key The key to look for in the body.
     * @param mixed $default The default value if the key is not found.
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * Gets a header value by name (case-insensitive).
     *
     * @param string $name The header name.
     * @return string|null
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Returns the raw, unparsed request body.
     *
     * @return string
     */
    public function getRawBody(): string
    {
        return $this->rawBody;
    }
}
