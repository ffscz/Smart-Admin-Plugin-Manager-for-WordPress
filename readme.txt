=== Smart Admin Plugin Manager ===
Contributors: ffscz
Tags: performance, admin, optimization, plugin manager, speed, loading, admin performance, developer tools
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Intelligent WordPress plugin loading management for admin interface - per-screen control with automatic optimization suggestions.

Compatibility quick facts: WordPress 6.0+ | PHP 7.4+ | License GPLv2+

== Description ==

Smart Admin Plugin Manager (SAPM) is an advanced WordPress plugin that optimizes admin performance by controlling which plugins load on specific admin screens. Unlike traditional solutions that load all active plugins on every admin page, SAPM allows granular per-screen control, automatically detecting unnecessary plugins and providing data-driven optimization suggestions.

The plugin operates at the core WordPress level using MU-plugins mechanism, intercepting the plugin loading process before WordPress initialization completes. This allows for dramatic performance improvements by preventing heavy plugins from loading on screens where they are not needed.

**Key Features:**

* **Per-Screen Plugin Control** - Granular control over which plugins load on each admin screen (Dashboard, Posts, Pages, Settings, WooCommerce, etc.)
* **Three Operation States** - Enabled (always load), Disabled (never load), Defer (load after main page load - reduces initial load time by ~70%)
* **Smart Menu Preservation** - Admin menu items preserved for blocked/deferred plugins with automatic snapshot system
* **Hierarchical Rule System** - Global, group, and screen-specific rules with intelligent inheritance
* **Automatic Mode with Auto Suggestions** - Monitors plugin performance and provides confidence-scored optimization suggestions
* **Dependency Cascade Blocking** - Automatically blocks dependent plugins when parent is blocked
* **Request Type Optimization** - Separate control for AJAX, REST API, WP-Cron, and WP-CLI
* **Update Optimizer** - Three strategies to reduce update check overhead (saves 0.3-0.6s per page load)
* **Performance Monitoring** - Real-time per-plugin metrics including load time, queries, and memory usage
* **Developer Tools** - Admin drawer, WP-Admin bar integration, debug mode, REST API, extensive hooks

**Performance Benefits:**

* **30-50% Faster Admin Pages** - Typical load time reduction across admin interface
* **20-40 Fewer Database Queries** - Per page by blocking unnecessary plugins
* **1-5 MB Memory Savings** - Per blocked plugin, crucial for shared hosting
* **Better User Experience** - Noticeable speed improvements in admin navigation

**Advanced Features:**

