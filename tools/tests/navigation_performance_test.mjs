import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');

const htmlResponse = read('src/html_response.php');
assert.match(htmlResponse, /type="speculationrules"/);
assert.match(htmlResponse, /"source":"document"/);
assert.match(htmlResponse, /"eagerness":"moderate"/);
assert.match(htmlResponse, /selector_matches/);
assert.match(htmlResponse, /navigation\.js/);

const navigation = read('public/js/navigation.js');
assert.match(navigation, /target\.origin !== global\.location\.origin/);
assert.match(navigation, /event\.metaKey \|\| event\.ctrlKey \|\| event\.shiftKey \|\| event\.altKey/);
assert.match(navigation, /link\.hasAttribute\('download'\)/);
assert.match(navigation, /link\.target === '_blank'/);
assert.doesNotMatch(navigation, /preventDefault\(/);
assert.doesNotMatch(navigation, /WebSocket|SignoffWS\.init|LmsWS\.init/);
assert.match(navigation, /pageshow/);
assert.match(navigation, /pagehide/);

const core = read('public/js/lms-core.js');
assert.match(core, /const _apiInFlight = new Map\(\)/);
assert.match(core, /GET_CACHE_TTL_MS/);
assert.match(core, /invalidateAccessCache/);
assert.match(core, /requestGeneration === _accessCacheGeneration/);
assert.match(core, /_apiInFlight\.get\(cacheKey\) === request/);
assert.match(core, /generation !== _accessCacheGeneration\) return loadMe\(\)/);
assert.match(core, /generation !== _accessCacheGeneration\) return loadCaps\(\)/);

const lmsWs = read('public/js/lms-ws.js');
assert.match(lmsWs, /course\\\.enrollment/);
assert.match(lmsWs, /user\\\.role/);

console.log('navigation performance tests passed');
