# Razorpay Payment Integration Guide

## Overview

PayPerCrawl now includes a secure, end-to-end Razorpay payment gateway integration that requires users to complete a one-time $25 payment before they can generate API keys. This ensures proper monetization and prevents unauthorized API key generation.

## Features

✅ **Secure Server-Side Verification** - All payment verification happens on the backend
✅ **Database Persistence** - Payment status stored in PostgreSQL via Prisma
✅ **User-Friendly UI** - Clean payment flow with real-time status updates
✅ **Razorpay Checkout** - Industry-standard payment processing
✅ **Lifetime Access** - One-time payment for permanent API key access
✅ **Payment Status Tracking** - Real-time payment state management

## Architecture

### Database Schema

The integration adds three new models to the Prisma schema:

#### 1. User Model
```prisma
model User {
  id              String    @id @default(cuid())
  email           String    @unique
  name            String?
  firebaseUid     String?   @unique
  hasActivePlan   Boolean   @default(false)  // Payment status
  planExpiresAt   DateTime? // Optional expiration
  payments        Payment[]
  apiKeys         ApiKey[]
}
```

#### 2. Payment Model
```prisma
model Payment {
  id              String    @id @default(cuid())
  userId          String
  orderId         String    @unique  // Razorpay order_id
  paymentId       String?   @unique  // Razorpay payment_id
  signature       String?   // Razorpay signature
  amount          Int       // Amount in cents (2500 for $25)
  currency        String    @default("USD")
  status          String    @default("created")
}
```

#### 3. ApiKey Model
```prisma
model ApiKey {
  id              String    @id @default(cuid())
  userId          String
  key             String    @unique
  active          Boolean   @default(true)
  lastUsedAt      DateTime?
}
```

### Payment Flow

```
┌─────────────┐
│ User Login  │
└──────┬──────┘
       │
       ▼
┌─────────────────────┐
│ Check Payment Status│ ◄── GET /api/payment/status?userId=xxx
└──────┬──────────────┘
       │
       ├─── Paid ────────┐
       │                 │
       │                 ▼
       │          ┌──────────────────┐
       │          │ Show Generate    │
       │          │ API Key Button   │
       │          └──────────────────┘
       │
       └─── Not Paid ───┐
                        │
                        ▼
                 ┌──────────────────┐
                 │ Show Pay $25     │
                 │ Button           │
                 └──────┬───────────┘
                        │
                        ▼
                 ┌──────────────────┐
                 │ POST /api/       │
                 │ payment/         │
                 │ create-order     │
                 └──────┬───────────┘
                        │
                        ▼
                 ┌──────────────────┐
                 │ Razorpay         │
                 │ Checkout Opens   │
                 └──────┬───────────┘
                        │
                        ▼
                 ┌──────────────────┐
                 │ User Completes   │
                 │ Payment          │
                 └──────┬───────────┘
                        │
                        ▼
                 ┌──────────────────┐
                 │ POST /api/       │
                 │ payment/verify   │
                 │ (Server-side)    │
                 └──────┬───────────┘
                        │
                        ▼
                 ┌──────────────────┐
                 │ Update User      │
                 │ hasActivePlan    │
                 │ = true           │
                 └──────┬───────────┘
                        │
                        ▼
                 ┌──────────────────┐
                 │ Enable API Key   │
                 │ Generation       │
                 └──────────────────┘
```

## Setup Instructions

### 1. Environment Variables

Add the following to your `.env` file:

```bash
# Razorpay Configuration
RAZORPAY_KEY_ID=rzp_test_xxxxxxxxxxxxx
RAZORPAY_KEY_SECRET=xxxxxxxxxxxxxxxxxxxxx
NEXT_PUBLIC_RAZORPAY_KEY_ID=rzp_test_xxxxxxxxxxxxx
```

**Note:** 
- Use `rzp_test_` prefix for test keys
- Use `rzp_live_` prefix for production keys
- Never commit your `.env` file!

### 2. Get Razorpay Credentials

