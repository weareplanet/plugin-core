<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV1;

use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\ManualTask\Exception\ManualTaskException;
use WeArePlanet\PluginCore\ManualTask\ManualTaskGatewayInterface;
use WeArePlanet\PluginCore\ManualTask\State;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\Sdk\Model\CriteriaOperator as SdkCriteriaOperator;
use WeArePlanet\Sdk\Model\EntityQueryFilter as SdkEntityQueryFilter;
use WeArePlanet\Sdk\Model\EntityQueryFilterType as SdkEntityQueryFilterType;
use WeArePlanet\Sdk\Service\ManualTaskService as SdkManualTaskService;

/**
 * Gateway implementation using the SDK.
 */
#[LogContext(domain: 'manual_task')]
class ManualTaskGateway implements ManualTaskGatewayInterface
{
    use DomainLoggerTrait;

    /**
     * @param SdkProvider $provider The SDK provider.
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        private readonly SdkProvider $provider,
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
            /** @var SdkManualTaskService $service */
            $service = $this->provider->getService(SdkManualTaskService::class);

            $filter = new SdkEntityQueryFilter();
            $filter->setType(SdkEntityQueryFilterType::LEAF);
            $filter->setOperator(SdkCriteriaOperator::EQUALS);
            $filter->setFieldName('state');
            $filter->setValue($state->value);

            return (int)$service->count($spaceId, $filter);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to count manual tasks from SDK.', [
                'spaceId' => $spaceId,
                'state' => $state->value,
                'exception' => $e,
            ]);
            throw new ManualTaskException(
                sprintf('Failed to count manual tasks for space %d.', $spaceId),
                new LocalizedString('Failed to retrieve manual tasks. Please try again or contact support.'),
                $e,
            );
        }
    }
}
