prepare:
	[ -e php-8.4.15-cli-linux-aarch64.tar.gz ] || curl -fsSL -o php-8.4.15-cli-linux-aarch64.tar.gz https://dl.static-php.dev/static-php-cli/common/php-8.4.15-cli-linux-aarch64.tar.gz
	tar -xzf php-8.4.15-cli-linux-aarch64.tar.gz
	chmod +x php

build-App: prepare
	cp php $(ARTIFACTS_DIR)/php
	cp -r src $(ARTIFACTS_DIR)/src
	cp composer.* $(ARTIFACTS_DIR)
	cp bootstrap $(ARTIFACTS_DIR)
	cp main.php $(ARTIFACTS_DIR)

	composer install --no-interaction --no-progress --no-suggest --prefer-dist --optimize-autoloader -d $(ARTIFACTS_DIR)
