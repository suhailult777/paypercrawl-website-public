import { NextRequest, NextResponse } from "next/server";
import { db } from "@/lib/db";
import { z } from "zod";

// ===== Zod Schemas =====

const PriceSchema = z.object({
  regular: z.number().optional(),
  sale: z.number().nullable().optional(),
  effective: z.number(),
});

const StockSchema = z.object({
  status: z.enum(["instock", "outofstock", "onbackorder"]),
  quantity: z.number().nullable().optional(),
  backorders: z.string().optional(),
});

const ProductEntitySchema = z.object({
  type: z.literal("product"),
  productId: z.union([z.string(), z.number()]),
  sku: z.string().optional(),
  name: z.string(),
  currency: z.string().default("USD"),
  price: PriceSchema,
  stock: StockSchema,
  permalink: z.string().optional(),
});

// Generic entity schema for future connectors
const GenericEntitySchema = z
  .object({
    type: z.string(),
  })
  .passthrough();

const LiveIngestSchema = z.object({
  siteUrl: z.string().url(),
  connector: z.string().min(1),
  eventType: z.string().min(1),
  eventId: z.string().min(1),
  occurredAt: z.string().datetime(),
  entity: z.union([ProductEntitySchema, GenericEntitySchema]),
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
    const parseResult = LiveIngestSchema.safeParse(body);

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

    // 3. Validate API key and get site
    const apiKeyRecord = await db.apiKey.findUnique({
      where: { key: apiKey },
      include: { user: true },
    });

    if (!apiKeyRecord || !apiKeyRecord.active) {
      return NextResponse.json(
        { success: false, error: "Invalid or inactive API key" },
        { status: 401 }
      );
    }

    // 4. Find or create Site
    const site = await db.site.upsert({
      where: {
        userId_url: {
          userId: apiKeyRecord.userId,
          url: data.siteUrl,
        },
      },
      update: {
        apiKey: apiKey,
        updatedAt: new Date(),
      },
      create: {
        userId: apiKeyRecord.userId,
        url: data.siteUrl,
        apiKey: apiKey,
        name: new URL(data.siteUrl).hostname,
      },
    });

    // 5. Find or create LiveConnector
    const connector = await db.liveConnector.upsert({
      where: {
        siteId_type: {
          siteId: site.id,
          type: data.connector,
        },
      },
      update: {
        lastEventAt: new Date(),
        eventCount: { increment: 1 },
        status: "active",
        lastError: null,
        lastErrorAt: null,
        updatedAt: new Date(),
      },
      create: {
        siteId: site.id,
        type: data.connector,
        name: data.connector.charAt(0).toUpperCase() + data.connector.slice(1),
        status: "active",
        lastEventAt: new Date(),
        eventCount: 1,
      },
    });

    // 6. Check for duplicate event (idempotency)
    const existingEvent = await db.liveEvent.findUnique({
      where: {
        connectorId_eventId: {
          connectorId: connector.id,
          eventId: data.eventId,
        },
      },
    });

    if (existingEvent) {
      return NextResponse.json({
        success: true,
        stored: false,
        deduped: true,
        receivedAt: new Date().toISOString(),
      });
    }

    // 7. Extract entity identifiers
    const entity = data.entity;
    const entityType = entity.type;
    const entityId =
      "sku" in entity && entity.sku
        ? String(entity.sku)
        : "productId" in entity
          ? String(entity.productId)
          : "id" in entity
            ? String(entity.id)
            : data.eventId;

    // 8. Create LiveEvent (append-only log)
    await db.liveEvent.create({
      data: {
        connectorId: connector.id,
        eventId: data.eventId,
        eventType: data.eventType,
        occurredAt: new Date(data.occurredAt),
        payload: entity as object,
        entityType: entityType,
        entityId: entityId,
      },
    });

    // 9. Upsert LiveEntitySnapshot (latest state)
    const snapshotData: {
      name?: string;
      currency?: string;
      price?: number;
      stockStatus?: string;
      stockQuantity?: number;
      permalink?: string;
      data: object;
      occurredAt: Date;
    } = {
      data: entity as object,
      occurredAt: new Date(data.occurredAt),
    };

    // Extract normalized fields for WooCommerce product
    if (entity.type === "product") {
      const productEntity = entity as z.infer<typeof ProductEntitySchema>;
      snapshotData.name = productEntity.name;
      snapshotData.currency = productEntity.currency;
      snapshotData.price = productEntity.price.effective;
      snapshotData.stockStatus = productEntity.stock.status;
      snapshotData.stockQuantity = productEntity.stock.quantity ?? undefined;
      snapshotData.permalink = productEntity.permalink;
    }

    await db.liveEntitySnapshot.upsert({
      where: {
        connectorId_entityType_entityId: {
          connectorId: connector.id,
          entityType: entityType,
          entityId: entityId,
        },
      },
      update: {
        ...snapshotData,
        updatedAt: new Date(),
      },
      create: {
        connectorId: connector.id,
        entityType: entityType,
        entityId: entityId,
        ...snapshotData,
      },
    });

    const latencyMs = Date.now() - startTime;

    return NextResponse.json({
      success: true,
      stored: true,
      deduped: false,
      receivedAt: new Date().toISOString(),
      latencyMs,
    });
  } catch (error) {
    console.error("Live ingest error:", error);
    return NextResponse.json(
      { success: false, error: "Internal server error" },
      { status: 500 }
    );
  }
}
