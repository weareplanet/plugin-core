<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk;

use WeArePlanet\PluginCore\SharedKernel\AbstractDomainException;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\Sdk\ApiException;
use WeArePlanet\Sdk\Configuration as SdkConfiguration;

class SdkProvider
{
    private SdkConfiguration $configuration;
    /** @var array<class-string<object>, object> */
    private array $serviceInstances = [];

    /**
     * Initializes the SDK Provider with the given settings.
     *
     * It sets up the SDK Configuration, including authentication credentials and
     * the "Smart URL" logic for the API host.
     *
     * @param Settings $settings
     * @param ClientMetadataProviderInterface|null $clientMetadataProvider Identifies the
     *        shop system and plugin to the WeArePlanet Portal. Optional: without one, calls carry no
     *        identification headers and work exactly as before.
     */
    public function __construct(
        private readonly Settings $settings,
        ?ClientMetadataProviderInterface $clientMetadataProvider = null,
    ) {
        // V2 uses SdkConfiguration
        $this->configuration = new SdkConfiguration($settings->getUserId(), $settings->getApiKey());

        $clientMetadata = $clientMetadataProvider?->getClientMetadata();
        if ($clientMetadata !== null) {
            // This SDK exposes defaults as one array and setting it replaces the lot, so
            // merge rather than assign: the configuration already carries the SDK's own
            // x-meta-sdk-* headers, and overwriting them would strip its identification.
            $this->configuration->setDefaultHeaders(
                array_merge($this->configuration->getDefaultHeaders(), $clientMetadata->toHeaders()),
            );
        }

        $baseUrl = $settings->getBaseUrl();
        if (!empty($baseUrl)) {
            $host = $baseUrl;
            // Ensure protocol is present
            if (!str_starts_with($host, 'http://') && !str_starts_with($host, 'https://')) {
                $host = 'https://' . $host;
            }
            // Smart URL Logic: Check for path (URI), append /api/v2.0 if missing
            $parts = parse_url($host);
            if (!isset($parts['path']) || $parts['path'] === '' || $parts['path'] === '/') {
                $host = rtrim($host, '/') . '/api/v2.0';
            }
            $this->configuration->setHost($host);
        }

        // Set global default configuration to avoid TypeError in ObjectSerializer which relies on it
        SdkConfiguration::setDefaultConfiguration($this->configuration);
    }

    /**
     * Returns the SDK configuration.
     *
     * This allows consumer applications to reuse the same configured SDK instance
     * and avoid duplicating the host URL formatting logic.
     *
     * @return SdkConfiguration
     */
    public function getConfiguration(): SdkConfiguration
    {
        return $this->configuration;
    }

    /**
     * Gets or creates an instance of the requested SDK service.
     * @param class-string<T> $serviceClassName
     * @return T
     * @template T of object
     */
    public function getService(string $serviceClassName): object
    {
        if (!isset($this->serviceInstances[$serviceClassName])) {
            if (!class_exists($serviceClassName) || !method_exists($serviceClassName, '__construct')) {
                throw new \InvalidArgumentException("Invalid SDK service class provided: {$serviceClassName}");
            }
            // V2 Services take SdkConfiguration as first argument
            $this->serviceInstances[$serviceClassName] = new $serviceClassName($this->configuration);
        }
        return $this->serviceInstances[$serviceClassName];
    }

    /**
     * Gets the configured Space ID.
     *
     * Resolved on demand rather than in the constructor, so a consumer that only
     * reads non-space-scoped data — the WeArePlanet Portal's global reference data, say — can
     * build a provider without configuring a space at all. A missing or invalid
     * Space ID therefore surfaces here, when something actually asks for one.
     *
     * @return int The configured Space ID.
     * @throws \InvalidArgumentException If no valid Space ID is configured.
     */
    public function getSpaceId(): int
    {
        return $this->settings->getSpaceId();
    }

