#!/usr/bin/env bash

rm -rf packlink-pro-shipping
mkdir packlink-pro-shipping
cp -r ./src/* packlink-pro-shipping

cd packlink-pro-shipping

composer install

/usr/bin/php5.6 vendor/bin/phpunit
/usr/bin/php7.0 vendor/bin/phpunit
/usr/bin/php7.1 vendor/bin/phpunit
/usr/bin/php7.2 vendor/bin/phpunit

cd ..
rm -rf packlink-pro-shipping