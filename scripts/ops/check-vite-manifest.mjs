#!/usr/bin/env node
// Dot.Ehail: proves that the Vite build contains everything the Blade views ask for. Runs on the CI runner and on the deploy
// runner right after `npm run build`; no PHP needed.
//
//   node scripts/ops/check-vite-manifest.mjs [--views resources/views] [--manifest public/build/manifest.json] [--build public/build]
//
// Fails (exit 1) when
//   1. a literal @vite([...]) entry in a view is missing from the manifest (that page would answer 500);
//   2. any `resources/css|js/...` path written anywhere in a view that contains @vite (a comment, a PHP array) is not a
//      manifest entry. This is the superset of the older deploy check that treated such text as an asset path (Dot.Auction
//      failed a deploy on a comment). If it fires, reword the comment or make the path a real entry;
//   3. a manifest entry points at a file that is not in the build folder, or imports a chunk or css that is not.
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

export function walk(dir) {
    const out = [];
    for (const name of readdirSync(dir)) {
        const full = join(dir, name);
        if (statSync(full).isDirectory()) out.push(...walk(full));
        else out.push(full);
    }
    return out;
}

const ASSET_PATH = /resources\/(?:css|js)\/[A-Za-z0-9_./@-]+/g;
const DIRECTIVE = /@vite\(\s*\[(.*?)\]\s*\)/gs;

export function check({ views, manifest, buildDir, viewFiles }) {
    const errors = [];
    const stats = { views: 0, vitePages: 0, directiveEntries: new Set(), mentions: new Set() };

    for (const file of viewFiles) {
        if (!file.endsWith('.blade.php')) continue;
        stats.views += 1;
        const text = readFileSync(file, 'utf8');
        if (!text.includes('@vite')) continue;
        stats.vitePages += 1;

        for (const m of text.matchAll(DIRECTIVE)) {
            for (const e of m[1].matchAll(ASSET_PATH)) {
                stats.directiveEntries.add(e[0]);
                if (!manifest[e[0]]) errors.push(`${file}: @vite asks for ${e[0]}, which is not in the manifest (the page would answer 500: add it to vite.config.js input)`);
            }
        }

        for (const m of text.matchAll(ASSET_PATH)) {
            stats.mentions.add(m[0]);
            if (!manifest[m[0]]) errors.push(`${file}: mentions ${m[0]}, which is not a manifest entry. A view that uses @vite must not name resources/ paths outside real entries (comments included): reword it`);
        }
    }

    for (const [key, v] of Object.entries(manifest)) {
        if (v.file && !existsSync(join(buildDir, v.file))) errors.push(`manifest entry ${key}: file ${v.file} is not in the build folder`);
        for (const css of v.css ?? []) if (!existsSync(join(buildDir, css))) errors.push(`manifest entry ${key}: css ${css} is not in the build folder`);
        for (const imp of v.imports ?? []) if (!manifest[imp]) errors.push(`manifest entry ${key}: imports ${imp}, which has no manifest record`);
    }

    return { errors: [...new Set(errors)], stats };
}

function main() {
    const arg = (name, def) => {
        const i = process.argv.indexOf(name);
        return i > -1 ? process.argv[i + 1] : def;
    };
    const views = resolve(arg('--views', 'resources/views'));
    const manifestPath = resolve(arg('--manifest', 'public/build/manifest.json'));
    const buildDir = resolve(arg('--build', 'public/build'));

    if (!existsSync(manifestPath)) {
        console.error(`::error::${manifestPath} is missing: run npm run build first`);
        process.exit(1);
    }

    const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
    const { errors, stats } = check({ views, manifest, buildDir, viewFiles: walk(views) });

    for (const e of errors) console.error(`::error::${e}`);
    if (errors.length) {
        console.error(`Vite manifest check FAILED: ${errors.length} problem(s).`);
        process.exit(1);
    }
    console.log(`Vite manifest check passed: ${stats.vitePages} views use @vite, ${stats.directiveEntries.size} distinct entries (all in the manifest), ${Object.keys(manifest).length} manifest records, every file present.`);
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) main();
