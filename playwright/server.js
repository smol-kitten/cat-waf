const express = require('express');
const { chromium } = require('playwright');
const sharp = require('sharp');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const app = express();
app.use(express.json());

const SCREENSHOT_DIR = process.env.SCREENSHOT_DIR || '/app/screenshots';
const CACHE_DIR = process.env.CACHE_DIR || '/app/cache';
const CACHE_TTL = parseInt(process.env.CACHE_TTL || '3600', 10); // 1 hour default
const MAX_CONCURRENT = parseInt(process.env.MAX_CONCURRENT || '3', 10);
const API_KEY = process.env.API_KEY || '';

// Ensure directories exist
[SCREENSHOT_DIR, CACHE_DIR].forEach(dir => {
    if (!fs.existsSync(dir)) {
        fs.mkdirSync(dir, { recursive: true });
    }
});

let browser = null;
let activeJobs = 0;

// Initialize browser
async function initBrowser() {
    if (!browser) {
        browser = await chromium.launch({
            headless: true,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu'
            ]
        });
    }
    return browser;
}

// Generate cache key from URL and options
function getCacheKey(url, options) {
    const data = JSON.stringify({ url, ...options });
    return crypto.createHash('md5').update(data).digest('hex');
}

// Check if cached screenshot exists and is valid
function getCachedScreenshot(cacheKey) {
    const cachePath = path.join(CACHE_DIR, `${cacheKey}.json`);
    if (fs.existsSync(cachePath)) {
        const meta = JSON.parse(fs.readFileSync(cachePath, 'utf8'));
        if (Date.now() < meta.expires) {
            const imagePath = path.join(SCREENSHOT_DIR, meta.filename);
            if (fs.existsSync(imagePath)) {
                return {
                    path: imagePath,
                    meta: meta
                };
            }
        }
        // Expired or missing, cleanup
        fs.unlinkSync(cachePath);
    }
    return null;
}

// Save screenshot metadata to cache
function cacheScreenshot(cacheKey, filename, meta) {
    const cachePath = path.join(CACHE_DIR, `${cacheKey}.json`);
    const cacheData = {
        filename,
        expires: Date.now() + (CACHE_TTL * 1000),
        created: Date.now(),
        ...meta
    };
    fs.writeFileSync(cachePath, JSON.stringify(cacheData));
}

// API Key middleware
function authMiddleware(req, res, next) {
    if (API_KEY) {
        const providedKey = req.headers['x-api-key'] || req.query.api_key;
        if (providedKey !== API_KEY) {
            return res.status(401).json({ error: 'Unauthorized' });
        }
    }
    next();
}

// Health check
app.get('/health', (req, res) => {
    res.json({
        status: 'healthy',
        activeJobs,
        maxConcurrent: MAX_CONCURRENT,
        browserReady: browser !== null
    });
});

// Take screenshot
app.post('/screenshot', authMiddleware, async (req, res) => {
    const {
        url,
        width = 1280,
        height = 800,
        fullPage = false,
        format = 'png',
        quality = 80,
        timeout = 30000,
        waitUntil = 'networkidle',
        delay = 0,
        selector = null,
        maskSelectors = [],
        deviceScale = 1,
        useCache = true
    } = req.body;

    if (!url) {
        return res.status(400).json({ error: 'URL is required' });
    }

    // Validate URL
    try {
        new URL(url);
    } catch (e) {
        return res.status(400).json({ error: 'Invalid URL' });
    }

    // Check cache first
    const cacheKey = getCacheKey(url, { width, height, fullPage, format, selector });
    if (useCache) {
        const cached = getCachedScreenshot(cacheKey);
        if (cached) {
            return res.json({
                success: true,
                cached: true,
                filename: cached.meta.filename,
                path: `/screenshots/${cached.meta.filename}`,
                width: cached.meta.width,
                height: cached.meta.height
            });
        }
    }

    // Check concurrent limit
    if (activeJobs >= MAX_CONCURRENT) {
        return res.status(429).json({ error: 'Too many concurrent requests' });
    }

    activeJobs++;
    let page = null;

    try {
        const browserInstance = await initBrowser();
        const context = await browserInstance.newContext({
            viewport: { width: parseInt(width), height: parseInt(height) },
            deviceScaleFactor: parseFloat(deviceScale)
        });

        page = await context.newPage();

        // Navigate to URL
        await page.goto(url, {
            waitUntil: waitUntil,
            timeout: parseInt(timeout)
        });

        // Optional delay
        if (delay > 0) {
            await page.waitForTimeout(parseInt(delay));
        }

        // Mask sensitive elements if specified
        if (maskSelectors.length > 0) {
            for (const sel of maskSelectors) {
                await page.evaluate((selector) => {
                    document.querySelectorAll(selector).forEach(el => {
                        el.style.backgroundColor = '#888';
                        el.style.color = '#888';
                        el.innerHTML = '[MASKED]';
                    });
                }, sel);
            }
        }

        // Take screenshot
        const screenshotOptions = {
            fullPage: fullPage,
            type: format === 'jpg' ? 'jpeg' : format
        };

        if (format === 'jpg' || format === 'jpeg') {
            screenshotOptions.quality = parseInt(quality);
        }

        let screenshotBuffer;
        if (selector) {
            const element = await page.$(selector);
            if (!element) {
                throw new Error(`Selector "${selector}" not found`);
            }
            screenshotBuffer = await element.screenshot(screenshotOptions);
        } else {
            screenshotBuffer = await page.screenshot(screenshotOptions);
        }

        // Generate filename
        const filename = `${cacheKey}.${format}`;
        const filepath = path.join(SCREENSHOT_DIR, filename);

        // Save screenshot
        fs.writeFileSync(filepath, screenshotBuffer);

        // Get image dimensions
        const metadata = await sharp(screenshotBuffer).metadata();

        // Cache the result
        if (useCache) {
            cacheScreenshot(cacheKey, filename, {
                url,
                width: metadata.width,
                height: metadata.height,
                format
            });
        }

        await context.close();

        res.json({
            success: true,
            cached: false,
            filename,
            path: `/screenshots/${filename}`,
            width: metadata.width,
            height: metadata.height
        });

    } catch (error) {
        console.error('Screenshot error:', error);
        res.status(500).json({
            error: error.message,
            url
        });
    } finally {
        activeJobs--;
    }
});

