# Entrega 11 — Interface e experiência

Implementar:

- Dashboard completo.
- Controles de progressão.
- Interface de pré-jogo.
- Interface de partidas.
- Estádio.
- Obras.
- Ingressos.
- Finanças.
- Estatísticas.
- Histórico de temporadas.
- Responsividade.

**Aceite:** todas as funcionalidades do MVP poderão ser utilizadas pela interface web sem operações manuais no banco.

---

## Referências e conclusão

Escopo e critérios de aceite extraídos da seção 17 do [PRD original](../PRD.md). Consultar também as regras detalhadas do PRD, o plano de testes (seção 18), os critérios de aceite do MVP (seção 19), os limites de escopo (seção 20) e a definição de pronto (seção 21).

**Estado:** implementada e validada.

### Evidências da implementação

- O painel da carreira reúne data interna, temporada, estado, próximo jogo, mando, dias restantes, classificação, condição do elenco, caixa, estádio, obras e acontecimentos.
- A progressão oferece a ação principal **Próxima partida**, as ações secundárias previstas e a transição para o pré-jogo, sempre com bloqueio contra reenvio e feedback de processamento.
- Todas as áreas do núcleo esportivo e administrativo estão disponíveis pela navegação contextual: elenco e táticas, calendário, classificação, estatísticas, partida, estádio, obras, ingressos, finanças, amistosos e histórico de temporadas.
- Pré-jogo e pós-jogo apresentam confronto, público, ingressos, confirmação, placar, estatísticas coletivas e individuais, eventos e decisões da IA.
- O sistema visual usa componentes e padrões compartilhados para painéis, indicadores, tabelas, formulários, avisos e estados vazios, com menu adaptável, navegação por teclado, foco visível, regiões semânticas e suporte a movimento reduzido.
- Formulários de progressão, partidas, escalação, ingressos, obras e amistosos exibem estado de carregamento e impedem múltiplos envios no navegador, mantendo a idempotência no servidor como garantia definitiva.
- A cobertura automatizada de interface verifica o painel completo e o acesso web a todas as áreas de gestão; a suíte funcional existente valida as regras preservadas.
