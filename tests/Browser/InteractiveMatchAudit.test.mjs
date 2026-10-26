import assert from 'node:assert/strict';
import test from 'node:test';
import puppeteer from 'puppeteer-core';

const baseUrl = process.env.FM_AUDIT_URL || 'http://127.0.0.1:18080';
const browserPath = process.env.FM_CHROMIUM_PATH || '/usr/bin/chromium';
const speedValues = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];

const pageText = (page, selector) => page.$eval(selector, (element) => element.textContent.trim());
const livePayload = (page) => page.$eval('[data-live-payload]', (element) => JSON.parse(element.textContent));
const waitForText = (page, selector, expected) => page.waitForFunction(
    (target, value) => document.querySelector(target)?.textContent.trim().includes(value),
    {}, selector, expected,
);

const attachDiagnostics = (page, diagnostics) => {
    page.on('pageerror', (error) => diagnostics.javascript.push(error.message));
    page.on('console', (message) => { if (message.type() === 'error') diagnostics.console.push(message.text()); });
    page.on('requestfailed', (request) => diagnostics.requests.push(`${request.method()} ${request.url()}: ${request.failure()?.errorText}`));
    page.on('response', (response) => { if (response.status() >= 400) diagnostics.responses.push(`${response.status()} ${response.url()}`); });
};

const installNetworkControl = async (page) => {
    const control = { disconnectNextAdvance: false };
    await page.setRequestInterception(true);
    page.on('request', (request) => {
        if (request.url().includes('fonts.googleapis.com') || request.url().includes('fonts.gstatic.com')) {
            request.abort('blockedbyclient');
        } else if (control.disconnectNextAdvance && request.url().includes('/live/advance')) {
            control.disconnectNextAdvance = false;
            request.abort('internetdisconnected');
        } else {
            request.continue();
        }
    });
    return control;
};

const navigateByClick = async (page, selector) => {
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click(selector)]);
};

const createReadyMatch = async (page, name) => {
    await page.goto(`${baseUrl}/careers/create`, { waitUntil: 'domcontentloaded' });
    await page.type('#name', name);
    await page.type('#coach_name', 'Treinadora Auditora');
    await page.click('[name=club_template_id]');
    await navigateByClick(page, '.career-submit');

    await navigateByClick(page, 'a[href$="/squad"]');
    await navigateByClick(page, '.squad-form button[type=submit]');
    await navigateByClick(page, 'a.breadcrumb[href*="/careers/"]');
    await navigateByClick(page, 'button[value=ADVANCE_TO_NEXT_MATCH]');
    await navigateByClick(page, '.career-main-action.amber');
    await navigateByClick(page, 'form[action*="/live/start"] button');
    await page.waitForSelector('[data-live-match]');
    assert.equal(await pageText(page, '[data-live-clock]'), "0'");
    assert.equal(await pageText(page, '[data-live-phase]'), 'Pausada');

    return page.url();
};

const advanceFromRenderedState = (page, targetMinute = null) => page.evaluate(async (target) => {
    const root = document.querySelector('[data-live-match]');
    const token = document.querySelector('meta[name="csrf-token"]').content;
    let payload = JSON.parse(root.querySelector('[data-live-payload]').textContent);
    while (payload.status === 'running' && !payload.paused && (target === null || payload.minute < target)) {
        const minutes = target === null ? 5 : Math.min(5, target - payload.minute);
        const response = await fetch(root.dataset.advanceUrl, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify({ minutes, request_id: crypto.randomUUID(), revision: payload.revision }),
        });
        payload = await response.json();
        if (!response.ok) throw new Error(JSON.stringify(payload));
    }
    return payload;
}, targetMinute);

const advanceUntilAutomaticPause = (page) => page.evaluate(async () => {
    const root = document.querySelector('[data-live-match]');
    const token = document.querySelector('meta[name="csrf-token"]').content;
    let payload = JSON.parse(root.querySelector('[data-live-payload]').textContent);
    while (payload.status === 'running' && !payload.paused) {
        const response = await fetch(root.dataset.advanceUrl, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify({ minutes: 5, request_id: crypto.randomUUID(), revision: payload.revision }),
        });
        payload = await response.json();
        if (!response.ok) throw new Error(JSON.stringify(payload));
    }
    return payload;
});

