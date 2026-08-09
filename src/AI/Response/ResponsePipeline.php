<?php

namespace App\AI\Response;

use Psr\Log\LoggerInterface;

/**
 * ResponsePipeline - End-to-End-Verarbeitung von LLM-Antworten
 *
 * Diese Klasse implementiert eine vollständige Antwort-Pipeline, die:
 * 1. Prompt-Normalisierung durchführt
 * 2. LLM-Antworten validiert und normalisiert
 * 3. Fehlertolerante Verarbeitung implementiert
 * 4. Monitoring und Logging bereitstellt
 * 5. Strukturierte Antworten für den Controller aufbereitet
 */
class ResponsePipeline
{
    private LoggerInterface $logger;
    private JsonResponseEnforcer $jsonResponseEnforcer;
    private ResponseNormalizer $responseNormalizer;
    private FaultTolerantValidator $faultTolerantValidator;

    public function __construct(
        LoggerInterface $logger,
        JsonResponseEnforcer $jsonResponseEnforcer,
        ResponseNormalizer $responseNormalizer,
        FaultTolerantValidator $faultTolerantValidator
    ) {
        $this->logger = $logger;
        $this->jsonResponseEnforcer = $jsonResponseEnforcer;
        $this->responseNormalizer = $responseNormalizer;
        $this->faultTolerantValidator = $faultTolerantValidator;
    }

    /**
     * Verarbeitet eine komplette Anfrage durch die Antwort-Pipeline
     */
    public function processRequest(string $userMessage, string $agentType = 'orchestrator'): array
    {
        $pipelineContext = [
            'user_message' => $userMessage,
            'agent_type' => $agentType,
            'start_time' => microtime(true),
            'processing_steps' => [],
            'metrics' => [
                'response_time' => 0,
                'normalization_attempts' => 0,
                'fallback_triggered' => false,
                'confidence_score' => 0.0
            ]
        ];

        $this->logger->info('ResponsePipeline - Verarbeitung gestartet', [
            'user_message' => substr($userMessage, 0, 100),
            'agent_type' => $agentType
        ]);

        try {
            // Schritt 1: Prompt-Normalisierung
            $pipelineContext = $this->stepPromptNormalization($userMessage, $agentType, $pipelineContext);

            // Schritt 2: LLM-Antwort simulieren (in einer echten Implementierung würde hier der LLM-Aufruf stattfinden)
            $simulatedResponse = $this->simulateLlmResponse($userMessage, $agentType);
            $pipelineContext['llm_raw_response'] = $simulatedResponse;

            // Schritt 3: Antwort-Validierung und Normalisierung
            $pipelineContext = $this->stepResponseValidation($simulatedResponse, $userMessage, $agentType, $pipelineContext);

            // Schritt 4: Fehlertolerante Verarbeitung
            $pipelineContext = $this->stepFaultTolerantProcessing($pipelineContext);

            // Schritt 5: Finalisierung
            $pipelineContext = $this->stepFinalization($pipelineContext);

            $this->logger->info('ResponsePipeline - Verarbeitung erfolgreich abgeschlossen', [
                'final_confidence' => $pipelineContext['metrics']['confidence_score'],
                'processing_time' => $pipelineContext['metrics']['response_time']
            ]);

            return $pipelineContext;

        } catch (\Exception $e) {
            $this->logger->error('Fehler in der ResponsePipeline', [
                'error' => $e->getMessage(),
                'user_message' => substr($userMessage, 0, 100)
            ]);

            return $this->createErrorPipelineContext($userMessage, $agentType, $e->getMessage());
        }
    }

    /**
     * Schritt 1: Prompt-Normalisierung
     */
    private function stepPromptNormalization(string $userMessage, string $agentType, array $context): array
    {
        $startTime = microtime(true);

        // Erstelle strukturierte Nachrichten
        $messages = $this->jsonResponseEnforcer->createStructuredPrompt($userMessage, $agentType);

        $context['processing_steps'][] = [
            'step' => 'prompt_normalization',
            'duration' => microtime(true) - $startTime,
            'status' => 'success',
            'details' => [
                'agent_type' => $agentType,
                'message_count' => count($messages->getMessages())
            ]
        ];

        $context['structured_messages'] = $messages;

        return $context;
    }

