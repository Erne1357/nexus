<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Queries\HealthReportQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * `GET /health` (readiness): fuera del grupo `web`, sin sesión, cookies ni throttle (dependen de Valkey y darían un 500
 * en lugar de un 503). Sin `X-Health-Token` correcto solo salen los estados.
 */
final class HealthEndpoint
{
    public function __invoke(Request $request, HealthReportQuery $query): JsonResponse
    {
        $token = (string) config('health.token');
        $provided = (string) $request->header('X-Health-Token');
        $withDetail = $token !== '' && $provided !== '' && hash_equals($token, $provided);

        try {
            $report = $query->run();

            return response()->json($report->toArray($withDetail), $report->httpStatus());
        } catch (Throwable $e) {
            return response()->json(['status' => 'fail', 'release' => (string) config('health.release')] + ($withDetail ? ['error' => $e->getMessage()] : []), 503);
        }
    }
}