const assertResponsive = async (page, width, height) => {
    await page.setViewport({ width, height, deviceScaleFactor: 1 });
    await new Promise((resolve) => setTimeout(resolve, 80));
    const layout = await page.evaluate(() => ({
        viewport: document.documentElement.clientWidth,
        documentWidth: document.documentElement.scrollWidth,
        fieldPlayers: document.querySelectorAll('[data-live-lineup] [data-live-player]').length,
        controlsVisible: [...document.querySelectorAll('.live-controls button, .live-controls select')].every((control) => {
            const rectangle = control.getBoundingClientRect();
            return rectangle.width > 0 && rectangle.height > 0 && rectangle.left >= -1 && rectangle.right <= innerWidth + 1;
        }),
    }));
    assert.ok(layout.documentWidth <= layout.viewport, `Rolagem horizontal em ${width}px: ${layout.documentWidth}/${layout.viewport}`);
    assert.ok(layout.fieldPlayers >= 7 && layout.fieldPlayers <= 11);
    assert.equal(layout.controlsVisible, true);
    return layout;
};

test('fluxo interativo real permanece sincronizado, responsivo e recuperável', { timeout: 120_000 }, async () => {
    const diagnostics = { javascript: [], console: [], requests: [], responses: [] };
    const browser = await puppeteer.launch({
        executablePath: browserPath,
        headless: true,
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });
    const page = await browser.newPage();
    page.setDefaultTimeout(15_000);
    page.setDefaultNavigationTimeout(15_000);
    attachDiagnostics(page, diagnostics);
    const network = await installNetworkControl(page);
    const evidence = { layouts: {}, speeds: {}, matches: [] };

    try {
        await page.setViewport({ width: 1440, height: 1000, deviceScaleFactor: 1 });
        await page.goto(`${baseUrl}/register`, { waitUntil: 'domcontentloaded' });
        await page.type('[name=name]', 'Auditoria Final');
        await page.type('[name=email]', `audit-${Date.now()}@example.test`);
        await page.type('[name=password]', 'Password123!');
        await page.type('[name=password_confirmation]', 'Password123!');
        await navigateByClick(page, 'button[type=submit]');

        const liveUrl = await createReadyMatch(page, 'Fluxo incremental final');
        evidence.layouts.desktop = await assertResponsive(page, 1440, 1000);
        assert.equal(evidence.layouts.desktop.fieldPlayers, 11);
        const labels = await page.$$eval('[data-live-speed] option', (options) => options.map((option) => option.textContent.trim()));
        assert.deepEqual(labels, ['0,5×', '0,75×', '1×', '1,25×', '1,5×', '1,75×', '2×']);
        assert.equal(await page.$$eval('[data-live-lineup] b', (items) => items.every((item) => !['GK', 'CB', 'LB', 'RB', 'DM', 'CM', 'AM', 'LW', 'RW', 'ST'].includes(item.textContent.trim()))), true);

        await page.click('[data-live-toggle]');
        await waitForText(page, '[data-live-phase]', 'Em andamento');
        const staleTabResponse = await page.evaluate(async () => {
            const root = document.querySelector('[data-live-match]');
            const token = document.querySelector('meta[name="csrf-token"]').content;
            const response = await fetch(root.dataset.controlUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify({ action: 'pause', request_id: crypto.randomUUID(), revision: 0 }),
            });
            return { status: response.status, body: await response.json() };
        });
        assert.equal(staleTabResponse.status, 422);
        assert.match(staleTabResponse.body.errors.revision[0], /outra aba/);

        await page.evaluate(() => {
            window.__auditDelays = [];
            window.__auditSetTimeout = window.setTimeout;
            window.setTimeout = (callback, delay, ...args) => {
                window.__auditDelays.push(delay);
                return window.__auditSetTimeout(callback, 60_000, ...args);
            };
        });
        for (const speed of speedValues) await page.select('[data-live-speed]', String(speed));
        const delays = await page.evaluate(() => window.__auditDelays.slice(-7));
        delays.forEach((delay, index) => {
            const expected = 300_000 / 90 / speedValues[index];
            assert.ok(Math.abs(delay - expected) < 2, `Temporizador ${speedValues[index]}×: ${delay}/${expected}`);
            evidence.speeds[`${speedValues[index]}x`] = delay;
        });
        await page.click('[data-live-toggle]');
        await waitForText(page, '[data-live-phase]', 'Pausada');
        await page.evaluate(() => { window.setTimeout = window.__auditSetTimeout; });

        const beforeSwap = await page.$$eval('[data-live-lineup] [data-player-id]', (items) => items.map((item) => item.dataset.playerId));
        await page.click('[data-live-lineup] [data-player-id]:nth-child(6)');
        await page.click('[data-live-lineup] [data-player-id]:nth-child(9)');
        await page.waitForFunction((left, right) => {
            const players = [...document.querySelectorAll('[data-live-lineup] [data-player-id]')];
            return players[5]?.dataset.playerId === right && players[8]?.dataset.playerId === left;
        }, {}, beforeSwap[5], beforeSwap[8]);

        const fieldBeforeSubstitution = await page.$$eval('[data-live-lineup] [data-player-id]', (items) => items.map((item) => item.dataset.playerId));
        const benchBeforeSubstitution = await page.$$eval('[data-live-bench] [data-player-id]', (items) => items.map((item) => item.dataset.playerId));
        await page.click('[data-live-lineup] [data-player-id]:nth-child(2)');
        await page.click('[data-live-bench] [data-player-id]:nth-child(2)');
        await page.waitForFunction((outId, inId) => {
            const field = [...document.querySelectorAll('[data-live-lineup] [data-player-id]')].map((item) => item.dataset.playerId);
            const bench = [...document.querySelectorAll('[data-live-bench] [data-player-id]')].map((item) => item.dataset.playerId);
            return field.includes(inId) && !field.includes(outId) && !bench.includes(inId);
        }, {}, fieldBeforeSubstitution[1], benchBeforeSubstitution[1]);

        await page.select('[data-live-tactics-form] [name=formation]', '4-3-3');
        await page.select('[data-live-tactics-form] [name=style]', 'total_attack');
        await page.select('[data-live-tactics-form] [name=attack_direction]', 'wings');
        await page.select('[data-live-tactics-form] [name=marking]', 'very_heavy');
        await page.click('[data-live-tactics-form] button[type=submit]');
        await waitForText(page, '[data-live-formation]', '4-3-3');
        const minuteZero = await page.evaluate(() => {
            const score = [document.querySelector('[data-home-score]').textContent, document.querySelector('[data-away-score]').textContent];
            return { clock: document.querySelector('[data-live-clock]').textContent, score };
        });
        assert.equal(minuteZero.clock, "0'");

        await page.click('[data-live-toggle]');
        await waitForText(page, '[data-live-phase]', 'Em andamento');
        await page.reload({ waitUntil: 'domcontentloaded' });
        const firstHydration = await advanceFromRenderedState(page, 30);
        assert.equal(firstHydration.minute, 30);
        assert.equal(firstHydration.paused, true);
        assert.equal(firstHydration.state.status, 'hydration_break');
        assert.equal(firstHydration.state.hydration_breaks.first_half.status, 'active');
        await page.reload({ waitUntil: 'domcontentloaded' });
        assert.equal(await pageText(page, '[data-live-phase]'), 'Parada técnica');
        assert.equal(await pageText(page, '[data-live-clock]'), "30'");
        assert.equal(await page.$eval('[data-live-hydration]', (element) => element.hidden), false);
        assert.match(await pageText(page, '[data-live-hydration]'), /Pausa regulamentar de três minutos/);
        await page.select('[data-live-tactics-form] [name=attack_direction]', 'center');
        await Promise.all([
            page.waitForResponse((response) => response.url().includes('/live/decision') && response.request().method() === 'POST'),
            page.click('[data-live-tactics-form] button[type=submit]'),
        ]);
        await page.click('[data-live-toggle]');
        await waitForText(page, '[data-live-phase]', 'Em andamento');
        await page.reload({ waitUntil: 'domcontentloaded' });

        const halfTime = await advanceUntilAutomaticPause(page);
        assert.equal(halfTime.minute, 45 + halfTime.state.first_half_added_time);
        assert.equal(halfTime.paused, true);
        assert.equal(halfTime.state.status, 'half_time');
        assert.equal(Math.max(...halfTime.state.events.map((event) => event.minute)), 45);
        assert.equal(halfTime.state.added_minute, halfTime.state.first_half_added_time);
        assert.ok(halfTime.state.first_half_added_time >= 3);
        const firstHalfEvents = structuredClone(halfTime.state.events);
        await page.reload({ waitUntil: 'domcontentloaded' });
        assert.equal(await pageText(page, '[data-live-phase]'), 'Intervalo');
        assert.equal(await pageText(page, '[data-live-clock]'), `45+${halfTime.state.first_half_added_time}'`);
        assert.match(await pageText(page, '[data-live-events]'), /Acréscimos anunciados/);
        evidence.layouts.tablet = await assertResponsive(page, 768, 1024);
        evidence.layouts.mobile = await assertResponsive(page, 390, 844);

        const halfField = await page.$$eval('[data-live-lineup] [data-player-id]', (items) => items.map((item) => item.dataset.playerId));
        const halfBench = await page.$$eval('[data-live-bench] [data-player-id]', (items) => items.map((item) => item.dataset.playerId));
        await page.click('[data-live-lineup] [data-player-id]:nth-child(3)');
        await page.click('[data-live-bench] [data-player-id]:nth-child(2)');
        await page.waitForFunction((outId, inId) => {
            const field = [...document.querySelectorAll('[data-live-lineup] [data-player-id]')].map((item) => item.dataset.playerId);
            return field.includes(inId) && !field.includes(outId);
        }, {}, halfField[2], halfBench[1]);
        await page.select('[data-live-tactics-form] [name=formation]', '5-4-1');
        await page.select('[data-live-tactics-form] [name=style]', 'counter');
        await page.select('[data-live-tactics-form] [name=attack_direction]', 'center');
        await page.select('[data-live-tactics-form] [name=marking]', 'light');
        await page.click('[data-live-tactics-form] button[type=submit]');
        await waitForText(page, '[data-live-formation]', '5-4-1');

        await page.click('[data-live-toggle]');
        await waitForText(page, '[data-live-phase]', 'Em andamento');
        await page.reload({ waitUntil: 'domcontentloaded' });
        const secondHydration = await advanceFromRenderedState(page, halfTime.minute + 30);
        assert.equal(secondHydration.minute, halfTime.minute + 30);
        assert.equal(secondHydration.state.minute, 75);
        assert.equal(secondHydration.state.status, 'hydration_break');
        await page.reload({ waitUntil: 'domcontentloaded' });
        assert.equal(await pageText(page, '[data-live-phase]'), 'Parada técnica');
        assert.equal(await pageText(page, '[data-live-clock]'), "75'");
        await page.click('[data-live-toggle]');
        await waitForText(page, '[data-live-phase]', 'Em andamento');
        await page.reload({ waitUntil: 'domcontentloaded' });
        const completed = await advanceFromRenderedState(page);
        assert.equal(completed.status, 'finished');
        assert.equal(completed.minute, 90 + completed.state.added_time);
        assert.ok(completed.state.first_half_added_time >= 3);
        assert.ok(completed.state.second_half_added_time >= 3);
        assert.deepEqual(completed.state.events.slice(0, firstHalfEvents.length), firstHalfEvents);
        assert.deepEqual(completed.state.events.map((event) => event.sequence), Array.from({ length: completed.state.events.length }, (_, index) => index + 1));
        const eventTicks = completed.state.events.map((event) => event.tick);
        assert.deepEqual(eventTicks, [...eventTicks].sort((left, right) => left - right));
        assert.equal(completed.state.events.filter((event) => event.type === 'hydration_break_start').length, 2);
        assert.equal(completed.state.events.filter((event) => event.type === 'hydration_break_end').length, 2);
        for (const side of ['home', 'away']) {
            const goals = completed.state.events.filter((event) => event.type === 'goal' && event.side === side).length;
            assert.equal(goals, completed.state.teams[side].stats.goals);
        }
        await page.reload({ waitUntil: 'domcontentloaded' });
        assert.equal(await pageText(page, '[data-live-phase]'), 'Encerrada');
        const firstMatch = {
            home: completed.state.teams.home.name,
            away: completed.state.teams.away.name,
            score: `${completed.state.teams.home.stats.goals} x ${completed.state.teams.away.stats.goals}`,
            minutes: completed.minute,
            highlights: completed.state.events.filter((event) => ['goal', 'yellow_card', 'red_card'].includes(event.type)).slice(0, 8),
        };
        evidence.matches.push(firstMatch);
        await navigateByClick(page, '[data-live-finalize] button');
        assert.ok(page.url().includes('/results/'));
        assert.match(await pageText(page, 'body'), /Resultado oficial registrado/);
        await navigateByClick(page, 'a.breadcrumb');
        assert.match(await pageText(page, '.career-summary-strip article:nth-child(2) small'), /1 jogos/);
        assert.ok((await pageText(page, '#estatisticas')).length > 0);
        assert.ok((await pageText(page, '.career-activity-list')).includes(firstMatch.score.replace('x', '×')));

        await page.setViewport({ width: 1440, height: 1000, deviceScaleFactor: 1 });
        await createReadyMatch(page, 'Fluxo rápido final');
        network.disconnectNextAdvance = true;
        await page.select('[data-live-speed]', '2');
        await page.click('[data-live-toggle]');
        await waitForText(page, '[data-live-status]', 'Failed to fetch');
        assert.equal(await pageText(page, '[data-live-clock]'), "0'");
        await page.waitForFunction(() => document.querySelector('[data-live-clock]').textContent !== "0'", { timeout: 5_000 });
        const recoveredMinute = await pageText(page, '[data-live-clock]');
        await page.reload({ waitUntil: 'domcontentloaded' });
        assert.equal(await pageText(page, '[data-live-clock]'), recoveredMinute);
        await page.click('[data-live-toggle]');
        await waitForText(page, '[data-live-phase]', 'Pausada');

        await page.click('[data-live-toggle]');
        await waitForText(page, '[data-live-phase]', 'Em andamento');
        await page.reload({ waitUntil: 'domcontentloaded' });
        let second = await advanceFromRenderedState(page, 30);
        assert.equal(second.state.status, 'hydration_break');
        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.select('[data-live-tactics-form] [name=formation]', '4-2-4');
        await page.select('[data-live-tactics-form] [name=style]', 'total_attack');
        await page.select('[data-live-tactics-form] [name=attack_direction]', 'wings');
        await page.select('[data-live-tactics-form] [name=marking]', 'heavy');
        await page.click('[data-live-tactics-form] button[type=submit]');
        await waitForText(page, '[data-live-formation]', '4-2-4');
        await page.click('[data-live-toggle]');
        await waitForText(page, '[data-live-phase]', 'Em andamento');
        await page.reload({ waitUntil: 'domcontentloaded' });
        second = await advanceUntilAutomaticPause(page);
        assert.equal(second.state.status, 'half_time');
        const secondHalfStartTick = second.minute;
        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.click('[data-live-toggle]');
        await waitForText(page, '[data-live-phase]', 'Em andamento');
        await page.reload({ waitUntil: 'domcontentloaded' });
        second = await advanceFromRenderedState(page, secondHalfStartTick + 15);
        assert.equal(second.state.minute, 60);
        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.click('[data-live-toggle]');
        await waitForText(page, '[data-live-phase]', 'Pausada');
        page.once('dialog', (dialog) => dialog.accept());
        await page.click('[data-live-finish]');
        await waitForText(page, '[data-live-phase]', 'Encerrada');
        const fast = await page.evaluate(() => {
            const clock = document.querySelector('[data-live-clock]').textContent;
            const score = `${document.querySelector('[data-home-score]').textContent} x ${document.querySelector('[data-away-score]').textContent}`;
            return { clock, score };
        });
        evidence.matches.push({ mode: 'simulate-to-end-at-60', ...fast });
        await navigateByClick(page, '[data-live-finalize] button');
        assert.match(await pageText(page, 'body'), /Resultado oficial registrado/);

        assert.deepEqual(diagnostics.javascript, []);
        const relevantConsole = diagnostics.console.filter((message) => ![
            'ERR_BLOCKED_BY_CLIENT', 'status of 422', 'ERR_INTERNET_DISCONNECTED',
        ].some((expected) => message.includes(expected)));
        assert.deepEqual(relevantConsole, []);
        const unexpectedRequests = diagnostics.requests.filter((request) => !request.includes('fonts.googleapis.com') && !request.includes('/live/advance'));
        assert.deepEqual(unexpectedRequests, []);
        const unexpectedResponses = diagnostics.responses.filter((response) => !response.startsWith('422 ') || !response.includes('/live/control'));
        assert.deepEqual(unexpectedResponses, []);

        console.log(`EVIDENCE ${JSON.stringify(evidence)}`);
        console.log(`DIAGNOSTICS ${JSON.stringify(diagnostics)}`);
    } finally {
        await browser.close();
    }
});
