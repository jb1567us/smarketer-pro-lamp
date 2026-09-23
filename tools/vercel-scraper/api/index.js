const chromium = require('@sparticuz/chromium');
const puppeteer = require('puppeteer-core');

module.exports = async (req, res) => {
    if (req.method !== 'POST') return res.status(405).end();

    const { url, wait_for } = req.body;
    if (!url) return res.status(400).json({ error: 'URL required' });

    let browser = null;
    try {
        browser = await puppeteer.launch({
            args: chromium.args,
            defaultViewport: chromium.defaultViewport,
            executablePath: await chromium.executablePath(),
            headless: chromium.headless,
        });

        const page = await browser.newPage();
        await page.goto(url, { waitUntil: 'networkidle0', timeout: 30000 });

        if (wait_for) {
            try { await page.waitForSelector(wait_for, { timeout: 5000 }); } catch (e) { }
        }

        const content = await page.content();
        const title = await page.title();

        await browser.close();

        res.status(200).json({ success: true, title, content });

    } catch (error) {
        if (browser) await browser.close();
        res.status(500).json({ success: false, error: error.message });
    }
};
