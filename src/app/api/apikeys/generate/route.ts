import { NextRequest, NextResponse } from 'next/server';
import crypto from 'crypto';
import { db } from '@/lib/db';

export async function POST(request: NextRequest) {
  try {
    const body = await request.json();
    const { userId } = body;

    // Validate userId is provided
    if (!userId) {
      return NextResponse.json(
        { success: false, error: 'User ID is required' },
        { status: 400 }
      );
    }

    // Check if user has paid
    const user = await db.user.findUnique({
      where: { id: userId },
    });

    if (!user) {
      return NextResponse.json(
        { 
          success: false, 
          error: 'User not found. Please complete payment first.' 
        },
        { status: 404 }
      );
    }

    // Check if user has active plan
    if (!user.hasActivePlan) {
      return NextResponse.json(
        { 
          success: false, 
          error: 'Payment required. Please complete the $25 payment to generate an API key.',
          requiresPayment: true 
        },
        { status: 403 }
      );
    }

    // Check if plan has expired (if expiration is set)
    if (user.planExpiresAt && new Date() > user.planExpiresAt) {
      return NextResponse.json(
        { 
          success: false, 
          error: 'Your plan has expired. Please renew to generate an API key.',
          requiresPayment: true 
        },
        { status: 403 }
      );
    }

    // Check if user already has an active API key
    const existingApiKey = await db.apiKey.findFirst({
      where: {
        userId: user.id,
        active: true,
      },
    });

    if (existingApiKey) {
      return NextResponse.json({
        success: true,
        apiKey: existingApiKey.key,
        message: 'Retrieved existing API key',
        isExisting: true,
      });
    }

    // Generate a secure API key
    const apiKey = `ppk_${crypto.randomBytes(32).toString('hex')}`;
    
    // Store the API key in database
    const newApiKey = await db.apiKey.create({
      data: {
        userId: user.id,
        key: apiKey,
        active: true,
      },
    });

    return NextResponse.json({
      success: true,
      apiKey: newApiKey.key,
      message: 'API key generated successfully',
      isExisting: false,
    });
  } catch (error) {
    console.error('Error generating API key:', error);
    
    const errorMessage = error instanceof Error ? error.message : 'Unknown error';
    
    return NextResponse.json(
      { 
        success: false, 
        error: 'Failed to generate API key',
        details: errorMessage 
      },
      { status: 500 }
    );
  }
}
