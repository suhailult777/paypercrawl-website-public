# PayPerCrawl AI Monetization Platform - Development Guide

## Architecture Overview

**PayPerCrawl** is a dual-component SaaS platform for monetizing AI bot traffic. The architecture consists of:

1. **Next.js 15 SaaS Platform** (`/src`) - Main web application with dashboard, API, and marketing pages
2. **WordPress Plugin** (`/crawlguard-wp-main`) - PHP plugin that integrates with the SaaS platform
3. **Custom Next.js Server** (`server.ts`) - Hybrid server combining Next.js + Socket.IO for real-time features

### Key Architectural Decisions

- **Standalone Output Mode**: Configured for Hostinger/VPS deployment (`next.config.ts` uses `output: 'standalone'`)
- **Custom Server Pattern**: HTTP server wraps Next.js to enable Socket.IO at `/api/socketio` path. Admin routes are protected directly in `server.ts` (bypasses Next.js middleware) via cookie-based `admin_session` check
- **Dual Authentication System**:
  - **Dashboard**: Token-based (`invite_token` cookie) OR Firebase Auth session
  - **Admin Panel**: Separate `admin_session` cookie OR `ADMIN_API_KEY` Bearer token
- **Dual Database Strategy**: Prisma for Next.js (PostgreSQL via Neon), WordPress plugin uses WP database tables

## Development Workflows

### Starting the Dev Server

```bash
npm run dev  # Uses nodemon + tsx to run server.ts with hot reload
# Server runs on http://0.0.0.0:3000 with Socket.IO enabled at /api/socketio
```

**CRITICAL**: Always use `npm run dev`, NOT `next dev` - the custom server pattern requires `server.ts` entry point. The custom server handles:

- Socket.IO integration at `/api/socketio` path
- Admin route protection (checks `admin_session` cookie before Next.js middleware)
- Both Next.js page requests and WebSocket connections

### Database Management

```bash
npm run db:push      # Push Prisma schema changes to database (no migration files)
npm run db:generate  # Regenerate Prisma client (runs post-install automatically)
npm run db:migrate   # Create migration files for version control
npm run db:reset     # Reset database (WARNING: deletes all data)
```

**Important**:

- Prisma client lives in `src/lib/db.ts` with singleton pattern for hot reload safety
- Export both `db` (sync singleton) and `getDb()` (async with connection guarantee)
- Uses `globalForPrisma` to prevent multiple instances in development
- Background connection attempt for legacy code, but `getDb()` is preferred for new code

### Building & Deployment

```bash
npm run build                 # Production build (creates .next folder + standalone output)
npm run start                 # Production start (uses tsx server.ts, not next start)
npm run start:next            # Next.js only (no Socket.IO) - for basic hosting
npm run start:hostinger       # Build + start for Hostinger VPS (runs build then start:next)
```

**Deployment Scripts**:

- `deploy-hostinger.sh` - Bash script for Linux VPS deployment
- `deploy-hostinger.bat` - Windows equivalent

**Build Configuration**:

- `output: 'standalone'` creates self-contained build in `.next/standalone/`
- `trailingSlash: true` for better hosting compatibility
- `typescript.ignoreBuildErrors: true` and `eslint.ignoreDuringBuilds: true` for rapid iteration
- `reactStrictMode: false` to prevent double-mounting issues

## Project-Specific Conventions

### API Route Pattern

All API routes in `src/app/api/*/route.ts` follow this structure:

```typescript
import { NextRequest, NextResponse } from "next/server";
import { db } from "@/lib/db";
import { z } from "zod";

const schema = z.object({
  field: z.string().min(2, "Validation message"),
});

export async function POST(request: NextRequest) {
  try {
    // 1. Parse and validate input with Zod
    const body = await request.json();
    const validatedData = schema.parse(body);

    // 2. Business logic with database operations
    const result = await db.model.create({ data: validatedData });

    // 3. Return success with data
    return NextResponse.json({ success: true, data: result });
  } catch (error) {
    // 4. Handle errors with proper status codes
    if (error instanceof z.ZodError) {
      return NextResponse.json({ error: error.errors }, { status: 400 });
    }
    return NextResponse.json(
      { error: "Internal server error" },
      { status: 500 }
    );
  }
}
```

**Critical Conventions**:

- Always use Zod for input validation (see `src/app/api/contact/route.ts` example)
- Return `status: 400` for validation errors, `500` for server errors, `401` for auth failures
- Use `@/lib/db` import path for database access
- Log important operations to audit tables (e.g., `EmailLog` for emails)

