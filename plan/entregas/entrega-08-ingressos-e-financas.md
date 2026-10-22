# Entrega 08 — Ingressos e finanças

Implementar:

- Preço padrão.
- Preço por partida.
- Previsão de receita.
- Precificação dos clubes da IA.
- Lançamentos financeiros.
- Bilheteria.
- Despesas operacionais.
- Atualização do saldo.
- Painel financeiro.

**Aceite:** partidas em casa gerarão resultados financeiros auditáveis, e alterações de preço influenciarão a demanda.

---

## Referências e conclusão

Escopo e critérios de aceite extraídos da seção 17 do [PRD original](../PRD.md). Consultar também as regras detalhadas do PRD, o plano de testes (seção 18), os critérios de aceite do MVP (seção 19), os limites de escopo (seção 20) e a definição de pronto (seção 21).

**Estado:** implementada. O preço padrão e o preço por jogo em casa são persistidos; a prévia de público e receita usa o mesmo modelo de demanda da partida. A IA avalia preços candidatos. Cada jogo oficial lança receita bruta e despesa operacional no livro financeiro e atualiza o saldo na transação da progressão. Há painel financeiro, histórico e proteção contra duplicação. Consulte [documentação técnica](../../docs/ingressos-e-financas.md). Investimentos em obras pertencem à Entrega 09.
