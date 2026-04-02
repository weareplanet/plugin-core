<?php

namespace WeArePlanet\PluginCore\Examples\Common;

class TransactionIdLoader
{
    /**
     * Resolves the Transaction ID.
     *
     * @param array $args The CLI arguments (argv).
     * @return int The resolved Transaction ID.
     * @throws \RuntimeException If the ID cannot be found.
     */
    public static function load(array $args): int
    {
        // Remove script name
        $cliArgs = array_slice($args, 1);

        $targetTransactionId = null;
        $targetSessionFile = null;

        if (count($cliArgs) > 0) {
            $arg1 = $cliArgs[0];
            if (self::isId($arg1)) {
                $targetTransactionId = (int)$arg1;
            } elseif (is_file($arg1)) {
                $targetSessionFile = $arg1;
                if (isset($cliArgs[1]) && self::isId($cliArgs[1])) {
                    $targetTransactionId = (int)$cliArgs[1];
                }
            } elseif (is_dir($arg1)) {
                $candidate = rtrim($arg1, '/') . '/session.json';
                if (file_exists($candidate)) {
                    $targetSessionFile = $candidate;
                }
                if (isset($cliArgs[1]) && self::isId($cliArgs[1])) {
                    $targetTransactionId = (int)$cliArgs[1];
                }
            }
        }

        if (!$targetTransactionId) {
            if (!$targetSessionFile) {
                // Auto-discovery logic: search common paths relative to current execution
                $possiblePaths = [
                    getcwd() . '/session.json',
                    __DIR__ . '/../../../Checkout/example/session.json', // Check Checkout example
                    __DIR__ . '/../../../Recurring/example/session.json',
                    __DIR__ . '/../../../Completion/example/session.json',
                ];

                foreach ($possiblePaths as $path) {
                    if (file_exists($path)) {
                        $targetSessionFile = $path;
                        break;
                    }
                }
            }

            if ($targetSessionFile) {
                echo "Loading session from: " . realpath($targetSessionFile) . "\n";
                // Reuse FilePersistence if available, else manual decode
                if (class_exists(FilePersistence::class)) {
                    $persistence = new FilePersistence($targetSessionFile);
                    $targetTransactionId = $persistence->getTransactionId();
                } else {
                    $data = json_decode(file_get_contents($targetSessionFile), true);
                    $targetTransactionId = $data['weareplanet_transaction_id'] ?? null;
                }
            }
        }

        if (!$targetTransactionId) {
            self::printUsage();
            throw new \RuntimeException("Could not find an active transaction ID.");
        }

        return $targetTransactionId;
    }

    private static function isId($val): bool
    {
        return is_numeric($val) && (int)$val > 0;
    }

    private static function printUsage(): void
    {
        echo "ERROR: Could not find an active transaction ID.\n\n";
        echo "Usage:\n";
        echo "  php script.php                             (Auto-detects session)\n";
        echo "  php script.php <path/to/session.json>      (Uses specific session file)\n";
        echo "  php script.php <transaction_id>            (Uses specific Transaction ID)\n\n";
    }
}
