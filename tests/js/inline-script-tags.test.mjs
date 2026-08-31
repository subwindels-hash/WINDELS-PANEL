/**
 * Every inline <script> start tag in a template must be closed with ">".
 *
 * Why this test exists — and why it is not a style nit:
 *
 * A template that renders
 *
 *     <script nonce="…"
 *     // comment
 *     (function () { … })();
 *     </script>
 *
 * (note the missing ">" after the attributes) does not merely "break the
 * script". The HTML tokenizer keeps consuming — the attributes, then the
 * script body, then everything the template emits after it — looking for the
 * ">" that ends the start tag. It finds the one in `</script>`, emits the
 * *script element*, and switches to script-data state. From that point every
 * byte of the page is script text until a literal `</script>` appears, and the
 * only one there was already eaten as an attribute.
 *
 * The rest of the document (navigation, <main>, footer, closing tags) is
 * therefore parsed as script data and never rendered: the visitor sees the top
 * strip of the page and nothing else, the tab keeps working because the
 * document never parses cleanly, and the operator reports "the website
 * stopped loading after I uploaded it". PHP, the database and the web server
 * are all perfectly healthy — which is exactly what makes this expensive to
 * find by looking at the application instead of the markup.
 *
 * It shipped because csp_nonce_attr() returns a *complete attribute*, so
 * `<script <?=csp_nonce_attr()?>` reads as finished in a template where the
 * short-echo tag is followed by a newline: PHP swallows that newline, so
 * nothing in the source betrays the missing bracket.
 *
 * The check walks each template like a tokenizer would — stepping over PHP
 * blocks in place, so line numbers stay the author's — and fails any <script>
 * start tag that reaches a newline or another tag before it reaches ">".
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(here, '../..');

/** Templates that emit markup: views, plus controllers/CLI that echo a page. */
const SCAN_DIRS = ['application/views', 'application/controllers', 'application/errors'];

/** A start tag longer than this is a mistake even without a newline in it. */
const MAX_START_TAG = 400;

let failures = 0;
let checked = 0;
let scripts = 0;

function phpTemplates(dir) {
    const out = [];
    if (!fs.existsSync(dir)) return out;
    const walk = (d) => {
        for (const entry of fs.readdirSync(d, { withFileTypes: true })) {
            const p = path.join(d, entry.name);
            if (entry.isDirectory()) walk(p);
            else if (entry.name.endsWith('.php')) out.push(p);
        }
    };
    walk(dir);
    return out;
}

/**
 * @return {{line:number, snippet:string, why:string|null}[]} one entry per
 *         <script ...> in the file; `why` is null when the tag is well formed.
 */
function scanScriptTags(src) {
    const hits = [];
    let from = 0;

    for (;;) {
        const lt = src.indexOf('<script', from);
        if (lt === -1) break;

        // Only a real tag: `<script` or `<script ` / `<script>`. Never
        // `<scriptx` or a `<script` inside a string.
        const after = src[lt + 7];
        if (after !== undefined && after !== ' ' && after !== '\t' && after !== '\n' && after !== '\r' && after !== '>') {
            from = lt + 7;
            continue;
        }

        scripts++;
        let j = lt + 7;
        let snippet = '<script';
        let why = null;

        while (j < src.length) {
            const ch = src[j];

            if (ch === '>') { snippet += '>'; break; }

            // Step over a PHP block: it can span lines and can never be the
            // ">" that closes the tag (these helpers emit attributes and
            // escaped text, never markup).
            if (ch === '<' && src.startsWith('<?', j)) {
                const end = src.indexOf('?>', j + 2);
                j = end === -1 ? src.length : end + 2;
                snippet += '<?php…?>';
                // `?>` eats one following newline, so the next line's text is
                // glued to the output — exactly how a missing ">" hides.
                if (src[j] === '\r') j++;
                if (src[j] === '\n') j++;
                continue;
            }

            if (ch === '<') { why = 'start tag swallows the markup that follows it'; break; }
            if (ch === '\n' || ch === '\r') { why = 'start tag runs onto the next line'; break; }
            if (snippet.length > MAX_START_TAG) { why = `start tag is ${snippet.length}+ chars long`; break; }

            snippet += ch;
            j++;
        }

        if (why === null && j >= src.length && !snippet.endsWith('>')) {
            why = 'start tag is never closed with ">"';
        }

        hits.push({
            line: src.slice(0, lt).split('\n').length,
            snippet: snippet.slice(0, 160),
            why,
        });
        from = lt + 7;
    }

    return hits;
}

for (const dir of SCAN_DIRS) {
    for (const file of phpTemplates(path.join(ROOT, dir))) {
        const src = fs.readFileSync(file, 'utf8');
        checked++;
        for (const hit of scanScriptTags(src)) {
            if (!hit.why) continue;
            failures++;
            console.error(`FAIL ${path.relative(ROOT, file)}:${hit.line} — <script> ${hit.why}`);
            console.error(`     ${hit.snippet.replace(/\n/g, '\\n')}`);
        }
    }
}

console.log(`inline-script-tags: ${checked} templates, ${scripts} <script> tags, ${failures} unterminated`);
if (failures > 0) {
    console.error('');
    console.error('An unclosed <script> start tag turns every byte after it — the navigation, the');
    console.error('page body, the footer — into script text, so the browser renders a strip of the');
    console.error('page and nothing else. Add the ">" that closes the start tag.');
    process.exit(1);
}
console.log('PASS - every inline <script> start tag is closed');
