PORT ?= 8000
start:
	PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT)  -t public
#start:
#	php -S localhost:8080 -t public public/index.php
install:
	composer install

lint:
	composer exec --verbose phpcs -- --standard=PSR12 public src