<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Token;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Token\State;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\PluginCore\Token\TokenVersion;
use WeArePlanet\PluginCore\Token\Version\State as VersionState;

class TokenTest extends TestCase
{
    public function testToString(): void
    {
        $token = new Token(
            id: 700,
            state: State::ACTIVE,
            spaceId: 1,
            version: 1,
        );

        $json = (string) $token;
        $this->assertJson($json);
        $decoded = json_decode($json, true);

        $this->assertEquals(700, $decoded['id']);
        $this->assertEquals(1, $decoded['spaceId']);
        $this->assertEquals(1, $decoded['version']);
        $this->assertArrayHasKey('state', $decoded);
    }

    public function testOptionalPropertiesDefaultToNull(): void
    {
        $token = new Token(id: 700, state: State::ACTIVE);

        $this->assertNull($token->spaceId);
        $this->assertNull($token->version);
        $this->assertNull($token->customerId);
        $this->assertNull($token->customerIdentifier);
        $this->assertNull($token->createdOn);
    }

    public function testOnlyAnActiveTokenIsChargeable(): void
    {
        $this->assertTrue((new Token(id: 700, state: State::ACTIVE))->isChargeable());

        foreach ([State::CREATE, State::INACTIVE, State::DELETING, State::DELETED] as $state) {
            $this->assertFalse((new Token(id: 700, state: $state))->isChargeable());
        }
    }

    public function testTheTwoCustomerFieldsAreIndependent(): void
    {
        // customerId is the shop's own key for the customer; customerIdentifier is a
        // display value. A customer-scoped lookup must key off the former, so neither
        // may fall back to the other.
        $token = new Token(
            id: 700,
            state: State::ACTIVE,
            customerId: 'shop-customer-4711',
            customerIdentifier: 'customer@example.com',
        );

        $this->assertSame('shop-customer-4711', $token->customerId);
        $this->assertSame('customer@example.com', $token->customerIdentifier);
    }

    public function testTokenIsImmutable(): void
    {
        $token = new Token(id: 700, state: State::ACTIVE);

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line Intentionally writing to a readonly property.
        $token->id = 701;
    }

    public function testTokenVersionToString(): void
    {
        $tokenVersion = $this->makeTokenVersion();

        $json = (string) $tokenVersion;
        $this->assertJson($json);
        $decoded = json_decode($json, true);

        $this->assertEquals(900, $decoded['id']);
        $this->assertEquals('Visa ****1234', $decoded['name']);
        $this->assertEquals(1, $decoded['linkedSpaceId']);
        $this->assertEquals(31, $decoded['connectorId']);
        $this->assertEquals(88, $decoded['paymentMethodId']);
        $this->assertEquals(5501, $decoded['connectorConfigurationId']);
        $this->assertEquals(5502, $decoded['paymentMethodConfigurationId']);
        // The owning token is serialized along with its version.
        $this->assertEquals(700, $decoded['token']['id']);
    }

    public function testTokenVersionOptionalPropertiesDefaultToNull(): void
    {
        $tokenVersion = new TokenVersion(
            id: 900,
            token: new Token(id: 700, state: State::ACTIVE),
            state: VersionState::ACTIVE,
        );

        $this->assertNull($tokenVersion->name);
        $this->assertNull($tokenVersion->linkedSpaceId);
        $this->assertNull($tokenVersion->connectorId);
        $this->assertNull($tokenVersion->paymentMethodId);
        $this->assertNull($tokenVersion->connectorConfigurationId);
        $this->assertNull($tokenVersion->paymentMethodConfigurationId);
    }

    public function testOnlyAnActiveTokenVersionReportsItselfActive(): void
    {
        $token = new Token(id: 700, state: State::ACTIVE);

        $this->assertTrue((new TokenVersion(900, $token, VersionState::ACTIVE))->isActive());
        $this->assertFalse((new TokenVersion(900, $token, VersionState::OBSOLETE))->isActive());
        $this->assertFalse((new TokenVersion(900, $token, VersionState::UNINITIALIZED))->isActive());
    }

    public function testTokenVersionIsImmutable(): void
    {
        $tokenVersion = $this->makeTokenVersion();

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line Intentionally writing to a readonly property.
        $tokenVersion->name = 'Something else';
    }

    private function makeTokenVersion(): TokenVersion
    {
        return new TokenVersion(
            id: 900,
            token: new Token(id: 700, state: State::ACTIVE, spaceId: 1),
            state: VersionState::ACTIVE,
            name: 'Visa ****1234',
            linkedSpaceId: 1,
            connectorId: 31,
            paymentMethodId: 88,
            connectorConfigurationId: 5501,
            paymentMethodConfigurationId: 5502,
        );
    }
}
