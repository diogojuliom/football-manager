import { LiveMatchClock, LIVE_MATCH_SPEEDS } from './live-match-clock.js';

const readJson = (root, selector) => JSON.parse(root.querySelector(selector).textContent);
const activePlayers = (team) => Object.values(team.active);
const uuid = () => crypto.randomUUID();
export const formatMatchClock = (item) => {
    const added = Number(item.added_minute || 0);
    return added > 0 ? `${item.minute}+${added}'` : `${item.minute}'`;
};

class LiveMatch {
    constructor(root) {
        this.root = root;
        this.payload = readJson(root, '[data-live-payload]');
        this.tacticalModel = readJson(root, '[data-live-tactics]');
        this.side = root.dataset.humanSide;
        this.speed = 1;
        this.clock = new LiveMatchClock(() => this.advance());
        this.busy = false;
        this.selected = null;
        this.csrf = document.querySelector('meta[name="csrf-token"]').content;
    }

    initialize() {
        this.root.querySelector('[data-live-toggle]').addEventListener('click', () => this.toggle());
        this.root.querySelector('[data-live-speed]').addEventListener('change', (event) => {
            const speed = Number(event.target.value);
            if (!LIVE_MATCH_SPEEDS.includes(speed)) return;
            this.speed = speed;
            this.clock.changeSpeed(speed);
            this.renderStatus();
        });
        this.root.querySelector('[data-live-finish]').addEventListener('click', () => this.finish());
        this.root.querySelector('[data-live-tactics-form]').addEventListener('submit', (event) => this.applyTactics(event));
        this.root.addEventListener('click', (event) => {
            const player = event.target.closest('[data-live-player]');
            if (player) this.selectPlayer(player);
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') this.clearSelection();
        });
        this.render();
        if (!this.payload.paused && this.payload.status === 'running') this.clock.start(this.speed);
    }

    async toggle() {
        if (this.payload.status !== 'running' || this.busy) return;
        const action = this.payload.paused ? 'resume' : 'pause';
        await this.request(this.root.dataset.controlUrl, { action });
        if (this.payload.paused) this.clock.stop();
        else this.clock.start(this.speed);
    }

    async advance() {
        if (this.busy || this.payload.paused || this.payload.status !== 'running') return false;
        await this.request(this.root.dataset.advanceUrl, { minutes: 1 });
        return !this.payload.paused && this.payload.status === 'running';
    }

    async finish() {
        if (this.payload.status !== 'running' || this.busy || !window.confirm('Simular todos os minutos restantes sem novas intervenções?')) return;
        this.clock.stop();
        await this.request(this.root.dataset.finishUrl, {});
    }

    async applyTactics(event) {
        event.preventDefault();
        if (!this.requirePause()) return;
        const form = new FormData(event.currentTarget);
        await this.request(this.root.dataset.decisionUrl, {
            type: 'tactics',
            payload: Object.fromEntries(form.entries()),
        });
    }

    selectPlayer(button) {
        if (!this.requirePause()) return;
        if (!this.selected) {
            this.selected = button;
            button.classList.add('is-selected');
            button.setAttribute('aria-pressed', 'true');
            this.status(`${button.dataset.playerName} selecionado. Escolha outro atleta.`);
            return;
        }
        if (this.selected === button) {
            this.clearSelection();
            return;
        }
        const first = this.selected;
        this.clearSelection();
        if (first.dataset.unit === 'active' && button.dataset.unit === 'active') {
            const active = activePlayers(this.team());
            [active[Number(first.dataset.slot)], active[Number(button.dataset.slot)]] = [active[Number(button.dataset.slot)], active[Number(first.dataset.slot)]];
            this.request(this.root.dataset.decisionUrl, { type: 'positions', payload: { active } });
        } else if (first.dataset.unit !== button.dataset.unit) {
            const field = first.dataset.unit === 'active' ? first : button;
            const bench = first.dataset.unit === 'bench' ? first : button;
            this.request(this.root.dataset.decisionUrl, { type: 'substitution', payload: { out: field.dataset.playerId, in: bench.dataset.playerId } });
        } else {
            this.status('Selecione um atleta em campo e outro no banco para substituir.');
        }
    }

    requirePause() {
        if (this.payload.status !== 'running') return false;
        if (!this.payload.paused) {
            this.status('Pause a partida antes de alterar a equipe.');
            return false;
        }
        return !this.busy;
    }

    clearSelection() {
        this.selected?.classList.remove('is-selected');
        this.selected?.setAttribute('aria-pressed', 'false');
        this.selected = null;
    }

