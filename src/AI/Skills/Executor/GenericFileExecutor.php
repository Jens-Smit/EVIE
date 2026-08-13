<?php

namespace App\AI\Skills\Executor;

use App\AI\Skills\Tool\DynamicTool;
use RuntimeException;

class GenericFileExecutor implements ExecutorInterface
{
    public function execute(DynamicTool $tool, array $parameters): mixed
    {
        $config = $tool->getExecutorConfig();
        $action = $config['action'] ?? 'read';
        $path = $parameters['path'] ?? null;

        if (!$path) {
            throw new RuntimeException('File-Executor: Pfad ist erforderlich');
        }

        switch ($action) {
            case 'read':
                if (!file_exists($path)) {
                    throw new RuntimeException("Datei nicht gefunden: {$path}");
                }
                return file_get_contents($path);

            case 'write':
                $content = $parameters['content'] ?? '';
                file_put_contents($path, $content);
                return ['status' => 'success', 'path' => $path, 'bytes' => strlen($content)];

            case 'delete':
                if (file_exists($path)) {
                    unlink($path);
                    return ['status' => 'success', 'path' => $path, 'deleted' => true];
                }
                return ['status' => 'success', 'path' => $path, 'deleted' => false];

            default:
                throw new RuntimeException("Unbekannte Aktion: {$action}");
        }
    }

    public function getType(): string
    {
        return 'filesystem';
    }
}
