import Razorpay from 'razorpay';
import crypto from 'crypto';

// Initialize Razorpay instance
let razorpayInstance: Razorpay | null = null;

export function getRazorpayInstance() {
  if (!razorpayInstance) {
    const keyId = process.env.RAZORPAY_KEY_ID;
    const keySecret = process.env.RAZORPAY_KEY_SECRET;

    if (!keyId || !keySecret) {
      throw new Error('Razorpay credentials not configured. Please set RAZORPAY_KEY_ID and RAZORPAY_KEY_SECRET environment variables.');
    }

    // Trim any whitespace from keys
    const trimmedKeyId = keyId.trim();
    const trimmedKeySecret = keySecret.trim();

    console.log('Initializing Razorpay with:');
    console.log('- Key ID:', trimmedKeyId.substring(0, 15) + '...');
    console.log('- Key Secret:', trimmedKeySecret ? '[SET]' : '[NOT SET]');
    console.log('- Key ID length:', trimmedKeyId.length);
    console.log('- Key Secret length:', trimmedKeySecret.length);

    razorpayInstance = new Razorpay({
      key_id: trimmedKeyId,
      key_secret: trimmedKeySecret,
    });
  }

  return razorpayInstance;
}

/**
 * Verify Razorpay payment signature
 * @param orderId - Razorpay order ID
 * @param paymentId - Razorpay payment ID
 * @param signature - Razorpay signature
 * @returns boolean - true if signature is valid
 */
export function verifyPaymentSignature(
  orderId: string,
  paymentId: string,
  signature: string
): boolean {
  try {
    const keySecret = process.env.RAZORPAY_KEY_SECRET;
    
    if (!keySecret) {
      throw new Error('RAZORPAY_KEY_SECRET not configured');
    }

    // Create expected signature
    const text = `${orderId}|${paymentId}`;
    const expectedSignature = crypto
      .createHmac('sha256', keySecret)
      .update(text)
      .digest('hex');

    // Compare signatures
    return expectedSignature === signature;
  } catch (error) {
    console.error('Error verifying payment signature:', error);
    return false;
  }
}

/**
 * Convert INR amount to paise (smallest currency unit)
 * @param amountInINR - Amount in INR
 * @returns Amount in paise
 */
export function convertToPaise(amountInINR: number): number {
  return Math.round(amountInINR * 100);
}

/**
 * Convert paise to INR
 * @param amountInPaise - Amount in paise
 * @returns Amount in INR
 */
export function convertToINR(amountInPaise: number): number {
  return amountInPaise / 100;
}

// Legacy USD functions (kept for backwards compatibility)
/**
 * Convert USD amount to cents (smallest currency unit)
 * @param amountInUSD - Amount in USD
 * @returns Amount in cents
 */
export function convertToCents(amountInUSD: number): number {
  return Math.round(amountInUSD * 100);
}

/**
 * Convert cents to USD
 * @param amountInCents - Amount in cents
 * @returns Amount in USD
 */
export function convertToUSD(amountInCents: number): number {
  return amountInCents / 100;
}
