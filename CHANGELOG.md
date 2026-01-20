# Changelog

All notable changes to `nano-service` will be documented in this file.

## [Unreleased]

### Fixed
- **CRITICAL**: Removed duplicate `$statsD` property declaration in `NanoConsumer` class (2026-01-20)
  - The `$statsD` property was incorrectly redeclared as `private` in `NanoConsumer` class
  - This property is already inherited from parent `NanoServiceClass` as `protected`
  - **Impact**: Fatal error: "Access level to AlexFN\NanoService\NanoConsumer::$statsD must be protected (as in class AlexFN\NanoService\NanoServiceClass) or weaker"
  - **Fix**: Removed the duplicate property declaration from `NanoConsumer.php:22-23`
  - **Root Cause**: PHP inheritance rules require child class properties to have equal or weaker visibility than parent
  - **Files Changed**: `src/NanoConsumer.php`

