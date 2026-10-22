<?php

namespace App\Http\Controllers;

use App\Application\Attendance\MatchAttendanceService;
use App\Application\Finance\TicketPricingService;
use App\Domain\Career\CareerState;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CareerMatch;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function index(Request $request, Career $career, MatchAttendanceService $attendance)
    {
        $this->authorize('view', $career);
        $club = $career->controlledClub->club;
        $homeMatches = $career->matches()->where('home_club_id', $club->id)->where('status', 'scheduled')
            ->where('scheduled_on', '>=', $career->current_date->toDateString())
            ->orderBy('scheduled_on')->orderBy('id')->with(['awayClub', 'ticketPolicy'])->get();
        $selected = $request->query('match_id');
        abort_if($selected !== null && (! is_string($selected) || ! ctype_digit($selected)), 404);
        $match = $selected === null ? $homeMatches->first() : $homeMatches->first(fn ($game) => $game->id === (int) $selected);
        abort_if($selected !== null && ! $match, 404);
        $forecast = $match ? $attendance->forecast($career, $club, $match->awayClub, $match) : null;

        return view('careers.tickets', compact('career', 'club', 'match', 'forecast', 'homeMatches'));
    }

    public function preview(Request $request, Career $career, CareerMatch $match,
        MatchAttendanceService $attendance, TicketPricingService $tickets)
    {
        $this->authorize('view', $career);
        $tickets->assertHomeMatch($career, $match);
        $price = $tickets->parsePrice($request->query('price'));
        $plan = $tickets->reprice($attendance->forecast($career, $match->homeClub, $match->awayClub, $match), $price);
        $gross = $plan['forecast'] * $price;
        $cost = $plan['input']['fixed_cost_cents'] + $plan['forecast'] * $plan['input']['variable_cost_cents'];

        return response()->json(['price_cents' => $price, 'forecast' => $plan['forecast'],
            'expected_demand' => $plan['expected_demand'], 'gross_revenue_cents' => $gross,
            'operating_cost_cents' => $cost, 'estimated_net_cents' => $gross - $cost,
            'projected_balance_cents' => $match->homeClub->balance_cents + $gross - $cost]);
    }

    public function updateDefault(Request $request, Career $career, TicketPricingService $tickets)
    {
        $this->authorize('view', $career);
        $tickets->setDefault($request->user(), $career, $tickets->parsePrice($request->input('price')));

        return redirect()->route('careers.tickets.index', $career)->with('status', 'Preço padrão atualizado.');
    }

    public function updateMatch(Request $request, Career $career, CareerMatch $match, TicketPricingService $tickets)
    {
        $this->authorize('view', $career);
        $tickets->setMatch($request->user(), $career, $match, $tickets->parsePrice($request->input('price')));

        if ($career->fresh()->state === CareerState::PreMatch && $match->scheduled_on->eq($career->current_date)) {
            return redirect()->route('careers.matches.pre-match', [$career, $match])->with('status', 'Preço desta partida atualizado.');
        }

        return redirect()->route('careers.tickets.index', $career)->with('status', 'Preço desta partida atualizado.');
    }

    public function clearMatch(Request $request, Career $career, CareerMatch $match, TicketPricingService $tickets)
    {
        $this->authorize('view', $career);
        $tickets->clearMatch($request->user(), $career, $match);

        if ($career->fresh()->state === CareerState::PreMatch && $match->scheduled_on->eq($career->current_date)) {
            return redirect()->route('careers.matches.pre-match', [$career, $match])->with('status', 'Preço padrão restaurado para esta partida.');
        }

        return redirect()->route('careers.tickets.index', ['career' => $career, 'match_id' => $match->id])
            ->with('status', 'Preço padrão restaurado para esta partida.');
    }
}
