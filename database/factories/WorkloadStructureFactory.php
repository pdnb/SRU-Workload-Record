<?php

namespace Database\Factories;

use App\Models\FiscalYear;
use App\Models\Position;
use App\Models\WorkloadStructure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkloadStructure>
 */
class WorkloadStructureFactory extends Factory
{
    public function definition(): array
    {
        return [
            'fiscal_year_id' => fn () => FiscalYear::firstOrCreate(['year' => 2569], ['is_active' => true])->id,
            'position_id' => Position::factory(),
        ];
    }

    public function forFiscalYear(FiscalYear|int $fiscalYear): static
    {
        return $this->state(['fiscal_year_id' => $fiscalYear instanceof FiscalYear ? $fiscalYear->id : $fiscalYear]);
    }
}
