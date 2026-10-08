// Renders docs/DOKUMENTACE.md into a standalone HTML page (docs/dokumentace.html)
// that is published as the shared documentation link. Run `npm run docs`
// after every documentation change (see CLAUDE.md).
import { readFileSync, writeFileSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { Marked } from 'marked';

const SOURCE = new URL('../docs/DOKUMENTACE.md', import.meta.url);
const TARGET = new URL('../docs/dokumentace.html', import.meta.url);

// Heading anchors like GitHub's, but ASCII only (diacritics removed); links
// inside the Markdown (#…) are converted the same way.
const slug = (text) =>
  decodeURIComponent(text)
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLowerCase()
    .replace(/<[^>]+>/g, '')
    .replace(/[^\p{L}\p{N}\s-]/gu, '')
    .trim()
    .replace(/\s/g, '-');

const escapeHtml = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

let markdown = readFileSync(SOURCE, 'utf8');
const title = markdown.match(/^# (.+)$/m)[1];
// The page has its own navigation: drop the H1 and the "Obsah" section.
markdown = markdown.replace(/^# .+\n/m, '').replace(/## Obsah\n[\s\S]*?\n---\n/, '');

const toc = [];
const marked = new Marked({
  gfm: true,
  renderer: {
    heading(text, level, raw) {
      const id = slug(raw);
      if (level === 2) toc.push({ id, text: raw });
      return `<h${level} id="${id}"><a class="anchor" href="#${id}" aria-hidden="true">#</a>${text}</h${level}>\n`;
    },
    code(code, lang) {
      if (lang === 'mermaid') return `<pre class="mermaid">${escapeHtml(code)}</pre>\n`;
      return `<pre class="code"><code>${escapeHtml(code)}</code></pre>\n`;
    },
    link(href, linkTitle, text) {
      const target = href.startsWith('#') ? `#${slug(href.slice(1))}` : href;
      const external = /^https?:/.test(href) ? ' target="_blank" rel="noopener"' : '';
      return `<a href="${target}"${external}>${text}</a>`;
    },
    table(header, body) {
      return `<div class="table-wrap"><table><thead>${header}</thead><tbody>${body}</tbody></table></div>\n`;
    },
  },
});
const body = marked.parse(markdown);

let updated = '';
try {
  updated = execSync('git log -1 --format=%cd --date=format:"%-d. %-m. %Y" -- docs/DOKUMENTACE.md', {
    cwd: new URL('..', import.meta.url),
  })
    .toString()
    .trim();
} catch {
  /* not a git checkout */
}

const nav = toc
  .map(({ id, text }) => {
    const [, num, label] = text.match(/^(\d+)\.\s*(.*)$/) ?? [null, '', text];
    return `<li><a href="#${id}"><span class="num">${num}</span><span>${escapeHtml(label)}</span></a></li>`;
  })
  .join('\n');

const html = `<title>Dokumentace Moje židle 2026</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* Layout: sticky chapter rail on the left (desktop), single reading column; stacks on phones. */
:root {
  --bg: #f5f1ea;
  --surface: #fffdf9;
  --surface-2: #efe9df;
  --line: #e2d9cb;
  --fg: #2b2620;
  --fg-2: #6b6256;
  --accent: #8a5a2b;
  --accent-soft: #f3e6d6;
  --code-bg: #f1ebe1;
  --font-display: 'Fraunces', Georgia, 'Times New Roman', serif;
  --font-body: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
  --font-mono: 'JetBrains Mono', ui-monospace, 'SFMono-Regular', Menlo, monospace;
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --bg: #1c1915; --surface: #25211c; --surface-2: #2e2923; --line: #3a342c;
    --fg: #ece5da; --fg-2: #b3a898; --accent: #d9a46c; --accent-soft: #3a2e22; --code-bg: #2e2923;
    color-scheme: dark;
  }
}
:root[data-theme="dark"] {
  --bg: #1c1915; --surface: #25211c; --surface-2: #2e2923; --line: #3a342c;
  --fg: #ece5da; --fg-2: #b3a898; --accent: #d9a46c; --accent-soft: #3a2e22; --code-bg: #2e2923;
  color-scheme: dark;
}
* { box-sizing: border-box; }
html { scroll-behavior: smooth; scroll-padding-top: 16px; }
@media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }
body { background: var(--bg); color: var(--fg); font: 16px/1.6 var(--font-body); }
.page {
  max-width: 1180px; margin: 0 auto; padding-inline: 16px; padding-block: 32px 64px;
  display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 40px; align-items: start;
}
header.doc-head { grid-column: 1 / -1; display: flex; flex-direction: column; gap: 6px; padding-bottom: 8px; }
.eyebrow { font-size: .75rem; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: var(--fg-2); }
h1 { margin: 0; font: 600 clamp(2rem, 4vw, 2.8rem)/1.1 var(--font-display); letter-spacing: -.01em; text-wrap: balance; }
h1 span { color: var(--accent); }
.meta { color: var(--fg-2); font-size: .9rem; }
nav.toc { position: sticky; top: calc(env(safe-area-inset-top, 0px) + 16px); max-height: calc(100vh - 32px); overflow-y: auto;
  background: var(--surface); border: 1px solid var(--line); border-radius: 16px; padding: 14px 10px; }
nav.toc p { margin: 0 8px 8px; }
nav.toc ol { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 1px; }
nav.toc a { display: flex; gap: 10px; padding: 6px 8px; border-radius: 8px; color: var(--fg); text-decoration: none; font-size: .9rem; line-height: 1.35; }
nav.toc a:hover, nav.toc a:focus-visible { background: var(--surface-2); }
nav.toc a.active { background: var(--accent-soft); color: var(--accent); font-weight: 600; }
nav.toc .num { min-width: 1.4em; text-align: right; color: var(--fg-2); font-variant-numeric: tabular-nums; }
main { min-width: 0; background: var(--surface); border: 1px solid var(--line); border-radius: 20px; padding: clamp(20px, 4vw, 48px); }
main > :first-child { margin-top: 0; }
main h2 { font: 600 1.6rem/1.2 var(--font-display); margin: 2.6em 0 .6em; padding-top: .9em; border-top: 1px solid var(--line); text-wrap: balance; }
main h3 { font: 600 1.15rem/1.3 var(--font-body); margin: 1.8em 0 .5em; }
main h2, main h3 { position: relative; }
.anchor { position: absolute; left: -1.1em; color: var(--fg-2); text-decoration: none; opacity: 0; font-weight: 400; }
h2:hover .anchor, h3:hover .anchor, .anchor:focus { opacity: .6; }
main p, main li { max-width: 72ch; }
main a { color: var(--accent); text-underline-offset: 3px; }
main a:focus-visible, nav.toc a:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
main ul, main ol { padding-left: 1.3em; }
main li + li { margin-top: .25em; }
blockquote { margin: 1.2em 0; padding: 12px 16px; border-radius: 12px; background: var(--accent-soft); color: var(--fg); }
blockquote p { margin: 0; }
hr { display: none; }
code { font-family: var(--font-mono); font-size: .86em; background: var(--code-bg); padding: .1em .35em; border-radius: 5px; overflow-wrap: anywhere; }
pre.code { overflow-x: auto; background: var(--code-bg); border-radius: 12px; padding: 14px 16px; line-height: 1.5; }
pre.code code { background: none; padding: 0; font-size: .85rem; overflow-wrap: normal; }
pre.mermaid { background: var(--surface-2); border-radius: 12px; padding: 16px; overflow-x: auto; text-align: center; }
.table-wrap { overflow-x: auto; margin: 1em 0 1.4em; border: 1px solid var(--line); border-radius: 12px; }
table { border-collapse: collapse; width: 100%; font-size: .9rem; }
th, td { text-align: left; vertical-align: top; padding: 9px 12px; border-bottom: 1px solid var(--line); }
tr:last-child td { border-bottom: 0; }
th { background: var(--surface-2); font-size: .74rem; text-transform: uppercase; letter-spacing: .06em; color: var(--fg-2); white-space: nowrap; }
td { font-variant-numeric: tabular-nums; }
@media (max-width: 860px) {
  .page { grid-template-columns: minmax(0, 1fr); gap: 20px; padding-block: 20px 48px; }
  nav.toc { position: static; max-height: none; }
  nav.toc ol { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); }
  .anchor { display: none; }
}
</style>
<div class="page">
  <header class="doc-head">
    <span class="eyebrow">Dokumentace aplikace</span>
    <h1>Moje židle <span>2026</span></h1>
    <span class="meta">Rezervace míst v kostele, platby, vstupenky a odbavení${updated ? ` · aktualizováno ${updated}` : ''}</span>
  </header>
  <nav class="toc" aria-label="Kapitoly">
    <p class="eyebrow">Kapitoly</p>
    <ol>
${nav}
    </ol>
  </nav>
  <main>
${body}
  </main>
</div>
<script>
// Highlight the chapter currently in view.
(() => {
  const links = new Map([...document.querySelectorAll('nav.toc a')].map((a) => [a.getAttribute('href').slice(1), a]));
  const observer = new IntersectionObserver((entries) => {
    for (const e of entries) {
      if (!e.isIntersecting) continue;
      links.forEach((a) => a.classList.remove('active'));
      links.get(e.target.id)?.classList.add('active');
    }
  }, { rootMargin: '0px 0px -75% 0px' });
  document.querySelectorAll('main h2[id]').forEach((h) => observer.observe(h));
})();
</script>
`;

writeFileSync(TARGET, html);
console.log(`docs/dokumentace.html written (${toc.length} chapters)`);
