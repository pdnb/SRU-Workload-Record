<?php

namespace Tests\Unit;

use App\Services\Oracle\OracleWorkloadReader;
use Tests\TestCase;

/**
 * Covers how VNMIS rows are mapped. The SQL itself can only be verified against
 * a real Oracle; here rows() is replaced so no connection is needed.
 */
class OracleWorkloadReaderTest extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function readerReturning(array $rows): OracleWorkloadReader
    {
        return new class($rows) extends OracleWorkloadReader
        {
            /** @var list<array{sql: string, bindings: array}> */
            public array $queries = [];

            public function __construct(private array $fixture) {}

            protected function rows(string $sql, array $bindings = []): iterable
            {
                $this->queries[] = ['sql' => $sql, 'bindings' => $bindings];

                return array_map(fn ($row) => array_change_key_case($row, CASE_LOWER), $this->fixture);
            }
        };
    }

    public function test_positions_use_code_and_group_level_name(): void
    {
        $reader = $this->readerReturning([
            ['ID' => 12, 'EVPOSITIONCODE' => '0901', 'EVPOSITIONNAME' => 'นักวิชาการคอมพิวเตอร์-ปฏิบัติการ', 'ISACTIVE_ID' => 1],
            ['ID' => 13, 'EVPOSITIONCODE' => '0902', 'EVPOSITIONNAME' => 'ตำแหน่งเลิกใช้', 'ISACTIVE_ID' => 0],
        ]);

        $rows = iterator_to_array($reader->positions(), false);

        $this->assertSame('12', $rows[0]->externalId);
        $this->assertSame('0901', $rows[0]->code);
        $this->assertSame('นักวิชาการคอมพิวเตอร์-ปฏิบัติการ', $rows[0]->name);
        $this->assertTrue($rows[0]->active);
        $this->assertFalse($rows[1]->active);
        $this->assertStringContainsString('VNMIS.VW_HR_EVPOSITION', $reader->queries[0]['sql']);
    }

    public function test_structure_items_map_scores_and_unlimited_max(): void
    {
        $reader = $this->readerReturning([[
            'BUDGETYEAR' => 2569, 'EVPOSITIONCODE' => '0901',
            'GROUP_ID' => 100, 'GROUP_NAME' => ' งานหลัก ', 'GROUP_MAXSCORE' => 0, 'GROUP_ORDER' => 1,
            'MAIN_ID' => 200, 'MAIN_NAME' => 'หมวดหลัก', 'MAIN_ORDER' => 2,
            'WORKLOAD_ID' => 300, 'WORKLOAD_NAME' => 'งานย่อย', 'WORKLOAD_ORDER' => 3, 'WORKLOAD_SCORE' => '1.50',
        ]]);

        $item = iterator_to_array($reader->structureItems(2569), false)[0];

        $this->assertSame(2569, $item->fiscalYear);
        $this->assertSame('0901', $item->positionCode);
        $this->assertSame('100', $item->groupId);
        $this->assertSame('งานหลัก', $item->groupName);
        $this->assertNull($item->groupMaxScore, 'MAXSCORE 0 means no limit');
        $this->assertSame('200', $item->mainId);
        $this->assertNull($item->mainMaxScore);
        $this->assertSame(1.5, $item->workloadScore);
        $this->assertSame([2569], $reader->queries[0]['bindings']);
        $this->assertStringContainsString('MAX(X.ROUNDNO)', $reader->queries[0]['sql']);
    }

    public function test_workload_without_main_gets_one_main_per_group(): void
    {
        $reader = $this->readerReturning([[
            'BUDGETYEAR' => 2569, 'EVPOSITIONCODE' => '0901',
            'GROUP_ID' => 100, 'GROUP_NAME' => 'กลุ่ม', 'GROUP_MAXSCORE' => 40, 'GROUP_ORDER' => 1,
            'MAIN_ID' => null, 'MAIN_NAME' => null, 'MAIN_ORDER' => null,
            'WORKLOAD_ID' => 300, 'WORKLOAD_NAME' => 'งาน', 'WORKLOAD_ORDER' => 1, 'WORKLOAD_SCORE' => 2,
        ]]);

        $item = iterator_to_array($reader->structureItems(), false)[0];

        $this->assertSame('group-100', $item->mainId);
        $this->assertSame('กลุ่ม', $item->mainName);
        $this->assertSame(40.0, $item->groupMaxScore);
        $this->assertSame([], $reader->queries[0]['bindings']);
    }

    public function test_staff_lookup_lowercases_email_and_keeps_alt_email(): void
    {
        $reader = $this->readerReturning([[
            'STAFF_ID' => 2519, 'STAFFCODE' => 'S001', 'EMAIL' => 'Somchai@Gmail.com',
            'ALT_EMAIL' => 'Somchai.S@SRU.ac.th', 'EVPOSITIONCODE' => '0901', 'BUDGETYEAR' => 2569,
        ]]);

        $row = $reader->staffPositionFor(null, 'Somchai.S@SRU.ac.th');

        $this->assertSame('2519', $row->employeeId);
        $this->assertSame('somchai@gmail.com', $row->email);
        $this->assertSame('somchai.s@sru.ac.th', $row->altEmail);
        $this->assertSame(['', 'somchai.s@sru.ac.th', 'somchai.s@sru.ac.th'], $reader->queries[0]['bindings']);
    }

    public function test_invalid_schema_name_is_rejected(): void
    {
        config(['oracle_workload.schema' => 'VNMIS; DROP TABLE X']);

        $this->expectExceptionMessage('Invalid Oracle schema name');

        iterator_to_array($this->readerReturning([])->positions());
    }
}
