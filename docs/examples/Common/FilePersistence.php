<?php

namespace WeArePlanet\PluginCore\Examples\Common;

use WeArePlanet\PluginCore\Transaction\TransactionPersistenceInterface;

class FilePersistence implements \WeArePlanet\PluginCore\Transaction\TransactionPersistenceInterface
{
    private string $filePath;

    public function __construct(?string $filePath = null)
    {
        $this->filePath = $filePath ?? getcwd() . '/session.json';
    }

    public function save(array $data): void
    {
        $current = $this->load();
        $merged = array_merge($current, $data);
        file_put_contents($this->filePath, json_encode($merged, JSON_PRETTY_PRINT));
    }

    public function load(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }
        $content = file_get_contents($this->filePath);
        return json_decode($content, true) ?: [];
    }

    public function get(string $key): mixed
    {
        $data = $this->load();
        return $data[$key] ?? null;
    }

    public function persist(int $transactionId): void
    {
        $this->save(['transaction_id' => $transactionId]);
    }

    public function getTransactionId(): ?int
    {
        return $this->get('transaction_id');
    }
}
