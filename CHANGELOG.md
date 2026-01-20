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

- **BUG**: Fixed StatsDConfig validation running when full array config provided (2026-01-20)
  - When creating `StatsDConfig` with all config values passed via array, env validation still ran
  - **Impact**: Tests and programmatic config usage could fail with "Missing required environment variables" even when all values were provided
  - **Fix**: Skip env validation when `host`, `port`, `namespace`, and `sampling` are all provided via config array
  - **Files Changed**: `src/Config/StatsDConfig.php`

- **BUG**: Fixed PHP 8.4 deprecation warning in `NanoServiceMessage::getTimestampWithMs()` (2026-01-20)
  - Implicit conversion from `float` to `int` in `date()` function was deprecated in PHP 8.4
  - **Impact**: Deprecation warning: "Implicit conversion from float X.X to int loses precision"
  - **Fix**: Added explicit `(int)` cast: `date('Y-m-d H:i:s', (int)$mic)`
  - **Files Changed**: `src/NanoServiceMessage.php`

### Added
- **Tests**: Comprehensive test coverage for StatsD metrics (2026-01-20)
  - `tests/Unit/StatsDClientTest.php`: 75+ tests for StatsDClient
  - `tests/Unit/StatsDConfigTest.php`: 50+ tests for StatsDConfig
  - `tests/Unit/EnumsTest.php`: 15 tests for all enum classes
  - `tests/Unit/NanoServiceMessageTest.php`: 48 tests for NanoServiceMessage
  - `tests/Unit/NanoServiceMessageStatusesTest.php`: 16 tests for status enum
  - `tests/Unit/EnvironmentTraitTest.php`: 9 tests for Environment trait
  - **Total**: 202 tests with 375 assertions

### Documentation
- Updated `docs/METRICS.md` with required environment variables clarification
- Updated `docs/CONFIGURATION.md` with fail-fast validation behavior
- Updated `docs/BUGFIXES.md` with newly discovered and fixed bugs

