<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Http;

use Magento\Framework\App\RequestInterface as MagentoRequest;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use WeArePlanet\PluginCore\Render\JsonStringableTrait;

/**
 * Represents an HTTP request in a framework-agnostic way.
 *
 * This class serves as a bridge between various shop system request objects
 * (Magento, Symfony, WordPress) and the plugin core logic. It provides a
 * unified interface to access headers, body parameters, and the raw payload.
 */
class Request
{
    use JsonStringableTrait;

    /** @var array<string, string|string[]> */
    private array $headers = [];

    private string $rawBody = '';

    /**
     * Initializes a new Request instance.
     *
     * The constructor is private to force the use of static factory methods
     * for different environments, ensuring that the creation logic is centralized.
     *
     * @param array<string, string|string[]> $headers The HTTP headers.
     * @param array<string, mixed> $body The parsed request body.
     * @param string $rawBody The raw, unparsed request body.
     */
    private function __construct(
        array $headers,
        public readonly array $body,
        string $rawBody,
    ) {
        // Store headers with lowercase keys for case-insensitive lookups.
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->rawBody = $rawBody;
    }

    /**
     * Creates a new Request instance with the provided data.
     *
     * Use this method when you need to manually construct a request object
     * without relying on specific framework implementations.
     *
     * @param array<string, string|string[]> $headers The HTTP headers.
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
     *
     * This is used when the plugin is running within a Magento environment.
     *
     * @param MagentoRequest $magentoRequest The Magento request framework object.
     * @return self The resulting normalized Request.
     */
    public static function fromMagentoRequest(MagentoRequest $magentoRequest): self
    {
        $headers = $magentoRequest->getHeaders()->toArray();
        $rawBody = (string) $magentoRequest->getContent();
        $body = json_decode($rawBody, true) ?? [];

        return new self($headers, $body, $rawBody);
    }

    /**
     * Creates a Request instance from a Symfony Request object.
     *
     * This allows the core logic to be compatible with Symfony-based frameworks
     * (like PrestaShop or Shopware).
     *
     * @param SymfonyRequest $symfonyRequest The Symfony HttpFoundation request object.
     * @return self The resulting normalized Request.
     */
    public static function fromSymfonyRequest(SymfonyRequest $symfonyRequest): self
    {
        $headers = $symfonyRequest->headers->all();
        $rawBody = $symfonyRequest->getContent();
        $body = $symfonyRequest->toArray();

        return new self($headers, $body, $rawBody);
    }

    /**
     * Creates a Request instance from PHP globals.
     *
     * This is useful for WordPress or other custom environments where we don't
     * have a formal request object provided by the framework.
     *
     * @return self The resulting normalized Request.
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

        $rawBody = file_get_contents('php://input') ?? '';
        $body = json_decode($rawBody, true) ?? [];

        return new self($headers, $body, $rawBody);
    }

    /**
     * Retrieves a value from the parsed request body.
     *
     * @param string $key The key to look for in the body.
     * @param mixed $default The default value if the key is not found.
     * @return mixed The value or the default.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * Gets a header value by name (case-insensitive).
     *
     * @param string $name The header name.
     * @return string|null The header value or null if not set.
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Returns the raw, unparsed request body.
     *
     * @return string The raw body content.
     */
    public function getRawBody(): string
    {
        return $this->rawBody;
    }
}
