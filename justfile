install:
    composer install --prefer-dist --no-interaction

format:
    composer format

lint:
    composer lint:ci

test:
    composer test

check:
    composer check-all

next-tag:
    ./genNextTagPrd.sh --dry-run

release:
    ./genNextTagPrd.sh
