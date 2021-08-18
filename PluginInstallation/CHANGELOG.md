# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/).

## [v3.2.1](https://github.com/logeecom/pl_woocommerce_module/compare/v3.1.3...v3.2.1)
### Changed
- Added new statuses and added order reference sync.

## [v3.2.0](https://github.com/logeecom/pl_woocommerce_module/compare/v3.1.3...v3.2.0) - 2021-07-07
### Changed
- Updated to the module white-label changes.
- Updated to the multi-currency changes.

## [v3.1.3](https://github.com/logeecom/pl_woocommerce_module/compare/v3.1.2...v3.1.3) - 2021-03-01
### Changed
- Preserve shipping class costs configuration when updating Packlink carriers.
- Remove notifications on the configuration page.
- Fix order status cancelled update.

## [v3.1.0](https://github.com/logeecom/pl_woocommerce_module/compare/v3.0.7...v3.1.0) - 2020-12-17
### Changed
- Added postal code transformer that transforms postal code into supported postal code format for GB, NL, US and PT countries.
- Added support for new warehouse countries.

## [v3.0.7](https://github.com/logeecom/pl_woocommerce_module/compare/v3.0.6...v3.0.7) - 2020-11-11
### Changed
- Revert change of `findOldestQueuedItems` method.
- Fix `findOldestQueuedItems` test and add support for PHP unit 5.4.0.

## [v3.0.6](https://github.com/logeecom/pl_woocommerce_module/compare/v3.0.5...v3.0.6) - 2020-11-10
### Changed
- Update to the latest Core 3.0.6.
- Fix warnings on the cart page.

## [v3.0.5](https://github.com/logeecom/pl_woocommerce_module/compare/v3.0.4...v3.0.5) - 2020-10-21
### Changed
- Update to the latest Core 3.0.4.
- Add sending "disable_carriers" analytics.

## [v3.0.4](https://github.com/logeecom/pl_woocommerce_module/compare/v3.0.3...v3.0.4) - 2020-09-28
### Changed
- Check whether Packlink object is defined before initializing checkout script.
- Fix error when plugin translations for a language don't exist.

## [v3.0.3](https://github.com/logeecom/pl_woocommerce_module/compare/v3.0.2...v3.0.3) - 2020-09-04
### Changed
- Fixed location picker issue.

## [v3.0.2](https://github.com/logeecom/pl_woocommerce_module/compare/v3.0.1...v3.0.2) - 2020-09-02
### Changed
- Fixed translation issue in italian.

## [v3.0.1](https://github.com/logeecom/pl_woocommerce_module/compare/v3.0.0...v3.0.1) - 2020-08-28
### Changed
- Fixed changing shop order status upon shipment status update.

## [v3.0.0](https://github.com/logeecom/pl_woocommerce_module/compare/v2.2.4...v3.0.0) - 2020-08-26
###
- Added an option for selecting specific shipping zones for a shipping service

### Changed
- Applied the new UI design
- Changed pricing policies management

## [v2.2.4](https://github.com/logeecom/pl_woocommerce_module/compare/v2.2.3...v2.2.4) - 2020-06-29
### Added
- Added support for the network activated WooCommerce plugin.
### Changed
- Added Hungary to the list of supported countries.
- Updated to breaking changes regarding the queue item priority.
- Fixed not saved drop-off point details on the order.

## [v2.2.3](https://github.com/logeecom/pl_woocommerce_module/compare/v2.2.2...v2.2.3) - 2020-06-11
### Changed
- Added "Send with Packlink" button on order overview page.

## [v2.2.2](https://github.com/logeecom/pl_woocommerce_module/compare/v2.2.1...v2.2.2) - 2020-05-26
### Changed
- Added top margin to drop-off button on checkout page.
- Fix retrieving controller url.

## [v2.2.1](https://github.com/logeecom/pl_woocommerce_module/compare/v2.2.0...v2.2.1) - 2020-04-27
### Changed
- Prevent export of order with no shippable products.
- Check order status before export to Packlink.
- Fix order export if orders are not made with Packlink shipping method.

