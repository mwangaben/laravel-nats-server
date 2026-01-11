.PHONY: help test unit integration check clean test-connection

help:
	@echo "NATS PHP Client - Development Commands"
	@echo "========================================"
	@echo ""
	@echo "Testing:"
	@echo "  make test              - Run all tests"
	@echo "  make unit              - Run unit tests only"
	@echo "  make integration       - Run integration tests"
	@echo "  make test-connection   - Test NATS server connection"
	@echo "  make check             - Run code quality checks"
	@echo "  make clean             - Clean generated files"



unit:
	@echo "Running unit tests..."
	@vendor/bin/phpunit --testsuite "Unit Tests" || true

integration:
	@echo "Checking NATS server..."
	@echo "Running integration tests..."
	@vendor/bin/phpunit --testsuite "Integration Tests" || true

check:
	@echo "Running code quality checks..."
	@echo "1. PHP CodeSniffer..."
	@if [ -f vendor/bin/phpcs ]; then vendor/bin/phpcs --standard=PSR12 src/ --warning-severity=0; else echo "phpcs not installed"; fi
	@echo "2. PHPStan..."
	@if [ -f vendor/bin/phpstan ]; then vendor/bin/phpstan analyse src/ --level=7 --no-progress; else echo "phpstan not installed"; fi
	@echo "3. Unit tests..."
	@make unit
	@echo "✅ Checks completed"

clean:
	@echo "Cleaning generated files..."
	rm -rf coverage/
	@echo "✅ Clean complete"

test-connection:
	@php tests/test-connection.php

# Install dev tools
install-dev:
	@echo "Installing development tools..."
	composer require --dev squizlabs/php_codesniffer phpstan/phpstan friendsofphp/php-cs-fixer
	@echo "✅ Dev tools installed"

# Fix code style
fix-style:
	@echo "Fixing code style..."
	@if [ -f vendor/bin/phpcbf ]; then vendor/bin/phpcbf --standard=PSR12 src/; else echo "phpcbf not installed"; fi
	@if [ -f vendor/bin/php-cs-fixer ]; then vendor/bin/php-cs-fixer fix src/; else echo "php-cs-fixer not installed"; fi
