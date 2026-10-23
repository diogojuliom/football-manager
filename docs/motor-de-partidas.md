# Motor de partidas — Entrega 04

## Usar na aplicação

Execute `php artisan migrate --seed` na conexão de desenvolvimento. Na nuvem, ative antes `source /workspace/.cloud-tools/football-env.sh`.

Abra uma carreira, salve a escalação em **Gerenciar elenco e táticas** e escolha **Simular amistoso**. Selecione outro clube da carreira e confirme o código da simulação (seed). O resultado apresenta placar, eventos cronológicos, estatísticas das equipes e dos atletas e condição física final simulada. Os últimos dez amistosos ficam acessíveis na tela de criação; os resultados anteriores continuam disponíveis por suas URLs autorizadas.

Os amistosos são simulações de treinamento: não avançam o calendário, não alteram jogadores, saldos, suspensões ou partidas da liga. A escalação humana precisa estar salva e válida. Com a Entrega 05, o adversário usa seleção e estratégias adaptativas; também há um formulário **IA contra IA**, independente da escalação humana. Consulte [a documentação da IA](ia-dos-clubes.md). A Entrega 06 integrou [partidas oficiais nas datas do calendário](competicoes-e-progressao.md), com classificação e efeitos físicos aplicados uma única vez.

## Núcleo independente

`App\Domain\Football\Engine\FootballMatchEngine` recebe um array e retorna outro. Não usa Eloquent, banco, sessão, autenticação, HTTP, relógio ou gerador aleatório global. `MatchInput` valida e normaliza a entrada antes da execução.

```php
$result = (new FootballMatchEngine)->simulate([
    'engine_version' => '3.0.0',
    'seed' => 'partida-001',
    'home' => $homeSnapshot,
    'away' => $awaySnapshot,
    'rules' => ['max_substitutions' => 3],
    'home_context' => ['neutral' => false],
]);
```

Cada equipe possui `name`, `tactics`, `starters` (11 atletas) e `substitutes` (até 12). As táticas usam `formation`, `mentality`, `pressure`, `tempo` e `build_up`, com os códigos da Entrega 03. Cada jogador contém:

- `id`: string estável e única entre as duas equipes; `name`, `primary_position`, `secondary_positions`.
- Os 11 atributos de 0 a 100 definidos em `Tactics::ATTRIBUTES`, como inteiros.
- `fitness` de 1 a 100, `morale` de 0 a 100 e, opcionalmente, `available` (deve ser verdadeiro).

A primeira vaga exige goleiro e as demais exigem jogadores de linha. O adaptador da aplicação verifica disponibilidade pela data da carreira e congela os valores dos atletas antes de chamar o motor. Não são lidos atributos do banco durante a partida.

## Versão e reprodução

As versões `1.0.0` e `2.0.0` permanecem suportadas para reproduzir partidas históricas. A versão atual é `3.0.0`, em `Engine/V3/Simulation.php`, e acrescenta perfis próprios de ataque, defesa e controle para cada formação. Em V2 e V3, cada equipe informa `controller=human|ai` (padrão `human`), e a IA usa `ai_policy_version=1.0.0`. A mesma entrada normalizada, seed e versão produz o mesmo resultado. `SeededRandom` gera uma sequência local por SHA-256 de seed e contador, sem afetar outros sorteios do processo. A seed aceita de 1 a 64 letras ASCII, números, hífens ou sublinhados.

Cada versão possui uma fixture de referência com hash de resultado fixo em teste, protegendo contra mudanças silenciosas na versão publicada. Alterações futuras nas regras/coeficientes exigem uma versão nova e manutenção da implementação anterior e das políticas de IA para reproduzir seus snapshots. Versões desconhecidas são rejeitadas.

`ReplayHash` ordena recursivamente as chaves associativas, preservando a ordem dos eventos/jogadores e os tipos numéricos. A persistência preserva frações zero no JSON. Isso permite conferir o replay mesmo quando MySQL reorganiza as chaves dos objetos JSON:

```php
$replayed = (new FootballMatchEngine)->simulate($simulation->input);
$valid = ReplayHash::of($replayed) === $simulation->result_checksum;
```

## Regras esportivas iniciais