* **Sampling-Based Analysis** - 30-day retention with confidence scoring (0-100%)
* **Contextual Intelligence** - Recognizes plugin purposes and screen relevance
* **Smart Detection** - Automatic handling for WooCommerce actions and REST namespaces
* **Update Badge Preservation** - Maintains accurate counts while reducing API calls
* **Admin Drawer** - Quick plugin management overlay on any admin screen

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/smart-admin-plugin-manager/` directory
2. Activate the plugin through the 'Plugins' screen in WordPress
3. **MU-Plugin automatically created** - The plugin creates `wp-content/mu-plugins/smart-admin-plugin-manager.php` automatically
4. Navigate to 'Settings → Plugin Manager' in your WordPress admin
5. Configure rules manually or enable Automatic Mode for auto-suggested optimizations

**Requirements Check:**

The plugin automatically verifies:
- PHP version 7.4+ (8.0+ recommended)
- WordPress version 6.0+
- Write permissions for `wp-content/mu-plugins/`
- Database table creation capability

== Frequently Asked Questions ==

= What makes SAPM different from other optimization plugins? =

SAPM operates at the WordPress core level before regular plugins load, using the MU-plugins mechanism. This allows true prevention of plugin loading, not just deactivation. It's the only solution that preserves admin menus while blocking plugins and provides automatic auto-suggested optimizations based on real performance data.

= How does Automatic Mode work? =

Automatic Mode collects performance samples (load time, queries, memory) for 24-48 hours across all admin screens. It then analyzes patterns to identify plugins with high load times but low usage frequency. Each suggestion includes a confidence score and potential savings. You review and apply suggestions manually.

= Is it safe to block plugins? =

SAPM includes extensive safety mechanisms:
- Dependency cascade detection prevents breaking plugin relationships
- Smart menu preservation keeps plugin settings accessible
- Automatic exclusions for critical scenarios (page builders, WooCommerce checkout)
- The plugin itself is auto-protected from being blocked
Always test on staging first and monitor functionality after applying rules.

= Will it break my admin functionality? =

With proper configuration, no. The plugin is designed to be conservative:
- Manual mode requires explicit rules
- Automatic mode only suggests, never applies automatically
- You can always access plugin settings even when blocked
- Easy rule reversal if issues occur
Follow recommended workflow: start with Auto mode on staging, test thoroughly, export rules to production.

= Does it work with page builders? =

Yes. SAPM automatically detects and bypasses optimization when you're actively editing with Elementor, Bricks, Divi, Beaver Builder, and other popular page builders to prevent conflicts.

= What about WooCommerce? =

Full WooCommerce support:
- Cart, checkout, and account pages automatically excluded
- Admin screens properly detected and categorized
- WooCommerce extension dependencies handled
- Product pages and orders screens configurable

= Can I use it on multisite? =

Yes, but network activation requires per-site activation. Each site gets its own rules and configuration.

= Does it affect frontend performance? =

No. SAPM only optimizes WordPress admin interface. Frontend (public-facing pages) is completely unaffected.

= How does the Update Optimizer work? =

Three strategies available:
1. **TTL Extension** - Increases time between update checks (12h → 24-72h)
2. **Page-Specific** - Only checks updates on Dashboard and Plugins pages
3. **Cron-Only** - All checks via background tasks, never during admin page loads
All strategies preserve update badge accuracy and allow manual checks anytime.

= What are the performance impacts? =

**Plugin Overhead:**
- Memory: ~3-5 MB
- Rule evaluation: < 5ms per page
- Database queries: 2-3 per page (cached)
- Sample storage: < 2ms per request

**Benefits Typically Outweigh Costs by 10-20x**

== Screenshots ==

1. Main dashboard showing rules for WordPress core screens
2. Automatic Mode with auto-suggested optimizations and confidence scores
3. Per-screen plugin control with visual state indicators
4. Admin drawer for quick plugin management on any screen
5. Performance monitoring dashboard with load time metrics
6. Update Optimizer configuration with strategy selection
7. Request type optimization (AJAX, REST API, WP-Cron, WP-CLI)

== Changelog ==

= 1.3.0 (Current) =
* NEW: Secure GitHub Release updater with native WordPress update integration
* NEW: SHA256 package integrity validation before install (download gate)
* NEW: Strict trusted-host allowlist and package URL validation for updater
* NEW: Extracted package structure validation (required root folder and main file)
* IMPROVED: Frontend drawer now uses shared drawer CSS with unified visual system
* IMPROVED: Frontend bar JavaScript refactor (Shadow DOM mount, per-page override UX, reset flow)
* IMPROVED: Update metadata caching strategy (lock + fallback + HTTP validators)
* IMPROVED: Admin/AJAX release hardening and input sanitization consistency
* FIXED: Removed noisy debug logging from dependency/cascade paths
* REMOVED: Legacy `assets/frontend-bar.css` (replaced by shared `assets/drawer.css`)

= 1.2.0 =
* NEW: Automatic Mode with auto-suggested optimization rules
* NEW: Database storage for sampling data with 30-day retention
* NEW: Update Optimizer with three strategies (TTL Extension, Page-Specific, Cron-Only)
* NEW: Dependency cascade blocking for plugin relationships
* NEW: Request type optimization (AJAX, REST API, WP-Cron, WP-CLI)
* NEW: Performance monitoring with per-plugin metrics
* NEW: Admin drawer for quick plugin management overlay
* IMPROVED: Hierarchical rule system with global, group, and screen-specific rules
* IMPROVED: Smart menu preservation system
* IMPROVED: WooCommerce integration and compatibility
* IMPROVED: REST API with 17 endpoints for programmatic control

= 1.1.0 =
* NEW: Per-screen plugin control system
* NEW: Defer loading support for 70% load time reduction
* NEW: Admin drawer interface
* NEW: Performance monitoring dashboard
* IMPROVED: Rule management system
* IMPROVED: Compatibility with popular plugins

= 1.0.0 =
* Initial release
* Manual mode for plugin management
* Basic rule system for global control
* MU-plugin loader mechanism
* WordPress core screen support

== Upgrade Notice ==

= 1.3.0 =
Security-focused update release with GitHub updater + package integrity verification. For GitHub delivery, publish both ZIP and matching `.sha256` asset in release.

= 1.2.0 =
Major update with Automatic Mode and auto-suggested optimizations. Backup recommended before upgrading. Test on staging site first.

= 1.1.0 =
Significant feature additions including defer loading and performance monitoring. Review configuration after update.

== Security ==

Smart Admin Plugin Manager takes security seriously:

* MU-plugin loader uses secure path validation
* All user inputs properly validated and sanitized
* REST API endpoints require proper authentication and capabilities
* Permission checks with `manage_options` capability
* CSRF protection for all administrative actions
* Secure database operations with prepared statements
* Debug mode respects WP_DEBUG configuration

== Performance ==

SAPM is optimized for minimal overhead:

* Rule evaluation: < 5ms per page load
* Memory usage: ~3-5 MB for plugin itself
* Database queries: 2-3 per admin page (cached)
* Sampling overhead: < 2ms per sampled request
* No impact on frontend performance

**Typical Performance Improvements:**
* 30-50% faster admin pages
* 20-40 fewer database queries per page
* 1-5 MB memory savings per blocked plugin
* 0.3-0.6s saved with Update Optimizer

== Developer Information ==

**Filters:**

`sapm_safe_to_block_patterns` - Modify safe-to-block plugin patterns
`sapm_required_plugins` - Specify plugins that should never be blocked
`sapm_screen_definitions` - Add custom screen definitions
`sapm_dependency_patterns` - Customize dependency detection patterns

**Actions:**

`sapm_rules_applied` - Triggered after rules are applied (parameters: $screen_id, $disabled_plugins)
`sapm_before_sample_store` - Before performance sample storage (parameter: $sample_data)

**REST API:**

Base URL: `/wp-json/sapm/v1/`

Endpoints:
- `GET /config` - Get all rules
- `POST /config` - Update rules
- `GET /stats` - Performance statistics
- `GET /auto_suggestions` - Get auto suggestions
- `POST /apply_auto_rules` - Apply suggestions
- Plus 12 more endpoints for comprehensive control

**Database Tables:**

`{prefix}_sapm_sampling_data` - Performance samples with 30-day retention

== Compatibility ==

**WordPress:**
- Minimum: WordPress 6.0
- Tested up to: WordPress 6.8
- Multisite: Fully compatible (per-site activation)

**PHP:**
- Minimum: PHP 7.4
- Recommended: PHP 8.0+
- Tested: PHP 7.4, 8.0, 8.1, 8.2

**Third-Party Plugins:**
- WooCommerce 7.0+
- Elementor & Elementor Pro
- Advanced Custom Fields (ACF)
- Yoast SEO / Rank Math
- Query Monitor (debug integration)
- Page builders (Bricks, Divi, Beaver Builder)

**Hosting Environments:**
- Shared hosting compatible
- VPS/Dedicated (recommended)
- Managed WordPress (SiteGround, Kinsta, WP Engine)
- Apache, Nginx, LiteSpeed, OpenLiteSpeed

== Support ==

For support, documentation, and feature requests:

* GitHub Issues: Report bugs or request features
* Documentation: See plugin's Settings page in WordPress admin
* Debug Mode: Enable WP_DEBUG for detailed logging
* Query Monitor: Use for performance profiling

== Credits ==

Built with:
- WordPress Plugin API
- WordPress Options API
- WordPress Database API
- WP-Cron for scheduled tasks
- jQuery (admin interface)

== Disclaimer ==

This plugin modifies core WordPress plugin loading behavior. While extensively tested and stable for production use:

- Always test on staging site first
- Backup your database before activation
- Monitor admin functionality after applying rules
- Keep plugin updated to latest version
- Start with Automatic Mode for safest optimization

The authors are not responsible for data loss or functionality issues caused by misconfiguration.

== License ==

This plugin is licensed under the GPL v2 or later.

This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
