import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { check } from '../../scripts/ops/check-vite-manifest.mjs';

// The deploy's manifest check (scripts/ops/check-vite-manifest.mjs): a view whose @vite entry is not built answers 500,
// and a comment naming a resources/ path inside a view with @vite fails the deploy (Dot.Auction), so both must be caught.
function fixture(views, manifest, files = []) {
    const dir = mkdtempSync(join(tmpdir(), 'eh-vite-'));
    const viewFiles = [];
    for (const [name, text] of Object.entries(views)) {
        const path = join(dir, name);
        writeFileSync(path, text);
        viewFiles.push(path);
    }
    mkdirSync(join(dir, 'build', 'assets'), { recursive: true });
    for (const f of files) writeFileSync(join(dir, 'build', f), 'x');
    return { dir, result: check({ views: dir, manifest, buildDir: join(dir, 'build'), viewFiles }) };
}

const manifest = {
    'resources/css/app.css': { file: 'assets/app.css', isEntry: true },
    'resources/js/app.js': { file: 'assets/app.js', isEntry: true, imports: ['_chunk.js'] },
    '_chunk.js': { file: 'assets/chunk.js' },
};
const built = ['assets/app.css', 'assets/app.js', 'assets/chunk.js'];

test('a view asking for built entries passes', () => {
    const { dir, result } = fixture({ 'a.blade.php': "@vite(['resources/css/app.css', 'resources/js/app.js'])" }, manifest, built);
    assert.deepEqual(result.errors, []);
    assert.equal(result.stats.vitePages, 1);
    rmSync(dir, { recursive: true });
});

test('an entry that was not built is an error', () => {
    const { dir, result } = fixture({ 'a.blade.php': "@vite(['resources/js/ops/live.js'])" }, manifest, built);
    assert.ok(result.errors.length >= 1);
    assert.ok(result.errors.some((e) => /@vite asks for resources\/js\/ops\/live\.js/.test(e)));
    rmSync(dir, { recursive: true });
});

test('a resources path in a comment of a view that uses @vite is an error, in a view without @vite it is not', () => {
    const withVite = "{{-- styles live in resources/css/old.css --}}\n@vite(['resources/css/app.css'])";
    const { dir, result } = fixture({ 'a.blade.php': withVite, 'b.blade.php': '{{-- resources/css/old.css --}}' }, manifest, built);
    assert.equal(result.errors.length, 1);
    assert.match(result.errors[0], /a\.blade\.php: mentions resources\/css\/old\.css/);
    rmSync(dir, { recursive: true });
});

test('a manifest entry whose file or imported chunk is missing is an error', () => {
    const { dir, result } = fixture({ 'a.blade.php': "@vite(['resources/js/app.js'])" }, { ...manifest, 'resources/js/app.js': { file: 'assets/app.js', isEntry: true, imports: ['_gone.js'] } }, ['assets/app.css']);
    assert.ok(result.errors.some((e) => /file assets\/app\.js is not in the build folder/.test(e)));
    assert.ok(result.errors.some((e) => /imports _gone\.js/.test(e)));
    rmSync(dir, { recursive: true });
});