### Authentication Flow

The platform uses **TWO separate authentication systems**:

#### 1. Dashboard Authentication (Dual-Layer)

**Primary**: Invite Token System (via `middleware.ts`)

- Cookie: `invite_token` (30-day expiry, httpOnly: false for client access)
- URL param: `?token=xxx` (automatically stored to cookie on first visit)
- Database: `WaitlistEntry.inviteToken` (UUID format)
- Flow: URL token → Cookie → Database lookup

**Secondary**: Firebase Auth (Google OAuth)

- Cookies: `__session`, `firebase:host`, `g_state`
- Client-side: `src/lib/firebase.ts` with Google OAuth provider
- Used as fallback when invite token is absent

**Protected Route Logic** (in `middleware.ts`):

```typescript
// /dashboard route checks for token OR Firebase session
if (!tokenToUse && !hasFirebaseSession) {
  redirect to /waitlist
}
// Token in URL → store in cookie, then continue
```

#### 2. Admin Panel Authentication (Stricter)

**Dual verification points**:

1. **Custom Server Check** (`server.ts` lines 38-70):
   - Runs BEFORE Next.js middleware
   - Checks `admin_session` cookie OR `ADMIN_API_KEY` header
   - Redirects to `/admin/login` if unauthorized
   - Prevents showing login page if already authenticated

2. **Middleware Check** (`middleware.ts` lines 25-43):
   - Secondary protection layer
   - Returns 401 for API routes, redirects for pages

**Admin Authentication Flow**:

```
POST /api/admin/session (with ADMIN_API_KEY)
  → Sets admin_session=1 cookie
  → Redirects to /admin
```

**Key Difference**: Admin uses cookie-based sessions, not tokens. Cookie expires on browser close.

### Email Service Integration

All emails use **Resend API** via `src/lib/email.ts`:

```typescript
import {
  sendWaitlistWelcome,
  sendInvitation,
  sendSupportTicketReceipt,
} from "@/lib/email";

// Every email is logged to EmailLog table for audit trail
await sendInvitation({ to, name, inviteToken });
```

**Email Functions Available**:

- `sendWaitlistWelcome(email, name)` - Welcome email after waitlist signup
- `sendInvitation({ to, name, inviteToken })` - Dashboard access invitation with magic link
- `sendSupportTicketReceipt(email, ticketData)` - User-facing support ticket confirmation
- `sendSupportTicketInternal(ticketData)` - Internal team notification for new tickets
- `sendApplicationReceipt(email, applicationData)` - Job/beta application confirmation

**Lazy Initialization Pattern**:

- Resend client constructed on first use via `getResend()` function
- Prevents build-time errors if `RESEND_API_KEY` is missing
- All emails logged to `EmailLog` table with status (sent/failed)

### Theme System

Three-theme support (Light, Dark, GitHub Dark Dimmed) via `next-themes`:

- **CSS Variables**: Defined in `src/app/globals.css` with `.dim` class
- **Components**: Use semantic tokens like `bg-background`, `text-foreground`
- **No Hardcoded Colors**: Always use Tailwind utilities with theme variables
- **Keyboard Shortcut**: `Ctrl+Shift+T` cycles themes (see `src/components/theme-keyboard-shortcuts.tsx`)

**Testing Themes**: Visit `/theme-test` page for comprehensive component showcase.

### WordPress Plugin Integration

Plugin communicates via REST API (`/api/plugin/*` and `/api/apikeys/*`):

1. **Plugin Download**: `GET /api/plugin/download` - Serves zipped plugin from `/crawlguard-wp-main/`
2. **API Key Generation**: `POST /api/apikeys/generate` - Creates key in database
3. **API Key Validation**: `POST /api/apikeys/validate` - Used by WordPress plugin to verify access

**Plugin Architecture** (`crawlguard-wp-main/crawlguard-wp.php`):

- **Singleton Pattern**: `CrawlGuardWP::get_instance()` - Single instance throughout request lifecycle
- **Core Classes**:
  - `CrawlGuard_Bot_Detector` (`includes/class-bot-detector.php`) - User-Agent parsing, bot identification
  - `CrawlGuard_API_Client` (`includes/class-api-client.php`) - HTTP client for PayPerCrawl API
  - `CrawlGuard_Admin` (`includes/class-admin.php`) - WordPress admin interface and settings
  - `CrawlGuard_Analytics` (`includes/class-analytics.php`) - Bot traffic logging and reporting
