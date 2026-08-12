<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Webhook;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Webhook\Exception\TransientWebhookException;
use WeArePlanet\PluginCore\Http\Request;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener;
use WeArePlanet\PluginCore\Webhook\WebhookProcessor;
use WeArePlanet\PluginCore\Webhook\StateFetcherInterface;
use WeArePlanet\PluginCore\Webhook\WebhookLifecycleHandler;
use WeArePlanet\PluginCore\Webhook\StateValidator;
use WeArePlanet\PluginCore\Webhook\Listener\WebhookListenerInterface;
use WeArePlanet\PluginCore\Webhook\Listener\WebhookListenerRegistry;
use WeArePlanet\PluginCore\Webhook\Command\WebhookCommandInterface;
use WeArePlanet\PluginCore\Webhook\Exception\CommandException;

class WebhookProcessorTest extends TestCase
{
    private MockObject|WebhookListenerRegistry $registryMock;
    private MockObject|StateValidator $validatorMock;
    private MockObject|WebhookLifecycleHandler $lifecycleHandlerMock;
    private MockObject|LoggerInterface $loggerMock;
    private WebhookProcessor $processor;
    private MockObject|StateFetcherInterface $stateFetcherMock;
    private MockObject|Request $requestMock;

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

    public function testTransientConditionIsLoggedWithItsReasonAndWithoutARawException(): void
    {
        // A TransientWebhookException is a deliberately caught, self-healing condition.
        // Handing the raw Throwable to the logger makes backends render it with a
        // file-and-line fragment that reads like an unhandled error, so the reason goes
        // into the message instead and no 'exception' key is passed at this level.
        $reason = 'order 000000009 is not yet authorized - deferring capture for retry.';

        $command = $this->createMock(WebhookCommandInterface::class);
        $command->method('execute')->willThrowException(new TransientWebhookException($reason));
        $listener = $this->createMock(WebhookListenerInterface::class);
        $listener->method('getCommand')->willReturn($command);

        $this->stateFetcherMock->method('fetchState')->willReturn('AUTHORIZED');
        $this->lifecycleHandlerMock->method('getLastProcessedState')->willReturn('PENDING');
        $this->validatorMock->method('getTransitionPath')->willReturn(['AUTHORIZED']);
        $this->registryMock->method('findListener')->willReturn($listener);
        $this->lifecycleHandlerMock->method('preProcess')->willReturn(true);

        $this->requestMock->method('get')->willReturnMap([
            ['listenerEntityTechnicalName', null, 'Transaction'],
            ['entityId', null, 123],
            ['spaceId', null, 405],
        ]);

        // The processor also logs the transition path at info, so collect every info
        // record and assert against the one describing the delay.
        $infoRecords = [];
        $this->loggerMock->method('info')
            ->willReturnCallback(function (string $message, array $context = []) use (&$infoRecords): void {
                $infoRecords[] = [$message, $context];
            });

        try {
            $this->processor->process($this->requestMock);
            $this->fail('Expected a CommandException.');
        } catch (CommandException $e) {
            // Expected: the transient branch still re-throws so the WeArePlanet Portal retries.
        }

        $delayed = array_values(array_filter(
            $infoRecords,
            static fn (array $record): bool => str_contains($record[0], 'delayed'),
        ));

        $this->assertCount(1, $delayed, 'Expected exactly one delay record at info level.');
        [$message, $context] = $delayed[0];

        // The reason lives in context only — repeating it in the message would print
        // it twice in a line-formatted backend.
        $this->assertStringNotContainsString($reason, $message);
        $this->assertSame($reason, $context['reason']);
        // And no raw Throwable is handed over for a backend to render as a trace.
        $this->assertArrayNotHasKey('exception', $context);
        $this->assertSame(123, $context['entityId']);
        $this->assertSame(405, $context['spaceId']);
    }
}
