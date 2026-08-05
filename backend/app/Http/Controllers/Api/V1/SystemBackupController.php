<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\SystemBackupService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SystemBackupController extends BaseController
{
    public function __construct(private readonly SystemBackupService $systemBackupService)
    {
    }

    public function index()
    {
        return $this->successResponse(
            $this->systemBackupService->listBackups(),
            'Backups retrieved successfully.'
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'mode' => ['required', Rule::in(['continue', 'refresh'])],
        ]);

        $backup = $this->systemBackupService->createBackup(
            $request->user(),
            $validated['mode']
        );

        return $this->successResponse(
            $backup,
            $validated['mode'] === 'refresh'
                ? 'Backup created and operational data refreshed successfully.'
                : 'Backup created successfully.',
            201
        );
    }

    public function download(string $backup)
    {
        return $this->systemBackupService->download($backup);
    }

    public function restore(Request $request, string $backup)
    {
        $request->validate(['confirmation' => ['required', Rule::in(['RESTORE'])]]);
        return $this->successResponse(
            $this->systemBackupService->restore($request->user(), $backup),
            'Backup restored successfully. A safety backup of the previous state was created first.'
        );
    }
}
