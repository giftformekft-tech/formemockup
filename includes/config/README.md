# Global Attributes Configuration

## 📁 Files in this folder:

### `global-attributes.php` (NOT in Git)
- **This is the LIVE config file** used by the plugin
- Contains all your actual product types, colors, sizes, and prices
- **Will NOT be overwritten by Git updates** (in .gitignore)
- Created via: WordPress Admin → Tools → MG Migration

### `global-attributes.example.php` (in Git)
- Example/template file tracked in Git
- Used as fallback if `global-attributes.php` doesn't exist
- Safe to update with new structure examples

## 🔄 Workflow:

### First Time Setup:
1. Go to: WordPress Admin → **Tools** → **MG Migration**
2. Click: **"Migrate to Global Config"**
3. This creates `global-attributes.php` with your data

### After Git Updates:
- Your `global-attributes.php` will **NOT be touched** ✅
- Git only updates the `.example.php` file
- Your live data remains safe

### If Config Gets Deleted:
- Plugin automatically falls back to `global-attributes.example.php`
- Run migration again to create fresh `global-attributes.php`

## 🛠️ Manual Editing:

You can directly edit `global-attributes.php`:
- Add new colors
- Change prices
- Modify size surcharges
- etc.

**DO NOT** edit `.example.php` unless you want to update the template!
