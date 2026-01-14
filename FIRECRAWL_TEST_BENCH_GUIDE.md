# Firecrawl Test Bench - Documentation

**Date**: October 18, 2025  
**Status**: ✅ READY TO USE

## 🎯 What Is This?

The **Firecrawl Test Bench** is an automated testing tool that uses your real Firecrawl API key to test whether your WordPress plugin successfully blocks Firecrawl scraping attempts.

## 🌐 Access the Test Bench

**URL**: `http://localhost:3000/test-firecrawl`

Or on production: `https://yourdomain.com/test-firecrawl`

## 🚀 How It Works

### The Testing Process:

```
1. You enter a WordPress site URL
        ↓
2. Test Bench uses your Firecrawl API
        ↓
3. Firecrawl attempts to scrape the URL
        ↓
4. Your WordPress plugin detects it
        ↓
5. Results show if it was blocked or not
```

## 📋 What Gets Tested

### ✅ Detection Methods Checked:

1. **HTTP Status Codes**
   - 402 Payment Required → BLOCKED ✅
   - 429 Too Many Requests → BLOCKED ✅
   - 403 Forbidden → BLOCKED ✅
   - 200 OK with no content → BLOCKED ✅
   - 200 OK with full content → NOT BLOCKED ❌

2. **Content Analysis**
   - If Firecrawl gets markdown content > 100 chars → NOT BLOCKED
   - If content is empty or error page → BLOCKED

3. **Error Detection**
   - Timeout errors → Possibly blocked
   - 402/429/403 in exception → BLOCKED
   - Other errors → Network issue (not blocking)

## 🎮 Using the Test Bench

### Step 1: Navigate to Test Page

```
http://localhost:3000/test-firecrawl
```

### Step 2: Enter Target URL

Examples:
- `https://white-zebra-414272.hostingersite.com` (your test site)
- `https://lightslategray-bison-497689.hostingersite.com` (demo site)
- Any WordPress site with CrawlGuard plugin installed

### Step 3: Click "Run Test"

The test bench will:
- Initialize Firecrawl with your API key
- Attempt to scrape the URL
- Analyze the response
- Show detection results

### Step 4: Read the Results

**Green Badge "BLOCKED ✓"** = Plugin working! 🎉
- Shows detection method used
- Confidence level (70-100%)
- Technical details

**Red Badge "NOT BLOCKED ✗"** = Plugin not working 😬
- Shows full Firecrawl response
- Indicates what content was scraped
- Suggests next steps

## 📊 Understanding Results

### ✅ Successful Blocking Examples:

```json
{
  "blocked": true,
  "detectionMethod": "HTTP 402 Payment Required",
  "confidence": 100
}
```

```json
{
  "blocked": true,
  "detectionMethod": "HTTP 429 Rate Limited",
  "confidence": 100
}
```

```json
{
  "blocked": true,
  "detectionMethod": "Heuristic Detection",
  "confidence": 85,
  "suspiciousScore": 85
}
```

### ❌ Failed Blocking Example:

```json
{
  "blocked": false,
  "firecrawlResponse": {
    "statusCode": 200,
    "contentLength": 5432,
    "title": "Your WordPress Site",
    "markdownPreview": "Full content here..."
  }
}
```

## 🔍 Heuristic Score Calculation

The test bench simulates your WordPress plugin's scoring:

| Check | Points | Reason |
|-------|--------|--------|
| Datacenter IP | +30 | Firecrawl uses cloud servers |
| Missing Accept-Language | +20 | API doesn't send browser headers |
| Missing Accept-Encoding | +15 | API doesn't send browser headers |
| No cookies | +20 | API requests don't use cookies |
| **Total** | **85** | Over 40 threshold = DETECTED |

## 🧪 Testing Scenarios

### Scenario 1: Plugin Not Installed

**Input**: Site without CrawlGuard  
**Expected Result**: ❌ NOT BLOCKED  
**Firecrawl Gets**: Full content

### Scenario 2: Plugin Installed, Monetization Disabled

**Input**: Site with plugin, but monetization off  
**Expected Result**: ❌ NOT BLOCKED (logs only)  
**Firecrawl Gets**: Full content

### Scenario 3: Plugin Installed & Configured

**Input**: Site with plugin, monetization enabled  
**Expected Result**: ✅ BLOCKED (HTTP 402)  
**Firecrawl Gets**: Payment required message

### Scenario 4: Rate Limiting Triggered

**Input**: Site with rate limiting enabled  
**Expected Result**: ✅ BLOCKED (HTTP 429)  
**Firecrawl Gets**: Rate limit error

## 🛠️ Troubleshooting

### Issue: "Firecrawl API key not configured"

**Solution**:
```bash
# Check .env file
cat .env | grep FIRECRAWL_API_KEY

# Should show:
FIRECRAWL_API_KEY=fc-your-actual-api-key-here
```

