# Entrega 03 — Elenco e táticas

Implementar:

- Listagem de jogadores.
- Escalação.
- Reservas.
- Formações.
- Configurações táticas.
- Validações.

**Aceite:** escalações e táticas persistidas corretamente.

---

## Referências e conclusão

Escopo e critérios de aceite extraídos da seção 17 do [PRD original](../PRD.md). Consultar também as regras detalhadas do PRD, o plano de testes (seção 18), os critérios de aceite do MVP (seção 19), os limites de escopo (seção 20) e a definição de pronto (seção 21).

**Estado:** implementada em 21/10/2026.


## Registro da implementação

- Tela de elenco do clube humano, com posições, disponibilidade e atributos.
- Onze titulares e reservas opcionais, respeitando a regra da competição.
- Formações 4-4-2, 4-3-3, 4-2-3-1 e 3-5-2.
- Configurações persistentes de mentalidade, pressão, ritmo e construção de jogadas.
- Validação de duplicações, goleiro, disponibilidade e pertencimento ao clube/carreira.
- Autorização por proprietário, transação de salvamento e controle de revisão entre abas.
- Bloqueio de alterações durante processamento ou execução de partida.

**Validação:** suíte completa com 55 testes aprovados em SQLite e MySQL 8.0.46, incluindo 20 testes desta entrega. Fluxo HTTP com sessão e CSRF, retomada e rollback/reaplicação da migração também verificados.

As escolhas são consumidas pelo motor da Entrega 04 nos amistosos. A interface permanece em Blade, conforme a implementação atual.

Consulte [Elenco e táticas: uso, regras e validação](../../docs/elenco-e-taticas.md).
