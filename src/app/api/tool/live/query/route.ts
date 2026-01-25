import { NextRequest, NextResponse } from "next/server";
import { db } from "@/lib/db";
import { z } from "zod";

// ===== Zod Schemas =====

const FiltersSchema = z
  .object({
    connector: z.string().optional(),
    entityType: z.string().optional(),
    sku: z.string().optional(),
    productId: z.union([z.string(), z.number()]).optional(),
  })
  .passthrough();

const ToolQuerySchema = z.object({
  siteUrl: z.string().url(),
  question: z.string().optional(),
  mode: z.enum(["live_only", "hybrid", "kb_only"]).default("hybrid"),
  freshnessSeconds: z.number().int().positive().default(900), // 15 minutes default
  filters: FiltersSchema.optional(),
  limit: z.number().int().positive().max(100).default(10),
});

export async function POST(request: NextRequest) {
  const startTime = Date.now();

  try {
    // 1. Extract API key from header
    const apiKey = request.headers.get("x-api-key");
    if (!apiKey) {
      return NextResponse.json(
        { success: false, error: "Missing x-api-key header" },
        { status: 401 }
      );
    }

    // 2. Parse and validate body
    const body = await request.json();
    const parseResult = ToolQuerySchema.safeParse(body);

    if (!parseResult.success) {
      return NextResponse.json(
        {
          success: false,
          error: "Invalid payload",
          details: parseResult.error.flatten(),
        },
        { status: 400 }
      );
    }

    const data = parseResult.data;

    // 3. Validate API key
    const apiKeyRecord = await db.apiKey.findUnique({
      where: { key: apiKey },
    });

    if (!apiKeyRecord || !apiKeyRecord.active) {
      // Log failed query
      await logQuery({
        apiKeyId: null,
        siteId: null,
        mode: data.mode,
        question: data.question,
        filters: data.filters,
        status: "error",
        errorMessage: "Invalid or inactive API key",
        latencyMs: Date.now() - startTime,
        resultCount: 0,
      });

      return NextResponse.json(
        { success: false, error: "Invalid or inactive API key" },
        { status: 401 }
      );
    }

    // 4. Find the site
    const site = await db.site.findFirst({
      where: { url: data.siteUrl },
      include: {
        liveConnectors: true,
      },
    });

    if (!site) {
      await logQuery({
        apiKeyId: apiKeyRecord.id,
        siteId: null,
        mode: data.mode,
        question: data.question,
        filters: data.filters,
        status: "error",
        errorMessage: "Site not found",
        latencyMs: Date.now() - startTime,
        resultCount: 0,
      });

      return NextResponse.json(
        {
          success: false,
          error:
            "Site not found. Ensure Live Sync is enabled and data has been ingested.",
        },
        { status: 404 }
      );
    }

    // 5. Build query for live snapshots
    const freshnessThreshold = new Date(
      Date.now() - data.freshnessSeconds * 1000
    );

    // Build where clause for snapshots
    const snapshotWhere: {
      connector: { siteId: string; type?: string };
      occurredAt: { gte: Date };
      entityType?: string;
      entityId?: string;
    } = {
      connector: { siteId: site.id },
      occurredAt: { gte: freshnessThreshold },
    };

    // Apply filters
    if (data.filters) {
      if (data.filters.connector) {
        snapshotWhere.connector.type = data.filters.connector;
      }
      if (data.filters.entityType) {
        snapshotWhere.entityType = data.filters.entityType;
      }
      if (data.filters.sku) {
        snapshotWhere.entityId = data.filters.sku;
      } else if (data.filters.productId) {
        snapshotWhere.entityId = String(data.filters.productId);
      }
    }

    // 6. Query live snapshots
    let liveResults: Awaited<
      ReturnType<typeof db.liveEntitySnapshot.findMany>
    > = [];

    if (data.mode !== "kb_only") {
      liveResults = await db.liveEntitySnapshot.findMany({
        where: snapshotWhere,
        include: {
          connector: true,
        },
        orderBy: { occurredAt: "desc" },
        take: data.limit,
      });
    }

    // 7. Optionally query static KB (hybrid mode)
    let kbResults: Awaited<ReturnType<typeof db.scrapedPage.findMany>> = [];

    if (data.mode === "hybrid" || data.mode === "kb_only") {
      // Simple keyword search over scraped pages if question provided
      if (data.question) {
        kbResults = await db.scrapedPage.findMany({
          where: {
            siteId: site.id,
            OR: [
              { title: { contains: data.question, mode: "insensitive" } },
              { contentToon: { contains: data.question, mode: "insensitive" } },
            ],
          },
          take: 5,
        });
      }
    }

    // 8. Build response
    const latencyMs = Date.now() - startTime;

    // Build answer from live results
    const answerParts: string[] = [];
    const answerData: Record<string, unknown>[] = [];
    const citations: {
      type: "live" | "kb";
      connector?: string;
      occurredAt?: string;
      entityId?: string;
      url?: string;
      title?: string;
    }[] = [];

    for (const snapshot of liveResults) {
      // Build human-readable text
      if (snapshot.entityType === "product") {
        const stockInfo =
          snapshot.stockQuantity != null
            ? `${snapshot.stockStatus} (qty ${snapshot.stockQuantity})`
            : snapshot.stockStatus || "unknown";

        answerParts.push(
          `${snapshot.entityId} (${snapshot.name || "Unknown"}): ${snapshot.currency || "USD"} ${snapshot.price?.toFixed(2) || "N/A"}, ${stockInfo}`
        );
      } else {
        answerParts.push(
          `${snapshot.entityType} ${snapshot.entityId}: ${snapshot.name || "data available"}`
        );
      }

      // Structured data
      answerData.push({
        entityType: snapshot.entityType,
        entityId: snapshot.entityId,
        name: snapshot.name,
        currency: snapshot.currency,
        price: snapshot.price,
        stockStatus: snapshot.stockStatus,
        stockQuantity: snapshot.stockQuantity,
        permalink: snapshot.permalink,
        data: snapshot.data,
      });

      // Citation
      citations.push({
        type: "live",
        connector: snapshot.connector.type,
        occurredAt: snapshot.occurredAt.toISOString(),
        entityId: snapshot.entityId,
      });
    }

    // Add KB context if in hybrid mode and we have results
    for (const page of kbResults) {
      citations.push({
        type: "kb",
        url: page.url,
        title: page.title || undefined,
      });
    }

    // Log successful query
    await logQuery({
      apiKeyId: apiKeyRecord.id,
      siteId: site.id,
      mode: data.mode,
      question: data.question,
      filters: data.filters,
      status: "success",
      latencyMs,
      resultCount: liveResults.length + kbResults.length,
    });

    return NextResponse.json({
      success: true,
      answer: {
        text:
          answerParts.length > 0
            ? answerParts.join("\n")
            : "No live data found within the freshness window.",
        data: answerData,
      },
      citations,
      meta: {
        mode: data.mode,
        freshnessSeconds: data.freshnessSeconds,
        freshnessThreshold: freshnessThreshold.toISOString(),
        liveResultCount: liveResults.length,
        kbResultCount: kbResults.length,
        latencyMs,
      },
    });
  } catch (error) {
    console.error("Tool query error:", error);
    return NextResponse.json(
      { success: false, error: "Internal server error" },
      { status: 500 }
    );
  }
}

// Helper to log queries
async function logQuery(params: {
  apiKeyId: string | null;
  siteId: string | null;
  mode: string;
  question?: string;
  filters?: object;
  status: string;
  errorMessage?: string;
  latencyMs: number;
  resultCount: number;
}) {
  try {
    await db.toolQueryLog.create({
      data: {
        apiKeyId: params.apiKeyId,
        siteId: params.siteId,
        mode: params.mode,
        question: params.question,
        filters: params.filters,
        status: params.status,
        errorMessage: params.errorMessage,
        latencyMs: params.latencyMs,
        resultCount: params.resultCount,
      },
    });
  } catch (err) {
    console.error("Failed to log query:", err);
  }
}
