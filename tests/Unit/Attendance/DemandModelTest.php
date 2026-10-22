<?php

namespace Tests\Unit\Attendance;

use App\Domain\Attendance\V1\DemandModel;
use PHPUnit\Framework\TestCase;

class DemandModelTest extends TestCase
{
    private function input(array $changes = []): array
    {
        return array_replace(['capacity' => 20000, 'potential_supporters' => 30000,
            'loyalty' => 70, 'engagement' => 60, 'infrastructure_level' => 3,
            'price_cents' => 3000, 'opponent_supporters' => 30000, 'recent_form' => 0,
            'round' => 1, 'round_count' => 18, 'leader_points' => 0,
            'home_points' => 0, 'away_points' => 0, 'home_rank' => 5,
            'away_rank' => 5, 'rivalry' => false], $changes);
    }

    private function rules(): array
    {
        return ['version' => DemandModel::VERSION, 'reference_price_cents' => 3000,
            'price_elasticity' => 1.6, 'minimum_price_factor' => 0.001,
            'maximum_price_factor' => 1.6, 'realized_variation_percent' => 7];
    }

    public function test_capacity_clamps_attendance_but_does_not_change_underlying_demand(): void
    {
        $model = new DemandModel;
        $large = $model->forecast($this->input(), $this->rules());
        $small = $model->forecast($this->input(['capacity' => 3000]), $this->rules());
        $this->assertSame($large['expected_demand'], $small['expected_demand']);
        $this->assertSame(3000, $small['forecast']);
        foreach (['same-seed', 'same-seed', 'different-seed'] as $seed) {
            $realized = $model->realize($small, $seed);
            $this->assertGreaterThanOrEqual(0, $realized);
            $this->assertLessThanOrEqual(3000, $realized);
        }
        $this->assertSame($model->realize($large, 'same-seed'), $model->realize($large, 'same-seed'));
        for ($seed = 0; $seed < 30; $seed++) {
            $realized = $model->realize($large, (string) $seed);
            $this->assertLessThanOrEqual((int) ceil($large['expected_demand'] * 0.07) + 1,
                abs($realized - $large['expected_demand']));
        }
    }

    public function test_form_engagement_loyalty_rivalry_and_title_race_affect_demand_gradually(): void
    {
        $model = new DemandModel;
        $rules = $this->rules();
        $baseline = $model->forecast($this->input(), $rules);
        $this->assertGreaterThan($baseline['expected_demand'], $model->forecast($this->input(['recent_form' => 5]), $rules)['expected_demand']);
        $this->assertGreaterThan($baseline['expected_demand'], $model->forecast($this->input(['engagement' => 90]), $rules)['expected_demand']);
        $this->assertGreaterThan($baseline['expected_demand'], $model->forecast($this->input(['loyalty' => 90]), $rules)['expected_demand']);
        $derby = $model->forecast($this->input(['rivalry' => true]), $rules);
        $this->assertGreaterThan($baseline['expected_demand'], $derby['expected_demand']);
        $this->assertGreaterThan($baseline['importance'], $derby['importance']);
        $late = $model->forecast($this->input(['round' => 18, 'leader_points' => 40, 'home_points' => 40,
            'away_points' => 40, 'home_rank' => 1, 'away_rank' => 2]), $rules);
        $this->assertGreaterThan($baseline['importance'], $late['importance']);
        $this->assertGreaterThan(0, $model->forecast($this->input(['engagement' => 0, 'recent_form' => -5]), $rules)['expected_demand']);
    }

    public function test_extreme_price_can_lower_both_attendance_and_gross_gate_value(): void
    {
        $model = new DemandModel;
        $normal = $model->forecast($this->input(), $this->rules());
        $expensive = $model->forecast($this->input(['price_cents' => 100000]), $this->rules());
        $this->assertLessThan($normal['expected_demand'], $expensive['expected_demand']);
        $this->assertLessThan($normal['forecast'] * 3000, $expensive['forecast'] * 100000);
    }
}
