# Entrega 04 — Motor de partidas

Implementar:

- Motor determinístico.
- Probabilidades.
- Posse.
- Oportunidades.
- Finalizações.
- Gols.
- Cartões.
- Substituições.
- Fadiga.
- Estatísticas.

O motor deverá aceitar um contexto de mando de campo, permitindo integrar posteriormente a influência do público.

**Aceite:** partidas reproduzíveis, com eventos e estatísticas coerentes.

---

## Referências e conclusão

Escopo e critérios de aceite extraídos da seção 17 do [PRD original](../PRD.md). Consultar também as regras detalhadas do PRD, o plano de testes (seção 18), os critérios de aceite do MVP (seção 19), os limites de escopo (seção 20) e a definição de pronto (seção 21).

**Estado:** implementada em 21/10/2026.


## Registro da implementação

- Motor puro e determinístico V1, com seed local, validação de entrada e referência fixa de replay.
- Eventos cronológicos de posse, construção, oportunidades, finalizações, defesas, gols, assistências, faltas, cartões, expulsões, substituições e desgaste.
- Estatísticas reconciliadas por equipe e jogador, incluindo xG e condição física final.
- Influência de formação, táticas, atributos, posição, moral, condição física e contexto limitado de mando/torcida.
- Amistosos autorizados por carreira com snapshots persistidos, resultados consultáveis e proteção contra duplicação.

**Validação:** 74 testes na suíte SQLite; 64 testes funcionais em MySQL 8.0.46; 2.000 partidas sintéticas, 100 seeds com invariantes detalhadas, replay após persistência e fluxo HTTP com CSRF. Migração e rollback verificados.

Os amistosos preservam o estado oficial da carreira. IA reativa, execução de partidas da liga no calendário, público dinâmico e consolidação financeira serão integrados nas entregas correspondentes. O motor já aceita o contexto de público consolidado sem depender dessas funcionalidades.

Consulte [Motor de partidas: entrada, regras, versão e validação](../../docs/motor-de-partidas.md).
