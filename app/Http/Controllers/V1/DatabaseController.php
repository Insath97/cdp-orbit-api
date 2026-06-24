<?php

namespace App\Http\Controllers\V1;

use App\Traits\ActivityLogTrait;
use App\Http\Controllers\Controller;
use App\Services\DatabaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DatabaseController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    protected $databaseService;

    public function __construct(DatabaseService $databaseService)
    {
        $this->databaseService = $databaseService;
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Database Export', only: ['export']),
        ];
    }

    /**
     * Export the database and download the file (Protected).
     */
    public function export(): BinaryFileResponse|JsonResponse
    {
        return $this->handleExport();
    }

    /**
     * Public Export for testing purposes.
     */
    public function publicExport(): BinaryFileResponse|JsonResponse
    {
        return $this->handleExport();
    }

    /**
     * Handle the common export logic.
     */
    protected function handleExport(): BinaryFileResponse|JsonResponse
    {
        try {
            $filePath = $this->databaseService->export();
            $filename = basename($filePath);

            return response()->download($filePath, $filename, [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ])->deleteFileAfterSend(true);

        } catch (\Throwable $th) {
            $this->logActivity('Error', 'Database', "Database export failure: " . $th->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to export database.',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
