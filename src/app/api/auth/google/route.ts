import { NextRequest, NextResponse } from "next/server";
import { db } from "@/lib/db";

interface GoogleAuthRequest {
  email: string;
  name: string;
  photoURL?: string;
}

export async function POST(request: NextRequest) {
  try {
    console.log('[FIREBASE AUTH] Request received');
    const body: GoogleAuthRequest = await request.json();
    console.log('[FIREBASE AUTH] Request body:', JSON.stringify({ email: body?.email, name: body?.name }));
    
    const { email, name, photoURL } = body;

    if (!email || !name) {
      return NextResponse.json(
        { error: "Email and name are required" },
        { status: 400 }
      );
    }

    // Check if user exists in waitlist
    const waitlistEntry = await db.waitlistEntry.findUnique({
      where: { email },
    });
    
    console.log('[FIREBASE AUTH] Waitlist check for email:', email);
    console.log('[FIREBASE AUTH] Waitlist entry found:', !!waitlistEntry);
    if (waitlistEntry) {
      console.log('[FIREBASE AUTH] Entry status:', waitlistEntry.status);
      console.log('[FIREBASE AUTH] Entry ID:', waitlistEntry.id);
    }

    if (!waitlistEntry) {
      // User is not on waitlist - return 404
      return NextResponse.json(
        { 
          error: "not_on_waitlist",
          message: "You need to join our waitlist first to access the dashboard.",
          redirectTo: "/waitlist"
        },
        { status: 404 }
      );
    }

    // Check user status
    if (waitlistEntry.status === "pending") {
      console.log('[FIREBASE AUTH] Checking status. Current:', waitlistEntry.status, 'Expected: accepted');
      // User is on waitlist but not yet approved - return 403
      return NextResponse.json(
        {
          error: "waitlist_pending",
          message: "Your application is still being reviewed. You'll receive an email when approved.",
          redirectTo: "/waitlist?status=pending"
        },
        { status: 403 }
      );
    }

    if (waitlistEntry.status === "rejected") {
      return NextResponse.json(
        {
          error: "application_rejected",
          message: "Your application has been reviewed. Please contact support for more information.",
          redirectTo: "/waitlist?status=rejected"
        },
        { status: 403 }
      );
    }

    if (waitlistEntry.status === "invited" || waitlistEntry.status === "accepted") {
      console.log('[FIREBASE AUTH] User authorized! Status:', waitlistEntry.status);
      // Ensure the user is marked as accepted and has an invite token
      let inviteToken = waitlistEntry.inviteToken;

      // If they were invited, promote to accepted
      if (waitlistEntry.status === "invited") {
        // Generate a token if missing
        if (!inviteToken) {
          // Lazy import to avoid top-level crypto on edge if not needed
          const { randomBytes } = await import("crypto");
          inviteToken = randomBytes(32).toString("hex");
        }

        await db.waitlistEntry.update({
          where: { id: waitlistEntry.id },
          data: { status: "accepted", inviteToken },
        });
      } else if (!inviteToken) {
        // Already accepted but missing token (legacy rows) -> generate and persist
        const { randomBytes } = await import("crypto");
        inviteToken = randomBytes(32).toString("hex");
        await db.waitlistEntry.update({
          where: { id: waitlistEntry.id },
          data: { inviteToken },
        });
      }
      
      console.log('[FIREBASE AUTH] Success! Returning user data and invite token');
      console.log('[FIREBASE AUTH] Invite token:', inviteToken);

      // Return success with user data and invite token
      return NextResponse.json({
        success: true,
        user: {
          email: waitlistEntry.email,
          name: waitlistEntry.name,
          website: waitlistEntry.website,
        },
        inviteToken,
      });
    }

    // Fallback for unknown status
    return NextResponse.json(
      {
        error: "invalid_status",
        message: "Account status is invalid. Please contact support.",
        redirectTo: "/contact"
      },
      { status: 403 }
    );

  } catch (error) {
    console.error('[FIREBASE AUTH] Authentication error:', error);
    console.error('[FIREBASE AUTH] Error details:', error instanceof Error ? error.message : 'Unknown error');
    console.error('[FIREBASE AUTH] Error stack:', error instanceof Error ? error.stack : 'No stack');
    console.error("Google auth error:", error);
    return NextResponse.json(
      { error: "Internal server error" },
      { status: 500 }
    );
  }
}