    /**
     * Simuliert eine LLM-Antwort (für Implementierung ohne tatsächliche API-Aufrufe)
     */
    private function simulateLlmResponse(string $userMessage, string $agentType): string
    {
        // Simuliere verschiedene Antworttypen basierend auf der Anfrage
        $messageLower = strtolower($userMessage);

        // Webseiten-Recherche-Anfragen
        if (strpos($messageLower, 'visiongastro') !== false || strpos($messageLower, 'webseite') !== false) {
            return $this->simulateWebsiteResearchResponse();
        }

        // Datenanalyse-Anfragen
        if (strpos($messageLower, 'daten') !== false || strpos($messageLower, 'analyse') !== false) {
            return $this->simulateDataAnalysisResponse();
        }

        // Standardantwort
        return $this->simulateGenericResponse();
    }

    /**
     * Simuliert eine Webseiten-Recherche-Antwort
     */
    private function simulateWebsiteResearchResponse(): string
    {
        // Simuliere eine gut strukturierte JSON-Antwort
        return json_encode([
            'type' => 'subagent_delegation',
            'subagent' => 'website_researcher',
            'reason' => 'User hat nach Webseiten-Recherche gefragt',
            'task_description' => 'Webseite visiongastro.de durchsuchen und Inhalt zusammenfassen',
            'confidence' => 0.95
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Simuliert eine Datenanalyse-Antwort
     */
    private function simulateDataAnalysisResponse(): string
    {
        // Simuliere eine teilweise beschädigte JSON-Antwort (für Testzwecke)
        return '{
            "type": "tool_call",
            "tool_name": "data_analyst",
            "parameters": {
                "task": "Datenanalyse durchführen",
                "data_source": "user_provided"
            },
            "confidence": 0.85
        }';
    }

    /**
     * Simuliert eine generische Antwort
     */
    private function simulateGenericResponse(): string
    {
        // Simuliere eine unstrukturierte Antwort (für Testzwecke)
        return 'Ich habe Ihre Anfrage verstanden und werde sie bearbeiten. Bitte haben Sie einen Moment Geduld, während ich die besten Optionen für Sie analysiere.';
    }

    /**
     * Schritt 2: Antwort-Validierung und Normalisierung
     */
    private function stepResponseValidation(string $rawResponse, string $userMessage, string $agentType, array $context): array
    {
        $startTime = microtime(true);

        // Validiere die Antwort
        $isValid = $this->jsonResponseEnforcer->validateJsonResponse($rawResponse);

        if ($isValid) {
            $context['processing_steps'][] = [
                'step' => 'response_validation',
                'duration' => microtime(true) - $startTime,
                'status' => 'success',
                'details' => [
                    'validation_result' => 'valid_json',
                    'response_type' => $this->jsonResponseEnforcer->extractResponseType($rawResponse)
                ]
            ];

            $context['validated_response'] = $rawResponse;
            $context['metrics']['confidence_score'] = 1.0;
        } else {
            // Versuche Normalisierung
            $normalizedResponse = $this->responseNormalizer->normalizeResponse($rawResponse);
            $context['metrics']['normalization_attempts'] = 1;

            $normalizedDecoded = json_decode($normalizedResponse, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($normalizedDecoded['type'])) {
                $context['processing_steps'][] = [
                    'step' => 'response_normalization',
                    'duration' => microtime(true) - $startTime,
                    'status' => 'success',
                    'details' => [
                        'original_valid' => false,
                        'normalization_result' => 'success',
                        'response_type' => $normalizedDecoded['type']
                    ]
                ];

                $context['validated_response'] = $normalizedResponse;
                $context['metrics']['confidence_score'] = 0.8;
            } else {
                $context['processing_steps'][] = [
                    'step' => 'response_validation',
                    'duration' => microtime(true) - $startTime,
                    'status' => 'failed',
                    'details' => [
                        'validation_result' => 'invalid_json',
                        'normalization_result' => 'failed',
                        'error' => 'Konnte Antwort nicht normalisieren'
                    ]
                ];

                $context['metrics']['confidence_score'] = 0.3;
                $context['metrics']['fallback_triggered'] = true;
            }
        }

        return $context;
    }

    /**
     * Schritt 3: Fehlertolerante Verarbeitung
     */
    private function stepFaultTolerantProcessing(array $context): array
    {
        $startTime = microtime(true);

        // Wenn wir bereits eine validierte Antwort haben, überspringen wir diesen Schritt
        if (isset($context['validated_response']) && $context['metrics']['confidence_score'] >= 0.8) {
            $context['processing_steps'][] = [
                'step' => 'fault_tolerant_processing',
                'duration' => microtime(true) - $startTime,
                'status' => 'skipped',
                'details' => [
                    'reason' => 'Antwort bereits validiert mit hoher Konfidenz'
                ]
            ];

            return $context;
        }

        // Führe fehlertolerante Validierung durch
        $rawResponse = $context['llm_raw_response'] ?? '';
        $validationResult = $this->faultTolerantValidator->validateWithFallback(
            $rawResponse,
            $context['user_message'],
            $context['agent_type']
        );

        if ($validationResult['is_valid'] && $validationResult['normalized_response'] !== null) {
            $context['validated_response'] = $validationResult['normalized_response'];
            $context['metrics']['confidence_score'] = $validationResult['confidence'];
            $context['metrics']['fallback_triggered'] = $validationResult['error_type'] === 'fallback_applied';

            $context['processing_steps'][] = [
                'step' => 'fault_tolerant_processing',
                'duration' => microtime(true) - $startTime,
                'status' => 'success',
                'details' => [
                    'recovery_action' => $validationResult['recovery_action'],
                    'final_confidence' => $validationResult['confidence'],
                    'error_type' => $validationResult['error_type']
                ]
            ];
        } else {
            $context['processing_steps'][] = [
                'step' => 'fault_tolerant_processing',
                'duration' => microtime(true) - $startTime,
                'status' => 'failed',
                'details' => [
                    'error_type' => $validationResult['error_type'],
                    'error_message' => $validationResult['error_message']
                ]
            ];

            $context['metrics']['confidence_score'] = 0.1;
        }

        return $context;
    }

    /**
     * Schritt 4: Finalisierung
     */
    private function stepFinalization(array $context): array
    {
        $startTime = microtime(true);

        // Berechne die Gesamtverarbeitungszeit
        $context['metrics']['response_time'] = microtime(true) - $context['start_time'];

        // Erstelle die finale Antwort
        if (isset($context['validated_response'])) {
            $finalResponse = json_decode($context['validated_response'], true);
            $finalResponse['pipeline_metadata'] = [
                'processing_time' => $context['metrics']['response_time'],
                'confidence' => $context['metrics']['confidence_score'],
                'steps_executed' => count($context['processing_steps']),
                'fallback_used' => $context['metrics']['fallback_triggered'],
                'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM)
            ];

            $context['final_response'] = json_encode($finalResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $context['final_response_data'] = $finalResponse;
        } else {
            // Erstelle eine Fehlerantwort
            $errorResponse = $this->createPipelineErrorResponse($context);
            $context['final_response'] = json_encode($errorResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $context['final_response_data'] = $errorResponse;
        }

        $context['processing_steps'][] = [
            'step' => 'finalization',
            'duration' => microtime(true) - $startTime,
            'status' => 'success',
            'details' => [
                'final_confidence' => $context['metrics']['confidence_score'],
                'response_length' => strlen($context['final_response'] ?? ''),
                'has_final_response' => isset($context['final_response'])
            ]
        ];

        return $context;
    }

    /**
     * Erstellt eine Pipeline-Fehlerantwort
     */
    private function createPipelineErrorResponse(array $context): array
    {
        $lastStep = end($context['processing_steps']);
        $errorDetails = $lastStep['details'] ?? ['error' => 'Unknown error'];

        return [
            'type' => 'pipeline_error',
            'error_message' => $errorDetails['error'] ?? 'Pipeline processing failed',
            'status' => 'failed',
            'original_message' => substr($context['user_message'], 0, 200),
            'processing_steps' => array_map(function($step) {
                return [
                    'step' => $step['step'],
                    'status' => $step['status'],
                    'duration' => $step['duration']
                ];
            }, $context['processing_steps']),
            'pipeline_metadata' => [
                'processing_time' => $context['metrics']['response_time'],
                'confidence' => 0.0,
                'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM)
            ]
        ];
    }

    /**
     * Erstellt einen Fehler-Pipeline-Kontext
     */
    private function createErrorPipelineContext(string $userMessage, string $agentType, string $errorMessage): array
    {
        return [
            'user_message' => $userMessage,
            'agent_type' => $agentType,
            'start_time' => microtime(true),
            'processing_steps' => [
                [
                    'step' => 'initialization',
                    'duration' => 0,
                    'status' => 'failed',
                    'details' => [
                        'error' => $errorMessage
                    ]
                ]
            ],
            'metrics' => [
                'response_time' => 0,
                'normalization_attempts' => 0,
                'fallback_triggered' => false,
                'confidence_score' => 0.0
            ],
            'error' => $errorMessage,
            'final_response' => json_encode([
                'type' => 'pipeline_error',
                'error_message' => $errorMessage,
                'status' => 'failed',
                'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM)
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        ];
    }

    /**
     * Extrahiere die finale Antwort aus dem Pipeline-Kontext
     */
    public function extractFinalResponse(array $pipelineContext): string
    {
        return $pipelineContext['final_response'] ?? json_encode([
            'type' => 'error',
            'message' => 'No response available',
            'status' => 'failed'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Generiert ein Pipeline-Monitoring-Dashboard
     */
    public function generateMonitoringDashboard(array $pipelineContext): array
    {
        $dashboard = [
            'pipeline_id' => uniqid('pipe_'),
            'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'user_message' => substr($pipelineContext['user_message'], 0, 100),
            'agent_type' => $pipelineContext['agent_type'],
            'metrics' => $pipelineContext['metrics'],
            'status' => $this->determinePipelineStatus($pipelineContext),
            'steps' => array_map(function($step) {
                return [
                    'name' => $step['step'],
                    'status' => $step['status'],
                    'duration' => round($step['duration'], 4),
                    'details' => $step['details'] ?? []
                ];
            }, $pipelineContext['processing_steps']),
            'quality_indicators' => [
                'confidence_score' => $pipelineContext['metrics']['confidence_score'],
                'fallback_used' => $pipelineContext['metrics']['fallback_triggered'],
                'normalization_attempts' => $pipelineContext['metrics']['normalization_attempts'],
                'response_quality' => $this->calculateResponseQuality($pipelineContext)
            ]
        ];

        if (isset($pipelineContext['final_response_data'])) {
            $dashboard['response_preview'] = substr(
                json_encode($pipelineContext['final_response_data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                0,
                500
            );
        }

        return $dashboard;
    }

    /**
     * Bestimmt den Pipeline-Status
     */
    private function determinePipelineStatus(array $context): string
    {
        if (!isset($context['final_response'])) {
            return 'failed';
        }

        if ($context['metrics']['confidence_score'] >= 0.8) {
            return 'success';
        }

        if ($context['metrics']['confidence_score'] >= 0.5) {
            return 'partial_success';
        }

        return 'degraded';
    }

    /**
     * Berechnet die Antwortqualität
     */
    private function calculateResponseQuality(array $context): string
    {
        $confidence = $context['metrics']['confidence_score'];

        if ($confidence >= 0.9) {
            return 'excellent';
        }

        if ($confidence >= 0.7) {
            return 'good';
        }

        if ($confidence >= 0.5) {
            return 'acceptable';
        }

        if ($confidence >= 0.3) {
            return 'poor';
        }

        return 'unacceptable';
    }
}