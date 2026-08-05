<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

class SystemBackupService
{
    private const BACKUP_DIRECTORY = 'backups';

    private const TABLE_GROUPS = [
        'master-data' => [
            'companies', 'branch_dels', 'roles', 'role_permissions', 'users', 'stores',
            'categories', 'm_brands', 'customers', 'suppliers', 'units', 'tax_rates',
            'registers', 'products', 'product_barcodes', 'product_units', 'expense_categories',
            'chart_accounts',
        ],
        'inventory' => [
            'product_batches', 'inventory_movements', 'grocery_stock_transfers',
            'grocery_stock_transfer_lines', 'stock_adjustments', 'stock_adjustment_lines',
            'stock_counts', 'stock_count_lines',
        ],
        'purchases' => [
            'grocery_purchase_orders', 'grocery_purchase_order_lines', 'goods_receipts',
            'goods_receipt_lines', 'purchase_returns', 'purchase_return_lines',
            'grocery_supplier_payments', 'supplier_account_entries',
            'payment_cheques', 'customer_account_entries',
        ],
        'sales' => [
            'sales', 'sale_lines', 'sale_payments', 'sales_returns', 'sales_return_lines',
            'promotions',
        ],
        'cash-and-admin' => [
            'cashier_shifts', 'cash_movements', 'grocery_expenses', 'audit_logs',
            'app_settings', 'document_sequences',
        ],
        'system' => [
            'personal_access_tokens',
            'migrations',
        ],
    ];

    private const REFRESH_TABLES = [
        'sales_return_lines', 'sales_returns', 'sale_payments', 'sale_lines', 'sales',
        'purchase_return_lines', 'purchase_returns', 'goods_receipt_lines', 'goods_receipts',
        'grocery_purchase_order_lines', 'grocery_purchase_orders', 'grocery_supplier_payments',
        'supplier_account_entries', 'stock_count_lines', 'stock_counts',
        'stock_adjustment_lines', 'stock_adjustments', 'grocery_stock_transfer_lines',
        'grocery_stock_transfers', 'inventory_movements', 'product_batches', 'cash_movements',
        'cashier_shifts', 'grocery_expenses', 'audit_logs',
        'payment_cheques', 'customer_account_entries',
    ];

    public function listBackups(): array
    {
        $disk = Storage::disk('local');

        if (! $disk->exists(self::BACKUP_DIRECTORY)) {
            return [];
        }

        return collect($disk->files(self::BACKUP_DIRECTORY))
            ->filter(fn (string $path) => str_ends_with($path, '.json'))
            ->map(function (string $path) use ($disk) {
                $raw = $disk->get($path);
                $decoded = json_decode($raw, true);

                return is_array($decoded) ? $decoded : null;
            })
            ->filter()
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    public function createBackup(User $user, string $mode): array
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::BACKUP_DIRECTORY);

        $timestamp = now();
        $identifier = sprintf(
            'erp-backup-%s-%s',
            $timestamp->format('Ymd-His-u'),
            Str::slug($mode)
        );
        $zipRelativePath = self::BACKUP_DIRECTORY.'/'.$identifier.'.zip';
        $metaRelativePath = self::BACKUP_DIRECTORY.'/'.$identifier.'.json';
        $zipAbsolutePath = $disk->path($zipRelativePath);

