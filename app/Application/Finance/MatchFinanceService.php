<?php

namespace App\Application\Finance;

use App\Domain\Attendance\Models\MatchAttendance;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CareerClub;
use App\Domain\Career\Models\CareerMatch;
use App\Domain\Career\Models\CompetitionEdition;
use App\Domain\Finance\Models\CareerFinancialTransaction;
use Illuminate\Support\Facades\DB;
use LogicException;

class MatchFinanceService
{
    public function record(Career $career, CareerMatch $match, MatchAttendance $attendance): void
    {
        if ($match->career_id !== $career->id || $attendance->career_id !== $career->id
            || $attendance->match_id !== $match->id || $attendance->home_club_id !== $match->home_club_id) {
            throw new LogicException('Bilheteria incompatível com a partida.');
        }

        DB::transaction(function () use ($career, $match, $attendance): void {
            if (CareerFinancialTransaction::where('career_id', $career->id)->where('match_id', $match->id)->exists()) {
                throw new LogicException('A bilheteria desta partida já foi lançada.');
            }
            $club = CareerClub::where('career_id', $career->id)->whereKey($match->home_club_id)->lockForUpdate()->firstOrFail();
            $input = $attendance->snapshot['input'];
            if ($attendance->realized > $attendance->capacity || ($input['fixed_cost_cents'] ?? -1) < 0
                || ($input['variable_cost_cents'] ?? -1) < 0) {
                throw new LogicException('Previsão sem dados financeiros válidos.');
            }
            $edition = CompetitionEdition::where('career_id', $career->id)->whereKey($match->competition_edition_id)->firstOrFail();
            $revenue = $attendance->realized * $attendance->ticket_price_cents;
            $cost = $input['fixed_cost_cents'] + $attendance->realized * $input['variable_cost_cents'];
            $balance = $club->balance_cents;
            foreach (['ticket_revenue' => $revenue, 'match_operating_cost' => -$cost] as $kind => $amount) {
                $balance += $amount;
                CareerFinancialTransaction::create([
                    'career_id' => $career->id, 'career_club_id' => $club->id,
                    'career_season_id' => $edition->career_season_id, 'match_id' => $match->id,
                    'source_key' => 'match:'.$match->id.':'.$kind, 'kind' => $kind,
                    'occurred_on' => $attendance->played_on, 'amount_cents' => $amount,
                    'balance_after_cents' => $balance,
                ]);
            }
            $club->update(['balance_cents' => $balance]);
        }, 5);
    }
}
