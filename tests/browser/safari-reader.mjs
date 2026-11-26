// Supply an independently obtained Safari ReaderArticleFinder.js; do not bundle
// Apple's proprietary detector. Playwright WebKit alone does not include Reader.
// SAFARI_READER_SCRIPT=/path/to/ReaderArticleFinder.js node tests/browser/safari-reader.mjs
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const { webkit } = await import(
    process.env.PLAYWRIGHT_MODULE_PATH || 'playwright'
);

assert.ok(
    process.env.SAFARI_READER_SCRIPT,
    'Set SAFARI_READER_SCRIPT to a Safari ReaderArticleFinder.js reference',
);
const detector = readFileSync(process.env.SAFARI_READER_SCRIPT, 'utf8');
const shells = ['resources/views/app.blade.php', 'frontend/index.html'];
const browser = await webkit.launch();
try {
    for (const width of [390, 1280]) {
        const page = await browser.newPage({
            viewport: { width, height: 900 },
        });
        await page.route('https://reader-fixture.test/**', (route) =>
            route.fulfill({
                body: '<!doctype html><html><head><title>Game analysis</title></head><body></body></html>',
                contentType: 'text/html',
            }),
        );
        await page.goto('https://reader-fixture.test/game');
        const content = `<main style="max-width:680px;margin:auto;font:18px/1.6 Georgia"><article><h1>Game analysis</h1>${Array.from({ length: 14 }, (_, i) => `<p>Matchup section ${i + 1}. ${'The matchup dashboard compares scoring, recent results, and team performance. Review the available evidence before making a selection. '.repeat(5)}</p>`).join('')}<button onclick="this.textContent='Selected'">Save pick</button></article></main>`;
        const available = async () => {
            await page.evaluate(detector);
            return page.evaluate(() =>
                Boolean(
                    new ReaderArticleFinder(document).isReaderModeAvailable(),
                ),
            );
        };
        await page.setContent(
            `<html><head><title>Game analysis</title></head><body>${content}</body></html>`,
        );
        assert.equal(
            await available(),
            true,
            `Control must trigger Reader at ${width}px`,
        );
        for (const shell of shells) {
            const source = readFileSync(
                new URL(`../../${shell}`, import.meta.url),
                'utf8',
            );
            const body = source.match(/<body\b[^>]*>/)?.[0];
            assert.ok(body, `${shell} has a server-rendered body`);
            const wrapper = source.match(
                /<div id="app-reader-comment"[^>]*>/,
            )?.[0];
            assert.ok(wrapper, `${shell} excludes Reader before the Vue mount`);
            await page.setContent(
                `<html><head><title>Game analysis</title></head>${body}${wrapper}${content}</div></body></html>`,
            );
            assert.equal(
                await available(),
                false,
                `${shell} must suppress Reader at ${width}px`,
            );
            assert.equal(await page.locator('article').isVisible(), true);
            await page.getByRole('button', { name: 'Save pick' }).click();
            await page.getByRole('button', { name: 'Selected' }).waitFor();
            // Inertia/router navigation replaces page content, leaving the shell intact.
            await page.evaluate((html) => {
                document.querySelector('main').outerHTML = html;
            }, content);
            assert.equal(
                await available(),
                false,
                `${shell} must retain protection after navigation`,
            );
            // Expanding research/analysis can make a previously short page look
            // like an article. Re-run detection after every disclosure mutation.
            await page.evaluate(() => {
                const main = document.querySelector('main');
                const article = main.querySelector('article');
                const details = document.createElement('details');
                details.innerHTML = '<summary>Toggle analysis</summary>';
                details.append(article);
                main.append(details);
            });
            for (let cycle = 0; cycle < 3; cycle++) {
                await page
                    .getByText('Toggle analysis', { exact: true })
                    .click();
                assert.equal(await page.locator('article').isVisible(), true);
                assert.equal(
                    await available(),
                    false,
                    `${shell} must suppress Reader after expanding analysis`,
                );
                // Cover async content insertion after a toggle as well.
                await page.evaluate(() => {
                    document
                        .querySelector('article')
                        .append(
                            document.querySelector('article p').cloneNode(true),
                        );
                });
                assert.equal(
                    await available(),
                    false,
                    `${shell} after loading expanded content`,
                );
                await page
                    .getByText('Toggle analysis', { exact: true })
                    .click();
                assert.equal(await page.locator('article').isVisible(), false);
                assert.equal(
                    await available(),
                    false,
                    `${shell} after collapsing analysis`,
                );
            }
        }
        await page.close();
    }
    console.log(
        'Reader control, both shells, navigation, repeated expand/collapse, inserted content and interaction passed at mobile/desktop widths.',
    );
} finally {
    await browser.close();
}
