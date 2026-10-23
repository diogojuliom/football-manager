const readModel = (board) => {
    const source = board.querySelector('[data-tactical-model]');

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
        this.model = readModel(board);
        this.markers = [...board.querySelectorAll('[data-pitch-player]')];
        this.currentFormation = board.dataset.currentFormation;
    }

    initialize() {
        if (!this.model) return;

        const formationSelect = document.querySelector('[data-formation-select]');
        formationSelect?.addEventListener('change', () => this.previewFormation(formationSelect.value));

        document.querySelectorAll('[data-starter-select]').forEach((select) => {
            select.addEventListener('change', () => this.updatePlayer(Number(select.dataset.starterSelect), select.selectedOptions[0]));
        });
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
        this.board.querySelector('[data-pitch]')?.setAttribute('aria-label', `Representação visual da formação ${formationId}`);

        Object.entries(formation.profile).forEach(([name, value]) => {
            const output = document.querySelector(`[data-formation-profile="${name}"]`);
            if (output) output.textContent = String(Math.round(value * 100));
        });

        const status = this.board.querySelector('[data-tactical-status]');
        if (status) {
            status.textContent = formationId === this.currentFormation
                ? `Formação ${formationId} aplicada.`
                : `Prévia da formação ${formationId}. Use “Aplicar formação” para recalcular a escalação.`;
        }
    }

    updatePlayer(slot, option) {
        const marker = this.markers[slot];
        if (!marker) return;

        marker.dataset.playerId = option?.value || '';
        marker.querySelector('[data-pitch-name]').textContent = option?.dataset.playerName || 'Escolha atleta';

        const fitness = Number(option?.dataset.playerFitness);
        const normalizedFitness = Number.isFinite(fitness) ? Math.min(100, Math.max(0, fitness)) : null;
        const condition = marker.querySelector('[data-pitch-fitness]');
        condition.hidden = normalizedFitness === null;

        if (normalizedFitness !== null) {
            condition.querySelector('i').style.setProperty('--fitness', `${normalizedFitness}%`);
            condition.querySelector('b').textContent = `${normalizedFitness}%`;
        }

        this.updateAccessibleName(marker);
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
