<?php

namespace Tests\Feature;

use App\Enums\EvaluationPeriodNumber;
use App\Enums\PeriodStatus;
use App\Enums\UserRole;
use App\Enums\WorkRecordStatus;
use App\Models\EvaluationPeriod;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\WorkRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RecordListTableBorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_desktop_header_divider_spans_the_entire_table(): void
    {
        $user = User::factory()->create(['role' => UserRole::Staff]);
        $fiscalYear = FiscalYear::create(['year' => 2569, 'is_active' => true]);
        $period = EvaluationPeriod::create([
            'fiscal_year_id' => $fiscalYear->id,
            'period' => EvaluationPeriodNumber::One,
            'label' => 'Period 1',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'status' => PeriodStatus::Open,
        ]);

        WorkRecord::create([
            'user_id' => $user->id,
            'evaluation_period_id' => $period->id,
            'status' => WorkRecordStatus::Draft,
        ]);

        Livewire::actingAs($user)
            ->test('pages::staff.record-list')
            ->assertSeeHtml('<table class="w-full divide-y divide-zinc-200 dark:divide-zinc-700">')
            ->assertDontSeeHtml('<tr class="divide-y divide-zinc-200');
    }
}
