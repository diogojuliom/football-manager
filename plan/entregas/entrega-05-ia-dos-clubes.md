# Entrega 05 — IA dos clubes

Implementar:

- Escalação automática.
- Estratégias.
- Substituições.
- Reações durante a partida.
- Regras iniciais de administração dos clubes.

**Aceite:** duas equipes controladas pela IA poderão disputar partidas completas.

---

## Referências e conclusão

Escopo e critérios de aceite extraídos da seção 17 do [PRD original](../PRD.md). Consultar também as regras detalhadas do PRD, o plano de testes (seção 18), os critérios de aceite do MVP (seção 19), os limites de escopo (seção 20) e a definição de pronto (seção 21).

**Estado:** implementada em 21/10/2026.

## Implementação e evidências

- Escalação automática nas quatro formações, considerando atributos, posições, condição física, moral e disponibilidade, sem inventar jogadores.
- Estratégias iniciais por força relativa e mando; reações ao placar, expulsões e desgaste; substituições com limites, intervalo e motivos registrados.
- Amistosos entre dois clubes da IA, sem exigir escalação humana salva, e adversário adaptativo nos amistosos do usuário.
- Recomendações administrativas de preços, procura, reserva operacional e investimentos limitados ao saldo, com regras para propostas de obras. Cobranças e execução de obras serão integradas nas Entregas 08/09.
- Motor V2 e política de IA versionados; preservados o motor V1, os resultados antigos e a idempotência das solicitações existentes.
- 92 testes aprovados em SQLite e 72 testes funcionais aprovados em MySQL 8.0.46. Fluxo HTTP com cookies/CSRF confirmou uma partida completa entre IAs, repetição segura e consulta após nova autenticação.

**Aceite atendido:** duas equipes controladas pela IA disputam partidas completas de 90 minutos. Os amistosos preservam o estado oficial; partidas da liga e progressão autorizada seguem na Entrega 06.

Consulte [a documentação da IA](../../docs/ia-dos-clubes.md) para regras, integração, compatibilidade e limites desta etapa.
