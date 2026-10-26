const readJson = (root, selector) => {
    const source = root.querySelector(selector);

    if (!source) return null;

    try {
        return JSON.parse(source.textContent);
    } catch {
        return null;
    }
};

const normalizedCoordinate = (value, model) => {
    const minimum = model.coordinateSystem?.min ?? 0;
    const maximum = model.coordinateSystem?.max ?? 100;

    return Math.min(maximum, Math.max(minimum, Number(value)));
};

export class TacticalBoard {
    constructor(board) {
        this.board = board;
        this.model = readJson(board, '[data-tactical-model]');
        this.players = readJson(board, '[data-squad-players]') || {};
        this.markers = [...board.querySelectorAll('[data-pitch-player]')];
        this.starterSelects = [...document.querySelectorAll('[data-starter-select]')];
        this.reserveSelects = [...document.querySelectorAll('[data-reserve-select]')];
        this.currentFormation = board.dataset.currentFormation;
        this.selectedCard = null;
        this.pointerCard = null;
        this.focusCard = null;
        this.tooltip = board.querySelector('[data-player-skills-tooltip]');
        this.editable = this.starterSelects.some((select) => !select.disabled);
    }

    initialize() {
        if (!this.model) return;

        const formationSelect = document.querySelector('[data-formation-select]');
        formationSelect?.addEventListener('change', () => this.previewFormation(formationSelect.value));

        [...this.starterSelects, ...this.reserveSelects].forEach((select) => {
            select.dataset.previousValue = select.value;
            select.addEventListener('change', () => this.handleSelectChange(select));
        });

        this.board.addEventListener('click', (event) => {
            const card = event.target.closest('[data-squad-player]');
            if (card && this.board.contains(card)) this.handlePlayerClick(card);
        });
        this.board.addEventListener('pointerover', (event) => {
            const card = event.target.closest('[data-squad-player]');
            if (!card || card.contains(event.relatedTarget)) return;
            this.pointerCard = card;
            this.refreshTooltip();
        });
        this.board.addEventListener('pointerout', (event) => {
            const card = event.target.closest('[data-squad-player]');
            if (!card || card.contains(event.relatedTarget)) return;
            if (this.pointerCard === card) this.pointerCard = null;
            this.refreshTooltip();
        });
        this.board.addEventListener('focusin', (event) => {
            const card = event.target.closest('[data-squad-player]');
            if (!card) return;
            this.focusCard = card;
            this.refreshTooltip();
        });
        this.board.addEventListener('focusout', (event) => {
            const card = event.target.closest('[data-squad-player]');
            if (!card || card.contains(event.relatedTarget)) return;
            if (this.focusCard === card) this.focusCard = null;
            this.refreshTooltip();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                this.pointerCard = null;
                this.focusCard = null;
                this.hideTooltip();
                if (this.selectedCard) {
                    this.clearSelection();
                    this.announce('Seleção cancelada.');
                }
            }
        });
        window.addEventListener('resize', () => this.refreshTooltip());
        window.addEventListener('scroll', () => this.refreshTooltip(), true);

