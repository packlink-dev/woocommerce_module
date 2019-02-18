outfile = packlink-pro-shipping.zip
plugin_dir = $(shell pwd)

$(outfile):
	rm -rf packlink
	mkdir packlink
	mkdir packlink/packlink-pro-shipping
	cp -r ./packlink-pro-shipping/* packlink/packlink-pro-shipping
	rm -rf packlink/packlink-pro-shipping/tests
	rm -rf packlink/packlink-pro-shipping/bin
	rm -rf packlink/packlink-pro-shipping/phpunit.xml.dist
	rm -rf packlink/packlink-pro-shipping/vendor
	composer install -d $(plugin_dir)/packlink/packlink-pro-shipping --no-dev
	rm -rf packlink/packlink-pro-shipping/vendor/packlink/integration-core/.git
	rm -rf packlink/packlink-pro-shipping/vendor/packlink/integration-core/.gitignore
	rm -rf packlink/packlink-pro-shipping/vendor/packlink/integration-core/.idea
	rm -rf packlink/packlink-pro-shipping/vendor/packlink/integration-core/tests
	rm -rf packlink/packlink-pro-shipping/vendor/packlink/integration-core/README.md
	cd packlink && zip -r -q $(plugin_dir)/$(outfile) packlink-pro-shipping
	rm -rf packlink

