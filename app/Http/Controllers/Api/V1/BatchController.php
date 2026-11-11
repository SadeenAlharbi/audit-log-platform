<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\IngestionBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BatchController extends Controller
{
    /**
     * GET /api/v1/batches/{uid} - outcome of an asynchronous batch.
     */
    public function show(Request $request, string $uid): JsonResponse
    {
        $batch = IngestionBatch::query()
            ->where('project_id', $request->attributes->get('project')->id)
            ->where('uid', $uid)
            ->firstOrFail();

        return new JsonResponse(['data' => [
            'batch_uid' => $batch->uid,
            'status' => $batch->status,
            'total' => $batch->count,
            'accepted' => $batch->accepted_count,
            'duplicates' => $batch->duplicate_count,
            'rejected' => $batch->rejected_count,
            'errors' => $batch->errors ?? [],
            'received_at' => $batch->created_at?->toIso8601String(),
            'processed_at' => $batch->processed_at?->toIso8601String(),
        ]]);
    }
}