If missing, add it to `.env`:
```
FIRECRAWL_API_KEY=your-key-here
```

### Issue: "Test shows NOT BLOCKED but plugin is installed"

**Possible Causes**:
1. ✅ Plugin not activated in WordPress
2. ✅ Monetization disabled in plugin settings
3. ✅ Old plugin version (without Firecrawl detection)
4. ✅ Firecrawl in "allowed bots" whitelist

**Solution**:
- Go to WP Admin → Plugins → Verify CrawlGuard is active
- Go to CrawlGuard → Settings → Enable monetization
- Download latest plugin ZIP and re-upload
- Check "Allowed Bots" list, remove "firecrawl" if present

### Issue: "Request timeout"

**Possible Causes**:
- WordPress site is down
- Firewall blocking Firecrawl IPs
- Plugin blocking too aggressively (good thing!)

**Check**: If it's a timeout, the plugin might be working!

## 📱 Quick Test Buttons

The test bench includes pre-configured buttons:

1. **Test Site (Hostinger)** → `white-zebra-414272.hostingersite.com`
2. **PayPerCrawl Demo** → `lightslategray-bison-497689.hostingersite.com`

Click these to instantly test known sites.

## 🔐 Security Notes

1. **API Key Protection**: Your Firecrawl API key is stored in `.env` (not exposed to clients)
2. **Server-Side Only**: All Firecrawl requests happen server-side
3. **No Data Storage**: Test results are not saved to database
4. **Real API Usage**: Tests consume Firecrawl API credits (typically 1 credit per test)

## 💰 API Credits Usage

Each test uses approximately **1 Firecrawl credit**.

**Recommendation**: Test sparingly during development. Use for:
- Initial plugin verification
- After plugin updates
- When troubleshooting blocking issues

## 🎯 Expected Test Results

### If Plugin is Working:

```
✅ Detection Method: HTTP 402 Payment Required
✅ Confidence: 100%
✅ Blocked: Yes
✅ Firecrawl Response: Payment required message
```

### If Plugin Needs Update:

```
❌ Detection Method: None
❌ Confidence: 0%
❌ Blocked: No
❌ Firecrawl Response: Full site content scraped
```

## 📈 Next Steps After Testing

### If Tests Show "BLOCKED" ✅

1. **Celebrate!** Your plugin is working
2. Monitor WordPress logs for real Firecrawl attempts
3. Check revenue generated from blocked bots
4. Consider testing with stealth mode enabled

### If Tests Show "NOT BLOCKED" ❌

1. **Verify plugin installation**:
   ```bash
   # SSH into WordPress site
   ls -la wp-content/plugins/crawlguard-wp/
   ```

2. **Check plugin version**:
   ```bash
   grep "Version:" wp-content/plugins/crawlguard-wp/crawlguard-wp.php
   ```

3. **Check for Firecrawl detection code**:
   ```bash
   grep -i "firecrawl" wp-content/plugins/crawlguard-wp/includes/class-bot-detector.php
   ```

4. **Enable monetization** in WordPress admin

5. **Re-test** after making changes

## 🔄 API Endpoints

### POST `/api/test-firecrawl`

**Request**:
```json
{
  "url": "https://your-wordpress-site.com"
}
```

**Response** (Blocked):
```json
{
  "success": true,
  "blocked": true,
  "detectionMethod": "HTTP 402 Payment Required",
  "confidence": 100,
  "error": "Payment required for bot access"
}
```

**Response** (Not Blocked):
```json
{
  "success": true,
  "blocked": false,
  "firecrawlResponse": {
    "statusCode": 200,
    "contentLength": 5432,
    "title": "Site Title",
    "markdownPreview": "Content preview..."
  },
  "suspiciousScore": 85
}
```

## 📝 Files Created

```
src/app/test-firecrawl/
├── page.tsx                    # Test bench UI
└── ...

src/app/api/test-firecrawl/
└── route.ts                    # API endpoint for testing

package.json
├── @mendable/firecrawl-js     # Firecrawl SDK
└── ...
```

## 🎨 UI Features

- **Real-time testing** with loading states
- **Color-coded results** (green = blocked, red = not blocked)
- **Detailed technical analysis** of detection methods
- **Firecrawl response preview** (JSON formatted)
- **Quick test buttons** for common sites
- **Responsive design** works on mobile/desktop

## 🚀 Production Deployment

When deploying to production:

1. **Environment variable** must be set:
   ```
   FIRECRAWL_API_KEY=fc-your-actual-key
   ```

2. **Build the app**:
   ```bash
   npm run build
   ```

3. **Access at**:
   ```
   https://yourdomain.com/test-firecrawl
   ```

4. **Optional**: Protect this page with authentication (recommended)

---

## 🎉 You're Ready!

Visit `http://localhost:3000/test-firecrawl` and start testing your WordPress plugin's Firecrawl detection capabilities!

**Have questions?** Check the console logs or WordPress database logs for more details.
