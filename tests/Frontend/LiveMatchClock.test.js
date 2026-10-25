import assert from 'node:assert/strict';
import test from 'node:test';
import {
    LIVE_MATCH_DURATION_MS,
    LIVE_MATCH_SPEEDS,
    LiveMatchClock,
    liveMinuteDuration,
} from '../../resources/js/live-match-clock.js';

class FakeTimers {
    constructor() {
        this.time = 0;
        this.sequence = 0;
        this.timers = new Map();
    }

    now = () => this.time;
    setTimer = (callback, delay) => {
        const id = ++this.sequence;
        this.timers.set(id, { at: this.time + delay, callback });
        return id;
    };
    clearTimer = (id) => this.timers.delete(id);

    async next() {
        const [id, timer] = [...this.timers.entries()].sort((left, right) => left[1].at - right[1].at)[0] || [];
        if (!timer) return false;
        this.timers.delete(id);
        this.time = timer.at;
        await timer.callback();
        return true;
    }
}

test('as sete velocidades têm a duração nominal correta e 1× dura cinco minutos', () => {
    assert.deepEqual(LIVE_MATCH_SPEEDS, [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2]);
    for (const speed of LIVE_MATCH_SPEEDS) {
        assert.ok(Math.abs(liveMinuteDuration(speed) * 90 - LIVE_MATCH_DURATION_MS / speed) < 0.001);
    }
    assert.equal(liveMinuteDuration(1) * 90, 300_000);
    assert.throws(() => liveMinuteDuration(3), RangeError);
});

test('latência das requisições não é acumulada no ritmo dos 90 minutos', async () => {
    const timers = new FakeTimers();
    let minute = 0;
    const clock = new LiveMatchClock(async () => {
        minute++;
        timers.time += 250;
        return minute < 90;
    }, timers);

    clock.start(1);
    while (await timers.next()) {}

    assert.equal(minute, 90);
    assert.ok(Math.abs(timers.time - (LIVE_MATCH_DURATION_MS + 250)) < 0.001);
    assert.equal(clock.running, false);
});

test('pausa congela o relógio e retomada cria um novo prazo completo', async () => {
    const timers = new FakeTimers();
    let minute = 0;
    const clock = new LiveMatchClock(async () => { minute++; return true; }, timers);

    clock.start(1);
    await timers.next();
    clock.stop();
    timers.time += 120_000;
    assert.equal(await timers.next(), false);
    assert.equal(minute, 1);

    clock.start(1);
    const resumedAt = timers.time;
    await timers.next();
    assert.equal(minute, 2);
    assert.ok(Math.abs(timers.time - resumedAt - liveMinuteDuration(1)) < 0.001);
    clock.stop();
});

test('alterar a velocidade reinicia somente o prazo de apresentação', async () => {
    const timers = new FakeTimers();
    let minute = 0;
    const clock = new LiveMatchClock(async () => { minute++; return true; }, timers);

    clock.start(0.5);
    clock.changeSpeed(2);
    const changedAt = timers.time;
    await timers.next();

    assert.equal(minute, 1);
    assert.equal(timers.time - changedAt, liveMinuteDuration(2));
    clock.stop();
});
