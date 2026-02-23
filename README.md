# Smart Admin Plugin Manager

Intelligent WordPress plugin loading management for WordPress admin interface - per-screen control with automatic optimization suggestions.

[![WordPress Plugin Version](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-GPLv2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Status](https://img.shields.io/badge/Status-Stable-brightgreen.svg)](https://github.com/ffscz/Smart-Admin-Plugin-Manager-for-WordPress)

## Description

Smart Admin Plugin Manager (SAPM) is an advanced WordPress plugin that optimizes admin performance by controlling which plugins load on specific admin screens. Unlike traditional solutions that load all active plugins on every admin page, SAPM allows granular per-screen control, automatically detecting unnecessary plugins and providing data-driven optimization suggestions.

The plugin operates at the core WordPress level using MU-plugins mechanism, intercepting the plugin loading process before WordPress initialization completes. This allows for dramatic performance improvements by preventing heavy plugins from loading on screens where they are not needed.

**Current Version:** 1.3.0  
**Status:** Active Development (Beta)  
**Minimum Requirements:** WordPress 6.0+, PHP 7.4+

## Key Features

### Core Functionality

- **Per-Screen Plugin Control**
  - Granular control over which plugins load on each admin screen
  - Support for WordPress core screens (Dashboard, Posts, Pages, Settings, etc.)
  - Custom Post Type compatibility
  - WooCommerce admin screens integration
  - Plugin-specific admin pages detection

- **Three Operation States Per Plugin**
  - **Enabled:** Always load on specified screen
  - **Disabled:** Never load on specified screen
  - **Defer:** Load after main page load (reduces initial page load time by ~70%)

- **Smart Menu Preservation**
  - Admin menu items preserved for blocked/deferred plugins
  - Automatic snapshot system captures plugin menu structures
  - Users retain access to plugin settings even when plugin is blocked
  - Works seamlessly with deferred loading

- **Hierarchical Rule System**
  - Global rules (apply to all admin screens)
  - Group rules (apply to screen categories: Core, Content, WooCommerce, etc.)
  - Screen-specific rules (override group/global rules)
  - Inheritance system prevents rule duplication

### Advanced Features

#### 1. Automatic Mode with AI-Suggested Rules
- **Sampling-Based Analysis:** Monitors plugin performance across admin screens
- **Confidence Scoring:** Each suggestion includes confidence level (0-100%)
- **Contextual Intelligence:** Recognizes plugin purposes and screen relevance
- **Database Storage:** 30-day retention of performance samples
- **Request Type Support:** Admin screens, AJAX, REST API, WP-Cron, WP-CLI

#### 2. Dependency Cascade Blocking
- **Automatic Detection:** Identifies plugin dependencies (e.g., WooCommerce extensions)
- **Smart Blocking:** When parent plugin is blocked, dependents are automatically blocked
- **Known Patterns:** Pre-configured patterns for popular plugin ecosystems
- **Manual Override:** Can be configured via filters

#### 3. Request Type Optimization
- **AJAX Request Control:** Three modes (Passthrough, Blacklist, Whitelist)
- **REST API Management:** Per-namespace plugin control
- **WP-Cron Optimization:** Background task plugin filtering
- **WP-CLI Support:** Command-specific plugin loading

#### 4. Update Optimizer
- **Three Strategies:**
  - **TTL Extension:** Reduces update check frequency (12h to 24-72h)
  - **Page-Specific:** Only checks updates on relevant pages
  - **Cron-Only:** All update checks via background tasks
- **Plugin Updater Blocking:** Blocks third-party plugin update API calls
- **Performance Impact:** Saves 0.3-0.6s per admin page load
- **Update Badge Preservation:** Maintains accurate update counts

#### 5. Performance Monitoring
- **Per-Plugin Metrics:**
  - Load time measurement (milliseconds)
  - Database query counting
  - Memory usage tracking
- **Admin Screen Sampling:** 100% sampling for admin screens
- **Request Type Sampling:** 10% sampling for AJAX/REST/Cron/CLI
- **Real-time Dashboard:** Visual performance reports

### Developer Tools

- **Admin Drawer:** Quick plugin management overlay on any admin screen
- **WP-Admin Bar Integration:** At-a-glance disabled plugin count
- **Debug Mode:** Detailed logging via Query Monitor integration
- **REST API:** Programmatic control (17 endpoints)
- **Filters & Actions:** 20+ customization hooks

## Compatibility

### WordPress Requirements
- **WordPress Version:** 6.0 or higher
- **PHP Version:** 7.4 or higher (8.0+ recommended)
- **Database:** MySQL 5.6+ or MariaDB 10.0+
- **Multisite:** Fully compatible

### Third-Party Plugin Compatibility

**Tested & Compatible:**
- WooCommerce 7.0+
- Elementor & Elementor Pro
- Advanced Custom Fields (ACF)
- Yoast SEO / Rank Math
- Query Monitor (debug integration)
- LiteSpeed Cache
- Redis Object Cache
- All major page builders (Bricks, Divi, Beaver Builder)

**Known Conflicts:**
- Plugin activation/deactivation may be blocked if performed on incompatible screens (by design)
- Some security plugins may flag MU-plugin loader (false positive)

### Hosting Environment Compatibility

- **Shared Hosting:** Compatible (low resource usage)
- **VPS/Dedicated:** Recommended for best performance
- **Managed WordPress:** Compatible (SiteGround, Kinsta, WP Engine tested)
- **Server Types:** Apache, Nginx, LiteSpeed, OpenLiteSpeed

## How It Works

### Architecture Overview

```
WordPress Load
    ↓
MU-Plugin Loader (wp-content/mu-plugins/smart-admin-plugin-manager.php)
    ↓
SAPM Core Initialization (VERY EARLY)
    ↓
Filter: option_active_plugins (Priority 1)
    ↓
    ├─ Screen Detection (Dashboard? Edit Post? WooCommerce Orders?)
    ├─ Rule Evaluation (Global → Group → Screen)
    ├─ Dependency Check (Block cascading dependencies)
    ├─ Filter Plugin List (Remove disabled plugins)
    └─ Load Remaining Plugins
    ↓
WordPress Continues Normal Load
    ↓
Admin Menu Restoration (Priority 9998 on admin_menu)
    ├─ Load menu snapshots from database
    ├─ Restore menu items for blocked/deferred plugins
    └─ Users retain access to plugin settings
    ↓
Menu Snapshot Capture (Priority 9999 on admin_menu)
    ├─ Detect all plugin menu items
    ├─ Store menu structure and URLs
    └─ Update snapshot database
    ↓
Deferred Plugins Load (Priority 999 on plugins_loaded)
    ↓
Performance Data Collection (If sampling enabled)
```

### Plugin Loading Process

1. **Early Initialization:**
   - MU-plugin loads SAPM Core before regular plugins
   - Hooks into `option_active_plugins` filter at priority 1
   - Detects current admin screen (Dashboard, Edit Post, etc.)

2. **Rule Application:**
   - Loads rules from database (wp_options)
   - Applies inheritance: Global → Group → Screen
   - Evaluates per-plugin state (enabled/disabled/defer)

3. **Dependency Resolution:**
   - Checks if blocked plugin has dependents
   - Automatically blocks dependent plugins
   - Prevents "missing dependency" warnings

4. **Plugin Filtering:**
   - Removes disabled plugins from active_plugins list
   - Stores deferred plugins for later loading
   - WordPress loads only filtered plugin list

5. **Deferred Loading:**
   - Loads deferred plugins after all regular plugins
   - Reduces initial page load time
   - Maintains full functionality

### Performance Sampling

- **Admin Screens:** 100% of page loads sampled
- **AJAX/REST/Cron/CLI:** 10% random sampling
- **Metrics Collected:**
  - Plugin load time (milliseconds)
  - Database queries executed
  - Memory usage
  - Request frequency

- **Data Storage:**
  - Custom database table: `{prefix}_sapm_sampling_data`
  - 30-day retention policy
  - Automatic cleanup via WP-Cron

### Automatic Mode Algorithm

1. **Data Collection:** Performance samples from all request types
2. **Pattern Analysis:** Identifies plugins with high load time but low usage
3. **Contextual Check:** Verifies plugin relevance for screen type
4. **Confidence Calculation:**
   ```
   Confidence = Base_Score + (Load_Time_Factor × 0.3) + (Presence_Factor × 0.4)
   Where:
   - Base_Score = 0.4 (40%)
   - Load_Time_Factor = avg_ms / 80 (normalized to 0-1)
   - Presence_Factor = 1 - (screens_used / total_screens)
   ```
5. **Suggestion Generation:** Only suggestions with confidence ≥ threshold (default 70%)

## Installation

### Automatic Installation

1. Upload `smart-admin-plugin-manager.zip` to WordPress
2. Activate through Plugins screen
3. Navigate to Settings → Plugin Manager
4. Configure rules or enable Automatic Mode

### Manual Installation

1. Upload `smart-admin-plugin-manager/` directory to `wp-content/plugins/`
2. Activate plugin
3. MU-plugin loader automatically created at `wp-content/mu-plugins/smart-admin-plugin-manager.php`

### Requirements Check

The plugin automatically checks:
- PHP version (7.4+ required)
- WordPress version (6.0+ required)
- Write permissions for `wp-content/mu-plugins/`
- Database table creation capability

## Usage

### Quick Start (Manual Mode)

1. **Access Plugin Manager:**
   - Go to Settings → Plugin Manager
   - View current screen ID and active plugins

2. **Set Global Rules:**
   - Find "Primary rules (WordPress Core)" section
   - Click plugin name to cycle: Gray → Green → Red → Orange
   - Rules save automatically

3. **Override for Specific Screen:**
   - Expand screen category (e.g., "Content")
   - Click plugin to set screen-specific state
   - Overrides group/global rules

### Automatic Mode

1. **Enable Automatic Mode:**
   - Switch from "Manual" to "Auto" radio button
   - Plugin starts collecting performance data

2. **Wait for Data Collection:**
   - Minimum 24-48 hours recommended
   - Browse all admin sections to collect samples
   - Check "Sampling Statistics" for data count

3. **Review Suggestions:**
   - Auto mode displays suggestions automatically
   - Each suggestion shows:
     - Plugin name
     - Confidence score (%)
     - Reason (e.g., "Plugin has 45.2ms load, used on 30% of screens")
     - Potential savings (ms)

4. **Apply Suggestions:**
   - Review each suggestion carefully
   - Click "Apply suggestions" to implement
   - Test admin functionality after applying

### Request Type Configuration

**AJAX Settings:**
```
Mode: Blacklist (recommended for AJAX)
Disabled Plugins:
  - [Heavy plugins that don't use AJAX]
Smart Detection: Enabled (auto-enables WooCommerce for woocommerce_* actions)
```

**REST API Settings:**
```
Mode: Whitelist (recommended for REST)
Default Plugins:
  - woocommerce/woocommerce.php (if using WooCommerce app)
  - jetpack/jetpack.php (if using Jetpack)
Smart Detection: Enabled (namespace-based detection)
```

**WP-Cron Settings:**
```
Mode: Passthrough (recommended - stay safe)
Comment: Cron tasks need reliable plugin access
```

### Update Optimizer

**Recommended Configuration:**
```
Strategy: Cron-Only (most aggressive)
Cron Interval: Twice daily
Show Stale Indicator: Enabled
Plugin Updater Blocking: All known updaters blocked except security plugins
```

**Performance Impact:**
- Blocks 4-8 HTTP requests per admin page load
- Saves 0.3-0.6 seconds total
- Preserves update badge accuracy

### Admin Drawer (Quick Access)

- **Activation:** Click "Plugin Manager" in Admin Bar
- **Features:**
  - Current screen info
  - Disabled plugins list
  - Quick rule toggle
  - Performance metrics for current screen

## Advantages

### Performance Benefits

1. **Admin Load Time Reduction:**
   - Typical improvement: 30-50% faster admin pages
   - Example: Dashboard load reduced from 2.1s to 1.3s
   - Heavy plugin pages (Settings): 40-60% improvement

2. **Database Query Reduction:**
   - Fewer plugins = fewer queries
   - Typical reduction: 20-40 queries per page
   - Compound effect across entire admin session

3. **Memory Usage Optimization:**
   - Each blocked plugin saves 1-5 MB memory
   - Important for shared hosting environments
   - Prevents memory limit errors on heavy pages

### User Experience

- **Faster Admin Navigation:** Noticeable speed improvement
- **Reduced Server Load:** Lower CPU/MySQL usage
- **Better Hosting Performance:** Works within resource limits
- **No Lost Access:** Plugin settings remain accessible even when blocked
- **Seamless Experience:** Admin menu preserved automatically
- **Developer-Friendly:** Granular control, extensive debugging

### SEO & Business Impact

- **Better Core Web Vitals:** Faster TTFB (Time to First Byte)
- **Improved Productivity:** Less waiting for admin pages
- **Cost Savings:** Run on cheaper hosting tiers
- **Scalability:** Handle more concurrent admin users

## Disadvantages & Warnings

### Potential Issues

1. **Complexity:**
   - Requires understanding of WordPress admin structure
   - Manual mode needs careful configuration
   - Risk of blocking needed functionality

2. **Plugin Conflicts:**
   - Some plugins may not work if dependencies blocked
   - Cascade blocking may be too aggressive
   - Must test after applying rules

3. **Debugging Difficulty:**
   - Blocked plugins don't appear in error traces
   - May complicate troubleshooting
   - Need to disable SAPM to diagnose some issues

4. **Maintenance Overhead:**
   - Rules need updating after plugin additions
   - Automatic mode accuracy depends on usage patterns
   - May need adjustment after major WordPress/plugin updates

### Critical Warnings

**DO NOT BLOCK THESE:**
- Security plugins (Wordfence, Sucuri) - Block AJAX/REST only, keep Cron
- Cache plugins (LiteSpeed Cache, WP Rocket) - Need to run everywhere
- Form plugins (Contact Form 7, Gravity Forms) - Need AJAX
- The SAPM plugin itself - Auto-protected but don't manually disable

**CAREFUL WITH:**
- WooCommerce on non-product pages - May break checkout/cart
- Page builders outside editor - May break frontend editing
- SEO plugins on non-content pages - May lose metadata

**RECOMMENDED WORKFLOW:**
1. Start with Automatic Mode on staging site
2. Review suggestions, test thoroughly
3. Export rules, apply to production
4. Monitor for 1 week, adjust if needed

### Known Limitations

- **No Frontend Optimization:** Only works in admin area
- **Active Plugins Only:** Cannot control inactive plugins
- **No Network Activation:** (Multisite requires per-site activation)
- **Database Overhead:** Sampling data can grow (auto-cleanup after 30 days)

## Development Status

**Current Version:** 1.3.0 (Beta)

### Status: Active Development

This plugin is actively developed and maintained. While stable for production use, new features are continuously added and edge cases discovered.

**What This Means:**
- Core functionality is tested and stable
- API may change between versions
- New features added regularly
- Bug reports addressed promptly

**Production Readiness:**
- Safe for production with proper testing
- Backup recommended before deployment
- Test on staging site first
- Monitor admin functionality after activation

### Roadmap

**Version 1.4.0 (Planned):**
- Multi-language admin interface (Czech, German)
- Import/Export rules functionality
- Advanced scheduling (time-based rules)
- Performance comparison reports

**Future Considerations:**
- Frontend optimization mode
- Plugin conflict detector
- Automatic rollback on errors
- Cloud rule sharing

### Changelog

**1.3.0 (Current):**
- Added secure GitHub release updater with SHA256 integrity validation
- Added strict package host allowlist and ZIP structure verification during update
- Refactored frontend drawer integration to shared CSS system
- Improved frontend override UX and reset flow in admin bar drawer
- Removed legacy `assets/frontend-bar.css` in favor of `assets/drawer.css`

**1.2.0:**
- Added Automatic Mode with AI suggestions
- Database storage for sampling data
- Update Optimizer strategies
- Dependency cascade blocking
- Request type optimization
- 30-day data retention

**1.1.0:**
- Per-screen plugin control
- Defer loading support
- Admin drawer interface
- Performance monitoring

**1.0.0:**
- Initial release
- Manual mode only
- Basic rule system

## Technical Details

### Database Tables

**sapm_sampling_data:**
```sql
CREATE TABLE {prefix}_sapm_sampling_data (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_type VARCHAR(20) NOT NULL,
    trigger_name VARCHAR(255) NOT NULL,
    plugin_file VARCHAR(255) NOT NULL,
    avg_ms DECIMAL(10,2) DEFAULT 0,
    avg_queries DECIMAL(10,2) DEFAULT 0,
    sample_count INT UNSIGNED DEFAULT 0,
    total_ms_sum DECIMAL(12,2) DEFAULT 0,
    total_queries_sum INT UNSIGNED DEFAULT 0,
    first_sample DATETIME NOT NULL,
    last_sample DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_trigger_plugin (request_type, trigger_name, plugin_file),
    KEY idx_request_type (request_type),
    KEY idx_trigger_name (trigger_name),
    KEY idx_last_sample (last_sample),
    KEY idx_plugin_file (plugin_file)
);
```

### WordPress Options

- `sapm_plugin_rules` - Main rule storage (screen → plugin → state)
- `sapm_request_type_rules` - AJAX/REST/Cron configuration
- `sapm_mode` - Operation mode (manual/auto)
- `sapm_update_optimizer_config` - Update optimizer settings
- `sapm_db_version` - Database schema version

### REST API Endpoints

**Base URL:** `/wp-json/sapm/v1/`

- `GET /config` - Get all rules
- `POST /config` - Update rules
- `POST /save_rules` - Save specific rules
- `GET /stats` - Performance statistics
- `GET /sampling_stats` - Sampling data statistics
- `GET /auto_suggestions` - Get automatic suggestions
- `POST /apply_auto_rules` - Apply automatic rules
- `POST /reset_auto_data` - Reset sampling data

### Filters & Actions

**Filters:**
```php
// Modify safe-to-block patterns
add_filter('sapm_safe_to_block_patterns', function($patterns) {
    $patterns['my-plugin'] = ['ajax', 'rest', 'cron'];
    return $patterns;
});

// Modify required plugins
add_filter('sapm_required_plugins', function($required) {
    $required['critical-plugin'] = []; // Never block
    return $required;
});

// Add screen definitions
add_filter('sapm_screen_definitions', function($screens) {
    $screens['my_screen'] = [
        'label' => 'My Custom Screen',
        'group' => 'custom',
        'matcher' => fn($s) => $s === 'my-screen-id',
    ];
    return $screens;
});
```

**Actions:**
```php
// After rule application
add_action('sapm_rules_applied', function($screen_id, $disabled_plugins) {
    // Log or process disabled plugins
}, 10, 2);

// Before sampling data storage
add_action('sapm_before_sample_store', function($sample_data) {
    // Modify or log sample data
}, 10, 1);
```

### Performance Impact

**Memory Usage:**
- Plugin itself: ~3-5 MB
- Sampling data (30 days): 5-20 MB database
- Per-request overhead: < 1 MB

**Database Queries:**
- Rule loading: 2-3 queries per admin page (cached)
- Sample storage: 1 query per sampled request
- Auto-suggestions: 5-10 queries (only when requested)

**Processing Time:**
- Rule evaluation: < 5ms
- Plugin filtering: < 10ms
- Sample storage: < 2ms

## Contributing

Contributions welcome! This plugin is under active development.

### Development Setup

1. Clone repository to `wp-content/plugins/`
2. Install WordPress development environment
3. Enable `WP_DEBUG` and `WP_DEBUG_LOG`
4. Install Query Monitor plugin

### Coding Standards

- Follow WordPress Coding Standards
- Use PHPDoc for all methods
- Comment complex logic
- Test on PHP 7.4, 8.0, 8.1, 8.2

### Testing

- Test on multiple WordPress versions (6.0-6.8)
- Test with popular plugins (WooCommerce, Elementor)
- Test in multisite environment
- Test with Query Monitor enabled

## License

GPL v2 or later

## Author

**FFS.cz**  
Website: https://ffs.cz  
Support: Via GitHub Issues

## Support & Documentation

- **GitHub Issues:** Report bugs or request features
- **Documentation:** See plugin's Settings page
- **Debug Mode:** Enable `WP_DEBUG` for detailed logging
- **Query Monitor:** Use for performance profiling

## Credits

Built with:
- WordPress Plugin API
- WordPress Options API
- WordPress Database API
- WP-Cron
- jQuery (admin interface)

## Disclaimer

This plugin modifies core WordPress plugin loading behavior. While extensively tested, always:
- Test on staging site first
- Backup your database before activation
- Monitor admin functionality after applying rules
- Keep plugin updated to latest version

The authors are not responsible for data loss or functionality issues caused by misconfiguration.
