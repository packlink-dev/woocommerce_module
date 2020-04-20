# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/).

## [Unreleased](https://github.com/logeecom/pl_woocommerce_module/compare/master...dev)

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
- Replaced the core PDF merge library with the one that was acceptable for WordPress
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