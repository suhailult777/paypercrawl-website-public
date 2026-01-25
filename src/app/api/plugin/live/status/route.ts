import { NextRequest, NextResponse } from "next/server";
import { db } from "@/lib/db";

export async function GET(request: NextRequest) {
  try {
    // 1. Extract API key from header
    const apiKey = request.headers.get("x-api-key");
    if (!apiKey) {
      return NextResponse.json(
        { success: false, error: "Missing x-api-key header" },
        { status: 401 }
      );
    }

    // 2. Validate API key
    const apiKeyRecord = await db.apiKey.findUnique({
      where: { key: apiKey },
    });

    if (!apiKeyRecord || !apiKeyRecord.active) {
      return NextResponse.json(
        { success: false, error: "Invalid or inactive API key" },
        { status: 401 }
      );
    }

    // 3. Get site URL from query param
    const { searchParams } = new URL(request.url);
    const siteUrl = searchParams.get("siteUrl");

    if (!siteUrl) {
      return NextResponse.json(
        { success: false, error: "Missing siteUrl parameter" },
        { status: 400 }
      );
    }

    // 4. Find site
    const site = await db.site.findFirst({
      where: { url: siteUrl },
      include: {
        liveConnectors: {
          select: {
            id: true,
            type: true,
            status: true,
            lastEventAt: true,
            eventCount: true,
            lastError: true,
            lastErrorAt: true,
            createdAt: true,
          },
        },
      },
    });

    if (!site) {
      return NextResponse.json({
        success: true,
        data: {
          registered: false,
          message:
            "Site not registered. Enable Live Sync and send your first event.",
        },
      });
    }

    // 5. Get snapshot counts per connector
    const connectorStats = await Promise.all(
      site.liveConnectors.map(async (connector) => {
        const snapshotCount = await db.liveEntitySnapshot.count({
          where: { connectorId: connector.id },
        });

        const recentEventCount = await db.liveEvent.count({
          where: {
            connectorId: connector.id,
            occurredAt: { gte: new Date(Date.now() - 24 * 60 * 60 * 1000) },
          },
        });

        return {
          ...connector,
          snapshotCount,
          eventsLast24h: recentEventCount,
        };
      })
    );

    return NextResponse.json({
      success: true,
      data: {
        registered: true,
        siteId: site.id,
        siteName: site.name,
        connectors: connectorStats,
      },
    });
  } catch (error) {
    console.error("Live status error:", error);
    return NextResponse.json(
      { success: false, error: "Internal server error" },
      { status: 500 }
    );
  }
}