        $manifest = [
            'id' => $identifier,
            'mode' => $mode,
            'created_at' => $timestamp->toIso8601String(),
            'created_by' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'branch' => $user->BC,
            ],
            'scope' => 'full-grocery-erp-dataset',
            'groups' => [],
        ];

        $temporaryFiles = [];
        $rowTotal = 0;
        $tableTotal = 0;
        $zip = new ZipArchive();

        if ($zip->open($zipAbsolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to initialize the backup archive.');
        }

        try {
            foreach (self::TABLE_GROUPS as $group => $tables) {
                $groupEntries = [];

                foreach ($tables as $table) {
                    if (! Schema::hasTable($table)) {
                        continue;
                    }

                    $columns = Schema::getColumnListing($table);
                    $rowCount = DB::table($table)->count();
                    $rowTotal += $rowCount;
                    $tableTotal++;

                    $csvRelativePath = $group.'/'.$table.'.csv';
                    $temporaryPath = tempnam(sys_get_temp_dir(), 'erp-backup-');

                    if ($temporaryPath === false) {
                        throw new \RuntimeException('Unable to create a temporary backup file.');
                    }

                    $temporaryFiles[] = $temporaryPath;
                    $this->writeTableCsv($table, $columns, $temporaryPath);

                    $zip->addFile($temporaryPath, $csvRelativePath);

                    $groupEntries[] = [
                        'table' => $table,
                        'rows' => $rowCount,
                        'file' => $csvRelativePath,
                    ];
                }

                if ($groupEntries !== []) {
                    $manifest['groups'][$group] = $groupEntries;
                }
            }

            $zip->addFromString(
                'manifest.json',
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        } finally {
            $zip->close();

            foreach ($temporaryFiles as $temporaryFile) {
                if (is_file($temporaryFile)) {
                    @unlink($temporaryFile);
                }
            }
        }

        $metadata = [
            'id' => $identifier,
            'filename' => basename($zipRelativePath),
            'mode' => $mode,
            'status' => $mode === 'refresh' ? 'backup_created' : 'completed',
            'created_at' => $timestamp->toIso8601String(),
            'created_by' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'branch' => $user->BC,
            ],
            'download_url' => '/v1/system/backups/'.basename($zipRelativePath).'/download',
            'size_bytes' => $disk->size($zipRelativePath),
            'size_label' => $this->formatBytes($disk->size($zipRelativePath)),
            'totals' => [
                'tables' => $tableTotal,
                'rows' => $rowTotal,
            ],
            'refresh_summary' => null,
            'preserves' => [
                'users',
                'roles',
                'master registries',
                'categories',
                'products',
                'customers',
                'suppliers',
                'units and pricing',
            ],
        ];

        $this->storeMetadata($metaRelativePath, $metadata);

        if ($mode === 'refresh') {
            try {
                $metadata['refresh_summary'] = $this->refreshOperationalData();
                $metadata['status'] = 'completed';
                $this->storeMetadata($metaRelativePath, $metadata);
            } catch (Throwable $exception) {
                $metadata['status'] = 'backup_created_refresh_failed';
                $metadata['refresh_error'] = $exception->getMessage();
                $this->storeMetadata($metaRelativePath, $metadata);

                throw new \RuntimeException(
                    'Backup ZIP was created successfully, but the refresh step failed. Download the backup before retrying.',
                    previous: $exception
                );
            }
        }

        return $metadata;
    }

    public function download(string $filename): BinaryFileResponse
    {
        $safeName = basename($filename);
        $relativePath = self::BACKUP_DIRECTORY.'/'.$safeName;
        $disk = Storage::disk('local');

        if (! $disk->exists($relativePath)) {
            throw new FileNotFoundException("Backup [{$safeName}] was not found.");
        }

        return response()->download(
            $disk->path($relativePath),
            $safeName,
            ['Content-Type' => 'application/zip']
        );
    }

    public function restore(User $user, string $filename): array
    {
        $safeName = basename($filename);
        if (! str_ends_with($safeName, '.zip')) {
            throw new FileNotFoundException('Select a valid grocery ERP backup ZIP.');
        }
        $disk = Storage::disk('local');
        $relativePath = self::BACKUP_DIRECTORY.'/'.$safeName;
        if (! $disk->exists($relativePath)) {
            throw new FileNotFoundException("Backup [{$safeName}] was not found.");
        }

        $safetyBackup = $this->createBackup($user, 'continue');
        $zip = new ZipArchive();
        if ($zip->open($disk->path($relativePath)) !== true) {
            throw new \RuntimeException('The selected backup archive could not be opened.');
        }

        try {
            $manifestRaw = $zip->getFromName('manifest.json');
            $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
            if (! is_array($manifest) || ($manifest['scope'] ?? null) !== 'full-grocery-erp-dataset') {
                throw new \RuntimeException('The archive is not a valid Grocery ERP backup.');
            }

            $allowedTables = collect(self::TABLE_GROUPS)->flatten()->all();
            $entries = collect($manifest['groups'] ?? [])->flatten(1)
                ->filter(fn ($entry) => is_array($entry) && in_array($entry['table'] ?? null, $allowedTables, true))
                ->values();
            if ($entries->isEmpty()) throw new \RuntimeException('The backup contains no restorable tables.');

            $restoredRows = DB::transaction(function () use ($zip, $entries) {
                $rows = 0;
                Schema::disableForeignKeyConstraints();
                try {
                    foreach ($entries->reverse() as $entry) {
                        if (Schema::hasTable($entry['table'])) DB::table($entry['table'])->delete();
                    }
                    foreach ($entries as $entry) {
                        $table = $entry['table']; $file = $entry['file'] ?? '';
                        if (! Schema::hasTable($table) || ! is_string($file)) continue;
                        $stream = $zip->getStream($file);
                        if ($stream === false) throw new \RuntimeException("Backup data for [{$table}] is missing.");
                        try {
                            $headers = fgetcsv($stream);
                            if (! is_array($headers)) continue;
                            $validColumns = array_flip(Schema::getColumnListing($table));
                            $batch = [];
                            while (($values = fgetcsv($stream)) !== false) {
                                $row = [];
                                foreach ($headers as $index => $column) {
                                    if (isset($validColumns[$column])) $row[$column] = ($values[$index] ?? '') === '' ? null : $values[$index];
                                }
                                $batch[] = $row; $rows++;
                                if (count($batch) >= 250) { DB::table($table)->insert($batch); $batch = []; }
                            }
                            if ($batch !== []) DB::table($table)->insert($batch);
                        } finally { fclose($stream); }
                    }
                } finally { Schema::enableForeignKeyConstraints(); }
                return $rows;
            });
        } finally {
            $zip->close();
        }

        return [
            'restored_from' => $safeName, 'restored_rows' => $restoredRows,
            'safety_backup' => $safetyBackup['filename'], 'restored_at' => now()->toIso8601String(),
        ];
    }

    private function refreshOperationalData(): array
    {
        return DB::transaction(function () {
            $details = [];
            $rowsCleared = 0;

            Schema::disableForeignKeyConstraints();

            try {
                foreach (self::REFRESH_TABLES as $table) {
                    if (! Schema::hasTable($table)) {
                        continue;
                    }

                    $count = DB::table($table)->count();
                    DB::table($table)->delete();

                    $details[] = [
                        'table' => $table,
                        'rows' => $count,
                    ];
                    $rowsCleared += $count;
                }
            } finally {
                Schema::enableForeignKeyConstraints();
            }

            if (Schema::hasTable('customers')) {
                DB::table('customers')->update([
                    'advance_balance' => 0,
                ]);
            }

            return [
                'tables_cleared' => count($details),
                'rows_cleared' => $rowsCleared,
                'details' => collect($details)
                    ->filter(fn (array $detail) => $detail['rows'] > 0)
                    ->values()
                    ->all(),
            ];
        });
    }

    private function writeTableCsv(string $table, array $columns, string $targetPath): void
    {
        $handle = fopen($targetPath, 'wb');

        if ($handle === false) {
            throw new \RuntimeException("Unable to write the CSV file for table [{$table}].");
        }

        try {
            fputcsv($handle, $columns);

            $orderColumn = $columns[0] ?? null;
            $query = DB::table($table);

            if ($orderColumn !== null) {
                $query->orderBy($orderColumn);
            }

            foreach ($query->cursor() as $row) {
                $payload = [];

                foreach ($columns as $column) {
                    $payload[] = $this->normalizeCsvValue($row->{$column} ?? null);
                }

                fputcsv($handle, $payload);
            }
        } finally {
            fclose($handle);
        }
    }

    private function normalizeCsvValue(mixed $value): string | int | float
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '';
        }

        return (string) $value;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $size = $bytes / 1024;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return number_format($size, 2).' '.$units[$unitIndex];
    }

    private function storeMetadata(string $path, array $metadata): void
    {
        Storage::disk('local')->put(
            $path,
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}
