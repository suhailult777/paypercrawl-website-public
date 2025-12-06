import { NextRequest, NextResponse } from 'next/server';
import { db } from '@/lib/db';

export async function POST(request: NextRequest) {
  try {
    const body = await request.json();
    const { api_key, site_url, content } = body;

    if (!api_key || !content || !Array.isArray(content)) {
      return NextResponse.json(
        { success: false, error: 'Invalid payload' },
        { status: 400 }
      );
    }

    // Validate API Key
    const apiKeyRecord = await db.apiKey.findUnique({
      where: { key: api_key },
      include: { user: true }
    });

    if (!apiKeyRecord || !apiKeyRecord.active) {
       return NextResponse.json(
        { success: false, error: 'Invalid or inactive API key' },
        { status: 401 }
      );
    }

    const userId = apiKeyRecord.userId;
    const validSiteUrl = site_url || 'unknown-url';

    // Find or Create Site
    const site = await db.site.upsert({
      where: {
        userId_url: {
          userId: userId,
          url: validSiteUrl
        }
      },
      update: {
        apiKey: api_key,
        updatedAt: new Date()
      },
      create: {
        userId: userId,
        url: validSiteUrl,
        apiKey: api_key,
        name: validSiteUrl !== 'unknown-url' ? new URL(validSiteUrl).hostname : 'Unknown Site'
      }
    });

    // Process Content (Upsert ScrapedPages)
    const results = [];
    for (const item of content) {
      const { url, title, content_toon, original_json } = item;
      
      if (!url) continue;

      const page = await db.scrapedPage.upsert({
        where: {
          siteId_url: {
            siteId: site.id,
            url: url
          }
        },
        update: {
          title: title,
          contentToon: content_toon,
          originalJson: original_json,
          status: 'scraped',
          lastScraped: new Date(),
          updatedAt: new Date()
        },
        create: {
          siteId: site.id,
          url: url,
          title: title,
          contentToon: content_toon,
          originalJson: original_json,
          status: 'scraped',
          lastScraped: new Date()
        }
      });
      results.push(page.id);
    }

    return NextResponse.json({ success: true, count: results.length });

  } catch (error) {
    console.error('Sync error:', error);
    return NextResponse.json(
      { success: false, error: 'Internal Server Error' },
      { status: 500 }
    );
  }
}