    /**
     * Wraps a raw SDK failure in a typed domain exception.
     *
     * Every gateway turns transport-level failures into its own domain exception, and
     * they were all building the same message by hand. Composing it here keeps that
     * format in one place; the gateway still owns the decision of *which* exception
     * type to raise, and its own logging.
     *
     * Static because it reads no instance state — it only formats. That also keeps it
     * out of reach of a mocked SdkProvider, so a gateway test asserting on a domain
     * exception exercises the real formatter instead of a stub.
     *
     * Constrained to {@see AbstractDomainException} rather than plain \Exception: the
     * constructor called below takes a LocalizedString where \Exception takes an int
     * code, so a looser bound would let a class through that cannot be built this way.
     *
     * @template T of AbstractDomainException
     * @param \Throwable $exception The SDK failure to wrap, kept as the previous throwable.
     * @param class-string<T> $exceptionClass The domain exception to instantiate.
     * @param string $operation The SDK operation name, for the message.
     * @param array<string, mixed> $context Identifying context, e.g. spaceId and transactionId.
     * @param string $clientMessage The user-facing message, wrapped in a LocalizedString.
     * @return T The domain exception, ready for the caller to throw.
     */
    public static function wrapException(
        \Throwable $exception,
        string $exceptionClass,
        string $operation,
        array $context,
        string $clientMessage,
    ): AbstractDomainException {
        $contextString = self::describeContext($context);

        $message = $contextString === ''
            ? sprintf('Operation %s failed: %s', $operation, $exception->getMessage())
            : sprintf('Operation %s failed for [%s]: %s', $operation, $contextString, $exception->getMessage());

        $domainException = new $exceptionClass(
            $message,
            new LocalizedString($clientMessage),
            $exception,
        );

        // Classified here rather than in each gateway so that every domain reports a
        // transient failure the same way. A gateway can still add causes only it can
        // recognise — a version conflict, say — by calling withRetryable() itself.
        if (self::isTransient($exception)) {
            $domainException->withRetryable(true);
        }

        return $domainException;
    }

    /**
     * Whether an SDK failure is transient, so retrying the same request may succeed.
     *
     * Code 0 on an ApiException means no HTTP response was ever received, so the
     * request never reached the WeArePlanet Portal: nothing was processed,
     * and replaying it is safe and expected to succeed once the network recovers.
     * Everything else is treated as terminal, which is the safe default — retrying a
     * rejected request only repeats the rejection.
     *
     * @param \Throwable $exception The SDK failure to classify.
     * @return bool True when the failure is transient.
     */
    private static function isTransient(\Throwable $exception): bool
    {
        return $exception instanceof ApiException && $exception->getCode() === 0;
    }

    /**
     * Builds the domain exception for a raw SDK response the gateway cannot interpret.
     *
     * Complements {@see wrapException()}: that one wraps a thrown failure, this one
     * covers the case where the SDK call itself succeeded but returned a shape the
     * gateway does not recognise. Nothing threw, so there is no previous throwable —
     * the failure originates in the response itself, not in an exception from the SDK.
     *
     * @template T of AbstractDomainException
     * @param class-string<T> $exceptionClass The domain exception to instantiate.
     * @param string $operation The SDK operation name, for the message.
     * @param array<string, mixed> $context Identifying context, e.g. spaceId and transactionId.
     * @param string $clientMessage The user-facing message, wrapped in a LocalizedString.
     * @return T The domain exception, ready for the caller to throw.
     */
    public static function unexpectedResponseException(
        string $exceptionClass,
        string $operation,
        array $context,
        string $clientMessage,
    ): AbstractDomainException {
        $contextString = self::describeContext($context);

        $message = $contextString === ''
            ? sprintf('Unexpected response for operation %s', $operation)
            : sprintf('Unexpected response for operation %s [%s]', $operation, $contextString);

        return new $exceptionClass($message, new LocalizedString($clientMessage));
    }

    /**
     * Renders identifying context as a readable `key=value` list for an exception message.
     *
     * Only scalars are rendered; anything else has no useful short form and would make
     * the message harder to read rather than easier.
     *
     * @param array<string, mixed> $context Identifying context.
     * @return string A description such as `spaceId=42, transactionId=1234`.
     */
    private static function describeContext(array $context): string
    {
        $parts = [];

        foreach ($context as $key => $value) {
            if (is_scalar($value)) {
                $parts[] = $key . '=' . (string)$value;
            }
        }

        return implode(', ', $parts);
    }
}