    async request(url, body) {
        if (this.busy) return;
        this.busy = true;
        this.status('Confirmando decisão no servidor…');
        try {
            const response = await fetch(url, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf },
                body: JSON.stringify({ ...body, request_id: uuid(), revision: this.payload.revision }),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'Não foi possível atualizar a partida.');
            this.payload = data;
            this.render();
        } catch (error) {
            this.status(error.message);
        } finally {
            this.busy = false;
        }
    }

    team() { return this.payload.state.teams[this.side]; }

    formatClock(item) {
        return formatMatchClock(item);
    }

    render() {
        const state = this.payload.state;
        const home = state.teams.home;
        const away = state.teams.away;
        this.root.querySelector('[data-live-clock]').textContent = this.formatClock(state);
        this.root.querySelector('[data-home-score]').textContent = home.stats.goals;
        this.root.querySelector('[data-away-score]').textContent = away.stats.goals;
        this.root.querySelector('[data-round-live]').textContent = `${home.stats.goals} × ${away.stats.goals}`;
        this.root.querySelector('[data-live-phase]').textContent = state.status === 'half_time' ? 'Intervalo' : (state.status === 'hydration_break' ? 'Parada técnica' : (this.payload.status === 'finished' ? 'Encerrada' : (this.payload.paused ? 'Pausada' : 'Em andamento')));
        const hydration = this.root.querySelector('[data-live-hydration]');
        hydration.hidden = state.status !== 'hydration_break';
        hydration.querySelector('[data-live-hydration-minute]').textContent = this.formatClock(state);
        const toggle = this.root.querySelector('[data-live-toggle]');
        toggle.textContent = this.payload.paused ? '▶ Continuar' : 'Ⅱ Pausar';
        toggle.disabled = this.payload.status !== 'running';
        this.root.querySelector('[data-live-finish]').disabled = this.payload.status !== 'running';
        this.root.querySelector('[data-live-finalize]').hidden = this.payload.status !== 'finished';
        this.root.querySelector('[data-live-formation]').textContent = this.team().tactics.formation;
        this.renderPitch();
        this.renderStats();
        this.renderEvents();
        this.syncTacticsForm();
        this.renderStatus();
    }

    renderPitch() {
        const team = this.team();
        const formation = this.tacticalModel.formations[team.tactics.formation];
        const active = activePlayers(team);
        const lineup = this.root.querySelector('[data-live-lineup]');
        lineup.replaceChildren();
        active.forEach((id, slot) => {
            const player = team.players[id];
            const position = formation.slots[slot] || formation.slots[formation.slots.length - 1];
            const button = document.createElement('button');
            button.type = 'button'; button.className = 'live-player';
            button.style.setProperty('--x', `${position.x}%`); button.style.setProperty('--y', `${position.y}%`);
            button.dataset.livePlayer = ''; button.dataset.unit = 'active'; button.dataset.slot = slot;
            button.dataset.playerId = id; button.dataset.playerName = player.name; button.setAttribute('aria-pressed', 'false');
            button.innerHTML = '<b></b><strong></strong><small></small><i><span></span></i>';
            button.querySelector('b').textContent = position.label;
            button.querySelector('strong').textContent = player.name;
            button.querySelector('small').textContent = `${Math.round(player.energy / 100)}% · nota ${this.rating(player)}${this.cards(player)}`;
            button.querySelector('i span').style.width = `${Math.round(player.energy / 100)}%`;
            lineup.append(button);
        });
        const bench = this.root.querySelector('[data-live-bench]');
        bench.replaceChildren();
        team.bench.forEach((id) => {
            const player = team.players[id];
            const button = document.createElement('button');
            button.type = 'button'; button.className = 'live-bench-player';
            button.dataset.livePlayer = ''; button.dataset.unit = 'bench'; button.dataset.playerId = id;
            button.dataset.playerName = player.name; button.setAttribute('aria-pressed', 'false');
            button.innerHTML = '<b></b><span><strong></strong><small></small></span><em></em>';
            button.querySelector('b').textContent = this.tacticalModel.positions[player.primary_position].label;
            button.querySelector('strong').textContent = player.name;
            button.querySelector('small').textContent = `Condição ${Math.round(player.energy / 100)}%${this.cards(player)}`;
            button.querySelector('em').textContent = this.rating(player);
            bench.append(button);
        });
    }

    rating(player) {
        const stats = player.stats;
        return Math.max(1, Math.min(10, 6 + stats.goals * 1.2 + stats.assists * .7 + stats.saves * .08 + stats.shots_on_target * .08 - stats.yellow_cards * .25 - stats.red_cards * 1.5)).toFixed(1);
    }

    cards(player) {
        if (player.stats.red_cards) return ' · 🟥';
        if (player.stats.yellow_cards) return ` · ${player.stats.yellow_cards > 1 ? '🟨🟨' : '🟨'}`;
        return player.injured ? ' · lesionado' : '';
    }

    renderStats() {
        const home = this.payload.state.teams.home.stats;
        const away = this.payload.state.teams.away.stats;
        const ticks = Math.max(1, home.possession_ticks + away.possession_ticks);
        const rows = [['Gols', 'goals'], ['Finalizações', 'shots'], ['No alvo', 'shots_on_target'], ['Oportunidades', 'opportunities'], ['Faltas', 'fouls'], ['Amarelos', 'yellow_cards'], ['Vermelhos', 'red_cards']];
        const area = this.root.querySelector('[data-live-stats]');
        area.replaceChildren(...rows.map(([label, key]) => {
            const row = document.createElement('div');
            row.innerHTML = '<b></b><span></span><b></b>'; row.children[0].textContent = home[key]; row.children[1].textContent = label; row.children[2].textContent = away[key]; return row;
        }));
        const possession = document.createElement('div');
        possession.innerHTML = '<b></b><span>Posse</span><b></b>';
        possession.children[0].textContent = `${Math.round(100 * home.possession_ticks / ticks)}%`;
        possession.children[2].textContent = `${Math.round(100 * away.possession_ticks / ticks)}%`;
        area.prepend(possession);
    }

    renderEvents() {
        const relevant = ['kickoff', 'goal', 'opportunity', 'shot', 'foul', 'yellow_card', 'red_card', 'injury', 'substitution', 'tactical_change', 'hydration_break_start', 'hydration_break_end', 'added_time_announced', 'added_time_extended', 'half_time', 'full_time'];
        const labels = { kickoff: 'Bola rolando', goal: 'Gol', opportunity: 'Grande oportunidade', shot: 'Finalização', foul: 'Falta', yellow_card: 'Cartão amarelo', red_card: 'Cartão vermelho', injury: 'Lesão', substitution: 'Substituição', tactical_change: 'Mudança tática', hydration_break_start: 'Início da parada para hidratação', hydration_break_end: 'Fim da parada para hidratação', added_time_announced: 'Acréscimos anunciados', added_time_extended: 'Acréscimos prolongados', half_time: 'Intervalo', full_time: 'Fim de jogo' };
        const events = this.payload.state.events.filter((event) => relevant.includes(event.type)).slice(-30).reverse();
        const list = this.root.querySelector('[data-live-events]');
        list.replaceChildren(...events.map((event) => {
            const item = document.createElement('li');
            const team = event.side ? this.payload.state.teams[event.side] : null;
            const player = team && event.player_id ? team.players[event.player_id] : null;
            item.innerHTML = '<time></time><span><strong></strong><small></small></span>';
            item.querySelector('time').textContent = this.formatClock(event);
            item.querySelector('strong').textContent = labels[event.type];
            const addedTime = event.type === 'added_time_announced'
                ? `Mais ${event.data.minutes} minuto${event.data.minutes === 1 ? '' : 's'} de acréscimos`
                : (event.type === 'added_time_extended' ? `Prorrogado para ${event.data.minutes} minuto${event.data.minutes === 1 ? '' : 's'}` : null);
            item.querySelector('small').textContent = addedTime || [team?.name, player?.name].filter(Boolean).join(' · ') || 'Partida';
            return item;
        }));
    }

    syncTacticsForm() {
        const form = this.root.querySelector('[data-live-tactics-form]');
        Object.entries(this.team().tactics).forEach(([field, value]) => { if (form.elements[field]) form.elements[field].value = value; });
        [...form.elements].forEach((field) => { field.disabled = !this.payload.paused || this.payload.status !== 'running'; });
    }

    renderStatus() {
        const state = this.payload.state;
        this.status(state.status === 'half_time' ? 'Intervalo: faça seus ajustes e clique em continuar.' : (state.status === 'hydration_break' ? 'Parada técnica para hidratação: faça seus ajustes e clique em continuar partida.' : (this.payload.status === 'finished' ? 'Fim de jogo. Registre o resultado para atualizar a carreira.' : (this.payload.paused ? 'Partida pausada. Faça ajustes ou continue.' : `Simulação em ${String(this.speed).replace('.', ',')}×.`))));
    }

    status(message) { this.root.querySelector('[data-live-status]').textContent = message; }
}

export const initializeLiveMatches = () => document.querySelectorAll('[data-live-match]').forEach((root) => new LiveMatch(root).initialize());
