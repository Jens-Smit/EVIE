<?php

namespace App\AI\Skills;

/**
 * Interface für Tools, die Secrets benötigen
 * 
 * Tools, die API-Keys oder andere Secrets benötigen, müssen dieses Interface
 * implementieren, um die benötigten Secrets zu deklarieren.
 */
interface SecretAwareToolInterface
{
    /**
     * Gibt die benötigten Secrets für dieses Tool zurück
     * 
     * @return array Array von Secret-Definitionen
     *   [
     *     [
     *       'key' => 'api_key_name',          // Der Key des Secrets
     *       'description' => 'Beschreibung',   // Beschreibung für den User
     *       'is_required' => true/false        // Ob das Secret erforderlich ist
     *     ],
     *     ...
     *   ]
     */
    public static function getRequiredSecrets(): array;
}
