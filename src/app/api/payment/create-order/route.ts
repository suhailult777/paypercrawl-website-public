import { NextRequest, NextResponse } from 'next/server';
import { db } from '@/lib/db';
import { getRazorpayInstance, convertToCents } from '@/lib/razorpay';

const PLAN_PRICE_USD = 25; // $25 for API key access

export async function POST(request: NextRequest) {
  try {
    const body = await request.json();
    const { email, name, userId } = body;

    // Validate input
    if (!email || !userId) {
      return NextResponse.json(
        { error: 'Email and userId are required' },
        { status: 400 }
      );
    }

    // Check if user already has an active plan
    const existingUser = await db.user.findUnique({
      where: { id: userId },
    });

    if (existingUser?.hasActivePlan) {
      return NextResponse.json(
        { error: 'User already has an active plan' },
        { status: 400 }
      );
    }

    // Create or update user
    const user = await db.user.upsert({
      where: { id: userId },
      update: {
        email,
        name: name || email,
      },
      create: {
        id: userId,
        email,
        name: name || email,
        hasActivePlan: false,
      },
    });

    // Create Razorpay order
    const razorpay = getRazorpayInstance();
    const amountInCents = convertToCents(PLAN_PRICE_USD);
    const receipt = `rcpt_${Date.now()}_${userId.substring(0, 8)}`;

    const razorpayOrder = await razorpay.orders.create({
      amount: amountInCents, // Amount in cents (smallest unit for USD)
      currency: 'USD',
      receipt,
      notes: {
        userId,
        email,
        plan: 'api_key_access',
      },
    });

    // Save payment record in database
    const payment = await db.payment.create({
      data: {
        userId: user.id,
        orderId: razorpayOrder.id,
        razorpayOrderId: razorpayOrder.id,
        email: email,
        amount: amountInCents,
        currency: 'USD',
        status: 'created',
      },
    });

    return NextResponse.json({
      success: true,
      order: {
        id: razorpayOrder.id,
        amount: amountInCents,
        currency: 'USD',
        receipt,
      },
      paymentId: payment.id,
    });
  } catch (error) {
    console.error('Error creating payment order:', error);
    
    // More detailed error message
    const errorMessage = error instanceof Error ? error.message : 'Failed to create payment order';
    
    return NextResponse.json(
      { 
        error: 'Failed to create payment order',
        details: errorMessage 
      },
      { status: 500 }
    );
  }
}
