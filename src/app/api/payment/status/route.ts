import { NextRequest, NextResponse } from 'next/server';
import { db } from '@/lib/db';

export async function GET(request: NextRequest) {
  try {
    const searchParams = request.nextUrl.searchParams;
    const userId = searchParams.get('userId');

    if (!userId) {
      return NextResponse.json(
        { error: 'userId is required' },
        { status: 400 }
      );
    }

    // Check if user exists and has active plan
    const user = await db.user.findUnique({
      where: { id: userId },
      select: {
        id: true,
        email: true,
        name: true,
        hasActivePlan: true,
        planExpiresAt: true,
      },
    });

    if (!user) {
      return NextResponse.json({
        success: true,
        hasPaid: false,
        user: null,
      });
    }

    // Check if plan has expired (if expiration is set)
    const isExpired = user.planExpiresAt 
      ? new Date() > user.planExpiresAt 
      : false;

    return NextResponse.json({
      success: true,
      hasPaid: user.hasActivePlan && !isExpired,
      user: {
        id: user.id,
        email: user.email,
        name: user.name,
        hasActivePlan: user.hasActivePlan,
        planExpiresAt: user.planExpiresAt,
        isExpired,
      },
    });
  } catch (error) {
    console.error('Error checking payment status:', error);
    
    return NextResponse.json(
      { error: 'Failed to check payment status' },
      { status: 500 }
    );
  }
}
