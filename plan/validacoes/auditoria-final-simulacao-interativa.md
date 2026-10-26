# Auditoria final — simulação interativa de partidas

Data da execução: 26/10/2026. Branch auditada: `main`.

## Escopo e arquitetura verificados

A auditoria percorreu o fluxo real carreira → pré-jogo → central da partida → resultado e conferiu as rotas `careers.matches.live.*`, o `InteractiveMatchController`, o `InteractiveMatchService`, os modelos `InteractiveMatchSession` e `InteractiveMatchRequest`, o motor incremental `V4\Simulation` (`4.0.0`), a política determinística de IA, a progressão da carreira e a interface formada por `matches/live.blade.php`, `live-match.js` e `live-match-clock.js`. Os motores V1, V2 e V3 permaneceram disponíveis para resultados históricos.

Os testes do motor usam as implementações reais de simulação, persistência, progressão, finanças e IA. O próprio núcleo esportivo não foi substituído por mocks. SQLite descartável foi usado na regressão e no navegador; os bloqueios de linha foram executados separadamente em MySQL 8 isolado.

## Comandos e resultados consolidados

| Verificação | Execução | Resultado real |
| --- | --- | --- |
| Regressão PHP completa | `php artisan test` no container PHP 8.3 | **199 aprovados**, **2 ignorados no SQLite**, 0 reprovados, **337.702 asserções**, 139,37 s |
| Concorrência MySQL | `php artisan test tests/Feature/Matches/InteractiveMatchMysqlConcurrencyTest.php` com MySQL 8 descartável | **2 aprovados**, 0 reprovados, **25 asserções**, 9,73 s |
| Relógio frontend | `npm run test:frontend` | **5 aprovados**, 0 reprovados |
| Navegador Chromium | `npm run test:browser` | **1 aprovado**, 0 reprovados, 20,9 s |
| Formatação PHP alterado | `vendor/bin/pint --test ...` | 2 arquivos aprovados |
| Build de produção | `npm run build` | 65 módulos transformados, concluído em 416 ms |

Os dois testes ignorados na regressão SQLite são exatamente os dois testes que exigem semântica real de `lockForUpdate()`. Ambos foram reexecutados e aprovados no MySQL; portanto, não há cenário pendente nessa área.

## Matriz T01 a T20

