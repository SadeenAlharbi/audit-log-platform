<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreLogBatchRequest;
use App\Http\Requests\Api\V1\StoreLogRequest;
use App\Http\Responses\ApiError;
use App\Models\ProjectApiKey;
use App\Services\Ingestion\IngestionService;
use App\Services\Ingestion\IngestResult;
use App\Services\Ingestion\LogPayloadRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogIngestController extends Controller
{
    public function __construct(private IngestionService $ingestion) {}

    /**
     * POST /api/v1/logs - one log. 202 when accepted, 409 when the
     * external_id was already received.
     */
    public function store(StoreLogRequest $request): JsonResponse
    {
        $result = $this->ingestion->ingestOne($this->key($request), $request->validated());

        if ($result->isDuplicate()) {
            return ApiError::make('duplicate', 'A log with this external_id was already received.', 409, [
                'uid' => $result->uid,
                'external_id' => $result->externalId,
            ]);
        }

        return new JsonResponse(['data' => ['uid' => $result->uid, 'status' => 'accepted']], 202);
    }

    /**
     * POST /api/v1/logs/batch - up to 500 logs. Valid items are accepted
     * even when others are rejected; the response lists each item.
     */
    public function batch(StoreLogBatchRequest $request): JsonResponse
    {
        $items = $request->validated('logs');
        $valid = [];
        $rejected = [];

        foreach ($items as $index => $item) {
            $validator = LogPayloadRules::validator($item);
            if ($validator->fails()) {
                $rejected[$index] = $validator->errors()->toArray();
            } else {
                $valid[$index] = $validator->validated();
            }
        }

        if ($valid === []) {
            return ApiError::make('validation', 'No log in the batch is valid.', 422, [
                'items' => array_map(fn (int $i, array $e) => ['index' => $i, 'errors' => $e], array_keys($rejected), $rejected),
            ]);
        }

        $outcome = $this->ingestion->ingestBatch($this->key($request), $valid, $rejected, count($items));

        $results = [];
        foreach (array_keys($items) as $index) {
            if (isset($rejected[$index])) {
                $results[] = ['index' => $index, 'status' => 'rejected', 'errors' => $rejected[$index]];
            } else {
                /** @var IngestResult $r */
                $r = $outcome->results[$index];
                $results[] = array_filter([
                    'index' => $index,
                    'status' => $r->status,
                    'uid' => $r->uid,
                    'external_id' => $r->externalId,
                ], fn ($v) => $v !== null);
            }
        }

        return new JsonResponse(['data' => [
            'batch_uid' => $outcome->batch->uid,
            'status' => $outcome->batch->status,
            'total' => count($items),
            'accepted' => $outcome->acceptedCount(),
            'duplicates' => count($outcome->results) - $outcome->acceptedCount(),
            'rejected' => count($rejected),
            'items' => $results,
        ]], 202);
    }

    private function key(Request $request): ProjectApiKey
    {
        return $request->attributes->get('api_key');
    }
}
