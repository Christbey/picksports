import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { test } from 'node:test';
import { pathToFileURL } from 'node:url';
const sdk = path.resolve(process.argv[2]);
const spec = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
const injurySchema = spec.components.schemas.SportPlayer.properties.active_injuries.items;
const playerSchema = spec.components.schemas.SportPlayer;
assert.deepEqual(injurySchema.properties.return_date, { type: ['string', 'null'], format: 'date' });
assert(injurySchema.required.includes('return_date'));
assert.deepEqual(playerSchema.properties.created_at, { type: ['string', 'null'], format: 'date-time' });
assert(playerSchema.required.includes('created_at'));
const injury = await import(pathToFileURL(path.join(sdk, 'dist/models/SportPlayerActiveInjuriesInner.js')).href);
const player = await import(pathToFileURL(path.join(sdk, 'dist/models/SportPlayer.js')).href);
globalThis.fetch = () => { throw new Error('SDK date tests forbid network'); };
const fullInjury = (date) => ({ id: 1, status: 'Out', detail: null, type: null, return_date: date, source_updated_at: null, source_updated_at_raw: null, source_clock_status: 'unverified_storage_clock' });
const fullPlayer = (date) => ({ ...Object.fromEntries(Object.keys(playerSchema.properties).map((name) => [name, null])), id: 1, sport: 'nfl', created_at: date, updated_at: date });
function roundTrip(label, schema, raw, from, to, dateKey) {
    assert.deepEqual(Object.keys(raw).sort(), [...schema.required].sort());
    const decoded = from(raw);
    let encoded, failure;
    try { encoded = to(decoded); } catch (error) { failure = { name: error.name, message: error.message }; }
    // Retain complete actual state before the desired null/positive assertion.
    console.log('SDK_DATE_TRACE', JSON.stringify({ label, raw, decoded, encoded, failure }));
    assert.equal(failure, undefined);
    assert.equal(encoded[dateKey], raw[dateKey]);
    return encoded;
}
test('required nullable API injury return_date preserves explicit null', () => {
    const encoded = roundTrip('injury-null', injurySchema, fullInjury(null), injury.SportPlayerActiveInjuriesInnerFromJSON, injury.SportPlayerActiveInjuriesInnerToJSON, 'return_date');
    assert.equal(encoded.source_updated_at, null);
});
test('required nullable API injury return_date preserves an observed date', () => {
    roundTrip('injury-date', injurySchema, fullInjury('2026-11-29'), injury.SportPlayerActiveInjuriesInnerFromJSON, injury.SportPlayerActiveInjuriesInnerToJSON, 'return_date');
});
test('required nullable API player timestamps preserve explicit null', () => {
    const encoded = roundTrip('player-null', playerSchema, fullPlayer(null), player.SportPlayerFromJSON, player.SportPlayerToJSON, 'created_at');
    assert.equal(encoded.updated_at, null);
});
test('required nullable API player timestamps preserve observed UTC instants', () => {
    const raw = fullPlayer('2026-11-28T00:00:00.000Z');
    const encoded = roundTrip('player-instant', playerSchema, raw, player.SportPlayerFromJSON, player.SportPlayerToJSON, 'created_at');
    assert.equal(encoded.updated_at, raw.updated_at);
});
test('invalid typed SDK date still fails conversion', () => {
    const decoded = injury.SportPlayerActiveInjuriesInnerFromJSON(fullInjury('2026-11-29'));
    decoded.returnDate = new Date('not-a-date');
    assert.throws(() => injury.SportPlayerActiveInjuriesInnerToJSON(decoded), RangeError);
});
test('missing required typed SDK date is not silently defaulted to null', () => {
    const decoded = injury.SportPlayerActiveInjuriesInnerFromJSON(fullInjury('2026-11-29'));
    decoded.returnDate = undefined;
    assert.throws(() => injury.SportPlayerActiveInjuriesInnerToJSON(decoded), TypeError);
});
