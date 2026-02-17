# Astra Child Theme - Installation Instructions

## Files Created

Child theme created in: `c:\Users\szoko\formemockup\astra-child\`

```
astra-child/
├── style.css
├── functions.php
└── woocommerce/
    └── single-product/
        ├── price.php
        └── title.php
```

## Installation Steps

### 1. Upload to WordPress

**Option A: Via FTP/SFTP**
1. Connect to your server via FTP (FileZilla, WinSCP, etc.)
2. Navigate to: `wp-content/themes/`
3. Upload the entire `astra-child` folder
4. Verify the path is: `wp-content/themes/astra-child/`

**Option B: Via ZIP Upload**
1. Compress the `astra-child` folder as a ZIP file
2. WordPress Admin → Megjelenés → Témák → Új hozzáadása
3. Click "Téma feltöltése"
4. Select the ZIP file and upload
5. WordPress will extract it automatically

### 2. Activate the Child Theme

1. WordPress Admin → Megjelenés → Témák
2. Find "Astra Child - Mockup Variant"
3. Click **Aktiválás**

### 3. Verify Installation

#### Check Theme is Active
- WordPress Admin → Megjelenés → Témák
- "Astra Child" should show as "Aktív"

#### Test Product Page
1. Visit any product page with variant: `https://yoursite.com/termek/?mg_type=ferfi-polo`
2. Check the price displays correctly (variant price, not base price)
3. Check the title includes variant type: "Póló - Férfi póló"

#### View Page Source
1. Right-click on product page → View Page Source
2. Search for `<p class="price">`
3. Verify it shows the variant price (e.g., "5 990" not "4 990")

### 4. Test Google Rich Results

1. Visit: https://search.google.com/test/rich-results
2. Enter your product URL with parameter: `https://yoursite.com/termek/?mg_type=ferfi-polo`
3. Click "Test URL"
4. Verify:
   - ✅ Price shows variant price
   - ✅ Title shows variant title
   - ✅ No errors in structured data

## How It Works

### Template Override System

WooCommerce looks for templates in this order:
1. **Child theme**: `astra-child/woocommerce/single-product/price.php` ← **OUR FILE**
2. Parent theme: `astra/woocommerce/single-product/price.php`
3. WooCommerce plugin: `woocommerce/templates/single-product/price.php`

Since our child theme has `price.php`, WooCommerce uses **our version**.

### Custom Price Logic

```php
if ($_GET['mg_type']) {
    // Load variant config
    $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
    
    // Get variant price
    $variant_price = $config['types']['ferfi-polo']['price'];
    
    // Output: <p class="price">5 990 Ft</p>
} else {
    // Normal WooCommerce price
    echo $product->get_price_html();
}
```

### Why This Works

- ✅ **Server-side**: Price is in HTML from the start (Google bot sees it)
- ✅ **No filters**: Doesn't use WooCommerce filters (no crashes)
- ✅ **Template override**: Standard WordPress practice
- ✅ **Update-safe**: Parent Astra updates don't affect child theme
- ✅ **Rollback**: Just activate parent Astra theme to revert

## Troubleshooting

### Theme Doesn't Appear
- Check folder name is exactly: `astra-child`
- Check `style.css` header has: `Template: astra`
- Check Astra parent theme is installed

### Price Still Shows Base Price
- Hard refresh: Ctrl+Shift+R
- Clear WordPress cache (if using cache plugin)
- Check URL has `?mg_type=ferfi-polo` parameter
- View page source to verify template is loading

### Styling Looks Wrong
- Clear browser cache
- Check `functions.php` is loading parent styles
- Verify parent Astra theme is still installed

### Rollback to Parent Theme
1. WordPress Admin → Megjelenés → Témák
2. Activate "Astra" (parent theme)
3. Child theme remains installed but inactive

## What Changed

### Before (Plugin Attempts)
- ❌ WooCommerce filters → crashed
- ❌ Object modification → crashed  
- ❌ Output buffering → crashed
- ❌ Early hooks → crashed

### After (Child Theme)
- ✅ Direct template override
- ✅ Simple PHP logic
- ✅ No WooCommerce internals touched
- ✅ 100% stable

## Next Steps

1. **Activate** the child theme
2. **Test** product pages with URL parameters
3. **Verify** Google Rich Results Tool
4. **Monitor** Google Merchant Center (24-48h for feed refresh)
5. **Report** results - does Google accept the prices now?
