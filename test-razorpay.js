// Test Razorpay API credentials
require('dotenv').config();
const Razorpay = require('razorpay');

const keyId = process.env.RAZORPAY_KEY_ID;
const keySecret = process.env.RAZORPAY_KEY_SECRET;

console.log('Testing Razorpay Credentials...');
console.log('Key ID:', keyId);
console.log('Key Secret:', keySecret ? '[SET]' : '[NOT SET]');
console.log('Key ID length:', keyId?.length);
console.log('Key Secret length:', keySecret?.length);

if (!keyId || !keySecret) {
    console.error('❌ Credentials not loaded from .env file!');
    process.exit(1);
}

const instance = new Razorpay({
    key_id: keyId.trim(),
    key_secret: keySecret.trim(),
});

// Test with a simple order creation
async function testOrder() {
    try {
        console.log('\n🧪 Creating test order with USD currency...');

        const order = await instance.orders.create({
            amount: 2500, // $25.00 in cents
            currency: 'USD',
            receipt: 'test_receipt_' + Date.now(),
            notes: {
                test: 'true'
            }
        });

        console.log('✅ SUCCESS! Order created:', order.id);
        console.log('Order details:', JSON.stringify(order, null, 2));
    } catch (error) {
        console.error('❌ ERROR creating order:');
        console.error('Status Code:', error.statusCode);
        console.error('Error:', error.error);
        console.error('\nFull error:', error);

        if (error.statusCode === 401) {
            console.error('\n⚠️  Authentication failed! Possible reasons:');
            console.error('1. Invalid API keys');
            console.error('2. Keys have extra whitespace');
            console.error('3. Account not activated for international payments');
            console.error('4. Need to enable USD currency in Razorpay Dashboard');
        }

        process.exit(1);
    }
}

testOrder();
