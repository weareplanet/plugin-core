<?php

namespace WeArePlanet\PluginCore\Examples\Common;

use WeArePlanet\PluginCore\Transaction\TransactionPersistenceInterface;

/**
 * Simulates a shop database or session storage using a local JSON file.
 */
class FilePersistence implements TransactionPersistenceInterface
{
    private string $file;

    /**
     * @param string|null $filePath Path to the session file. Defaults to 'session.json' relative to execution directory.
     */
    public function __construct(?string $filePath = null)
    {
        // Default to a session.json in the current working directory or relative to specific script logic
        // But unified approach: let caller specify or default to 'session.json' in CWD.
        $this->file = $filePath ?? getcwd() . '/session.json';
    }

    public function persist(int $transactionId): void
    {
        $data = $this->load();
        $data['weareplanet_transaction_id'] = $transactionId;

        // Ensure directory exists
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($this->file, json_encode($data, JSON_PRETTY_PRINT));
        echo "[PERSISTENCE] Saved Transaction ID $transactionId to storage ({$this->file}).\n";
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
