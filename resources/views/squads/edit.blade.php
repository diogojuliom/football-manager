@extends('careers.layout')
@section('title', 'Elenco e táticas — '.$career->name)
@section('content')
@php
    $roles = \App\Domain\Tactics\Tactics::FORMATIONS[$settings['formation']];
    $starters = old('starters', $settings['starters']);
    $substitutes = old('substitutes', $settings['substitutes']);
    $starters = is_array($starters) ? $starters : [];
    $substitutes = is_array($substitutes) ? $substitutes : [];
    $submittedRevision = old('revision', $settings['revision']);
    $revision = is_scalar($submittedRevision) && filter_var($submittedRevision, FILTER_VALIDATE_INT) !== false ? $submittedRevision : $settings['revision'];
@endphp
<p><a href="{{ route('careers.show', $career) }}">Voltar à carreira</a></p>
<h1>Elenco e táticas</h1>
<p>{{ $club->name }} · {{ $career->current_date->format('d/m/Y') }}</p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if(!$lineup)<p>A seleção abaixo é uma sugestão inicial. Confirme em “Salvar escalação e táticas” para persistir.</p>@endif
@if(!$editable)<p role="alert">A edição está bloqueada enquanto uma partida ou ação da carreira está em processamento.</p>@endif
<form method="get" action="{{ route('careers.squad.edit', $career) }}">
    <label for="formation">Formação</label>
    <select id="formation" name="formation">
        @foreach(\App\Domain\Tactics\Tactics::FORMATIONS as $formation => $positions)
        <option value="{{ $formation }}" @selected($settings['formation'] === $formation)>{{ $formation }}</option>
        @endforeach
    </select>
    <button type="submit">Trocar formação</button>
    <p>Trocar formação prepara outra sugestão e descarta alterações ainda não salvas neste formulário.</p>
</form>
<form method="post" action="{{ route('careers.squad.update', $career) }}">
    @csrf @method('PUT')
    <input type="hidden" name="formation" value="{{ $settings['formation'] }}">
    <input type="hidden" name="revision" value="{{ $revision }}">
    <fieldset @disabled(!$editable)>
        <legend>Titulares — {{ $settings['formation'] }}</legend>
        <p>Selecione onze atletas distintos, com um goleiro na primeira vaga. Jogadores de linha fora da posição recebem um aviso; os efeitos no desempenho serão aplicados pelo motor de partidas.</p>
        @foreach($roles as $slot => $role)
            <label for="starter-{{ $slot }}">{{ $slot + 1 }}. {{ $role }}</label>
            <select id="starter-{{ $slot }}" name="starters[{{ $slot }}]" required>
                <option value="">Selecione um jogador</option>
                @foreach($players as $player)
                    @php
                        $selected = is_scalar($starters[$slot] ?? null) && (string) $starters[$slot] === (string) $player->id;
                        $allowedRole = ($role === 'GK') === $player->canPlay('GK');
                    @endphp
                    @if($allowedRole || $selected)
                    <option value="{{ $player->id }}" @selected($selected) @disabled(!$player->isAvailableOn($career->current_date) && !$selected)>{{ $player->name }} ({{ $player->primary_position }}){{ !$player->isAvailableOn($career->current_date) ? ' — Indisponível' : '' }}{{ !$player->canPlay($role) ? ' — Fora da posição' : '' }}</option>
                    @endif
                @endforeach
            </select>
        @endforeach
    </fieldset>
    <fieldset @disabled(!$editable)>
        <legend>Reservas — até {{ $limit }}</legend>
        <p>As vagas de reserva são opcionais. O mesmo jogador não pode aparecer entre os titulares e os reservas.</p>
        @for($slot = 0; $slot < $limit; $slot++)
        <label for="substitute-{{ $slot }}">Reserva {{ $slot + 1 }}</label>
        <select id="substitute-{{ $slot }}" name="substitutes[]">
            <option value="">Sem reserva</option>
            @foreach($players as $player)
            @php($selected = is_scalar($substitutes[$slot] ?? null) && (string) $substitutes[$slot] === (string) $player->id)
            <option value="{{ $player->id }}" @selected($selected) @disabled(!$player->isAvailableOn($career->current_date) && !$selected)>{{ $player->name }} ({{ $player->primary_position }}){{ !$player->isAvailableOn($career->current_date) ? ' — Indisponível' : '' }}</option>
            @endforeach
        </select>
        @endfor
    </fieldset>
    <fieldset @disabled(!$editable)>
        <legend>Configurações táticas</legend>
        @foreach(\App\Domain\Tactics\Tactics::OPTIONS as $field => $options)
        <label for="{{ $field }}">{{ \App\Domain\Tactics\Tactics::LABELS[$field] }}</label>
        <select id="{{ $field }}" name="{{ $field }}" required>
            @foreach($options as $value => $label)
            <option value="{{ $value }}" @selected(old($field, $settings[$field]) === $value)>{{ $label }}</option>
            @endforeach
        </select>
        @endforeach
    </fieldset>
    <button type="submit" @disabled(!$editable)>Salvar escalação e táticas</button>
    <a href="{{ route('careers.squad.edit', $career) }}">Recarregar dados salvos</a>
</form>
<h2>Jogadores e atributos</h2>
<p>Os atributos variam de 0 a 100. Lesões são consideradas até a data interna indicada, inclusive. O histórico esportivo será exibido quando houver partidas simuladas.</p>
<div class="table-scroll"><table>
    <thead><tr><th>Jogador</th><th>Posição</th><th>Posições secundárias</th><th>Idade</th><th>Condição física</th><th>Moral</th><th>Disponibilidade</th>@foreach(\App\Domain\Tactics\Tactics::ATTRIBUTES as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
    <tbody>@foreach($players as $player)
        <tr><td>{{ $player->name }}</td><td>{{ $player->primary_position }}</td><td>{{ implode(', ', $player->secondary_positions) ?: '—' }}</td><td>{{ $player->age }}</td><td>{{ $player->fitness }}</td><td>{{ $player->morale }}</td>
        <td>
        @if($player->isAvailableOn($career->current_date))
            Disponível
        @else
            @if($player->fitness <= 0)Sem condição física. @endif
            @if($player->injured_until && $player->injured_until->gte($career->current_date))Lesionado até {{ $player->injured_until->format('d/m/Y') }}. @endif
            @if($player->suspended_matches > 0)Suspenso por {{ $player->suspended_matches }} partida(s).@endif
        @endif</td>
        @foreach(\App\Domain\Tactics\Tactics::ATTRIBUTES as $attribute => $label)<td>{{ $player->$attribute }}</td>@endforeach</tr>
    @endforeach</tbody>
</table></div>
@endsection
