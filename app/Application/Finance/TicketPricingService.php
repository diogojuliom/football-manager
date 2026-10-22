<?php

namespace App\Application\Finance;

use App\Domain\Attendance\V1\DemandModel;
use App\Domain\Career\CareerState;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CareerMatch;
use App\Domain\Finance\Models\MatchTicketPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TicketPricingService
{
    public function __construct(private DemandModel $demand) {}

    public function parsePrice(mixed $value): int
    {
        if (! is_string($value) || ! preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $value)) {
            $this->invalid('price', 'Informe um preço válido em reais, com até duas casas decimais.');
        }
        [$reais, $centavos] = array_pad(explode('.', $value, 2), 2, '');
        $price = (int) $reais * 100 + (int) str_pad($centavos, 2, '0');
        if ($price < config('tickets.min_price_cents') || $price > config('tickets.max_price_cents')) {
            $this->invalid('price', 'O preço deve ficar entre R$ '
                .number_format(config('tickets.min_price_cents') / 100, 2, ',', '.').' e R$ '
                .number_format(config('tickets.max_price_cents') / 100, 2, ',', '.').'.');
        }

        return $price;
    }

    public function reprice(array $plan, int $priceCents): array
    {
        if ($priceCents < config('tickets.min_price_cents') || $priceCents > config('tickets.max_price_cents')) {
            $this->invalid('price', 'O preço dos ingressos está fora dos limites.');
        }
        $input = array_replace($plan['input'], ['price_cents' => $priceCents]);

        return $this->demand->forecast($input, $plan['rules']) + array_intersect_key($plan, array_flip([
            'career_id', 'match_id', 'home_club_id', 'away_club_id', 'stadium_name',
        ]));
    }

    public function chooseForAi(array $base): array
    {
        $default = $base['input']['price_cents'];
        $best = null;
        $bestScore = null;
        foreach (config('tickets.ai_price_percentages') as $percent) {
            $price = max(config('tickets.min_price_cents'), min(config('tickets.max_price_cents'), (int) round($default * $percent / 100)));
            $candidate = $this->reprice($base, $price);
            $crowd = $candidate['forecast'];
            $net = $crowd * $price - $candidate['input']['fixed_cost_cents'] - $crowd * $candidate['input']['variable_cost_cents'];
            $score = $net + $crowd * config('tickets.ai_occupancy_value_cents');
            if ($bestScore === null || $score > $bestScore || ($score === $bestScore && abs($price - $default) < abs($best['input']['price_cents'] - $default))) {
                $best = $candidate;
                $bestScore = $score;
            }
        }
        $best['pricing'] = ['policy_version' => '1.0.0', 'actor' => 'ai', 'default_price_cents' => $default,
            'chosen_price_cents' => $best['input']['price_cents'], 'estimated_net_cents' => $best['forecast'] * $best['input']['price_cents'] - $best['input']['fixed_cost_cents']
                - $best['forecast'] * $best['input']['variable_cost_cents']];

        return $best;
    }

    public function setDefault(User $user, Career $career, int $priceCents): void
    {
        Gate::forUser($user)->authorize('view', $career);
        $this->assertPrice($priceCents);
        DB::transaction(function () use ($user, $career, $priceCents): void {
            $career = $this->lock($career);
            Gate::forUser($user)->authorize('view', $career);
            $this->assertAvailable($career);
            $club = $career->controlledClub->club;
            if ($club->default_ticket_price_cents !== $priceCents) {
                $club->update(['default_ticket_price_cents' => $priceCents]);
                $career->increment('progression_revision');
            }
        }, 5);
    }

    public function setMatch(User $user, Career $career, CareerMatch $match, int $priceCents): void
    {
        Gate::forUser($user)->authorize('view', $career);
        $this->assertPrice($priceCents);
        DB::transaction(function () use ($user, $career, $match, $priceCents): void {
            $career = $this->lock($career);
            Gate::forUser($user)->authorize('view', $career);
            $this->assertAvailable($career);
            $match = CareerMatch::where('career_id', $career->id)->whereKey($match->id)->lockForUpdate()->firstOrFail();
            $this->assertHomeMatch($career, $match);
            $policy = MatchTicketPolicy::where('match_id', $match->id)->first();
            if (! $policy || $policy->price_cents !== $priceCents) {
                MatchTicketPolicy::updateOrCreate(['match_id' => $match->id], [
                    'career_id' => $career->id, 'home_club_id' => $match->home_club_id, 'price_cents' => $priceCents,
                ]);
                $career->increment('progression_revision');
            }
        }, 5);
    }

    public function clearMatch(User $user, Career $career, CareerMatch $match): void
    {
        Gate::forUser($user)->authorize('view', $career);
        DB::transaction(function () use ($user, $career, $match): void {
            $career = $this->lock($career);
            Gate::forUser($user)->authorize('view', $career);
            $this->assertAvailable($career);
            $match = CareerMatch::where('career_id', $career->id)->whereKey($match->id)->lockForUpdate()->firstOrFail();
            $this->assertHomeMatch($career, $match);
            $policy = MatchTicketPolicy::where('match_id', $match->id)->first();
            if ($policy) {
                $policy->delete();
                $career->increment('progression_revision');
            }
        }, 5);
    }

    public function assertHomeMatch(Career $career, CareerMatch $match): void
    {
        if ($match->career_id !== $career->id || $match->home_club_id !== $career->controlledClub->career_club_id
            || $match->status !== 'scheduled' || $match->scheduled_on->lt($career->current_date)) {
            $this->invalid('match', 'Só é possível definir ingressos de uma partida futura em casa.');
        }
    }

    private function assertPrice(int $priceCents): void
    {
        if ($priceCents < config('tickets.min_price_cents') || $priceCents > config('tickets.max_price_cents')) {
            $this->invalid('price', 'O preço dos ingressos está fora dos limites.');
        }
    }

    private function assertAvailable(Career $career): void
    {
        if ($career->state === CareerState::Processing) {
            $this->invalid('price', 'Conclua a operação pendente antes de alterar ingressos.');
        }
    }

    private function lock(Career $career): Career
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::table('careers')->where('id', $career->id)->update(['progression_revision' => DB::raw('progression_revision')]);
        }

        return Career::whereKey($career->id)->lockForUpdate()->firstOrFail();
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
