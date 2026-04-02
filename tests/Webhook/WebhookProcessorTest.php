<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Webhook;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Http\Request;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Webhook\Command\WebhookCommandInterface;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener;
use WeArePlanet\PluginCore\Webhook\Exception\CommandException;
use WeArePlanet\PluginCore\Webhook\Listener\WebhookListenerInterface;
use WeArePlanet\PluginCore\Webhook\Listener\WebhookListenerRegistry;
use WeArePlanet\PluginCore\Webhook\StateFetcherInterface;
use WeArePlanet\PluginCore\Webhook\StateValidator;
use WeArePlanet\PluginCore\Webhook\WebhookLifecycleHandler;
use WeArePlanet\PluginCore\Webhook\WebhookProcessor;

class WebhookProcessorTest extends TestCase
{
    private WebhookLifecycleHandler $lifecycleHandlerMock;
    private LoggerInterface $loggerMock;
    private WebhookProcessor $processor;
    private WebhookListenerRegistry $registryMock;
    private Request $requestMock;
    private StateFetcherInterface $stateFetcherMock;
    private StateValidator $validatorMock;

    protected function setUp(): void
    {
        $this->registryMock = $this->createMock(WebhookListenerRegistry::class);
        $this->validatorMock = $this->createMock(StateValidator::class);
        $this->lifecycleHandlerMock = $this->createMock(WebhookLifecycleHandler::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->requestMock = $this->createMock(Request::class);
        $this->stateFetcherMock = $this->createMock(StateFetcherInterface::class);

        $this->processor = new WebhookProcessor(
            $this->registryMock,
            $this->validatorMock,
            $this->lifecycleHandlerMock,
            $this->stateFetcherMock,
            $this->loggerMock,
        );
    }

    public function testExecutesAllIntermediateCommandsOnCatchUp(): void
    {
        $catchUpPath = ['CONFIRMED', 'PROCESSING', 'AUTHORIZED'];

        $this->stateFetcherMock->method('fetchState')->willReturn('AUTHORIZED');
        $this->lifecycleHandlerMock->method('getLastProcessedState')->willReturn('PENDING');
        $this->validatorMock->method('getTransitionPath')->willReturn($catchUpPath);

        $commandConfirmed = $this->createMock(WebhookCommandInterface::class);
        $commandConfirmed->expects($this->once())->method('execute');
        $listenerConfirmed = $this->createMock(WebhookListenerInterface::class);
        $listenerConfirmed->method('getCommand')->willReturn($commandConfirmed);

        $commandProcessing = $this->createMock(WebhookCommandInterface::class);
        $commandProcessing->expects($this->once())->method('execute');
        $listenerProcessing = $this->createMock(WebhookListenerInterface::class);
        $listenerProcessing->method('getCommand')->willReturn($commandProcessing);

        $commandAuthorized = $this->createMock(WebhookCommandInterface::class);
        $commandAuthorized->expects($this->once())->method('execute');
        $listenerAuthorized = $this->createMock(WebhookListenerInterface::class);
        $listenerAuthorized->method('getCommand')->willReturn($commandAuthorized);

        $this->registryMock->method('findListener')
            ->willReturnMap([
                [WebhookListener::TRANSACTION, 'CONFIRMED', $listenerConfirmed],
                [WebhookListener::TRANSACTION, 'PROCESSING', $listenerProcessing],
                [WebhookListener::TRANSACTION, 'AUTHORIZED', $listenerAuthorized],
            ]);

        $this->lifecycleHandlerMock->expects($this->exactly(3))->method('preProcess')->willReturn(true);
        $this->lifecycleHandlerMock->expects($this->exactly(3))->method('postProcess');

        $this->requestMock->method('get')->willReturnMap([
            ['listenerEntityTechnicalName', null, 'Transaction'],
            ['entityId', null, 123],
            ['spaceId', null, 405], // <-- FIX: Add spaceId
        ]);

        $this->processor->process($this->requestMock);
    }

    public function testExecutesOnlyTargetCommandForAnyToTransition(): void
    {
        $targetState = 'VOIDED';

        $this->stateFetcherMock->method('fetchState')->willReturn($targetState);
        $this->lifecycleHandlerMock->method('getLastProcessedState')->willReturn('PROCESSING');
        $this->validatorMock->method('getTransitionPath')->willReturn([$targetState]);

        $commandVoided = $this->createMock(WebhookCommandInterface::class);
        $commandVoided->expects($this->once())->method('execute');

        $listenerVoided = $this->createMock(WebhookListenerInterface::class);
        $listenerVoided->method('getCommand')->willReturn($commandVoided);

        $this->registryMock->method('findListener')
            ->with(WebhookListener::TRANSACTION, $targetState)
            ->willReturn($listenerVoided);

        $this->lifecycleHandlerMock->expects($this->once())->method('preProcess')->willReturn(true);
        $this->lifecycleHandlerMock->expects($this->once())->method('postProcess');

        $this->requestMock->method('get')->willReturnMap([
            ['listenerEntityTechnicalName', null, 'Transaction'],
            ['entityId', null, 123],
            ['spaceId', null, 405], // <-- FIX: Add spaceId
        ]);

        $this->processor->process($this->requestMock);
    }

    public function testIgnoresDuplicateWebhook(): void
    {
        $this->stateFetcherMock->method('fetchState')->willReturn('COMPLETED');
        $this->lifecycleHandlerMock->method('getLastProcessedState')->willReturn('CREATE');
        $this->validatorMock->method('getTransitionPath')->willReturn([]); // Empty path = duplicate

        $this->loggerMock
            ->expects($this->once())
            ->method('debug')
            ->with($this->stringContains('already processed'));

        $this->registryMock->expects($this->never())->method('findListener');

        $this->requestMock->method('get')->willReturnMap([
            ['listenerEntityTechnicalName', null, 'Transaction'],
            ['entityId', null, 123],
            ['spaceId', null, 405], // <-- FIX: Add spaceId
        ]);

        $this->processor->process($this->requestMock);
    }

    public function testLogsDebugOnInvalidOrStaleStateTransition(): void
    {
        $this->stateFetcherMock->method('fetchState')->willReturn('COMPLETED');
        $this->lifecycleHandlerMock->method('getLastProcessedState')->willReturn('PENDING');
        $this->validatorMock->method('getTransitionPath')->willReturn(null); // Invalid/Stale transition

        $this->loggerMock
            ->expects($this->once())
            ->method('debug') // Expect DEBUG
            ->with($this->stringContains('not possible or already passed')); // Updated message check

        $this->lifecycleHandlerMock->expects($this->never())->method('preProcess');
        $this->registryMock->expects($this->never())->method('findListener');

        $this->requestMock->method('get')->willReturnMap([
            ['listenerEntityTechnicalName', null, 'Transaction'],
            ['entityId', null, 123],
            ['spaceId', null, 405],
        ]);

        $this->processor->process($this->requestMock);
    }

    public function testLogsNoticeWhenListenerNotFound(): void
    {
        $this->stateFetcherMock->method('fetchState')->willReturn('COMPLETED');
        $this->lifecycleHandlerMock->method('getLastProcessedState')->willReturn('CREATE');
        $this->validatorMock->method('getTransitionPath')->willReturn(['COMPLETED']);
        $this->registryMock->method('findListener')->willReturn(null);
        $this->loggerMock->expects($this->once())->method('debug');
        $this->lifecycleHandlerMock->expects($this->once())
            ->method('preProcess')->willReturn(true);
        $this->lifecycleHandlerMock->expects($this->once())
            ->method('postProcess');

        $this->requestMock->method('get')->willReturnMap([
            ['listenerEntityTechnicalName', null, 'Transaction'],
            ['entityId', null, 123],
            ['spaceId', null, 405], // <-- FIX: Add spaceId
        ]);

        $this->processor->process($this->requestMock);
    }

    public function testProcessCallsOnFailureHookWhenCommandFails(): void
    {
        $this->stateFetcherMock->method('fetchState')->willReturn('COMPLETED');
        $this->lifecycleHandlerMock->method('getLastProcessedState')->willReturn('PENDING');
        $this->validatorMock->method('getTransitionPath')->willReturn(['COMPLETED']);
        $this->expectException(CommandException::class);

        $commandMock = $this->createMock(WebhookCommandInterface::class);
        $commandMock->method('execute')->willThrowException(new \Exception('Database failed!'));

        $listenerMock = $this->createMock(WebhookListenerInterface::class);
        $listenerMock->method('getCommand')->willReturn($commandMock);

        $this->registryMock->method('findListener')->willReturn($listenerMock);

        $this->lifecycleHandlerMock->expects($this->once())->method('preProcess')->willReturn(true);
        $this->lifecycleHandlerMock->expects($this->once())->method('onFailure');
        $this->lifecycleHandlerMock->expects($this->never())->method('postProcess');

        $this->requestMock->method('get')->willReturnMap([
            ['listenerEntityTechnicalName', null, 'Transaction'],
            ['entityId', null, 123],
            ['spaceId', null, 405], // <-- FIX: Add spaceId
        ]);

        $this->processor->process($this->requestMock);
    }

    public function testProcessSuccessfullyFindsAndExecutesCommand(): void
    {
        $this->stateFetcherMock->method('fetchState')->willReturn('COMPLETED');
        $this->lifecycleHandlerMock->method('getLastProcessedState')->willReturn('PENDING');
        $this->validatorMock->method('getTransitionPath')->willReturn(['COMPLETED']);

        $commandMock = $this->createMock(WebhookCommandInterface::class);
        $commandMock->expects($this->once())->method('execute');

        $listenerMock = $this->createMock(WebhookListenerInterface::class);
        $listenerMock->method('getCommand')->willReturn($commandMock);

        $this->registryMock->method('findListener')->willReturn($listenerMock);

        $this->lifecycleHandlerMock->expects($this->once())->method('preProcess')->willReturn(true);
        $this->lifecycleHandlerMock->expects($this->once())->method('postProcess');
        $this->lifecycleHandlerMock->expects($this->never())->method('onFailure');

        $this->requestMock->method('get')->willReturnMap([
            ['listenerEntityTechnicalName', null, 'Transaction'],
            ['entityId', null, 123],
            ['spaceId', null, 405], // <-- FIX: Add spaceId
        ]);

        $this->processor->process($this->requestMock);
    }
}
