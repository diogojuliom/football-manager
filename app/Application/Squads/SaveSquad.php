<?php

namespace App\Application\Squads;

use App\Domain\Career\Models\Career;
use App\Domain\Tactics\Models\CareerLineup;
use App\Domain\Tactics\Tactics;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveSquad
{
    public function __construct(private SquadSheet $sheet) {}

    public function execute(User $user, Career $career, array $input): CareerLineup
    {
        Gate::forUser($user)->authorize('manageSquad', $career);

        return DB::transaction(function () use ($user, $career, $input): CareerLineup {
            $career = Career::whereKey($career->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('manageSquad', $career);
            if (! $this->sheet->editable($career)) {
                throw ValidationException::withMessages(['lineup' => 'A escalação não pode ser alterada durante o processamento ou execução de uma partida.']);
            }
            $limit = $this->sheet->maxSubstitutes($career);
            $rules = [
                'formation' => ['required', Rule::in(array_keys(Tactics::FORMATIONS))],
                'revision' => ['required', 'integer', 'min:0'],
                'starters' => ['required', 'array', 'size:11'],
                'starters.*' => ['required', 'integer', 'distinct'],
                'substitutes' => ['present', 'array', 'max:'.$limit],
                'substitutes.*' => ['required', 'integer', 'distinct'],
            ];
            foreach (Tactics::OPTIONS as $field => $options) {
                $rules[$field] = ['required', Rule::in(array_keys($options))];
            }
            // Empty reserve slots from HTML forms mean "no player"; other invalid inputs remain errors.
            $input['substitutes'] ??= [];
            if (is_array($input['substitutes'])) {
                $input['substitutes'] = array_values(array_filter($input['substitutes'], fn ($id) => $id !== null && $id !== ''));
            }
            $data = Validator::make($input, $rules)->validate();
            if (! array_is_list($data['starters'])) {
                throw ValidationException::withMessages(['starters' => 'Informe os onze titulares na ordem das posições.']);
            }
            $starters = array_map('intval', $data['starters']);
            $substitutes = array_map('intval', $data['substitutes']);
            $ids = [...$starters, ...$substitutes];
            if (count(array_unique($ids)) !== count($ids)) {
                throw ValidationException::withMessages(['substitutes' => 'Um atleta não pode ocupar mais de uma vaga entre titulares e reservas.']);
            }
            $club = $career->controlledClub->club;
            $players = $club->players()->where('career_id', $career->id)->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
            foreach ($ids as $id) {
                if (! isset($players[$id]) || ! $players[$id]->isAvailableOn($career->current_date)) {
                    throw ValidationException::withMessages(['starters' => 'Selecione apenas atletas disponíveis do seu clube nesta carreira.']);
                }
            }
            foreach ($starters as $slot => $id) {
                if (($slot === 0) !== $players[$id]->canPlay('GK')) {
                    throw ValidationException::withMessages(['starters' => 'A primeira vaga exige um goleiro; as outras dez exigem jogadores de linha.']);
                }
            }
            $lineup = $club->lineup()->first();
            if ((int) ($lineup?->revision ?? 0) !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'A escalação foi alterada em outra aba. Recarregue a página antes de salvar.']);
            }
            $settings = array_intersect_key($data, array_flip(['formation', ...array_keys(Tactics::OPTIONS)]));
            $lineup ??= new CareerLineup(['career_id' => $career->id, 'career_club_id' => $club->id]);
            $lineup->fill($settings + ['revision' => (int) $data['revision'] + 1])->save();
            $lineup->selections()->delete();
            foreach (['starter' => $starters, 'substitute' => $substitutes] as $unit => $members) {
                foreach ($members as $slot => $id) {
                    $lineup->selections()->create([
                        'career_id' => $career->id, 'career_club_id' => $club->id,
                        'career_player_id' => $id, 'unit' => $unit, 'slot' => $slot,
                    ]);
                }
            }

            return $lineup->load('selections');
        }, 3);
    }
}