- **Feature Flags**: Rate limiting, JS challenges, advanced bot detection (toggled via API responses)

**Building Plugin**:

```bash
cd crawlguard-wp-main
node create-plugin-zip.js  # Creates production-ready .zip with minified assets
```

**Plugin-to-SaaS Data Flow**:

1. WordPress detects bot → `CrawlGuard_Bot_Detector::is_bot()`
2. Validate API key → `POST /api/apikeys/validate`
3. Log event → `CrawlGuard_Analytics::log_event()`
4. Apply monetization rules (paywall, rate limit, etc.)

## Critical File Locations

### Database & State

- `prisma/schema.prisma` - Database schema (models: WaitlistEntry, EmailLog, BetaApplication)
- `src/lib/db.ts` - Prisma singleton client

### Configuration

- `.env.example` - Template for required environment variables
- `middleware.ts` - Route protection and CORS headers
- `next.config.ts` - Standalone mode, image optimization, Hostinger compatibility

### Core Services

- `src/lib/email.ts` - Email service (Resend API)
- `src/lib/firebase.ts` - Firebase Auth configuration
- `src/lib/socket.ts` - Socket.IO setup for real-time features
- `server.ts` - Custom server entry point (Next.js + Socket.IO)

### Key Features

- `src/app/api/bot-analyzer/analyze/route.ts` - Bot traffic analysis engine (robots.txt, sitemap, tech detection)
- `src/app/api/waitlist/` - Waitlist management endpoints
- `src/app/dashboard/` - Protected dashboard pages
- `src/app/admin/` - Admin interface for managing invitations

## Testing Strategy

- **Playwright Tests**: Located in `/tests` directory
- **WordPress Plugin Tests**: PHPUnit tests in `crawlguard-wp-main/tests/`
- **Manual Testing Guides**: See `PRODUCTION_TESTING_GUIDE.md` and `DASHBOARD_PROTECTION_TEST.md`

**No automated test runner configured** - Tests are run manually using Playwright CLI.

## Common Gotchas

1. **Socket.IO Routing**: Socket.IO path is `/api/socketio`, not `/socket.io` - configured in `server.ts`
2. **TypeScript/ESLint in Production**: Both set to `ignoreBuildErrors: true` and `ignoreDuringBuilds: true` for faster iterations
3. **React Strict Mode**: Disabled (`reactStrictMode: false`) to prevent double-mounting issues with third-party libraries
4. **Image Optimization**: Set to `unoptimized: false` for Hostinger compatibility
5. **Prisma Generate**: Runs automatically on `npm install` via `postinstall` script
6. **Environment Variables**: Client-side vars must be prefixed with `NEXT_PUBLIC_`

## WordPress Plugin Development

**Location**: `/crawlguard-wp-main/`

### Building the Plugin

```bash
cd crawlguard-wp-main
node create-plugin-zip.js  # Creates production-ready ZIP file
```

### Testing Locally

1. Copy plugin folder to WordPress `wp-content/plugins/`
2. Activate via WordPress admin
3. Configure API key from PayPerCrawl dashboard
4. Test bot detection on any page

**Key Classes**:

- `CrawlGuard_Bot_Detector` - Main bot detection logic
- `CrawlGuard_API_Client` - Communicates with SaaS platform
- `CrawlGuard_Admin` - WordPress admin interface
- `CrawlGuard_Analytics` - Bot traffic logging

## External Dependencies

- **Database**: Neon PostgreSQL (DATABASE_URL)
- **Email**: Resend API (RESEND_API_KEY)
- **Auth**: Firebase (Google OAuth)
- **Optional**: Firecrawl API for enhanced website analysis (FIRECRAWL_API_KEY)

## When Editing Components

- **UI Components**: Located in `src/components/ui/` (shadcn/ui based)
- **Theme Components**: Use `src/components/theme-aware/` wrappers for theme-responsive styling
- **Forms**: Use `react-hook-form` + `zod` for validation (see `src/components/waitlist-form.tsx`)
- **Async State**: Prefer `@tanstack/react-query` for server state management

## Documentation References

- **Full Setup**: `README.md`
- **Deployment**: `HOSTINGER_DEPLOYMENT_GUIDE.md`, `DEPLOYMENT_READY_SUMMARY.md`
- **Features**: `BOT_ANALYZER_QUICK_START.md`, `INVITE_ONLY_DASHBOARD_GUIDE.md`
- **Theme System**: `THEME_SYSTEM.md`, `DIM_THEME_ENHANCEMENTS.md`
