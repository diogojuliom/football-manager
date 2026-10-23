# Entrega 10 — Temporadas consecutivas

Implementar:

- Consolidação de temporada.
- Preservação de histórico.
- Nova edição da liga.
- Novo calendário.
- Evolução básica do elenco.
- Persistência de estádio, torcida e finanças.
- Continuidade de obras.
- Integração com o calendário interno.
- Confirmação do usuário para iniciar a próxima temporada.

**Aceite:** será possível disputar no mínimo três temporadas sequenciais sem perda de dados nem reinicialização financeira ou administrativa.

---

## Referências e conclusão

Escopo e critérios de aceite extraídos da seção 17 do [PRD original](../PRD.md). Consultar também as regras detalhadas do PRD, o plano de testes (seção 18), os critérios de aceite do MVP (seção 19), os limites de escopo (seção 20) e a definição de pronto (seção 21).

**Estado:** implementada e validada. O encerramento congela classificação, campeão, artilharia e resultados. A continuidade exige confirmação explícita e usa uma operação transacional retomável para criar a temporada, a edição, as inscrições e o calendário seguintes sem duplicidades. Clube, elenco, estádio, torcida, saldo, histórico financeiro e obras permanecem na carreira. Atletas envelhecem por uma política determinística versionada; evolução, declínio, aposentadoria aos 40 anos e reposição da base mantêm elencos válidos. O calendário não avança pelo tempo real.
