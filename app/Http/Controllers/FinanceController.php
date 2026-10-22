<?php

namespace App\Http\Controllers;

use App\Domain\Career\Models\Career;
use App\Domain\Finance\Models\CareerFinancialTransaction;

class FinanceController extends Controller
{
    public function show(Career $career)
    {
        $this->authorize('view', $career);
        $club = $career->controlledClub->club;
        $season = $career->seasons()->where('number', $career->current_season_number)->sole();
        $transactions = CareerFinancialTransaction::where('career_id', $career->id)->where('career_club_id', $club->id);
        $totals = (clone $transactions)->where('career_season_id', $season->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'ticket_revenue' THEN amount_cents ELSE 0 END), 0) as revenue")
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'match_operating_cost' THEN -amount_cents ELSE 0 END), 0) as operating_cost")
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'investment' THEN -amount_cents ELSE 0 END), 0) as investments")
            ->selectRaw("COUNT(CASE WHEN kind = 'ticket_revenue' THEN 1 END) as home_matches")
            ->first();
        $history = (clone $transactions)->with(['match.awayClub', 'match.result:id,match_id'])
            ->orderByDesc('occurred_on')->orderByDesc('id')->paginate(20);

        return view('careers.finances', compact('career', 'club', 'season', 'totals', 'history'));
    }
}
