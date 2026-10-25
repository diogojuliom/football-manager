export const LIVE_MATCH_DURATION_MS = 5 * 60 * 1000;
export const LIVE_MATCH_MINUTES = 90;
export const LIVE_MATCH_SPEEDS = Object.freeze([0.5, 0.75, 1, 1.25, 1.5, 1.75, 2]);

export const liveMinuteDuration = (speed) => {
    const parsed = Number(speed);
    if (!LIVE_MATCH_SPEEDS.includes(parsed)) throw new RangeError('Velocidade de reprodução inválida.');

    return LIVE_MATCH_DURATION_MS / LIVE_MATCH_MINUTES / parsed;
};

/**
 * Agenda os minutos pelo tempo monotônico esperado. O tempo gasto na requisição
 * não é somado à duração da partida, e o relógio nunca altera o estado esportivo.
 */
export class LiveMatchClock {
    constructor(onMinute, dependencies = {}) {
        this.onMinute = onMinute;
        this.now = dependencies.now || (() => performance.now());
        this.setTimer = dependencies.setTimer || ((callback, delay) => setTimeout(callback, delay));
        this.clearTimer = dependencies.clearTimer || ((timer) => clearTimeout(timer));
        this.timer = null;
        this.running = false;
        this.speed = 1;
        this.nextMinuteAt = null;
        this.generation = 0;
    }

    start(speed = this.speed) {
        this.speed = this.validate(speed);
        this.running = true;
        this.generation++;
        this.nextMinuteAt = this.now() + liveMinuteDuration(this.speed);
        this.schedule();
    }

    stop() {
        this.running = false;
        this.generation++;
        this.nextMinuteAt = null;
        if (this.timer !== null) this.clearTimer(this.timer);
        this.timer = null;
    }

    changeSpeed(speed) {
        this.speed = this.validate(speed);
        if (this.running) this.start(this.speed);
    }

    validate(speed) {
        const parsed = Number(speed);
        if (!LIVE_MATCH_SPEEDS.includes(parsed)) throw new RangeError('Velocidade de reprodução inválida.');

        return parsed;
    }

    schedule() {
        if (!this.running) return;
        if (this.timer !== null) this.clearTimer(this.timer);
        const delay = Math.max(0, this.nextMinuteAt - this.now());
        const generation = this.generation;
        this.timer = this.setTimer(async () => {
            this.timer = null;
            const keepRunning = await this.onMinute();
            if (generation !== this.generation) return;
            if (!this.running || !keepRunning) {
                this.stop();
                return;
            }
            this.nextMinuteAt += liveMinuteDuration(this.speed);
            this.schedule();
        }, delay);
    }
}
