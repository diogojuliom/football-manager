@extends('careers.layout')
@section('title', 'Resultado do amistoso — '.$career->name)
@section('content')
@php
    $names = [];
    foreach (['home', 'away'] as $side) {
        foreach ($result[$side]['players'] as $player) { $names[$player['player_id']] = $player['name']; }
    }
    $labels = ['kickoff' => 'Início da partida', 'possession' => 'Posse de bola', 'build_up' => 'Construção de jogada', 'opportunity' => 'Oportunidade', 'shot' => 'Finalização', 'save' => 'Defesa do goleiro', 'goal' => 'Gol', 'assist' => 'Assistência', 'foul' => 'Falta', 'yellow_card' => 'Cartão amarelo', 'red_card' => 'Expulsão', 'substitution' => 'Substituição', 'fatigue' => 'Desgaste físico', 'half_time' => 'Intervalo', 'full_time' => 'Fim da partida', 'abandoned' => 'Partida interrompida'];
@endphp
<p><a href="{{ route('careers.friendlies.create', $career) }}">Voltar aos amistosos</a></p>
<h1>{{ $result['home']['name'] }} {{ $result['home']['stats']['goals'] }} × {{ $result['away']['stats']['goals'] }} {{ $result['away']['name'] }}</h1>
<p>Amistoso · {{ $result['minutes'] }} minutos · Motor {{ $simulation->engine_version }} · Seed: {{ $simulation->seed }}</p>
@if($result['status'] === 'abandoned')<p role="alert">Partida interrompida por equipe com menos de sete jogadores. O placar acima é o registro até a interrupção.</p>@endif
<p>Resultado de treinamento: a liga, a data interna, o saldo e a condição física oficial dos atletas foram preservados.</p>
<h2>Estatísticas das equipes</h2>
<div class="table-scroll"><table><thead><tr><th>Indicador</th><th>{{ $result['home']['name'] }}</th><th>{{ $result['away']['name'] }}</th></tr></thead><tbody>
@foreach(['possession' => 'Posse (%)', 'opportunities' => 'Oportunidades', 'shots' => 'Finalizações', 'shots_on_target' => 'Finalizações no gol', 'xg' => 'Gols esperados (xG)', 'saves' => 'Defesas', 'fouls' => 'Faltas', 'yellow_cards' => 'Amarelos', 'red_cards' => 'Expulsões', 'substitutions' => 'Substituições'] as $key => $label)
<tr><th>{{ $label }}</th><td>{{ $result['home']['stats'][$key] }}</td><td>{{ $result['away']['stats'][$key] }}</td></tr>
@endforeach
</tbody></table></div>
<p>Público, ocupação e bilheteria: não aplicáveis a este amistoso.</p>
<h2>Eventos destacados</h2>
<ol>
@foreach($result['events'] as $event)
    @if(in_array($event['type'], ['goal', 'assist', 'yellow_card', 'red_card', 'substitution', 'abandoned'], true))
    <li>{{ $event['minute'] }}' — {{ $labels[$event['type']] }}: {{ $names[$event['player_id']] ?? '' }}
        @if($event['type'] === 'substitution') no lugar de {{ $names[$event['data']['out_player_id']] }} @endif
        @if($event['type'] === 'red_card' && $event['data']['reason'] === 'second_yellow') (segundo amarelo) @endif
    </li>
    @endif
@endforeach
</ol>
@foreach(['home', 'away'] as $side)
<h2>{{ $result[$side]['name'] }} — Estatísticas individuais</h2>
<div class="table-scroll"><table><thead><tr><th>Jogador</th><th>Minutos</th><th>Gols</th><th>Assistências</th><th>Finalizações</th><th>No gol</th><th>xG</th><th>Defesas</th><th>Amarelos</th><th>Expulsões</th><th>Condição inicial</th><th>Condição final simulada</th></tr></thead><tbody>
@foreach($result[$side]['players'] as $player)
<tr><td>{{ $player['name'] }}</td>@foreach(['minutes', 'goals', 'assists', 'shots', 'shots_on_target', 'xg', 'saves', 'yellow_cards', 'red_cards', 'initial_fitness', 'final_fitness'] as $key)<td>{{ $player[$key] }}</td>@endforeach</tr>
@endforeach
</tbody></table></div>
@endforeach
<details><summary>Todas as jogadas</summary>
<div class="table-scroll"><table><thead><tr><th>Ordem</th><th>Minuto</th><th>Evento</th><th>Equipe</th><th>Jogador</th></tr></thead><tbody>
@foreach($result['events'] as $event)
<tr><td>{{ $event['sequence'] }}</td><td>{{ $event['minute'] }}</td><td>{{ $labels[$event['type']] }}</td><td>{{ $event['side'] ? $result[$event['side']]['name'] : '—' }}</td><td>{{ $names[$event['player_id']] ?? '—' }}</td></tr>
@endforeach
</tbody></table></div>
</details>
@endsection
