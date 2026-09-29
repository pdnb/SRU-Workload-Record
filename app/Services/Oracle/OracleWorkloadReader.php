<?php

namespace App\Services\Oracle;

use App\Contracts\WorkloadSourceReader;
use App\Data\Oracle\PositionRow;
use App\Data\Oracle\StaffPositionRow;
use App\Data\Oracle\StructureItemRow;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Reads the VNMIS evaluation schema (database/VNMIS.sql). The only class that
 * talks to Oracle; it only ever runs SELECTs.
 *
 * Source tables:
 *   HR_EVLOTAPP (round: BUDGETYEAR, ROUNDNO) -> HR_EVLOTWL (criteria set)
 *   -> HR_EVLOTWLPOS (position in set) -> HR_EVLOTWLPOSGRP (group)
 *   -> HR_EVLOTWLPOSGRPWL (workload), optionally under HR_EVLOTWLPOSGRPMAIN (main)
 *
 * The app keeps one structure per fiscal year, so each year uses the criteria
 * set of its latest round (highest ROUNDNO).
 */
class OracleWorkloadReader implements WorkloadSourceReader
{
    public function positions(): iterable
    {
        $s = $this->schema();

        $sql = "SELECT ID, EVPOSITIONCODE, EVPOSITIONNAME, ISACTIVE_ID
                FROM {$s}.VW_HR_EVPOSITION
                ORDER BY EVPOSITIONCODE";

        foreach ($this->rows($sql) as $row) {
            if ($mapped = $this->mapPosition($row)) {
                yield $mapped;
            }
        }
    }

    public function structureItems(?int $fiscalYear = null): iterable
    {
        $s = $this->schema();
        $yearFilter = $fiscalYear !== null ? 'AND LA.BUDGETYEAR = ?' : '';

        // Workload score mirrors VNMIS DISPLAY_EACHSCORE: the item's own score,
        // or its main's score when the item has none.
        $sql = "SELECT LA.BUDGETYEAR, P.EVPOSITIONCODE,
                       G.ID AS GROUP_ID, G.WORKGROUPNAME AS GROUP_NAME, G.MAXSCORE AS GROUP_MAXSCORE, G.ORDERNO AS GROUP_ORDER,
                       M.ID AS MAIN_ID, M.MAINNAME AS MAIN_NAME, M.ORDERNO AS MAIN_ORDER,
                       W.ID AS WORKLOAD_ID, W.WORKNAME AS WORKLOAD_NAME, W.ORDERNO AS WORKLOAD_ORDER,
                       CASE WHEN W.EACHSCORE = 0 THEN NVL(M.EACHSCORE, 0) ELSE W.EACHSCORE END AS WORKLOAD_SCORE
                FROM {$s}.HR_EVLOTAPP LA
                    INNER JOIN {$s}.HR_EVLOTWLPOS LP ON LP.HR_EVLOTWL_ID = LA.HR_EVLOTWL_ID
                    INNER JOIN {$s}.HR_EVPOSITION P ON P.ID = LP.HR_EVPOSITION_ID
                    INNER JOIN {$s}.HR_EVLOTWLPOSGRP G ON G.HR_EVLOTWLPOS_ID = LP.ID
                    INNER JOIN {$s}.HR_EVLOTWLPOSGRPWL W ON W.HR_EVLOTWLPOSGRP_ID = G.ID
                    LEFT JOIN {$s}.HR_EVLOTWLPOSGRPMAIN M ON M.ID = W.HR_EVLOTWLPOSGRPMAIN_ID AND M.HR_EVLOTWLPOSGRP_ID = G.ID
                WHERE LA.ROUNDNO = (SELECT MAX(X.ROUNDNO) FROM {$s}.HR_EVLOTAPP X WHERE X.BUDGETYEAR = LA.BUDGETYEAR)
                    {$yearFilter}
                ORDER BY LA.BUDGETYEAR, P.EVPOSITIONCODE, G.ORDERNO, M.ORDERNO NULLS FIRST, W.ORDERNO";

        foreach ($this->rows($sql, $fiscalYear !== null ? [$fiscalYear] : []) as $row) {
            if ($mapped = $this->mapStructureItem($row)) {
                yield $mapped;
            }
        }
    }