| ID | Cenário | Arquivo e método/evidência | Resultado efetivamente observado | Status | Correção nesta auditoria |
| --- | --- | --- | --- | --- | --- |
| T01 | Simulação completa | `InteractiveMatchTest::test_basic_match_flow_advances_each_minute_freezes_on_pauses_and_consolidates_once`; `InteractiveMatchAudit.test.mjs` | Partida percorreu cada minuto, intervalo e acréscimos; eventos ficaram ordenados, o placar correspondeu aos gols e a consolidação repetida não duplicou o resultado. | **Aprovado** | Nenhuma. |
| T02 | Sete velocidades | `LiveMatchClock.test.js` — testes de duração nominal, 90 minutos e troca de velocidade; navegador real | Foram validadas 0,5×, 0,75×, 1×, 1,25×, 1,5×, 1,75× e 2×. Em 1×, a duração nominal foi 300 s. Cada velocidade apresentou exatamente os minutos 1–90; velocidade não foi enviada ao motor. | **Aprovado** | Adicionado teste de todos os 90 ticks em cada velocidade. |
| T03 | Pausa e retomada | `InteractiveMatchTest::test_basic_match_flow...`; `IncrementalDeterminismTest::test_fixed_seeds_decisions_speeds_chunks_pauses_and_recoveries_are_identical` | Durante a pausa, minuto, eventos e contador do RNG ficaram congelados; a retomada continuou do estado confirmado sem alterar o resultado. | **Aprovado** | Nenhuma. |
| T04 | Intervalo e ajustes | `InteractiveMatchTest::test_match_pauses_at_half_time...`; `IncrementalMatchEngineTest::test_half_time_accepts_substitution_and_tactics_without_recalculating_the_first_half`; navegador real | O avanço parou automaticamente no intervalo. Substituição, formação e instruções foram salvas e aplicadas apenas ao segundo tempo; o primeiro tempo permaneceu byte a byte inalterado. | **Aprovado** | Adicionado cenário conjunto de substituição e tática no intervalo. |
| T05 | Substituição aos 65' | `InteractiveTacticalChangesTest::test_substitution_at_minute_65_uses_the_new_players_attributes_only_after_the_decision` | O reserva assumiu o slot do titular, passou a compor a força do time e o substituído deixou os cálculos futuros; prefixo de eventos e estatísticas anteriores foi preservado. | **Aprovado** | Nenhuma. |
| T06 | Substituições inválidas | `InteractiveTacticalChangesTest::test_invalid_substitutions_and_limits_are_rejected_without_mutating_state`; `IncrementalMatchEngineTest::test_multiple_substitutions_in_one_pause_use_one_window_and_later_windows_are_blocked`; `InteractiveMatchIntegrityTest::test_stale_revision_invalid_player_and_arbitrary_minute_do_not_corrupt_official_state` | Jogadores fora do campo/banco, indisponíveis, repetidos, limites e janelas excedidos e ações após o fim foram rejeitados sem mudar estado, RNG ou contadores. | **Aprovado** | Nenhuma. |
| T07 | 21 formações | `InteractiveTacticalChangesTest::test_all_21_formations_produce_valid_unique_lineups_and_internal_strengths`; `FormationsTest::test_catalog_defines_valid_roles_shapes_and_profiles_for_all_formations`; `SquadTest::test_each_formation_and_tactical_option_can_be_saved_and_reloaded` | As 21 formações produziram 11 slots válidos e únicos, foram aceitas pelo motor, persistidas e recarregadas. | **Aprovado** | Nenhuma. |
| T08 | Troca de posições | `InteractiveTacticalChangesTest` (troca de titulares e adequação); navegador real; `InteractiveMatchTest::test_official_result_renders_a_saved_position_change` | Dois titulares trocaram funções sem mudar a formação ou duplicar jogadores. O motor usou a nova adequação apenas dali em diante e o histórico passou a renderizar a decisão corretamente. | **Aprovado** | Corrigido erro HTTP 500 no resultado ao exibir evento `positions_change`; incluído rótulo de lesão e fallback seguro para eventos versionados. |
| T09 | Estilo de jogo | `InteractiveTacticalChangesTest` (instruções e cálculos); `IncrementalMatchEngineTest::test_tactical_instructions_change_future_fitness_and_sports_statistics` | Equilibrado, ataque total e contra-ataque/retranca alteraram diretamente ataque, defesa, controle e desgaste do estado futuro, sem reprocessar o passado. | **Aprovado** | Nenhuma. |
| T10 | Direção e marcação | Mesmos testes de instruções de T09; `InteractiveTacticalChangesTest::test_invalid_tactical_command_is_atomic` | Meio, laterais e equilibrado alteraram o perfil ofensivo; marcação leve, pesada e muito pesada alterou parâmetros defensivos, desgaste e disciplina. Comando inválido foi atômico. | **Aprovado** | Nenhuma. |
| T11 | Determinismo completo | `IncrementalDeterminismTest::test_fixed_seeds_decisions_speeds_chunks_pauses_and_recoveries_are_identical` | Representações esportivas canônicas tiveram igualdade exata em eventos, minutos, gols, cartões, estatísticas, decisões da IA, RNG e placar final. | **Aprovado** | Nenhuma. |
| T12 | Determinismo entre blocos | `IncrementalMatchEngineTest::test_chunk_sizes_pauses_and_state_restoration_produce_the_same_match`; teste de determinismo fixo | Blocos de 1, 5 e tamanhos variáveis, com diferentes pausas, convergiram para a mesma sequência esportiva e o mesmo estado final. | **Aprovado** | Nenhuma. |
| T13 | Interrupção, retomada e rollback | `InteractiveMatchTest::test_disconnect_and_failed_block_preserve_the_last_confirmed_rng_state_for_recovery`; `InteractiveMatchIntegrityTest::test_persistence_and_finish_failures_roll_back_and_can_be_retried`; navegador real | Retomadas em estados persistidos conservaram minuto, decisões e RNG. Exceções controladas em avanço e encerramento mantiveram o último commit íntegro; a repetição concluiu sem duplicar dados. Recarga e queda de conexão não avançaram o relógio oficial. | **Aprovado** | Nenhuma. |
| T14 | Idempotência | `InteractiveMatchIntegrityTest::test_start_advance_decision_and_finish_are_idempotent` | Chaves repetidas de início, avanço, decisão e encerramento retornaram o efeito já confirmado uma única vez, sem novo minuto, RNG ou registro. | **Aprovado** | Nenhuma. |
| T15 | Concorrência | `InteractiveMatchMysqlConcurrencyTest::test_same_and_competing_advances_are_serialized_without_duplicate_rng_consumption`; `::test_independent_careers_advance_concurrently_without_cross_contamination`; navegador com duas revisões | No MySQL 8, avanços concorrentes foram serializados pelo bloqueio de linha e revisões conflitantes não foram confirmadas. Carreiras paralelas mantiveram RNG e dados isolados. Uma aba desatualizada recebeu 422 sem mutação. | **Aprovado** | Nenhuma. |
| T16 | Inteligência artificial | `InteractiveClubAiTest` — cenários vencendo/perdendo/empatando, cansaço/indisponibilidade, preservação humana e partida IA × IA; `IncrementalMatchEngineTest::test_ai_uses_the_same_tactical_model_to_chase_a_result` | A IA escolheu decisões válidas e determinísticas conforme placar, minuto e condição, respeitou limites e não sobrescreveu o treinador humano. IA × IA encerrou pelo mesmo núcleo sem eventos duplicados. | **Aprovado** | Nenhuma. |
| T17 | Simular até o final | `InteractiveMatchTest::test_finish_at_minute_60_matches_incremental_play_after_the_same_human_decisions`; navegador real | Após decisão aos 30' e avanço até 60', o término rápido foi idêntico ao processamento incremental equivalente, manteve a IA ativa e consolidou uma vez. No navegador, a partida de controle terminou 2 × 2 em 90+3'. | **Aprovado** | Nenhuma. |
| T18 | Integridade esportiva e financeira | `InteractiveSimulationIntegrationTest::test_interactive_result_and_ai_games_close_a_reconciled_round_once`; `FinanceTest::test_full_round_posts_reconciled_home_finances_once_including_ai_prices`; `ProgressionTest::test_standings_and_scorers_use_official_results_and_frozen_configurable_rules` | A rodada fechou com resultados únicos, classificação e artilharia reconciliadas, condição física aplicada e público/finanças lançados uma única vez. Repetir o encerramento não criou efeitos extras. | **Aprovado** | Nenhuma. |
| T19 | Três temporadas e histórico | `ConsecutiveSeasonsTest::test_three_complete_seasons_preserve_history_administration_and_unique_schedules`; `::test_interrupted_transition_rolls_back_and_resume_is_idempotent_and_isolated`; testes de replay V1/V2/V3 | Três temporadas foram criadas e concluídas sem calendários duplicados; clube, elenco, estádio, saldo e histórico persistiram. Transição interrompida foi recuperável e isolada. Resultados dos motores anteriores continuaram consultáveis/reproduzíveis. | **Aprovado** | Nenhuma. |
| T20 | Interface e navegação | `InteractiveMatchAudit.test.mjs`; `InteractiveMatchTest::test_live_routes_render_all_controls_and_do_not_advance_without_an_explicit_request`; teste de resultado de T08 | Chromium real validou desktop 1440 px, tablet 768 px e mobile 390 px: 11 jogadores, campo, banco, placar, relógio, eventos, estatísticas, velocidade, pausa, substituições, táticas, intervalo, recarga, erro, resultado e dashboard sem overflow horizontal ou erro JavaScript. | **Aprovado** | A correção de renderização de T08 eliminou o único erro 500 encontrado no primeiro passe. |

