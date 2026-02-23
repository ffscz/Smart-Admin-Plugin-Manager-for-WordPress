# Changelog

All notable changes to Smart Admin Plugin Manager will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Release Badges
[![WordPress Plugin Version](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-GPLv2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Status](https://img.shields.io/badge/Status-Stable-brightgreen.svg)](https://github.com/ffscz/Smart-Admin-Plugin-Manager-for-WordPress)

## [1.3.0] - 2026-02-23

### Added
- **Secure GitHub Release updater** (`includes/class-sapm-github-updater.php`)
  - Native integration with WordPress update transient and plugin details API
  - Metadata fetch from GitHub Releases (`/releases/latest`) with schema validation
  - Runtime fetch lock and last-good metadata fallback
- **Frontend Optimizer mode**
  - New frontend filtering engine (`includes/class-sapm-frontend.php`) with plugin and asset rules
  - Frontend settings and rules management in dedicated admin tab
  - Frontend admin-bar drawer runtime (`assets/frontend-bar.js`) for quick per-page overrides
- **Integrity-gated package installation**
  - SHA256 verification against release `.sha256` asset before install
  - Strict package URL equality check and trusted-host allowlist
  - ZIP structure validation (required root folder + main plugin file)

### Changed
- **Bootstrap loading flow**
  - Plugin bootstrap now loads GitHub updater module from `smart-admin-plugin-manager.php`
- **Administration UI redesign**
  - Settings page reorganized into section switcher and tab routing (`Administration`, `Frontend`, `Update Optimizer`)
  - Admin components and styles refactored for cleaner layout and better UX consistency
- **Frontend drawer architecture**
  - `assets/frontend-bar.js` refactored for isolated mount (Shadow DOM) and stronger per-page override UX
  - Frontend bar styling consolidated into shared `assets/drawer.css` for visual consistency
- **Admin AJAX surface expansion** (`includes/class-sapm-admin.php`)
  - Added handlers for admin theme persistence and frontend rule/settings operations
  - Added JSON input sanitization helper for stricter payload handling
- **Update optimizer flow refinement** (`includes/class-sapm-update-optimizer.php`)
  - Improved page/context gating and update-check orchestration for lower admin overhead

### Fixed
- Removed noisy debug logging from dependency/cascade execution paths (`class-sapm-admin.php`, `class-sapm-core.php`, `class-sapm-dependencies.php`)
- Improved frontend enqueue ordering for better compatibility with complex theme/plugin stacks

### Security
- Enforced secure update transport and source policy:
  - HTTPS-only update URLs
  - Host allowlist checks for GitHub asset domains
  - SHA256 integrity verification before upgrade extraction
  - Extracted source folder/file integrity checks prior to installation

### Docs
- Updated `README.md` and `readme.txt` with current release context and compatibility highlights

### Breaking Changes
- None. Release is backward-compatible with existing rule storage and MU-loader behavior.

## [1.2.0] - 2025-01-26

### Added
- **Automatic Mode** with AI-driven optimization suggestions
  - Performance sampling for all request types (Admin, AJAX, REST, Cron, CLI)
  - Confidence scoring system for rule suggestions
  - Contextual plugin relevance detection
  - 30-day data retention with automatic cleanup
- **Dependency Cascade Blocking**
  - Automatic detection of plugin dependencies
  - Smart blocking of dependent plugins when parent is blocked
  - Pre-configured patterns for WooCommerce, Elementor, ACF
- **Update Optimizer** with three strategies
  - TTL Extension (12h → 24-72h)
  - Page-Specific (only update pages)
  - Cron-Only (background updates)
  - Plugin updater endpoint blocking
- **Database Storage** for sampling data
  - Custom table: `{prefix}_sapm_sampling_data`
  - Optimized indexes for fast queries
  - Automatic cleanup via WP-Cron
- **Request Type Optimization**
  - AJAX: Passthrough/Blacklist/Whitelist modes
  - REST API: Per-namespace control
  - WP-Cron: Background task optimization
  - WP-CLI: Command-specific loading
- **Performance Monitoring Dashboard**
  - Real-time plugin load time metrics
  - Database query counting
  - Memory usage tracking
  - Visual performance charts

### Changed
- Improved screen detection algorithm
- Enhanced admin drawer UI
- Optimized database queries (reduced from 11+ to 1 per request)
- Better multisite compatibility
- Refined rule inheritance system

### Fixed
- Memory leak in sampling data collection
- Race condition in deferred plugin loading
- Active plugins corruption during filtered requests
- MU-plugin loader not refreshing after version update

### Performance
- Reduced database queries: 11+ → 1 per admin page load (caching)
- Admin load time improvement: 30-50% typical
- Memory usage: -20% with moderate plugin blocking
- Update check overhead: -95% with Cron-Only strategy

## [1.1.0] - 2024-12-15

### Added
- Per-screen plugin control
- Defer loading support (load plugins after page init)
- Admin drawer quick access interface
- Basic performance monitoring
- WP-Admin Bar integration

### Changed
- Improved rule evaluation performance
- Better screen matching algorithm

### Fixed
- Plugin activation conflicts
- Multisite activation issues

## [1.0.0] - 2024-11-01

### Added
- Initial release
- Manual mode plugin management
- Global and group-level rules
- Screen category support
- Basic MU-plugin loader
- Settings page interface

### Core Features
- Plugin loading filtering
- Screen detection system
- Rule inheritance
- Admin interface

## [Unreleased]

### Planned for 1.4.0
- Multi-language admin interface (Czech, German)
- Import/Export rules functionality
- Advanced scheduling (time-based rules)
- Performance comparison reports
- Plugin conflict detector
- Automatic rollback on errors

### Under Consideration
- Cloud rule sharing
- Automatic performance benchmarking
- Advanced analytics dashboard
- WP-CLI extended commands

---

## Version Compatibility

| SAPM Version | WordPress | PHP     | Tested With |
|--------------|-----------|---------|-------------|
| 1.3.0        | 6.0+      | 7.4+    | 6.8.3       |
| 1.2.0        | 6.0+      | 7.4+    | 6.8.3       |
| 1.1.0        | 6.0+      | 7.4+    | 6.4.2       |
| 1.0.0        | 6.0+      | 7.4+    | 6.2.0       |

## Migration Guide

### From 1.2.0 to 1.3.0

**Automatic Migration:**
- Existing rules/options remain compatible
- MU-loader behavior remains backward-compatible
- No schema-breaking changes required for activation/update

**Manual Steps (GitHub Release Delivery):**
1. Build ZIP with root folder `smart-admin-plugin-manager/`
2. Generate SHA256 file for the exact ZIP (`smart-admin-plugin-manager.sha256`)
3. Upload both assets to the same GitHub Release tag
4. Keep release as non-draft and non-prerelease for updater discovery

**Breaking Changes:**
- None - fully backward compatible

### From 1.1.0 to 1.2.0

**Automatic Migration:**
- Database tables created automatically on activation
- Existing rules preserved and migrated
- No manual intervention required

**Manual Steps (Optional):**
1. Review and test automatic suggestions
2. Configure Update Optimizer strategy
3. Set up request type rules for AJAX/REST
4. Enable sampling for 24-48 hours before applying auto rules

**Breaking Changes:**
- None - fully backward compatible

### From 1.0.0 to 1.1.0

**Breaking Changes:**
- None - rules fully compatible

**New Features:**
- Enable defer loading for heavy plugins
- Use admin drawer for quick access

## Known Issues

### Version 1.3.0

**Minor Issues:**
- Sampling data can grow large (5-20 MB) with high traffic
  - **Workaround:** Automatic cleanup after 30 days
  - **Manual:** Use "Reset Auto data" button

- Auto-suggestions may be too aggressive on first run
  - **Workaround:** Start with confidence threshold 80%+
  - **Manual:** Review each suggestion carefully

- Update Optimizer "Cron-Only" may show stale data
  - **Workaround:** Enable "Show stale indicator"
  - **Manual:** Click "Force update check" on plugins page

**Compatibility:**
- Plugin deactivation may be blocked if page optimized
  - **Workaround:** Disable SAPM temporarily for bulk operations
  - **Fix:** Visit update-core.php or plugins.php (always passthrough)

### Reporting Issues

Please report issues on GitHub with:
- WordPress version
- PHP version
- Active plugins list
- Steps to reproduce
- Error messages or screenshots

## Credits

**Contributors:**
- Core development: FFS.cz team
- Testing: Community contributors

**Special Thanks:**
- WordPress Core team for excellent documentation
- Query Monitor plugin for debugging capabilities
- Beta testers for valuable feedback

## License

GPL v2 or later - see LICENSE file for details
