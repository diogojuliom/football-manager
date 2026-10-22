# Entrega 06 — Competições, calendário e progressão temporal

**Objetivo:** implementar o calendário esportivo e o mecanismo central de progressão das carreiras.

Esta entrega deverá observar obrigatoriamente todas as regras do RF04, especialmente o calendário diário interno, a progressão prioritária até a próxima partida e a ausência de avanço automático durante períodos de desconexão.

Implementar:

### 06.1. Competições

- Geração das rodadas.
- Agendamento dos confrontos.
- Classificação.
- Artilharia.
- Persistência dos resultados.
- Encerramento esportivo.

### 06.2. Calendário interno

- Campo `careers.current_date`.
- Datas internas independentes por carreira.
- Identificação de partidas e acontecimentos futuros.
- Processamento cronológico dos dias.
- Controle de acontecimentos processados.
- Estado persistente de pré-jogo.

### 06.3. Serviço de progressão

Implementar `CareerProgressionService` com as operações:

- `ADVANCE_ONE_DAY`
- `ADVANCE_TO_NEXT_MATCH`
- `ADVANCE_TO_NEXT_EVENT`

A operação `ADVANCE_TO_NEXT_MATCH` deverá ser a principal funcionalidade de progressão utilizada pela interface.

Ao utilizá-la, o sistema deverá avançar automaticamente pelos dias intermediários, processar acontecimentos aplicáveis e parar antes da próxima partida do clube do usuário.

### 06.4. Partidas e pré-jogo

- Identificar a próxima partida do usuário.
- Interromper o avanço no pré-jogo.
- Permitir ajustes finais de escalação e táticas.
- Exigir confirmação para executar a partida.
- Simular os confrontos da IA conforme a cronologia.
- Persistir resultados sem duplicação.
- Exibir os dados do pós-jogo.

### 06.5. Recuperação e segurança

- Implementar idempotência.
- Impedir progressões concorrentes.
- Garantir consistência após falhas.
- Recuperar operações autorizadas interrompidas.
- Preservar a data da carreira entre sessões.
- Impedir progressão baseada no relógio real.
- Não utilizar cron jobs para iniciar progressões automáticas.

### 06.6. Regras de UX

O dashboard deverá apresentar:

- Data interna.
- Próxima partida.
- Dias restantes.
- Botão principal "Próxima partida".
- Opções "Avançar 1 dia" e "Próximo acontecimento".

No dia da partida, deverá apresentar a ação "Jogar partida".

### Critérios de aceite da Entrega 06

A entrega somente será considerada concluída quando:

- [x] A carreira possuir calendário interno persistente.
- [x] O usuário conseguir avançar um dia.
- [x] O usuário conseguir avançar até a próxima partida.
- [x] O usuário conseguir avançar até o próximo acontecimento relevante.
- [x] Os dias intermediários forem processados cronologicamente.
- [x] Os jogos da IA forem processados nas datas aplicáveis.
- [x] A progressão parar no pré-jogo do usuário.
- [x] A partida exigir confirmação antes de sua execução.
- [x] A carreira não avançar automaticamente após a desconexão.
- [x] O retorno posterior ao jogo preservar o estado consistente da carreira.
- [x] Duas solicitações simultâneas não produzirem progressões duplicadas.
- [x] Uma operação interrompida puder ser recuperada com segurança.
- [x] Uma temporada completa puder ser disputada com essa mecânica.

**Aceite final:** será possível disputar uma temporada completa utilizando a progressão até a próxima partida como principal experiência de jogo, sem progressão automática offline e ainda sem os recursos administrativos de estádio finalizados.

---

## Referências e conclusão

Escopo e critérios de aceite extraídos da seção 17 do [PRD original](../PRD.md). Consultar também as regras detalhadas do PRD, o plano de testes (seção 18), os critérios de aceite do MVP (seção 19), os limites de escopo (seção 20) e a definição de pronto (seção 21).

**Estado:** implementada em 21/10/2026.

## Implementação e evidências

- Serviço de progressão com as três modalidades previstas, processamento diário, recuperação física e paradas obrigatórias.
- Pré-jogo persistente, ajustes de escalação e táticas, confirmação explícita e resultados oficiais imutáveis, com aplicação única de desgaste e suspensões.
- Partidas da IA nas datas agendadas, classificação e artilharia reconstruídas dos resultados oficiais, encerramento com campeão e histórico final.
- Operações autorizadas persistentes, revisão por carreira, bloqueios, idempotência, rollback dos efeitos em falhas e retomada explícita pelo dashboard.
- 110 testes aprovados em SQLite e 90 testes funcionais em MySQL 8.0.46. Temporada completa validada nos dois bancos e em fluxo HTTP com cookies/CSRF.
- Concorrência real validada nos dois bancos para avanço e confirmação de partida, com chaves iguais e distintas, sem duplicar dias ou resultados.
- Migração de carreira existente, rollback e reaplicação verificados sem alterar os universos anteriores nos cenários descartáveis.

**Aceite final atendido:** uma temporada completa de 18 rodadas e 90 partidas pode ser disputada pela progressão até a próxima partida, com confirmação no pré-jogo e sem avanço offline. Recursos administrativos de estádio seguem nas Entregas 07–09; criação da temporada seguinte, na Entrega 10.

Consulte [a documentação de competições e progressão](../../docs/competicoes-e-progressao.md) para o fluxo, regras, recuperação e evidências.