## Evidência real do navegador

O teste criou uma carreira em base descartável e executou duas partidas completas:

- **Atlético da Ribeira 2 × 0 Vila das Pedras Esporte Clube**, encerrada após 94 minutos lógicos. Gols aos 6' (jogador 1619) e 23' (jogador 1603); cartões observados aos 16', 17', 20', 42', 45' e 51'. O fluxo incluiu as sete velocidades, pausa, conflito de revisão, troca de posições, substituição, mudança de formação e das três instruções, intervalo automático e ajustes no intervalo.
- Uma segunda partida seguiu incrementalmente até 30', recebeu alteração tática, avançou até 60' e usou **Simular até o final**, encerrando em **2 × 2 aos 90+3'**. Uma falha de rede controlada foi recuperada automaticamente; recarregar a página não moveu o minuto oficial.

O navegador não registrou exceções JavaScript. As únicas respostas de erro foram as induzidas pelo teste: 422 para revisão obsoleta e falha de rede para comprovar recuperação. Fontes externas do Google foram bloqueadas deliberadamente no ambiente isolado e a interface utilizou suas fontes de fallback, sem impacto funcional ou geométrico.

## Problema encontrado e correção

Uma troca de posições era persistida e aplicada corretamente pelo motor, mas a página de resultado usava um mapa fechado de rótulos e lançava `Undefined array key "positions_change"`. A tela agora reconhece `positions_change` e `injury` e possui fallback legível para futuros tipos preservados em históricos versionados. O teste de feature reproduz o caminho oficial até a página de resultado e impede a regressão.

Também foi fechada uma lacuna de cobertura do relógio: o novo teste percorre todos os minutos em cada uma das sete velocidades com relógio virtual e comprova que não há tick perdido ou duplicado. O cenário de intervalo passou a provar em conjunto substituição e mudança tática, além da imutabilidade do primeiro tempo.

## Resultado da auditoria

- T01–T20: **20 aprovados, 0 reprovados, 0 não executados**.
- Execuções automatizadas finais: **207 aprovadas** (199 PHP SQLite + 2 PHP MySQL + 5 frontend + 1 navegador), 0 reprovadas. Os 2 skips condicionais do passe SQLite foram aprovados no passe MySQL.
- Build: aprovado.
- Testes críticos pendentes: nenhum.
- Artefatos de teste: bancos SQLite e containers descartáveis não fazem parte do repositório.

A simulação incremental V4 ficou aprovada no escopo auditado: decisões do treinador alteram os cálculos seguintes; determinismo, idempotência, recuperação, autorização, isolamento e integridade transacional possuem evidência executada; integração esportiva e financeira e três temporadas consecutivas passaram na regressão; a interface permaneceu sincronizada e responsiva no navegador real.
