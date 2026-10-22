# Entrega 07 — Estádios e torcida

Implementar:

- Cadastro e estado dos estádios.
- Capacidade disponível.
- Torcida potencial.
- Fidelidade e engajamento.
- Importância das partidas.
- Previsão de demanda.
- Público realizado.
- Influência moderada do apoio da torcida.
- Histórico de público.

**Aceite:** cada partida em casa produzirá público coerente com sua demanda e capacidade.

---

## Referências e conclusão

Escopo e critérios de aceite extraídos da seção 17 do [PRD original](../PRD.md). Consultar também as regras detalhadas do PRD, o plano de testes (seção 18), os critérios de aceite do MVP (seção 19), os limites de escopo (seção 20) e a definição de pronto (seção 21).

**Estado:** implementada. Estádio e torcida são copiados para cada carreira. A demanda versionada considera torcida potencial, fidelidade, engajamento, fase, classificação, adversário, rivalidade, importância, preço e infraestrutura. Cada partida oficial arquiva previsão, público, capacidade e configuração utilizados; os amistosos preservam o contexto no snapshot. O pré-jogo, o resultado e a página do estádio exibem os dados. O apoio da torcida afeta moderadamente o mando e o engajamento muda gradualmente após jogos oficiais. Consulte [documentação técnica](../../docs/estadios-e-torcida.md).

Bilheteria financeira e edição de preços foram integradas na Entrega 08; obras e ampliação pertencem à Entrega 09.
