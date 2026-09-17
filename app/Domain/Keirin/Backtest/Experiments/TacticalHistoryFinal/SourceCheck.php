<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal;

use App\Domain\Keirin\Backtest\Contracts\Bt02FingerprintRunner;
use App\Domain\Keirin\Backtest\Enums\Bt02FingerprintType;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\SourceIntegrity;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\SourceVerifier;
use App\Domain\Keirin\Backtest\Repositories\PgCopyFingerprintRunner;
use App\Domain\Keirin\Backtest\Services\Bt03e02ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Services\Bt03e02SourcePreflightService;
use App\Domain\Keirin\Backtest\Support\Bt02FingerprintCopySql;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SourceCheck
{
    public function verify(string $source, string $report): array
    {
        $db = DB::connection();
        $audit = app(Bt03e02ReadOnlyQueryAudit::class);
        $record = ['status' => 'FAILED', 'started_at' => gmdate('c')];
        $runner = app(Bt02FingerprintRunner::class);
        $ownsTransaction = false;
        $ownsAudit = false;
        try {
            if ($db->getDriverName() !== 'pgsql' || $db->transactionLevel() !== 0 || $audit->active()) {
                throw new RuntimeException('Source verification requires an idle read-only PostgreSQL connection.');
            }
            $setting = $db->selectOne("SELECT current_setting('default_transaction_read_only') AS session_read_only, current_setting('transaction_read_only') AS transaction_read_only");
            if ($setting->session_read_only !== 'on' || $setting->transaction_read_only !== 'on') {
                throw new RuntimeException('Read-only connection must be configured before source verification.');
            }
            $record['read_only'] = (array) $setting;
            $db->beginTransaction();
            $ownsTransaction = true;
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $snapshot = $db->selectOne('SELECT pg_export_snapshot() AS id')->id;
            if (preg_match('/\A[0-9A-F]+-[0-9A-F]+-[0-9]+\z/', $snapshot) !== 1) {
                throw new RuntimeException('Invalid exported read-only snapshot.');
            }
            $record['source_snapshot'] = $snapshot;
            $copy = new class($snapshot) extends Bt02FingerprintCopySql
            {
                public function __construct(private readonly string $snapshot) {}

                public function for(int $runId, Bt02FingerprintType $type): string
                {
                    return "BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY;\nSET TRANSACTION SNAPSHOT '".$this->snapshot."';\n".parent::for($runId, $type)."\nROLLBACK;\n";
                }
            };
            app()->instance(Bt02FingerprintRunner::class, new PgCopyFingerprintRunner(copySql: $copy));
            $audit->start();
            $ownsAudit = true;
            $expected = Files::json($source.'/input-run-result.json')['source_start'];
            $record = array_replace($record, (new SourceIntegrity)->verify($expected,
                fn () => app(Bt03e02SourcePreflightService::class)->run(),
                fn () => app(SourceVerifier::class)->verify($source.'/inputs-v2')));
        } catch (Throwable $e) {
            $record['error_class'] = $e::class;
            $record['error'] = $e instanceof \PDOException || $e instanceof QueryException
                ? 'Read-only SQL verification failed; SQLSTATE '.$e->getCode() : $e->getMessage();
            throw new RuntimeException($record['error']);
        } finally {
            if ($ownsTransaction && $db->transactionLevel() > 0) {
                $db->rollBack();
            }
            if ($ownsAudit && $audit->active()) {
                $record['query_audit'] = $audit->finish();
            }
            app()->instance(Bt02FingerprintRunner::class, $runner);
            $record['finished_at'] = gmdate('c');
            JsonlArtifact::json($report, $record);
        }

        return $record;
    }
}
