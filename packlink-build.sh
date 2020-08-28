#!/bin/bash
set -e

# Cleanup any leftovers
echo "\e[32mCleaning up...\e[39m"
rm -rf ./packlink-pro-shipping.zip
rm -rf ./packlink-pro-shipping

# Create deployment source
echo "\e[32mSTEP 1:\e[39m Copying plugin source..."
mkdir packlink-pro-shipping
cp -r ./src/* packlink-pro-shipping
rm -rf ./packlink-pro-shipping/tests
rm -rf ./packlink-pro-shipping/bin
rm -rf ./packlink-pro-shipping/phpunit.xml
rm -rf ./packlink-pro-shipping/vendor

# Ensure proper composer dependencies
echo "\e[32mSTEP 2:\e[39m Installing composer dependencies..."
cd packlink-pro-shipping
composer install --no-dev
cd ..

# Remove unnecessary files from final release archive
echo "\e[32mSTEP 3:\e[39m Removing unnecessary files from final release archive..."
rm -rf packlink-pro-shipping/vendor/packlink/integration-core/.git
rm -rf packlink-pro-shipping/vendor/packlink/integration-core/.gitignore
rm -rf packlink-pro-shipping/vendor/packlink/integration-core/.idea
rm -rf packlink-pro-shipping/vendor/packlink/integration-core/tests
rm -rf packlink-pro-shipping/vendor/packlink/integration-core/generic_tests
rm -rf packlink-pro-shipping/vendor/packlink/integration-core/README.md
rm -rf packlink-pro-shipping/vendor/packlink/integration-core/src/BusinessLogic/Utility/PdfMerge.php
rm -rf packlink-pro-shipping/vendor/iio/libmergepdf/tests
rm -rf packlink-pro-shipping/vendor/iio/libmergepdf/.gitignore
rm -rf packlink-pro-shipping/vendor/iio/libmergepdf/.travis.yml
rm -rf packlink-pro-shipping/vendor/iio/libmergepdf/phpunit.xml.dist
rm -rf packlink-pro-shipping/vendor/setasign/fpdf/doc
rm -rf packlink-pro-shipping/vendor/setasign/fpdf/tutorial

# Copy resources
echo "\e[32mSTEP 4:\e[39m Copying resources from core to the integration..."
root="$PWD";
source="$PWD/packlink-pro-shipping/vendor/packlink/integration-core/src/BusinessLogic/Resources";
destination="$PWD/packlink-pro-shipping/resources";

if [ ! -d "$destination/js/location-picker" ]; then
  mkdir "$destination/js/location-picker"
fi

cp -r "${source}/LocationPicker/js/*" "${destination}/js/location-picker"
cp -r "${source}/LocationPicker/css/*" "${destination}/css"

cd "${destination}/js/location-picker"
for file in ./* ; do
    mv "$file" "$(echo "$file"|sed -e 's/^.\//packlink\-/' -e 's/\([A-Z]\)/\-\L\1/g' -e 's/\-\-/\-/')"
done

cd "${destination}/css"
mv ./locationPicker.css ./packlink-location-picker.css

cd "${root}"

# get plugin version
echo "\e[32mSTEP 5:\e[39m Reading module version..."

version="$1"
if [ "$version" = "" ]; then
    version=$(php -r "echo json_decode(file_get_contents('src/composer.json'), true)['version'];")
    if [ "$version" = "" ]; then
        echo "Please enter new plugin version (leave empty to use root folder as destination) [ENTER]:"
        read -r version
    else
        echo -e "\e[35mVersion read from the composer.json file: $version\e[39m"
    fi
fi

# add version to artifact
echo "$1" > ./packlink-pro-shipping/release.version

# Create plugin archive
echo "\e[32mSTEP 6:\e[39m Creating new archive..."
zip -r -q  packlink-pro-shipping.zip ./packlink-pro-shipping
