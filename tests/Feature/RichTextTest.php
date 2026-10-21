<?php

namespace Tests\Feature;

use App\Enums\EvaluationPeriodNumber;
use App\Enums\PeriodStatus;
use App\Enums\UserRole;
use App\Enums\WorkRecordStatus;
use App\Models\EvaluationPeriod;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\WorkEntry;
use App\Models\Workload;
use App\Models\WorkloadGroup;
use App\Models\WorkloadMain;
use App\Models\WorkRecord;
use App\Support\RichText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RichTextTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{user: User, period: EvaluationPeriod, workload: Workload}
     */
    private function createContext(): array
    {
        $user = User::factory()->create(['position_id' => $this->defaultPosition()->id, 'role' => UserRole::Staff]);

        $fiscalYear = FiscalYear::create(['year' => 2568, 'is_active' => true]);

        $period = EvaluationPeriod::create([
            'fiscal_year_id' => $fiscalYear->id,
            'period' => EvaluationPeriodNumber::One,
            'label' => 'รอบที่ 1',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'status' => PeriodStatus::Open,
        ]);

        $group = WorkloadGroup::create([
            'workload_structure_id' => $this->structureFor($fiscalYear->id)->id,
            'name' => 'กลุ่มทดสอบ',
            'sort_order' => 1,
        ]);

        $main = WorkloadMain::create([
            'workload_group_id' => $group->id,
            'name' => 'หมวดทดสอบ',
            'sort_order' => 1,
        ]);

        $workload = Workload::create([
            'workload_main_id' => $main->id,
            'name' => 'ภาระงานทดสอบ',
            'score' => 5,
            'sort_order' => 1,
        ]);

        return compact('user', 'period', 'workload');
    }

    public function test_sanitize_strips_unsafe_markup_and_keeps_allowed_formatting(): void
    {
        $clean = RichText::sanitize(
            '<p onclick="x()"><strong>ตัวหนา</strong> <em>เอียง</em> <u>ขีดเส้นใต้</u></p>'
            .'<script>alert(1)</script><img src=x onerror="alert(1)">'
            .'<ul><li>หนึ่ง</li></ul><ol><li>สอง</li></ol>'
            .'<p><a href="javascript:alert(1)">ร้าย</a> <a href="https://example.com">ดี</a></p>',
        );

        $this->assertStringContainsString('<strong>ตัวหนา</strong>', $clean);
        $this->assertStringContainsString('<em>เอียง</em>', $clean);
        $this->assertStringContainsString('<u>ขีดเส้นใต้</u>', $clean);
        $this->assertStringContainsString('<ul><li>หนึ่ง</li></ul>', $clean);
        $this->assertStringContainsString('<ol><li>สอง</li></ol>', $clean);
        $this->assertStringContainsString('href="https://example.com"', $clean);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('<img', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
    }

    public function test_sanitize_keeps_legacy_plain_text_unchanged(): void
    {
        $this->assertSame("บรรทัดแรก\nบรรทัดสอง", RichText::sanitize("บรรทัดแรก\nบรรทัดสอง"));
    }

    public function test_render_escapes_legacy_plain_text_and_keeps_line_breaks(): void
    {
        $this->assertSame(
            "a &lt; b<br>\nบรรทัดสอง",
            RichText::render("a < b\nบรรทัดสอง"),
        );
    }

    public function test_to_plain_text_strips_tags_and_entities(): void
    {
        $this->assertSame(
            "หนึ่ง & สอง\nสาม",
            RichText::toPlainText('<p><strong>หนึ่ง</strong> &amp; สอง</p><ul><li>สาม</li></ul>'),
        );
    }

    public function test_detail_min_length_counts_text_without_markup(): void
    {
        ['user' => $user, 'period' => $period, 'workload' => $workload] = $this->createContext();

        Livewire::actingAs($user)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->set('step', 2)
            ->call('openEntryModal', $workload->id)
            ->set('modalEntry.detail', '<p><strong>สั้น</strong></p>')
            ->call('saveModalEntry')
            ->assertHasErrors(['modalEntry.detail'])
            ->assertSee('รายละเอียดการดำเนินงาน ต้องมีความยาวอย่างน้อย 10 ตัวอักษร');
    }

    public function test_saved_detail_is_sanitized(): void
    {
        ['user' => $user, 'period' => $period, 'workload' => $workload] = $this->createContext();

        Livewire::actingAs($user)
            ->test('pages::staff.work-record-form')
            ->set('selectedPeriodId', $period->id)
            ->set('step', 2)
            ->call('openEntryModal', $workload->id)
            ->set('modalEntry.detail', '<p><strong>รายละเอียดผลงาน</strong>ทดสอบ</p><script>alert(1)</script>')
            ->call('saveModalEntry')
            ->assertHasNoErrors();

        $entry = WorkEntry::firstOrFail();

        $this->assertSame('<p><strong>รายละเอียดผลงาน</strong>ทดสอบ</p>', $entry->detail);
    }

    public function test_print_page_renders_rich_text_detail(): void
    {
        ['user' => $user, 'period' => $period, 'workload' => $workload] = $this->createContext();

        $record = WorkRecord::create([
            'user_id' => $user->id,
            'evaluation_period_id' => $period->id,
            'status' => WorkRecordStatus::Draft,
        ]);

        $record->workEntries()->create([
            'workload_id' => $workload->id,
            'detail' => '<p><strong>ผลงานเด่น</strong></p><ul><li>ข้อหนึ่ง</li></ul>',
            'hours' => 1,
            'sort_order' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('staff.records.print', $record))
            ->assertOk()
            ->assertSee('<strong>ผลงานเด่น</strong>', false)
            ->assertSee('<li>ข้อหนึ่ง</li>', false);
    }
}