- Simulação cronológica de 90 minutos, com uma disputa de posse e construção de jogada por minuto. O placar vem dos gols gerados pelas finalizações; não há sorteio direto de placar.
- Força ofensiva, defensiva e controle consideram os atributos individuais, a função na formação, condição física, moral e, na V3, o perfil do esquema escolhido.
- Posição principal recebe fator integral, secundária 0,95 e atuação fora da posição 0,78.
- Mentalidade altera o compromisso entre ataque e defesa; passes curtos favorecem controle, jogo direto favorece ataque; ritmo e pressão alteram disputas e desgaste.
- Uma oportunidade pode gerar finalização, xG, acerto no alvo, gol ou defesa. Assistências são ligadas ao autor do gol. xG é acumulado em milésimos inteiros.
- Faltas podem gerar amarelos, segundo amarelo ou expulsão direta. Expulsos não voltam a atuar; a redução de atletas afeta as forças da equipe.
- Abaixo de sete jogadores, a simulação termina com `status=abandoned` e placar até a interrupção, sem inventar um resultado administrativo.
- Condição física diminui conforme minutos jogados, resistência, ritmo, pressão e função. Reservas não utilizadas não sofrem desgaste.
- Na V1 e para equipes humanas na V2/V3, substituições automáticas priorizam jogadores de linha mais desgastados e reservas compatíveis com a função. O padrão é até três mudanças nas janelas 60/70/80; a entrada pode definir de zero a cinco, usando 55/60/65/70/80 quando o limite supera três. Não há reentrada de substituídos. Na V2/V3, a IA considera desgaste, cartões, placar e qualidade, respeitando o limite de trocas e intervalo mínimo de dez minutos.

As regras são uma base funcional testada, sujeita ao balanceamento da Entrega 12. Os testes de distribuição não demonstram equilíbrio econômico/esportivo final.

## Mando, público e receitas

`home_context` aceita `neutral`, `attendance`, `capacity`, `engagement` e `ticket_price_cents`. O bônus esportivo de mando é 3% mais até 2% por ocupação e engajamento, limitado a 5%. Em campo neutro, é zero.

Quando informados, público, capacidade e preço precisam ser inteiros fornecidos juntos, com público dentro da capacidade e valores não negativos. O resultado conserva público, ocupação e bilheteria calculada em centavos, sem recalculá-los pelo placar. O bônus não recebe valores arbitrários enviados pelo cliente.

Nos amistosos atuais, público/ocupação/bilheteria são `null`, pois não há demanda calculada ou cobrança. O motor já aceita o contexto consolidado para as Entregas 07 e 08. Não há lançamento financeiro nesta entrega.

## Resultado e persistência

A saída contém versão, seed, status, minutos, bônus de mando, eventos em ordem, estatísticas por equipe e atleta e dados de público quando fornecidos. Inclui posse, oportunidades, finalizações, finalizações no gol, xG, gols, assistências, faltas, defesas, cartões, substituições, minutos individuais e condição física final. Placar, eventos e somas individuais são reconciliados em testes.

`match_simulations` armazena os snapshots completos de entrada e resultado, seed, versão e checksums. Não há rotas de edição/exclusão, e o modelo bloqueia atualização e exclusão. Consultar um resultado não executa a simulação novamente nem consulta os atributos atuais para reconstruir a partida.

`SimulateFriendly` autoriza o proprietário, bloqueia a carreira, valida o adversário e a escalação e grava o resultado em uma transação. Cada formulário possui `request_id` UUID. Repetir a mesma solicitação retorna o mesmo registro; reutilizar a chave com seed/adversário diferentes é rejeitado. Uma falha não deixa resultados parciais. Rotas com uma simulação de outra carreira são rejeitadas.

As tabelas oficiais `matches` continuam como calendário da liga. A Entrega 06 registra seus resultados em `official_match_results`, aplicando desgaste, suspensões e classificação durante a operação autorizada, uma única vez. A integração financeira pertence às entregas correspondentes.

## Validação

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test
```

A suíte completa passou com **162 testes e 334.912 asserções em SQLite**, incluindo replays V1/V2, motor V3, todas as formações e três temporadas consecutivas. As validações anteriores em MySQL também cobrem a ida e volta dos snapshots pelo JSON do banco.

Os testes do motor incluem 100 seeds com reconciliação detalhada de eventos/estatísticas e **2.000 partidas sintéticas**: 1.000 entre equipes equivalentes, 250 com mandante muito superior, 250 com visitante muito superior, 250 com esforço alto e 250 com mandante exausto. Verificam diversidade de placares, empates, jogos sem gols, jogos com cinco ou mais gols, vantagem técnica, desgaste e limites estatísticos. Também há cenários com atributos extremos, nenhuma reserva, limites de substituição, mando/torcida, entradas inválidas, referência de replay e abandono por expulsões.

Um fluxo HTTP com cookies e CSRF validou escalação salva, amistoso, repetição segura, consulta após logout/login e preservação da carreira. Rollback/reaplicação da migração foram verificados em SQLite e MySQL. O rollback desta entrega remove somente os registros de amistosos e sua tabela; não redefine os universos das carreiras. Usar banco descartável para testar rollback.
