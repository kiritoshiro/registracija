#!/usr/bin/env node
/*
 * Writes the minified copies the plugin loads, next to the readable sources:
 * assets/<name>.min.js and assets/<name>.min.css. The plugin falls back to the
 * readable file when SCRIPT_DEBUG is on or a copy is missing.
 *
 *   node scripts/minify-assets.mjs           rebuild the copies
 *   node scripts/minify-assets.mjs --check   fail if any copy is out of date (CI)
 *
 * Scripts go through SWC (whitespace, syntax and local names only; top-level
 * names stay global and no newer syntax is introduced), styles through
 * lightningcss for the browsers listed below. Every result is parsed once
 * more. Both tools are pinned in package-lock.json; nothing here ships in the
 * plugin ZIP (see .gitattributes).
 */
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Script } from 'node:vm';
import { minifySync } from '@swc/core';
import { transform } from 'lightningcss';

// Oldest browsers the styles must keep working in; lightningcss adds the
// vendor prefixes they need and never uses syntax they lack.
const version = (major) => major << 16;
const targets = {
    chrome: version(90),
    edge: version(90),
    firefox: version(88),
    safari: version(14),
    ios_saf: version(14),
    samsung: version(15),
};

const sources = ['assets/admin.css', 'assets/admin.js', 'assets/public.css', 'assets/public.js'];

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const check = process.argv.includes('--check');
let failed = false;
let before = 0;
let after = 0;

for (const name of sources) {
    const target = name.replace(/\.(js|css)$/, '.min.$1');
    // Same bytes from a Windows (CRLF) checkout and a Linux CI checkout.
    const source = readFileSync(join(root, name), 'utf8').replace(/\r\n/g, '\n');
    let code;
    if (name.endsWith('.css')) {
        code = transform({ filename: name, code: Buffer.from(source), minify: true, targets }).code.toString();
        // Parse check: lightningcss throws on invalid CSS.
        transform({ filename: target, code: Buffer.from(code), targets });
    } else {
        code = minifySync(source, {
            module: false,
            compress: { toplevel: false },
            mangle: { toplevel: false },
            format: { comments: 'some' },
        }).code;
        // Parse check only; nothing is executed.
        new Script(code, { filename: target });
    }
    code += '\n';
    before += Buffer.byteLength(source);
    after += Buffer.byteLength(code);

    const file = join(root, target);
    if (check) {
        const current = existsSync(file) ? readFileSync(file, 'utf8').replace(/\r\n/g, '\n') : '';
        if (current !== code) {
            console.error(`${target} is out of date: run node scripts/minify-assets.mjs`);
            failed = true;
        } else {
            console.log(`PASS: ${target} is up to date (${Buffer.byteLength(code)} bytes from ${Buffer.byteLength(source)}).`);
        }
    } else {
        writeFileSync(file, code);
        console.log(`Wrote ${target}: ${Buffer.byteLength(code)} bytes from ${Buffer.byteLength(source)}.`);
    }
}
console.log(`${sources.length} files: ${before} -> ${after} bytes.`);
process.exit(failed ? 1 : 0);
