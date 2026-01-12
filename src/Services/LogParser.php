<?php

declare(strict_types=1);

namespace LogScope\Services;

use Carbon\Carbon;
use Generator;
use RuntimeException;

class LogParser
{
    /**
     * Laravel log pattern matching.
     * Matches: [2024-01-15 10:30:45] local.ERROR: Message here {"context":"data"}
     */
    protected const LARAVEL_LOG_PATTERN = '/^\[(\d{4}-\d{2}-\d{2}[T\s]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:\d{2})?)\]\s+(\w+)\.(\w+):\s+(.*)$/s';

    /**
     * Stack trace continuation pattern.
     */
    protected const STACK_TRACE_PATTERN = '/^#\d+\s+|^\[stacktrace\]|^Stack trace:|^\s+at\s+/';

    /**
     * Parse a log file using streaming to handle large files.
     *
     * @return Generator<array>
     */
    public function parseFile(string $path, ?Carbon $since = null): Generator
    {
        if (! file_exists($path)) {
            throw new RuntimeException("Log file not found: {$path}");
        }

        $handle = fopen($path, 'r');
        if (! $handle) {
            throw new RuntimeException("Unable to open log file: {$path}");
        }

        try {
            $currentEntry = null;
            $lineBuffer = '';

            while (! feof($handle)) {
                $line = fgets($handle);
                if ($line === false) {
                    break;
                }

                // Check if this line starts a new log entry
                if (preg_match(self::LARAVEL_LOG_PATTERN, $line, $matches)) {
                    // Yield the previous entry if exists
                    if ($currentEntry !== null) {
                        $parsed = $this->finalizeEntry($currentEntry);
                        if ($parsed && ($since === null || $parsed['occurred_at']->gte($since))) {
                            yield $parsed;
                        }
                    }

                    // Start new entry
                    $currentEntry = [
                        'datetime' => $matches[1],
                        'environment' => $matches[2],
                        'level' => strtolower($matches[3]),
                        'message' => $matches[4],
                    ];
                } elseif ($currentEntry !== null) {
                    // Continuation of the previous entry (stack trace, multi-line message)
                    $currentEntry['message'] .= "\n".rtrim($line);
                }
            }

            // Don't forget the last entry
            if ($currentEntry !== null) {
                $parsed = $this->finalizeEntry($currentEntry);
                if ($parsed && ($since === null || $parsed['occurred_at']->gte($since))) {
                    yield $parsed;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Finalize and parse a log entry.
     */
    protected function finalizeEntry(array $entry): ?array
    {
        try {
            $occurred_at = Carbon::parse($entry['datetime']);
        } catch (\Exception) {
            return null;
        }

        // Try to extract JSON context from the message
        $message = trim($entry['message']);
        $context = [];

        // Look for JSON at the end of the message
        if (preg_match('/\s+(\{.*\})\s*$/s', $message, $jsonMatch)) {
            try {
                $decoded = json_decode($jsonMatch[1], true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $context = $decoded;
                    $message = trim(substr($message, 0, -strlen($jsonMatch[0])));
                }
            } catch (\JsonException) {
                // Not valid JSON, keep as part of message
            }
        }

        // Extract exception information if present
        $source = null;
        $sourceLine = null;

        // Look for file:line pattern in message
        if (preg_match('/in\s+([^\s:]+):(\d+)/', $message, $fileMatch)) {
            $source = $fileMatch[1];
            $sourceLine = (int) $fileMatch[2];
        }

        // Also check context for exception
        if (isset($context['exception'])) {
            if (is_string($context['exception']) && preg_match('/in\s+([^\s:]+):(\d+)/', $context['exception'], $exMatch)) {
                $source = $source ?? $exMatch[1];
                $sourceLine = $sourceLine ?? (int) $exMatch[2];
            }
        }

        return [
            'level' => $entry['level'],
            'message' => $message,
            'context' => $context,
            'channel' => 'import',
            'environment' => $entry['environment'],
            'source' => $source,
            'source_line' => $sourceLine,
            'occurred_at' => $occurred_at,
        ];
    }

    /**
     * Count entries in a log file without loading all into memory.
     */
    public function countEntries(string $path, ?Carbon $since = null): int
    {
        $count = 0;

        foreach ($this->parseFile($path, $since) as $_) {
            $count++;
        }

        return $count;
    }

    /**
     * Get all log files from Laravel's storage/logs directory.
     */
    public function getLogFiles(?string $path = null): array
    {
        $path = $path ?? storage_path('logs');

        if (! is_dir($path)) {
            return [];
        }

        $files = [];

        foreach (glob($path.'/*.log') as $file) {
            $files[] = [
                'path' => $file,
                'name' => basename($file),
                'size' => filesize($file),
                'modified' => filemtime($file),
            ];
        }

        // Sort by modification time, newest first
        usort($files, fn ($a, $b) => $b['modified'] <=> $a['modified']);

        return $files;
    }
}
