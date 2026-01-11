# NATS PHP Client

Official PHP client for the NATS messaging system. NATS is a simple, secure and performant communications system for digital systems, services and devices.

## Features

- Full NATS protocol support
- Publish/Subscribe messaging
- Request/Reply pattern
- Queue groups
- TLS/SSL support
- Automatic reconnection
- Connection pooling
- Multiple encoding formats (JSON, raw, etc.)
- Comprehensive error handling
- PSR standards compliant

## Installation

```bash
composer require nats-php/nats-client
```


## 21. **CHANGELOG.md**

```markdown
# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2024-01-10

### Added
- Official NATS PHP client package
- Full NATS protocol support
- TLS/SSL encryption support
- Automatic reconnection with configurable attempts
- Queue groups support
- Request/Reply pattern (sync & async)
- Multiple encoders (JSON, Raw)
- Comprehensive error handling
- Connection statistics
- Debug logging
- PSR standards compliance
- Unit tests
- Examples and documentation

### Changed
- Complete rewrite with modern PHP practices
- Improved architecture with separation of concerns
- Better error messages and exceptions
- Enhanced performance and reliability

## [1.0.0] - Initial Release
- Basic NATS client functionality
- Publish/Subscribe support
- Basic authentication

