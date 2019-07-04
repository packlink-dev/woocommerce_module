# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/).

## [Unreleased](https://github.com/logeecom/pl_woocommerce_module/compare/dev...master)

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