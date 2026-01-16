PHP_VERSION = 8.4.15

# Detect architecture from build container (arm64 -> aarch64, x86_64 -> x86_64)
ARCH = $(shell uname -m)
PHP_ARCHIVE = php-$(PHP_VERSION)-cli-linux-$(ARCH).tar.gz
PHP_URL = https://dl.static-php.dev/static-php-cli/common/$(PHP_ARCHIVE)

# Download PHP binary matching the target architecture
# NOTE: Use `sam build --use-container` to build in Linux environment
prepare:
	@echo "Detected architecture: $(ARCH)"
	@echo "Downloading: $(PHP_URL)"
	[ -e $(PHP_ARCHIVE) ] || curl -fsSL -o $(PHP_ARCHIVE) $(PHP_URL)
	tar -xzf $(PHP_ARCHIVE)
	chmod +x php
	[ -e composer.phar ] || curl -fsSL -O https://getcomposer.org/download/latest-stable/composer.phar

# Build PHP Layer (static-php-cli binary)
# Layer is mounted at /opt, so php will be at /opt/bin/php
build-PhpLayer: prepare
	mkdir -p $(ARTIFACTS_DIR)/bin
	cp php $(ARTIFACTS_DIR)/bin/php

# Build Application Function (code + vendor only, no PHP binary)
# NOTE: This sample app requires dev dependencies (nyholm/psr7, php-http/curl-client)
#       For production, add required implementations to your own composer.json "require" section
build-App: prepare
	cp -r src $(ARTIFACTS_DIR)/src
	cp composer.json composer.lock $(ARTIFACTS_DIR)
	cp bootstrap $(ARTIFACTS_DIR)
	cp main.php $(ARTIFACTS_DIR)
	./php composer.phar install --no-interaction --no-progress --prefer-dist --optimize-autoloader -d $(ARTIFACTS_DIR)
	# Remove unnecessary files to reduce package size
	find $(ARTIFACTS_DIR)/vendor -type f -name "*.md" -delete
	find $(ARTIFACTS_DIR)/vendor -type f -name "*.txt" -delete
	find $(ARTIFACTS_DIR)/vendor -type f -name "LICENSE*" -delete
	find $(ARTIFACTS_DIR)/vendor -type f -name "CHANGELOG*" -delete
	find $(ARTIFACTS_DIR)/vendor -type d -name "tests" -exec rm -rf {} + 2>/dev/null || true
	find $(ARTIFACTS_DIR)/vendor -type d -name "Tests" -exec rm -rf {} + 2>/dev/null || true
	find $(ARTIFACTS_DIR)/vendor -type d -name "docs" -exec rm -rf {} + 2>/dev/null || true