    public function staffPositions(): iterable
    {
        foreach ($this->rows($this->staffPositionSql()) as $row) {
            if ($mapped = $this->mapStaffPosition($row)) {
                yield $mapped;
            }
        }
    }

    public function staffPositionFor(?string $employeeId, ?string $email): ?StaffPositionRow
    {
        if (blank($employeeId) && blank($email)) {
            return null;
        }

        $email = filled($email) ? mb_strtolower($email) : null;
        $sql = $this->staffPositionSql('AND (TO_CHAR(STAFF_ID) = ? OR LOWER(EMAIL) = ? OR LOWER(ALT_EMAIL) = ?)');

        foreach ($this->rows($sql, [(string) $employeeId, (string) $email, (string) $email]) as $row) {
            return $this->mapStaffPosition($row);
        }

        return null;
    }

    /**
     * Connection check for `oracle:ping`.
     *
     * @return array{ok: bool, version: string|null}
     */
    public function ping(): array
    {
        $row = $this->connection()->selectOne('SELECT banner FROM v$version WHERE ROWNUM = 1');
        $row = $row ? array_change_key_case((array) $row, CASE_LOWER) : [];

        return ['ok' => true, 'version' => $row['banner'] ?? null];
    }

    /**
     * One row per active staff member: the evaluation position of their latest
     * appraisal (HR_EVAPP), or — before any appraisal exists — the one mapped from
     * their HR position (HR_POSITIONEV).
     */
    protected function staffPositionSql(string $extraWhere = ''): string
    {
        $s = $this->schema();

        return "SELECT STAFF_ID, STAFFCODE, EMAIL, ALT_EMAIL, EVPOSITIONCODE, BUDGETYEAR
                FROM (
                    SELECT S.ID AS STAFF_ID, S.STAFFCODE, S.EMAIL, H.EVAPPEMAILACCOUNT AS ALT_EMAIL,
                           X.EVPOSITIONCODE, X.BUDGETYEAR,
                           ROW_NUMBER() OVER (PARTITION BY S.ID ORDER BY X.SEQ DESC) AS RN
                    FROM {$s}.VW_HR_STAFF S
                        LEFT JOIN {$s}.HR_STAFF H ON H.HR_STAFF_ID = S.ID
                        INNER JOIN (
                            SELECT A.HR_STAFF_ID, P.EVPOSITIONCODE, LA.BUDGETYEAR, LA.BUDGETYEAR * 10 + LA.ROUNDNO AS SEQ
                            FROM {$s}.HR_EVAPP A
                                INNER JOIN {$s}.HR_EVLOTAPP LA ON LA.ID = A.HR_EVLOTAPP_ID
                                INNER JOIN {$s}.HR_EVPOSITION P ON P.ID = A.HR_EVPOSITION_ID
                            UNION ALL
                            SELECT S2.ID, P.EVPOSITIONCODE, NULL, 0
                            FROM {$s}.VW_HR_STAFF S2
                                INNER JOIN {$s}.HR_POSITIONEV PE ON PE.HR_POSITION_ID = S2.HR_POSITION_ID
                                INNER JOIN {$s}.HR_EVPOSITION P ON P.ID = PE.HR_EVPOSITION_ID
                        ) X ON X.HR_STAFF_ID = S.ID
                    WHERE S.ISACTIVE_ID = 1
                )
                WHERE RN = 1 {$extraWhere}";
    }

    public function mapPosition(array $row): ?PositionRow
    {
        $code = $this->string($row, 'evpositioncode');

        if ($code === null) {
            return null;
        }

        return new PositionRow(
            externalId: $this->string($row, 'id') ?? $code,
            code: $code,
            name: $this->string($row, 'evpositionname') ?? $code,
            active: (int) ($row['isactive_id'] ?? 1) === 1,
        );
    }

