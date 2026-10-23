# IA dos clubes — Entrega 05

## Usar na aplicação

Abra uma carreira e escolha **Simular amistoso**. O formulário habitual usa a escalação salva do seu clube contra um adversário da IA. O formulário **IA contra IA** permite escolher dois dos nove clubes adversários, sem exigir escalação humana salva. Ambos usam o motor atual `3.0.0` e registram uma partida completa, com decisões da IA e estatísticas.

Os amistosos são executados somente pelo envio explícito do formulário. Não alteram data, calendário oficial, condição dos jogadores, suspensões, saldos, preços ou capacidade. Consultar o resultado não executa decisões novamente. A Entrega 06 integrou a execução das partidas da liga durante a [progressão autorizada](competicoes-e-progressao.md).

## Escalação e estratégia

`Domain/AI/LineupPlanner` recebe apenas arrays, sem banco ou serviços externos. O adaptador `Application/AI/ClubSquadSnapshot` reúne os dados da carreira e identifica os clubes efetivamente controlados pela IA. Lesionados, suspensos e atletas com condição física zero são excluídos.

O planejador compara as 21 formações disponíveis. Para cada uma, usa atribuição ótima de jogadores às onze vagas, sem duplicações, maximizando a soma das avaliações por função. A avaliação pondera atributos próprios da posição, posição principal/secundária, condição física e moral. A escolha do esquema também pondera seu perfil de ataque, defesa e controle conforme a força relativa e o mando. Exige um goleiro e dez jogadores de linha disponíveis; elenco insuficiente gera erro de validação, sem criar atletas. A ordenação estável por ID resolve empates de maneira determinística.

O banco respeita o limite de reservas congelado na edição da competição. Prioriza um goleiro reserva quando disponível e depois cobertura complementar das funções de linha. Não reutiliza titulares.

A estratégia inicial compara a força dos titulares à estimativa dos onze melhores atletas disponíveis do adversário: abaixo de 85%, defensiva; acima de 115%, ofensiva; entre esses limites, equilibrada. Condição física inferior a 65 reduz pressão e ritmo. Mando e mentalidade também influenciam o estilo de construção.

## Decisões durante a partida

`Domain/AI/V1/MatchDecisions` contém as regras da política `1.0.0`, usadas pelos motores V2 e V3. A IA revê a estratégia nos minutos 1, 30, 45, 60, 70 e 80 e após mudanças na quantidade de atletas em campo. Considera placar, vantagem/desvantagem numérica e condição física média. Exemplos: buscar o resultado a partir dos 60 minutos, proteger vantagem a partir dos 70, reorganizar após expulsão e reduzir esforço com condição média inferior a 50. A formação permanece fixa durante a partida.

As substituições consideram desgaste, amarelos, ganho de avaliação e necessidade de atacar ou defender. Há intervalo mínimo de dez minutos entre mudanças e o limite esportivo de substituições é respeitado. Antes do intervalo, só há troca por condição muito baixa. Goleiros só são substituídos em condição crítica e por outro goleiro. Expulsos e substituídos não retornam; vagas de expulsos não recebem reservas.

Cada ajuste efetivo gera `tactical_change`, com motivo, táticas anteriores e novas. Substituições também registram o motivo. O resultado conserva as táticas finais e a interface apresenta esses acontecimentos em português. A equipe humana mantém suas táticas salvas e a regra anterior de substituições automáticas dos amistosos.

## Administração inicial

`Domain/AI/AdministrationPlanner` calcula recomendações a partir de saldo, estádio, preço atual e torcida da própria carreira. Compara preços de 80%, 90%, 100%, 110% e 120% do atual, dentro do teto aceito pelo motor, escolhendo o maior resultado operacional estimado. A procura preliminar considera torcida potencial, lealdade, engajamento e elasticidade ao preço; o público estimado nunca supera a capacidade disponível.

O planejador reserva o custo operacional de dois jogos com estádio cheio e limita o orçamento de investimentos ao saldo restante, nunca inferior a zero. Quando recebe propostas de obras, recomenda apenas uma proposta útil, de custo e prazo positivos, dentro desse orçamento e sem projeto ativo. Expansão exige ocupação estimada de pelo menos 85%; modernização exige infraestrutura inferior ao nível máximo. Saldo negativo, receita operacional estimada insuficiente ou demanda baixa impedem as propostas aplicáveis. Não concede crédito, capacidade ou recursos extras.

O snapshot de cada clube da IA guarda a recomendação, e a tela mostra ingresso sugerido, procura estimada, reserva operacional e orçamento. Esta etapa não fornece propostas concretas de construção nem executa recomendações. As Entregas 07/08 passaram a calcular público oficial, escolher o preço efetivo da IA com o modelo de demanda e lançar bilheteria e despesas; consulte [ingressos e finanças](ingressos-e-financas.md). Catálogo, custos, prazos e execução das obras pertencem à Entrega 09. As estimativas do planejador continuam distintas do público e da receita efetivos; amistosos não geram lançamentos.

## Integração, persistência e compatibilidade

O motor continua independente de banco, autenticação e relógio. Os snapshots de V2/V3 identificam `controller` (`human` ou `ai`) e, para IA, `ai_policy_version=1.0.0`, além de titulares, reservas e táticas iniciais. O adaptador congela todos esses dados antes da simulação. Alterações futuras nas regras exigem novas versões: preservar `Engine/V1`, `Engine/V2`, `Engine/V3` e as políticas usadas para reproduzir resultados publicados.

O motor aceita explicitamente `engine_version=1.0.0`, `2.0.0` e `3.0.0`; a versão padrão é `3.0.0`. As implementações e referências fixas de V1 e V2 foram preservadas. Resultados anteriores continuam consultáveis e reproduzíveis pela entrada persistida, inclusive após leitura do JSON do MySQL.

O POST existente `/careers/{career}/friendlies` aceita `mode=user_vs_ai` (padrão) ou `mode=ai_vs_ai`. Ambos exigem `away_club_id`, `seed` e `request_id` UUID; o segundo também exige `home_club_id`. No modo entre IAs, os dois clubes devem pertencer à carreira, ser distintos e ter controlador IA. O servidor deriva escalações e decisões; parâmetros administrativos enviados pelo cliente não alteram o mundo.

Autorização de proprietário, bloqueio da carreira, transação e imutabilidade dos resultados permanecem aplicados. Repetir a solicitação retorna o mesmo registro; reutilizar o UUID com outros dados é rejeitado. A assinatura anterior do modo humano foi preservada para permitir repetições de solicitações anteriores à atualização. No modo entre IAs, a assinatura também inclui modo e mandante.

Não há novas tabelas ou migrations nesta entrega: os snapshots existentes armazenam as decisões. Em uma instalação atualizada basta usar a aplicação; em uma instalação nova, executar `php artisan migrate --seed` conforme a documentação de ambiente.

## Validação

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test
```

A suíte completa passou com **162 testes e 334.912 asserções em SQLite**. Ela cobre disponibilidade, determinismo, reações, substituições, limites financeiros, autorização, isolamento, idempotência, as 21 formações, os perfis usados pela V3 e a preservação dos replays V1/V2. Os cenários sintéticos entre IAs reconciliam participantes, gols, cartões e substituições.

Um fluxo HTTP real com cookies e CSRF validou cadastro, criação de carreira sem escalação humana, amistoso IA contra IA de 90 minutos, repetição segura da solicitação e consulta após logout/login. Conferiu que somente um resultado foi gravado e que o estado oficial da carreira permaneceu idêntico.
