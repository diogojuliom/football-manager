<?php

namespace Tests\Unit\Finance;

use App\Application\Finance\TicketPricingService;
use App\Domain\Attendance\V1\DemandModel;
use Tests\TestCase;

class TicketPricingServiceTest extends TestCase
{
    private function plan(array $changes): array
    {
        $input = array_replace([
            'capacity' => 20000, 'potential_supporters' => 30000,
            'loyalty' => 70, 'engagement' => 60, 'infrastructure_level' => 3,
            'price_cents' => 3000, 'opponent_supporters' => 30000,
            'recent_form' => 0, 'round' => 1, 'round_count' => 18,
            'leader_points' => 0, 'home_points' => 0, 'away_points' => 0,
            'home_rank' => 5, 'away_rank' => 5, 'rivalry' => false,
            'fixed_cost_cents' => 1500000, 'variable_cost_cents' => 50,
        ], $changes);

        return (new DemandModel)->forecast($input, config('attendance.v1'));
    }

    public function test_ai_pricing_balances_capacity_revenue_and_occupancy_with_the_official_demand_model(): void
    {
        $tickets = app(TicketPricingService::class);
        $scarceSeats = $tickets->chooseForAi($this->plan(['capacity' => 500, 'potential_supporters' => 100000]));
        $weakDemand = $tickets->chooseForAi($this->plan(['capacity' => 30000, 'potential_supporters' => 2000]));

        $this->assertGreaterThan($weakDemand['input']['price_cents'], $scarceSeats['input']['price_cents']);
        $this->assertSame(500, $scarceSeats['forecast']);
        $this->assertSame('ai', $weakDemand['pricing']['actor']);
        $this->assertSame($weakDemand['input']['price_cents'], $weakDemand['pricing']['chosen_price_cents']);
        $this->assertSame($weakDemand['forecast'] * $weakDemand['input']['price_cents']
            - $weakDemand['input']['fixed_cost_cents'] - $weakDemand['forecast'] * $weakDemand['input']['variable_cost_cents'],
            $weakDemand['pricing']['estimated_net_cents']);
    }
}