    public function mapStructureItem(array $row): ?StructureItemRow
    {
        $year = $this->int($row, 'budgetyear');
        $positionCode = $this->string($row, 'evpositioncode');
        $groupId = $this->string($row, 'group_id');
        $workloadId = $this->string($row, 'workload_id');

        if ($year === null || $positionCode === null || $groupId === null || $workloadId === null) {
            return null;
        }

        $groupName = $this->string($row, 'group_name') ?? $groupId;
        $mainId = $this->string($row, 'main_id');

        return new StructureItemRow(
            fiscalYear: $year,
            positionCode: $positionCode,
            groupId: $groupId,
            groupName: $groupName,
            groupMaxScore: $this->maxScore($row, 'group_maxscore'),
            groupOrder: $this->int($row, 'group_order'),
            // Workloads may sit directly under a group; the app needs a main, so use one per group.
            mainId: $mainId ?? "group-{$groupId}",
            mainName: $mainId !== null ? ($this->string($row, 'main_name') ?? $mainId) : $groupName,
            // VNMIS stores a main MAXSCORE but does not cap by it when scoring.
            mainMaxScore: null,
            mainOrder: $mainId !== null ? $this->int($row, 'main_order') : 0,
            workloadId: $workloadId,
            workloadName: $this->string($row, 'workload_name') ?? $workloadId,
            workloadScore: $this->float($row, 'workload_score'),
            workloadOrder: $this->int($row, 'workload_order'),
        );
    }

    public function mapStaffPosition(array $row): ?StaffPositionRow
    {
        $positionCode = $this->string($row, 'evpositioncode');

        if ($positionCode === null) {
            return null;
        }

        $email = $this->string($row, 'email');
        $altEmail = $this->string($row, 'alt_email');

        return new StaffPositionRow(
            employeeId: $this->string($row, 'staff_id'),
            email: $email !== null ? mb_strtolower($email) : null,
            positionCode: $positionCode,
            fiscalYear: $this->int($row, 'budgetyear'),
            altEmail: $altEmail !== null ? mb_strtolower($altEmail) : null,
        );
    }

    /**
     * @return iterable<array<string, mixed>> rows with lower-case keys
     */
    protected function rows(string $sql, array $bindings = []): iterable
    {
        foreach ($this->connection()->cursor($sql, $bindings) as $row) {
            yield array_change_key_case((array) $row, CASE_LOWER);
        }
    }

    protected function connection(): ConnectionInterface
    {
        return DB::connection(config('oracle_workload.connection'));
    }

    protected function schema(): string
    {
        $schema = (string) config('oracle_workload.schema');

        if (! preg_match('/^[A-Za-z][A-Za-z0-9_$#]*$/', $schema)) {
            throw new \RuntimeException("Invalid Oracle schema name [{$schema}].");
        }

        return strtoupper($schema);
    }

    protected function string(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        // Thai text can arrive as TIS-620 when the client NLS_LANG is not UTF-8.
        if (! mb_check_encoding($value, 'UTF-8')) {
            $converted = @iconv('TIS-620', 'UTF-8//IGNORE', $value);
            $value = $converted !== false ? $converted : mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $value === '' ? null : $value;
    }

    protected function int(array $row, string $key): ?int
    {
        $value = $this->string($row, $key);

        return $value !== null && is_numeric($value) ? (int) $value : null;
    }

    protected function float(array $row, string $key): ?float
    {
        $value = $this->string($row, $key);

        return $value !== null && is_numeric($value) ? (float) $value : null;
    }

    /**
     * VNMIS uses MAXSCORE = 0 for "no limit"; the app uses null.
     */
    protected function maxScore(array $row, string $key): ?float
    {
        $value = $this->float($row, $key);

        return $value !== null && $value > 0 ? $value : null;
    }
}
