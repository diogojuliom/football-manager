import assert from 'node:assert/strict';
import test from 'node:test';
import { formatMatchClock } from '../../resources/js/live-match.js';

test('o relógio distingue tempo regulamentar e acréscimos dos dois períodos', () => {
    assert.equal(formatMatchClock({ minute: 30, added_minute: 0 }), "30'");
    assert.equal(formatMatchClock({ minute: 45, added_minute: 3 }), "45+3'");
    assert.equal(formatMatchClock({ minute: 75, added_minute: 0 }), "75'");
    assert.equal(formatMatchClock({ minute: 90, added_minute: 3 }), "90+3'");
});
