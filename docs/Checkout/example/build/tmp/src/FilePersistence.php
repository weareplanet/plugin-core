<?php

namespace MyPlugin\ExampleCheckoutImplementation;

use WeArePlanet\PluginCore\Transaction\TransactionPersistenceInterface;

/**
 * Simulates a shop database or session storage using a local JSON file.
 */
class FilePersistence implements TransactionPersistenceInterface
{
    private string $file = __DIR__ . '/../session.json';

    public function persist(int $transactionId): void
    {
        $data = $this->load();
        $data['weareplanet_transaction_id'] = $transactionId;
        
        file_put_contents($this->file, json_encode($data, JSON_PRETTY_PRINT));
        echo "[PERSISTENCE] Saved Transaction ID $transactionId to storage.\n";
    }

    public function getTransactionId(): ?int
    {
        $data = $this->load();
        return $data['weareplanet_transaction_id'] ?? null;
    }

    private function load(): array
    {
        if (!file_exists($this->file)) {
            return [];
        }
        return json_decode(file_get_contents($this->file), true) ?? [];
    }
}