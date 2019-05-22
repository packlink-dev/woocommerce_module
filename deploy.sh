#!/bin/bash

# Cleanup any leftovers
rm -f ./packlink-pro-shipping.zip
rm -f ./packlink-pro-shipping

# Create deployment source
echo -e "\e[32mSTEP 1:\e[39m Copying plugin source..."
mkdir packlink-pro-shipping
cp -r ./src/* packlink-pro-shipping
rm -rf ./packlink-pro-shipping/tests
rm -rf ./packlink-pro-shipping/bin
rm -rf ./packlink-pro-shipping/phpunit.xml.dist
rm -rf ./packlink-pro-shipping/vendor

# Ensure proper composer dependencies
echo -e "\e[32mSTEP 2:\e[39m Installing composer dependencies..."
composer install -d "$PWD/packlink-pro-shipping" --no-dev -q

# Remove unnecessary files from final release archive
echo -e "\e[32mSTEP 3:\e[39m Removing unnecessary files from final release archive..."
rm -rf packlink-pro-shipping/vendor/packlink/integration-core/.git
rm -rf packlink-pro-shipping/vendor/packlink/integration-core/.gitignore
rm -rf packlink-pro-shipping/vendor/packlink/integration-core/.idea
rm -rf packlink-pro-shipping/vendor/packlink/integration-core/tests
rm -rf packlink-pro-shipping/vendor/packlink/integration-core/generic_tests
rm -rf packlink-pro-shipping/vendor/packlink/integration-core/README.md

# Copy resources
echo -e "\e[32mSTEP 4:\e[39m Copying resources from core to the integration..."
root="$PWD";
source="$PWD/packlink-pro-shipping/vendor/packlink/integration-core/src/BusinessLogic/Resources";
destination="$PWD/packlink-pro-shipping/resources";
if [ ! -d "$destination/images/carriers" ]; then
  mkdir "$destination/images/carriers"
fi
if [ ! -d "$destination/js/core" ]; then
  mkdir "$destination/js/core"
fi
if [ ! -d "$destination/js/location-picker" ]; then
  mkdir "$destination/js/location-picker"
fi
cp -r ${source}/img/carriers/* ${destination}/images/carriers
cp -r ${source}/js/* ${destination}/js/core
cp -r ${source}/LocationPicker/js/* ${destination}/js/location-picker
cp -r ${source}/LocationPicker/css/* ${destination}/css

cd ${destination}/js/core
for file in ./* ; do
    mv "$file" "$(echo $file|sed -e 's/^.\//packlink\-/' -e 's/\([A-Z]\)/\-\L\1/g' -e 's/\-\-/\-/')"
done

cd ${destination}/js/location-picker
for file in ./* ; do
    mv "$file" "$(echo $file|sed -e 's/^.\//packlink\-/' -e 's/\([A-Z]\)/\-\L\1/g' -e 's/\-\-/\-/')"
done

cd ${destination}/css
mv ./locationPicker.css ./packlink-location-picker.css

cd ${root}

# Create plugin archive
echo -e "\e[32mSTEP 5:\e[39m Creating new archive..."
zip -r -q  packlink-pro-shipping.zip ./packlink-pro-shipping

version="$1"
if [ "$version" = "" ]; then
    version=$(php -r "echo json_decode(file_get_contents('src/composer.json'), true)['version'];")
    if [ "$version" = "" ]; then
        echo "Please enter new plugin version (leave empty to use root folder as destination) [ENTER]:"
        read version
    else
        echo -e "\e[35mVersion read from the composer.json file: $version\e[39m"
    fi
fi

if [ "$version" != "" ]; then
    if [ ! -d ./PluginInstallation/ ]; then
        mkdir ./PluginInstallation/
    fi
    if [ ! -d ./PluginInstallation/"$version"/ ]; then
        mkdir ./PluginInstallation/"$version"/
    fi

    mv ./packlink-pro-shipping.zip ./PluginInstallation/${version}/
    touch "./PluginInstallation/$version/Release notes $version.txt"
    echo -e "\e[32mDONE!\n\e[93mNew release created under: $PWD/PluginInstallation/$version"
else
    echo -e "\e[32mDONE!\n\e[93mNew plugin archive created: $PWD/packlink-pro-shipping.zip"
fi

rm -fR ./packlink-pro-shipping