## [v2.2.0](https://github.com/logeecom/pl_woocommerce_module/compare/v2.1.2...v2.2.0) - 2020-03-06
### Changed
- Updated to the latest Packlink Integration Core v2.0.0. The most important changes:
  * Update interval for shipment details is optimized. Now, the data for active shipments will be updated once a day.
  * Optimized code
  * Added a background task for cleaning up the task runner queue for completed tasks.

### Added
- Added more supported countries for Packlink accounts and shipments.

## [v2.1.2](https://github.com/logeecom/pl_woocommerce_module/compare/v2.1.1...v2.1.2) - 2019-12-16
### Changed
- Fixed the mechanism for updating information about created shipments.

## [v2.1.1](https://github.com/logeecom/pl_woocommerce_module/compare/v2.1.0...v2.1.1) - 2019-11-18
### Changed
- Fixed the update plugin mechanism.
- Allow setting the lowest boundary for fixed price policies per shipping method.
- Changed the update interval for getting shipment data from Packlink API.
- Updated compatibility with the latest WP and WC versions.

## [v2.1.0](https://github.com/logeecom/pl_woocommerce_module/compare/v2.0.9...v2.1.0) - 2019-10-21
### Changed
- Added automatic re-configuration of the module based on WooCommerce and WordPress settings in cases when the module cannot run with the default shop and server settings.

## [v2.0.9](https://github.com/logeecom/pl_woocommerce_module/compare/v2.0.8...v2.0.9) - 2019-09-11
### Changed
- Fixed compatibility bug with the WooCommerce prior to 3.0.4 for order shipping and billing addresses.

## [v2.0.8](https://github.com/logeecom/pl_woocommerce_module/compare/v2.0.7...v2.0.8) - 2019-09-03
### Changed
- Fixed compatibility bug with the PHP versions prior to 5.5.

## [v2.0.7](https://github.com/logeecom/pl_woocommerce_module/compare/v2.0.6...v2.0.7) - 2019-08-30
### Changed
- Fixed compatibility bug with the WooCommerce prior to 3.2 for shipment methods that require drop-off location.

## [v2.0.6](https://github.com/logeecom/pl_woocommerce_module/compare/v2.0.5...v2.0.6) - 2019-08-29
### Changed
- Fixed backward compatibility with the WooCommerce prior to 3.2
- Fixed problem in updating shipping information from Packlink

## [v2.0.5](https://github.com/logeecom/pl_woocommerce_module/compare/v2.0.5...v2.0.4) - 2019-07-22
### Changed
- Added new registration links
- Fixed some CSS issues

## [v2.0.4](https://github.com/logeecom/pl_woocommerce_module/compare/v2.0.4...v2.0.3) - 2019-07-17
### Changed
- Updated to the latest core
- Changed escaping resource URLs
- Fixed sending full shipping address with address 2 part (in Core) 
- Enhanced logging
- Added update message mechanism

## [v2.0.3](https://github.com/logeecom/pl_woocommerce_module/compare/v2.0.1...v2.0.2) - 2019-07-04
### Changed
- Replaced the core PDF merge library with the one acceptable for WordPress
- Prepared the code for the release

## [v2.0.2](https://github.com/logeecom/pl_woocommerce_module/compare/v2.0.2...v2.0.1) - 2019-06-01
### Changed
- Every Packlink API call now has a custom header that identifies the module (CR 14-01)
- Module now supports sending analytics events to the Packlink API (CR 14-02)

## [v2.0.1](https://github.com/logeecom/pl_woocommerce_module/compare/v2.0.1...v2.0.0) - 2019-05-29
### Changed
- Updated to the latest core changes
- Shipment labels are now fetched from Packlink only when order does not have labels set 
and shipment status is in one of:
    * READY_TO_PRINT
    * READY_FOR_COLLECTION
    * IN_TRANSIT
    * DELIVERED

## [v2.0.0](https://github.com/logeecom/pl_woocommerce_module/tree/v2.0.0) - 2019-03-11
- First stable release of the new module