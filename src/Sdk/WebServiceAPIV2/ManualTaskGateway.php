<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV2;

use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\ManualTask\Exception\ManualTaskException;
use WeArePlanet\PluginCore\ManualTask\ManualTaskGatewayInterface;
use WeArePlanet\PluginCore\ManualTask\State;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\SearchPaginationTrait;
use WeArePlanet\Sdk\Service\ManualTasksService as SdkManualTasksService;

/**
 * Gateway implementation using the SDK.
 *
 * Unlike WebServiceAPIV1, this SDK version has no direct count endpoint for
 * manual tasks — only a paginated search. {@see countByState()} pages through
 * the search results and sums them, so its cost scales with the number of
 * matching tasks (one HTTP call per 100 tasks) rather than being a single
 * cheap lookup.
 */
#[LogContext(domain: 'manual_task')]
class ManualTaskGateway implements ManualTaskGatewayInterface
{
    use DomainLoggerTrait;
    use SearchPaginationTrait;

    /**
     * @param SdkProvider $sdkProvider The SDK provider.
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        private readonly SdkProvider $sdkProvider,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
    }

    /**
     * @inheritDoc
     */
    public function countByState(int $spaceId, State $state): int
    {
        try {
            /** @var SdkManualTasksService $service */
            $service = $this->sdkProvider->getService(SdkManualTasksService::class);

            $query = sprintf('state:%s', $state->value);

            // Counted by walking the generator so that only one page is ever held
            // in memory, however many tasks the space has.
            return iterator_count($this->paginateSearch(
                static function (int $offset) use ($service, $spaceId, $query): object {
                    return $service->getManualTasksSearch(
                        $spaceId,
                        null,
                        SdkProvider::MAX_PAGE_SIZE,
                        $offset,
                        null,
                        $query,
                    );
                },
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to count manual tasks from SDK.', [
                'spaceId' => $spaceId,
                'state' => $state->value,
                'exception' => $e,
            ]);
            throw SdkProvider::wrapException(
                $e,
                ManualTaskException::class,
                'getManualTasksSearch',
                ['spaceId' => $spaceId, 'state' => $state->value],
                'Failed to retrieve manual tasks. Please try again or contact support.',
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function countAll(int $spaceId): int
    {
        try {
            /** @var SdkManualTasksService $service */
            $service = $this->sdkProvider->getService(SdkManualTasksService::class);

            // No query: the search then returns every manual task in the space.
            return iterator_count($this->paginateSearch(
                static function (int $offset) use ($service, $spaceId): object {
                    return $service->getManualTasksSearch(
                        $spaceId,
                        null,
                        SdkProvider::MAX_PAGE_SIZE,
                        $offset,
                    );
                },
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to count manual tasks from SDK.', [
                'spaceId' => $spaceId,
                'exception' => $e,
            ]);
            throw SdkProvider::wrapException(
                $e,
                ManualTaskException::class,
                'getManualTasksSearch',
                ['spaceId' => $spaceId],
                'Failed to retrieve manual tasks. Please try again or contact support.',
            );
        }
    }

}
