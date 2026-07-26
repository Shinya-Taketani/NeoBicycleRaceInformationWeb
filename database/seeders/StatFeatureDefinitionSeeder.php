<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Keirin\Statistics\Calculators\Stat01Calculator;
use App\Models\StatFeatureDefinition;
use Illuminate\Database\Seeder;

class StatFeatureDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Stat01Calculator::featureDefinitions() as $definition) {
            StatFeatureDefinition::query()->updateOrCreate(
                [
                    'stat_code' => Stat01Calculator::STAT_CODE,
                    'feature_code' => $definition['feature_code'],
                    'definition_version' => Stat01Calculator::CALCULATION_VERSION,
                ],
                [
                    'feature_name' => $definition['feature_name'],
                    'value_type' => $definition['value_type'],
                    'unit_code' => $definition['unit_code'],
                    'description' => $definition['description'],
                    'is_active' => true,
                ],
            );
        }
    }
}