1. Sign up at [Razorpay Dashboard](https://dashboard.razorpay.com/)
2. Navigate to **Settings → API Keys**
3. Generate **Test Keys** for development
4. Generate **Live Keys** for production
5. Copy `Key Id` and `Key Secret` to your `.env` file

### 3. Database Migration

Run the database migration to create the new tables:

```bash
npm run db:push
```

This will create the `users`, `payments`, and `api_keys` tables.

### 4. Install Dependencies

The Razorpay SDK is already installed:

```bash
npm install razorpay
```

## API Routes

### 1. Create Payment Order

**Endpoint:** `POST /api/payment/create-order`

**Request Body:**
```json
{
  "userId": "user_unique_id",
  "email": "user@example.com",
  "name": "John Doe"
}
```

**Response:**
```json
{
  "success": true,
  "order": {
    "id": "order_xxxxxxxxxxxxx",
    "amount": 2500,
    "currency": "USD",
    "receipt": "rcpt_1234567890_user123"
  },
  "paymentId": "payment_db_id"
}
```

### 2. Verify Payment

**Endpoint:** `POST /api/payment/verify`

**Request Body:**
```json
{
  "razorpay_order_id": "order_xxxxxxxxxxxxx",
  "razorpay_payment_id": "pay_xxxxxxxxxxxxx",
  "razorpay_signature": "signature_hash",
  "userId": "user_unique_id"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Payment verified successfully",
  "payment": {
    "id": "payment_db_id",
    "orderId": "order_xxxxxxxxxxxxx",
    "paymentId": "pay_xxxxxxxxxxxxx",
    "status": "captured",
    "amount": 2500,
    "currency": "USD"
  }
}
```

### 3. Check Payment Status

**Endpoint:** `GET /api/payment/status?userId=xxx`

**Response:**
```json
{
  "success": true,
  "hasPaid": true,
  "user": {
    "id": "user_unique_id",
    "email": "user@example.com",
    "name": "John Doe",
    "hasActivePlan": true,
    "planExpiresAt": null,
    "isExpired": false
  }
}
```

### 4. Generate API Key (Updated)

**Endpoint:** `POST /api/apikeys/generate`

**Request Body:**
```json
{
  "userId": "user_unique_id"
}
```

**Response (Success):**
```json
{
  "success": true,
  "apiKey": "ppk_xxxxxxxxxxxxxxxxxxxx",
  "message": "API key generated successfully",
  "isExisting": false
}
```

**Response (Payment Required):**
```json
{
  "success": false,
  "error": "Payment required. Please complete the $25 payment to generate an API key.",
  "requiresPayment": true
}
```

## Frontend Integration

### Payment Button Component

The `DashboardClient` component now includes:

1. **Payment Status Check** - Automatically checks if user has paid on load
2. **Conditional UI** - Shows payment button if not paid, API key button if paid
3. **Razorpay Checkout** - Integrated Razorpay modal for seamless payments
4. **Real-time Updates** - Toast notifications for payment success/failure

### Key Features

```tsx
// Payment status state
const [hasPaid, setHasPaid] = useState(false);
const [isProcessingPayment, setIsProcessingPayment] = useState(false);

// Payment handler
const handlePayment = async () => {
  // 1. Create order on backend
  // 2. Open Razorpay checkout
  // 3. Verify payment on success
  // 4. Update UI
};

// API key generation (only if paid)
const generateApiKey = async () => {
  if (!hasPaid) {
    toast({ title: "Payment Required" });
    return;
  }
  // Generate API key...
};
```

## Security Features

### 1. Server-Side Signature Verification

All payment signatures are verified on the backend using HMAC SHA256:

```typescript
const text = `${orderId}|${paymentId}`;
const expectedSignature = crypto
  .createHmac('sha256', RAZORPAY_KEY_SECRET)
  .update(text)
  .digest('hex');

return expectedSignature === signature;
```

### 2. User Authorization Check

The API key generation endpoint verifies:
- User exists in database
- User has `hasActivePlan = true`
- Plan hasn't expired (if expiration is set)

### 3. Database Constraints

- Unique constraints on `orderId` and `paymentId`
- Foreign key constraints ensure data integrity
- Cascade delete for user data

## Testing

### Test Mode

1. Use Razorpay Test Keys (`rzp_test_`)
2. Test card numbers:
   - Success: `4111 1111 1111 1111`
   - Failure: `4000 0000 0000 0002`
   - CVV: Any 3 digits
   - Expiry: Any future date

### Payment Flow Test

1. Log in to dashboard
2. Verify "Pay $25" button appears
3. Click payment button
4. Complete test payment
5. Verify success toast appears
6. Verify "Generate API Key" button is now enabled
7. Generate API key
8. Verify API key is displayed

## Production Deployment

### 1. Switch to Live Mode

Update `.env` with production keys:

```bash
RAZORPAY_KEY_ID=rzp_live_xxxxxxxxxxxxx
RAZORPAY_KEY_SECRET=xxxxxxxxxxxxxxxxxxxxx
NEXT_PUBLIC_RAZORPAY_KEY_ID=rzp_live_xxxxxxxxxxxxx
```

### 2. Enable Webhooks (Optional)

Configure webhooks in Razorpay Dashboard:
- Webhook URL: `https://yourdomain.com/api/payment/webhook`
- Events: `payment.captured`, `payment.failed`

### 3. Monitor Payments

Use Razorpay Dashboard to:
- View all transactions
- Issue refunds
- Track failed payments
- Generate reports

## Troubleshooting

### Payment Verification Fails

**Issue:** Signature verification returns false

**Solution:**
- Ensure `RAZORPAY_KEY_SECRET` matches the key used to create the order
- Check that order_id and payment_id are correct
- Verify signature format (no extra spaces)

### API Key Generation Still Disabled

**Issue:** User paid but can't generate API key

**Solution:**
1. Check database: `SELECT * FROM users WHERE email = 'user@example.com'`
2. Verify `hasActivePlan = true`
3. Check `payments` table for successful payment record
4. Clear browser cache and reload

### Razorpay Checkout Not Opening

**Issue:** Payment modal doesn't appear

**Solution:**
1. Verify Razorpay script is loaded: Check browser console
2. Ensure `NEXT_PUBLIC_RAZORPAY_KEY_ID` is set correctly
3. Check browser popup blocker settings

## Cost Structure

- **Amount:** $25 USD per user (one-time)
- **Razorpay Fees:** ~2.9% + $0.30 per transaction
- **Net Revenue:** ~$24.18 per user

## Future Enhancements

- [ ] Subscription model with recurring payments
- [ ] Multiple pricing tiers
- [ ] Webhook integration for automated status updates
- [ ] Payment analytics dashboard
- [ ] Discount codes/coupons
- [ ] Refund management

## Support

For issues or questions:
- Email: support@paypercrawl.tech
- Razorpay Support: https://razorpay.com/support/
- Documentation: https://razorpay.com/docs/

## References

- [Razorpay Node.js SDK](https://github.com/razorpay/razorpay-node)
- [Razorpay Checkout Documentation](https://razorpay.com/docs/payments/payment-gateway/web-integration/standard/)
- [Payment Verification Guide](https://razorpay.com/docs/payments/payment-gateway/web-integration/standard/verify-payment/)
