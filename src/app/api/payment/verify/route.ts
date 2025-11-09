import { NextRequest, NextResponse } from 'next/server';
import { db } from '@/lib/db';
import { verifyPaymentSignature } from '@/lib/razorpay';

export async function POST(request: NextRequest) {
  try {
    const body = await request.json();
    const { 
      razorpay_order_id, 
      razorpay_payment_id, 
      razorpay_signature,
      userId 
    } = body;

    // Validate input
    if (!razorpay_order_id || !razorpay_payment_id || !razorpay_signature) {
      return NextResponse.json(
        { error: 'Missing payment verification parameters' },
        { status: 400 }
      );
    }

    // Verify signature
    const isValidSignature = verifyPaymentSignature(
      razorpay_order_id,
      razorpay_payment_id,
      razorpay_signature
    );

    if (!isValidSignature) {
      return NextResponse.json(
        { error: 'Invalid payment signature' },
        { status: 400 }
      );
    }

    // Find the payment record
    const payment = await db.payment.findUnique({
      where: { orderId: razorpay_order_id },
      include: { user: true },
    });

    if (!payment) {
      return NextResponse.json(
        { error: 'Payment record not found' },
        { status: 404 }
      );
    }

    // Verify userId matches (security check)
    if (userId && payment.userId !== userId) {
      return NextResponse.json(
        { error: 'Unauthorized' },
        { status: 403 }
      );
    }

    // Update payment record
    const updatedPayment = await db.payment.update({
      where: { orderId: razorpay_order_id },
      data: {
        paymentId: razorpay_payment_id,
        signature: razorpay_signature,
        status: 'captured',
      },
    });

    // Update user to have active plan (lifetime access)
    await db.user.update({
      where: { id: payment.userId },
      data: {
        hasActivePlan: true,
        // Optional: Set expiration date if you want timed access
        // planExpiresAt: new Date(Date.now() + 365 * 24 * 60 * 60 * 1000), // 1 year from now
      },
    });

    return NextResponse.json({
      success: true,
      message: 'Payment verified successfully',
      payment: {
        id: updatedPayment.id,
        orderId: updatedPayment.orderId,
        paymentId: updatedPayment.paymentId,
        status: updatedPayment.status,
        amount: updatedPayment.amount,
        currency: updatedPayment.currency,
      },
    });
  } catch (error) {
    console.error('Error verifying payment:', error);
    
    const errorMessage = error instanceof Error ? error.message : 'Failed to verify payment';
    
    return NextResponse.json(
      { 
        error: 'Failed to verify payment',
        details: errorMessage 
      },
      { status: 500 }
    );
  }
}