        this.syncInterface();
    }

    previewFormation(formationId) {
        const formation = this.model.formations?.[formationId];

        if (!formation || formation.slots.length !== this.markers.length) return;

        formation.slots.forEach((slot, index) => {
            const marker = this.markers[index];
            const x = normalizedCoordinate(slot.x, this.model);
            const y = normalizedCoordinate(slot.y, this.model);

            marker.style.setProperty('--pitch-x', `${x}%`);
            marker.style.setProperty('--pitch-y', `${y}%`);
            marker.dataset.x = String(x);
            marker.dataset.y = String(y);
            marker.dataset.role = slot.role;
            marker.querySelector('[data-pitch-position]').textContent = slot.label;
            marker.querySelector('[data-pitch-position]').title = slot.name;
            this.updateAccessibleName(marker);
        });

        this.board.dataset.previewFormation = formationId;
        this.board.querySelector('[data-board-title]').textContent = `Distribuição em campo — ${formationId}`;
        this.board.querySelector('[data-pitch]')?.setAttribute('aria-label', `Titulares da formação ${formationId}`);

        Object.entries(formation.profile).forEach(([name, value]) => {
            const output = document.querySelector(`[data-formation-profile="${name}"]`);
            if (output) output.textContent = String(Math.round(value * 100));
        });

        this.announce(formationId === this.currentFormation
            ? `Formação ${formationId} aplicada.`
            : `Prévia da formação ${formationId}. Use “Aplicar formação” para recalcular a escalação.`);
    }

    handlePlayerClick(card) {
        if (!this.editable || card.disabled || !card.dataset.playerId) return;

        if (!this.selectedCard) {
            this.selectedCard = card;
            card.classList.add('is-selected');
            card.setAttribute('aria-pressed', 'true');
            this.announce(`${this.player(card.dataset.playerId).name} selecionado. Escolha o segundo atleta ou pressione Esc para cancelar.`);

            return;
        }

        if (this.selectedCard === card) {
            this.clearSelection();
            this.announce('Seleção cancelada.');

            return;
        }

        const first = this.selection(this.selectedCard);
        const second = this.selection(card);
        const result = this.prepareSwap(first, second);
        this.clearSelection();

        if (!result.ok) {
            this.announce(result.message);

            return;
        }

        this.syncInterface();
        this.announce(`Troca preparada: ${this.player(first.playerId).name} e ${this.player(second.playerId).name}. Salve a escalação para confirmar.`);
    }

    selection(card) {
        return {
            unit: card.dataset.unit,
            slot: card.dataset.slot === undefined ? null : Number(card.dataset.slot),
            playerId: card.dataset.playerId,
        };
    }

    prepareSwap(first, second) {
        if (first.unit === 'starter' && second.unit === 'starter') {
            return this.swapSelectValues(this.starterSelects[first.slot], this.starterSelects[second.slot]);
        }

        const starter = first.unit === 'starter' ? first : (second.unit === 'starter' ? second : null);
        const reserve = first.unit === 'reserve' ? first : (second.unit === 'reserve' ? second : null);
        const outside = first.unit === 'outside' ? first : (second.unit === 'outside' ? second : null);

        if (starter && reserve && !outside) {
            return this.swapSelectValues(this.starterSelects[starter.slot], this.reserveSelects[reserve.slot]);
        }

        if (reserve && outside && !starter) {
            return this.assignSelect(this.reserveSelects[reserve.slot], outside.playerId);
        }

        return { ok: false, message: 'Essa troca não é permitida. Troque titulares entre si, titular com reserva ou reserva com atleta fora da convocação.' };
    }

    swapSelectValues(first, second) {
        const firstValue = first.value;
        const secondValue = second.value;

        if (!this.canSelect(first, secondValue) || !this.canSelect(second, firstValue)) {
            return { ok: false, message: 'A troca não respeita a vaga de goleiro ou envolve um atleta indisponível.' };
        }

        first.value = secondValue;
        second.value = firstValue;
        first.dataset.previousValue = first.value;
        second.dataset.previousValue = second.value;

        return { ok: true };
    }

    assignSelect(select, playerId) {
        if (!this.canSelect(select, playerId)) {
            return { ok: false, message: 'O atleta não está disponível para ocupar essa vaga.' };
        }

        select.value = playerId;
        select.dataset.previousValue = select.value;

        return { ok: true };
    }

    canSelect(select, playerId) {
        const option = [...select.options].find((candidate) => candidate.value === String(playerId));
        if (!option || option.disabled) return false;

        if (select.matches('[data-starter-select]')) {
            const player = this.player(playerId);
            return Boolean(player?.isGoalkeeper) === (Number(select.dataset.starterSelect) === 0);
        }

        return true;
    }

    handleSelectChange(select) {
        const values = [...this.starterSelects, ...this.reserveSelects].map((field) => field.value).filter(Boolean);
        const duplicate = new Set(values).size !== values.length;
        const invalidStarter = this.starterSelects.some((field, slot) => {
            const player = this.player(field.value);
            return !player || Boolean(player.isGoalkeeper) !== (slot === 0);
        });

        if (duplicate || invalidStarter) {
            select.value = select.dataset.previousValue || '';
            this.announce(duplicate ? 'Esse atleta já ocupa outra vaga.' : 'A primeira vaga exige goleiro e as demais exigem jogadores de linha.');
        } else {
            select.dataset.previousValue = select.value;
            this.announce('Escalação atualizada no formulário. Salve para confirmar.');
        }

        this.clearSelection();
        this.syncInterface();
    }

    syncInterface() {
        this.starterSelects.forEach((select, slot) => this.updatePlayer(slot, select.selectedOptions[0]));
        this.reserveSelects.forEach((select, slot) => this.updateBench(slot, select.value));
        this.renderOutsidePlayers();
        const count = this.reserveSelects.filter((select) => select.value).length;
        const counter = this.board.querySelector('[data-bench-count]');
        if (counter) counter.textContent = `${count}/${this.reserveSelects.length}`;
        this.refreshTooltip();
    }

    updatePlayer(slot, option) {
        const marker = this.markers[slot];
        if (!marker) return;

        const player = this.player(option?.value);
        marker.dataset.playerId = player?.id || '';
        marker.disabled = !this.editable || !player;
        marker.querySelector('[data-pitch-name]').textContent = player?.name || 'Escolha atleta';

        const condition = marker.querySelector('[data-pitch-fitness]');
        condition.hidden = !player;
        if (player) {
            condition.querySelector('i').style.setProperty('--fitness', `${player.fitness}%`);
            condition.querySelector('b').textContent = `${player.fitness}%`;
        }

        this.updateAccessibleName(marker);
    }

    updateBench(slot, playerId) {
        const card = this.board.querySelector(`[data-unit="reserve"][data-slot="${slot}"]`);
        if (!card) return;

        const player = this.player(playerId);
        card.dataset.playerId = player?.id || '';
        card.disabled = !this.editable || !player;
        this.fillCard(card, player, player ? `Condição ${player.fitness}%` : 'Sem jogador relacionado');
        card.setAttribute('aria-label', player ? `Reserva ${slot + 1}: ${player.name}, ${player.positionName}, condição ${player.fitness}%` : `Reserva ${slot + 1}: vaga livre`);
    }

    renderOutsidePlayers() {
        const selected = new Set([...this.starterSelects, ...this.reserveSelects].map((select) => select.value).filter(Boolean));
        const outside = Object.values(this.players).filter((player) => !selected.has(player.id));
        const list = this.board.querySelector('[data-outside-list]');
        if (!list) return;

        list.replaceChildren();
        outside.forEach((player) => list.append(this.createOutsideCard(player)));
        if (outside.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'empty-squad-list';
            empty.textContent = 'Todo o elenco disponível está relacionado.';
            list.append(empty);
        }
        const counter = this.board.querySelector('[data-outside-count]');
        if (counter) counter.textContent = String(outside.length);
    }

    createOutsideCard(player) {
        const card = document.createElement('button');
        card.className = 'squad-player-card';
        card.type = 'button';
        card.dataset.squadPlayer = '';
        card.dataset.unit = 'outside';
        card.dataset.playerId = player.id;
        card.setAttribute('aria-pressed', 'false');
        card.disabled = !this.editable || !player.available;
        card.innerHTML = '<span class="squad-player-position" data-card-position></span><span class="squad-player-copy"><strong data-card-name></strong><small data-card-meta></small><i><span data-card-fitness></span></i></span><b data-card-fitness-label></b>';
        this.fillCard(card, player, player.available ? `Condição ${player.fitness}%` : 'Indisponível');
        card.setAttribute('aria-label', `${player.name}, ${player.positionName}, ${player.available ? `condição ${player.fitness}%` : 'indisponível'}`);

        return card;
    }

    fillCard(card, player, meta) {
        card.querySelector('[data-card-position]').textContent = player?.positionLabel || '—';
        card.querySelector('[data-card-position]').title = player?.positionName || '';
        card.querySelector('[data-card-name]').textContent = player?.name || 'Vaga livre';
        card.querySelector('[data-card-meta]').textContent = meta;
        card.querySelector('[data-card-fitness]').style.setProperty('--fitness', `${player?.fitness || 0}%`);
        card.querySelector('[data-card-fitness-label]').textContent = player ? `${player.fitness}%` : '—';
    }

    player(playerId) {
        return this.players[String(playerId)] || null;
    }

    refreshTooltip() {
        const card = [this.focusCard, this.pointerCard].find((candidate) => candidate?.isConnected && candidate.dataset.playerId);
        const player = card ? this.player(card.dataset.playerId) : null;
        if (!this.tooltip || !card || !player) {
            this.hideTooltip();

            return;
        }

        if (this.tooltipCard && this.tooltipCard !== card) this.tooltipCard.removeAttribute('aria-describedby');
        this.tooltipCard = card;
        if (!this.tooltip.id) this.tooltip.id = 'squad-player-skills';
        card.setAttribute('aria-describedby', this.tooltip.id);
        this.tooltip.querySelector('[data-skills-player-name]').textContent = player.name;
        this.tooltip.querySelector('[data-skills-player-meta]').textContent = `${player.positionLabel} · Condição ${player.fitness}%`;
        const list = this.tooltip.querySelector('[data-player-skills]');
        list.replaceChildren(...(player.attributes || []).map((attribute) => {
            const item = document.createElement('div');
            const label = document.createElement('dt');
            const value = document.createElement('dd');
            label.textContent = attribute.label;
            value.textContent = String(attribute.value);
            item.append(label, value);

            return item;
        }));
        this.tooltip.hidden = false;
        this.positionTooltip(card);
    }

    positionTooltip(card) {
        const cardRect = card.getBoundingClientRect();
        const tooltipRect = this.tooltip.getBoundingClientRect();
        const margin = 12;
        const centered = cardRect.left + cardRect.width / 2 - tooltipRect.width / 2;
        const left = Math.min(window.innerWidth - tooltipRect.width - margin, Math.max(margin, centered));
        const below = cardRect.bottom + 8;
        const top = below + tooltipRect.height <= window.innerHeight - margin
            ? below
            : Math.max(margin, cardRect.top - tooltipRect.height - 8);
        this.tooltip.style.left = `${Math.round(left)}px`;
        this.tooltip.style.top = `${Math.round(top)}px`;
    }

    hideTooltip() {
        if (!this.tooltip) return;
        this.tooltip.hidden = true;
        this.tooltipCard?.removeAttribute('aria-describedby');
        this.tooltipCard = null;
    }

    clearSelection() {
        if (!this.selectedCard) return;
        this.selectedCard.classList.remove('is-selected');
        this.selectedCard.setAttribute('aria-pressed', 'false');
        this.selectedCard = null;
    }

    announce(message) {
        const status = this.board.querySelector('[data-tactical-status]');
        if (status) status.textContent = message;
    }

    updateAccessibleName(marker) {
        const shirt = marker.querySelector('[data-pitch-shirt]')?.textContent;
        const position = marker.querySelector('[data-pitch-position]')?.title;
        const player = marker.querySelector('[data-pitch-name]')?.textContent;
        const condition = marker.querySelector('[data-pitch-fitness]');
        const fitness = condition?.hidden ? null : condition?.querySelector('b')?.textContent;
        marker.setAttribute('aria-label', `Vaga ${shirt}, ${position}: ${player}${fitness ? `, condição ${fitness}` : ''}`);
    }
}

export const initializeTacticalBoards = () => {
    document.querySelectorAll('[data-tactical-board]').forEach((board) => new TacticalBoard(board).initialize());
};
