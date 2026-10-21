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

- [ ] A carreira possuir calendário interno persistente.
- [ ] O usuário conseguir avançar um dia.
- [ ] O usuário conseguir avançar até a próxima partida.
- [ ] O usuário conseguir avançar até o próximo acontecimento relevante.
- [ ] Os dias intermediários forem processados cronologicamente.
- [ ] Os jogos da IA forem processados nas datas aplicáveis.
- [ ] A progressão parar no pré-jogo do usuário.
- [ ] A partida exigir confirmação antes de sua execução.
- [ ] A carreira não avançar automaticamente após a desconexão.
- [ ] O retorno posterior ao jogo preservar o estado consistente da carreira.
- [ ] Duas solicitações simultâneas não produzirem progressões duplicadas.
- [ ] Uma operação interrompida puder ser recuperada com segurança.
- [ ] Uma temporada completa puder ser disputada com essa mecânica.

**Aceite final:** será possível disputar uma temporada completa utilizando a progressão até a próxima partida como principal experiência de jogo, sem progressão automática offline e ainda sem os recursos administrativos de estádio finalizados.

---

## Referências e conclusão

Escopo e critérios de aceite extraídos da seção 17 do [PRD original](../PRD.md). Consultar também as regras detalhadas do PRD, o plano de testes (seção 18), os critérios de aceite do MVP (seção 19), os limites de escopo (seção 20) e a definição de pronto (seção 21).

**Estado:** planejada; implementação não iniciada por esta organização documental.