// Generate thumbnail
app.post('/thumbnail', authMiddleware, async (req, res) => {
    const {
        url,
        width = 320,
        height = 200,
        format = 'webp',
        quality = 75,
        useCache = true
    } = req.body;

    if (!url) {
        return res.status(400).json({ error: 'URL is required' });
    }

    const cacheKey = getCacheKey(url, { width, height, format, type: 'thumbnail' });
    
    if (useCache) {
        const cached = getCachedScreenshot(cacheKey);
        if (cached) {
            return res.json({
                success: true,
                cached: true,
                filename: cached.meta.filename,
                path: `/screenshots/${cached.meta.filename}`
            });
        }
    }

    if (activeJobs >= MAX_CONCURRENT) {
        return res.status(429).json({ error: 'Too many concurrent requests' });
    }

    activeJobs++;

    try {
        const browserInstance = await initBrowser();
        const context = await browserInstance.newContext({
            viewport: { width: 1280, height: 800 }
        });

        const page = await context.newPage();
        await page.goto(url, { waitUntil: 'networkidle', timeout: 30000 });

        const screenshot = await page.screenshot({ type: 'png' });
        await context.close();

        // Resize to thumbnail
        const thumbnail = await sharp(screenshot)
            .resize(parseInt(width), parseInt(height), {
                fit: 'cover',
                position: 'top'
            })
            .toFormat(format, { quality: parseInt(quality) })
            .toBuffer();

        const filename = `thumb_${cacheKey}.${format}`;
        const filepath = path.join(SCREENSHOT_DIR, filename);
        fs.writeFileSync(filepath, thumbnail);

        if (useCache) {
            cacheScreenshot(cacheKey, filename, { url, width, height, format, type: 'thumbnail' });
        }

        res.json({
            success: true,
            cached: false,
            filename,
            path: `/screenshots/${filename}`
        });

    } catch (error) {
        console.error('Thumbnail error:', error);
        res.status(500).json({ error: error.message });
    } finally {
        activeJobs--;
    }
});

// Serve screenshots
app.use('/screenshots', express.static(SCREENSHOT_DIR));

// List screenshots
app.get('/screenshots', authMiddleware, (req, res) => {
    const files = fs.readdirSync(SCREENSHOT_DIR).map(filename => {
        const stats = fs.statSync(path.join(SCREENSHOT_DIR, filename));
        return {
            filename,
            size: stats.size,
            created: stats.birthtime
        };
    });
    res.json({ screenshots: files });
});

// Delete screenshot
app.delete('/screenshot/:filename', authMiddleware, (req, res) => {
    const filename = req.params.filename;
    const filepath = path.join(SCREENSHOT_DIR, filename);
    
    if (!fs.existsSync(filepath)) {
        return res.status(404).json({ error: 'Screenshot not found' });
    }

    fs.unlinkSync(filepath);

    // Also remove cache entry
    const cacheFiles = fs.readdirSync(CACHE_DIR);
    for (const cacheFile of cacheFiles) {
        const cachePath = path.join(CACHE_DIR, cacheFile);
        try {
            const meta = JSON.parse(fs.readFileSync(cachePath, 'utf8'));
            if (meta.filename === filename) {
                fs.unlinkSync(cachePath);
                break;
            }
        } catch (e) {}
    }

    res.json({ success: true });
});

// Clear cache
app.post('/cache/clear', authMiddleware, (req, res) => {
    const files = fs.readdirSync(CACHE_DIR);
    let cleared = 0;
    
    for (const file of files) {
        fs.unlinkSync(path.join(CACHE_DIR, file));
        cleared++;
    }

    res.json({ success: true, cleared });
});

// Cache stats
app.get('/cache/stats', authMiddleware, (req, res) => {
    const cacheFiles = fs.readdirSync(CACHE_DIR);
    let totalSize = 0;
    let expired = 0;
    let valid = 0;

    for (const file of cacheFiles) {
        try {
            const meta = JSON.parse(fs.readFileSync(path.join(CACHE_DIR, file), 'utf8'));
            if (Date.now() > meta.expires) {
                expired++;
            } else {
                valid++;
            }
        } catch (e) {}
    }

    const screenshotFiles = fs.readdirSync(SCREENSHOT_DIR);
    for (const file of screenshotFiles) {
        const stats = fs.statSync(path.join(SCREENSHOT_DIR, file));
        totalSize += stats.size;
    }

    res.json({
        cacheEntries: cacheFiles.length,
        validEntries: valid,
        expiredEntries: expired,
        screenshotCount: screenshotFiles.length,
        totalSizeBytes: totalSize,
        totalSizeMB: (totalSize / 1024 / 1024).toFixed(2)
    });
});

// Start server
const PORT = process.env.PORT || 3000;

initBrowser().then(() => {
    app.listen(PORT, '0.0.0.0', () => {
        console.log(`Playwright screenshot service running on port ${PORT}`);
    });
}).catch(err => {
    console.error('Failed to initialize browser:', err);
    process.exit(1);
});

// Graceful shutdown
process.on('SIGTERM', async () => {
    console.log('Shutting down...');
    if (browser) {
        await browser.close();
    }
    process.exit(0);
});
