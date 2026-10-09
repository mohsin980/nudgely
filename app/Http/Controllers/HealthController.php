<?php

namespace App\Http\Controllers;

use App\Support\Logging\SensitiveDataRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Readiness: can this instance serve requests that need the database? Liveness is Laravel's /up route.
 *
 * Deliberately minimal: a public caller learns only "ok" or "unavailable". The check is one query with a short
 * statement timeout. It does not report queue, scheduler or provider state, because nothing here can observe
 * those reliably (see docs/logging-and-monitoring.md).
 */
class HealthController extends Controller
{
    private const TIMEOUT = '2s';

    public function ready(): JsonResponse
    {
        $ready = $this->databaseReachable();

        return response()->json(['status' => $ready ? 'ok' : 'unavailable'], $ready ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    private function databaseReachable(): bool
    {
        try {
            return DB::transaction(function () {
                // Bounded: a database that hangs must fail the check, not hold the health probe open.
                DB::statement("set local statement_timeout = '".self::TIMEOUT."'");

                return (int) DB::selectOne('select 1 as ok')->ok === 1;
            });
        } catch (Throwable $exception) {
            Log::warning('Readiness check failed: database.', [
                'event' => 'health.database_unavailable',
                'exception' => SensitiveDataRedactor::describe($exception),
            ]);

            return false;
        }
    }
}
