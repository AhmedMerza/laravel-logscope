<?php

declare(strict_types=1);

namespace LogScope\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use LogScope\Models\LogEntry;
use LogScope\Services\LogParser;
use Throwable;

class ImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'logscope:import
                            {path? : Path to the log file to import}
                            {--days=7 : Only import logs from the last N days}
                            {--fresh : Clear existing logs before importing}
                            {--chunk=100 : Number of entries to insert per batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import logs from Laravel log files into the database';

    public function __construct(
        protected LogParser $parser
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = $this->argument('path');
        $days = (int) $this->option('days');
        $fresh = $this->option('fresh');
        $chunkSize = (int) $this->option('chunk');

        $since = $days > 0 ? now()->subDays($days) : null;

        // Clear existing logs if --fresh
        if ($fresh) {
            if ($this->confirm('This will delete all existing log entries. Continue?', false)) {
                $deleted = LogEntry::query()->delete();
                $this->components->info("Deleted {$deleted} existing log entries.");
            } else {
                return self::SUCCESS;
            }
        }

        // Get files to import
        $files = $this->getFilesToImport($path);

        if (empty($files)) {
            $this->components->warn('No log files found to import.');

            return self::SUCCESS;
        }

        $this->components->info('Found '.count($files).' log file(s) to import.');

        $totalImported = 0;
        $totalSkipped = 0;
        $totalErrors = 0;

        foreach ($files as $file) {
            $this->components->task(
                "Importing {$file['name']} (".$this->formatBytes($file['size']).')',
                function () use ($file, $since, $chunkSize, &$totalImported, &$totalSkipped, &$totalErrors) {
                    $result = $this->importFile($file['path'], $since, $chunkSize);
                    $totalImported += $result['imported'];
                    $totalSkipped += $result['skipped'];
                    $totalErrors += $result['errors'];

                    return $result['errors'] === 0;
                }
            );
        }

        $this->newLine();
        $this->components->bulletList([
            "Imported: {$totalImported} entries",
            "Skipped: {$totalSkipped} entries (older than {$days} days)",
            "Errors: {$totalErrors} entries",
        ]);

        if ($totalErrors > 0) {
            $this->components->warn('Some entries could not be imported. Check the logs for details.');

            return self::FAILURE;
        }

        $this->components->info('Import completed successfully.');

        return self::SUCCESS;
    }

    /**
     * Get files to import based on path argument.
     */
    protected function getFilesToImport(?string $path): array
    {
        if ($path) {
            if (! file_exists($path)) {
                $this->components->error("File not found: {$path}");

                return [];
            }

            return [[
                'path' => $path,
                'name' => basename($path),
                'size' => filesize($path),
            ]];
        }

        return $this->parser->getLogFiles();
    }

    /**
     * Import entries from a single file.
     */
    protected function importFile(string $path, ?Carbon $since, int $chunkSize): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = 0;
        $batch = [];

        foreach ($this->parser->parseFile($path, $since) as $entry) {
            try {
                $batch[] = $this->prepareEntry($entry);

                if (count($batch) >= $chunkSize) {
                    $this->insertBatch($batch);
                    $imported += count($batch);
                    $batch = [];
                }
            } catch (Throwable $e) {
                $errors++;
                if ($this->output->isVerbose()) {
                    $this->components->warn("Error parsing entry: {$e->getMessage()}");
                }
            }
        }

        // Insert remaining batch
        if (! empty($batch)) {
            $this->insertBatch($batch);
            $imported += count($batch);
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Prepare an entry for batch insert.
     */
    protected function prepareEntry(array $entry): array
    {
        $limits = config('logscope.limits', []);

        // Generate preview
        $messagePreview = LogEntry::createPreview(
            $entry['message'],
            $limits['message_preview_length'] ?? 500
        );

        $contextJson = json_encode($entry['context'] ?? []);
        $contextPreview = LogEntry::createPreview(
            $contextJson,
            $limits['context_preview_length'] ?? 500
        );

        // Check for truncation
        $isTruncated = false;
        $truncateAt = $limits['truncate_at'] ?? 1000000;

        if (strlen($entry['message']) > $truncateAt) {
            $entry['message'] = substr($entry['message'], 0, $truncateAt);
            $isTruncated = true;
        }

        return [
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'level' => $entry['level'],
            'message' => $entry['message'],
            'message_preview' => $messagePreview,
            'context' => json_encode($entry['context'] ?? []),
            'context_preview' => $contextPreview,
            'channel' => $entry['channel'] ?? 'import',
            'environment' => $entry['environment'] ?? app()->environment(),
            'source' => $entry['source'],
            'source_line' => $entry['source_line'],
            'occurred_at' => $entry['occurred_at'],
            'is_truncated' => $isTruncated,
            'created_at' => now(),
        ];
    }

    /**
     * Insert a batch of entries.
     */
    protected function insertBatch(array $batch): void
    {
        LogEntry::insert($batch);
    }

    /**
     * Format bytes to human readable.
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
