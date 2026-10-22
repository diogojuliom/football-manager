# Entrega 09 — Obras e infraestrutura

Implementar:

- Ampliação.
- Modernização.
- Custos.
- Prazos.
- Status.
- Conclusão automática dentro de progressões autorizadas.
- Impacto na capacidade e infraestrutura.
- Investimentos dos clubes controlados pela IA.

**Aceite:** será possível financiar uma obra, avançar os dias internos e receber seu benefício somente após a conclusão, sem depender de tempo real.

---

## Referências e conclusão

Escopo e critérios de aceite extraídos da seção 17 do [PRD original](../PRD.md). Consultar também as regras detalhadas do PRD, o plano de testes (seção 18), os critérios de aceite do MVP (seção 19), os limites de escopo (seção 20) e a definição de pronto (seção 21).

**Estado:** implementada. Projetos de ampliação e modernização são exclusivos do estádio de cada carreira, têm preço e prazo configuráveis, debitam o saldo ao iniciar e registram o investimento no livro financeiro. A conclusão acontece uma única vez durante a progressão autorizada do calendário interno, antes dos jogos da data, aplicando capacidade ou infraestrutura. A IA avalia investimentos no primeiro dia interno de cada mês, respeitando saldo, reserva financeira e obra ativa. A tela do estádio apresenta propostas, status e histórico.

**Limite atual:** projetos cuja conclusão ultrapasse o encerramento da primeira temporada permanecem ativos; o avanço para a temporada seguinte depende da Entrega 10.
