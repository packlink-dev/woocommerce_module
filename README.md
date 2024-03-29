[![CircleCI](https://circleci.com/gh/packlink-dev/woocommerce_module.svg?style=svg&circle-token=d06e0f1584eb81581966c36b1fb9aa310969e9f4)](https://circleci.com/gh/packlink-dev/woocommerce_module)
[![Codacy Badge](https://app.codacy.com/project/badge/Grade/2c7f382158ce4b85ba550c84bb65371f)](https://www.codacy.com/gh/packlink-dev/woocommerce_module?utm_source=github.com&amp;utm_medium=referral&amp;utm_content=packlink-dev/woocommerce_module&amp;utm_campaign=Badge_Grade)

![Packlink logo](https://cdn.packlink.com/apps/giger/logos/packlink-pro.png)

# Packlink WooCommerce plugin

## Getting started

### Installation

To work with this integration, the module can be installed in a few minutes by going through these following steps:

- Step 1: Download the module
- Step 2: Go to WooCommerce back office
- Step 3: Navigate to Plugins >> Add New
- Step 4: Click on "Upload Plugin" button
- Step 5: Select downloaded file and click on "Install Now".
- Step 6: Click on "Activate Plugin" button.

After installation is over, plugin configuration can be set by navigating to WooCommerce >> Packlink PRO.

## Compatibility

- WordPress v4.7+
- WooCommerce v3.0+

## Prerequisites

- PHP 5.5 or newer
- MySQL 5.0 or newer

## Development Guidelines

### Coding standards

Use WordPress extension for PhpStorm IDE. It will help significantly during the development.
To check the code against the coding standards, execute these commands in the root of the project

```bash
composer install
vendor/bin/phpcs --config-set installed_paths vendor/wp-coding-standards/wpcs/
vendor/bin/phpcs src/ --standard=WordPress --colors --severity=10
```

Correct **all** errors reported but the code sniffer.

### Running the tests

Tests are run on WordPress testing SDK. More on this can be found [here](https://make.wordpress.org/cli/handbook/plugin-unit-tests/).

First install the needed wordpress database for tests (this has to be run just once):

```bash
cd src
bin/install-wp-tests.sh wordpress_test dbuser dbpass localhost latest
```

Then, either setup PHPStorm to run tests based on the `/src/phpunit.xml` configuration file
or go to the root directory and run:

```bash
./run-tests.sh
```

This command will run unit tests on all supported PHP versions from 5.6 to 7.3.

### Manual synchronization


For merchants that have a problem with CPU usage on their servers, there is a manual synchronization configuration option that can be set from the module configuration page.

Note: This “big“ timeout will potentially not always work on the windows system.

If the .ini configuration value of [max_execution_time](https://www.php.net/manual/en/info.configuration.php#ini.max-execution-time) is less than 5 minutes, this will not work on the Windows server due to the following restriction which can be found at the following [link](https://www.php.net/manual/en/info.configuration.php#ini.max-execution-time).

The `set_time_limit()` function and the configuration directive `max_execution_time` only affect the execution time of the script itself. Any time spent on activity that happens outside the execution of the script such as system calls using `system()`, stream operations, database queries, etc. is not included when determining the maximum time that the script has been running. This is not true on Windows where the measured time is real.

### Releasing a new module version

Please follow instructions provided [here](https://logeecom.atlassian.net/wiki/spaces/PACKLINK/pages/1367179297/WC+-+Plugin+Release+Procedure).

### Running Tests on docker

```bash
docker run --name percona -e MYSQL_ROOT_PASSWORD=root -d -it eu.gcr.io/packlink-tools/packlink-percona:5.6_packlink1
docker run -it -v $(pwd):/woocommerce php:<version> /bin/bash
```

```bash
# apt system
  apt-get update
  apt-get install -y  git wget curl subversion mysql-client php<version>-mysql php<version>-zip zlib1g-dev libzip-dev

# Install php dependencies (zip mysqli)
  docker-php-ext-install mysqli zip

# Install composer
  php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
  HASH="$(wget -q -O - https://composer.github.io/installer.sig)"
  php -r "if (hash_file('SHA384', 'composer-setup.php') === '$HASH') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"
  php composer-setup.php --install-dir=/usr/local/bin --filename=composer

# Install wordpress
  cd src
  bin/install-wp-tests.sh wordpress_70 root root  172.17.0.1 latest
  cd ..

# Run Test
  rm -rf packlink-pro-shipping
  mkdir packlink-pro-shipping
  cp -r ./src/* packlink-pro-shipping
  cd packlink-pro-shipping
  composer install
  php vendor/bin/phpunit

```

### Circleci Test and Build

Circle ci will run:
```bash
- !master: Test suite(php 5.6, 7.0, 7.1, 7.2,7.3)
- master: nothing run
- tag/release run build_publish package to cdn
```
