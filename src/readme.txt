=== Packlink PRO shipping module ===
Contributors: packlink
Tags: woocommerce, shipment, shipping, packlink
Requires at least: 4.7
Requires PHP: 5.5
Tested up to: 5.3.0
Stable tag: 2.1.1
License: LICENSE-2.0
License URI: http://www.apache.org/licenses/LICENSE-2.0

Free professional shipping platform that will simplify and automate your logistics.

== Description ==

**Packlink PRO is the professional shipping platform that allows you to automate your shipment process.** It is free to use and does not require a minimum volume of shipments. You only need to register and you'll get instant access to a variety of shipping services and rates that can help to make your business more profitable.


Connect your WooCommerce account with Packlink PRO.

- You have complete control over your sales and you can manage all your shipments from a single platform.

- You can start shipping straight away: there's no contract to sign and no minimum shipping volume.

- Choose the transport services that your customers want: express, international, etc...

- Save time in your daily shipping routine - import the parcel dimensions and destination, print the labels in bulk and check at a glance the status of all your shipments.

- Individual telephone support: a team of shipping specialists will assist you with the integration process and provide ongoing account management.


No download costs, installation or monthly fees - you pay purely for the shipments you book!

**<a href="https://pro.packlink.es/cmslp/woocommerce" target="_blank" title="Subscription">Register free</a> in Packlink PRO and get started!**

== Installation ==

== Installation ==

This is how the WooCommerce integration with Packlink PRO works.

**1. Install and configure the plugin**

- You can install the Packlink PRO plugin in one of two ways: either a. directly from your back office, or b. from the WooCommerce plugs page.
  - Option a. From your WordPress back office go to "Plugins" > "Add new" > then, search for "Packlink" > "Install now".
  - Option b. Go to <a href="https://wordpress.org/plugins/packlink-pro-shipping">https://wordpress.org/plugins/packlink-pro-shipping</a> and click on the "Download" button. Then, from your WordPress back office "Plugins" section click on "Add new" > "Upload plugin" and upload the downloaded zip file.

- Once you have installed the plugin, login to the Packlink PRO website and click on the "Configuration" icon in the top right-hand corner. Then, from the left-hand menu, select "Integrations for your online store" and click on the WooCommerce logo, where you can generate the API key required to synchronize both platforms. Copy this API key. You will need to enter this key in Packlink PRO module in WooCommerce.

- In Packlink PRO, you can define the dimensions of your most common parcel and pickup address. This information is automatically synchronized with your WooCommerce and becomes your predefined parcel and address.

**2. Sync with your Packlink PRO account**

- Go back to your WooCommerce back office and select the WooCommerce > Packlink PRO from the left-hand menu. When the module login page opens, paste the API key you copied from your Packlink PRO account and click on the Log in button. The module will automatically synchronize your default parcel dimensions and pickup address from Packlink PRO. Also, after a few moments, it will synchronize all available shipping services.

- Select the shipping services you want to use. When you click on a "configure" button next to each shipping service, you can configure how you name each service and whether you show the carrier logo to your customers.

- Besides name and logo, for each shipping service you can define your pricing policy by choosing from the following options: direct Packlink prices, percentage of Packlink price, fixed price by weight, or fixed price by shopping cart.


**3. Use the module**

- If an order has been paid or payment was accepted by you, the shipment will be automatically imported into your Packlink PRO account. Also, you have an option to manually send an order to the Packlink PRO by opening order details page and clicking on the "Create draft" button in the "Packlink PRO Shipping" section on the right side.

- Packlink PRO is always updated with all shipments that are ready for shipment in WooCommerce.

- You only need to access Packlink PRO for the payment. Sender and recipient details will already have been synchronized with WooCommerce data.


Click <a href="https://support-pro.packlink.com/hc/es-es/articles/210158585" target="_blank" title="support">here</a> to get more information about the installation of the module.


== Upgrade Notice ==

= 2.1.1 =

* Allow setting the lowest boundary for fixed price policies per shipping method.
* Changed the update interval for getting shipment data from Packlink API.
* Updated compatibility with the latest WP and WC versions

= 2.1.0 =

* Added automatic re-configuration of the module based on WooCommerce and WordPress settings in cases when the module cannot run with the default shop and server settings.

= 2.0.9 =

* Fixed compatibility bug with the WooCommerce prior to 3.0.4 for order shipping and billing addresses.

= 2.0.8 =

* Fixed compatibility bug with the PHP versions prior to 5.5.

= 2.0.7 =

* Fixed compatibility bug with the WooCommerce prior to 3.2 for shipment methods that require drop-off location.

= 2.0.6 =

* Fixed backward compatibility with the WooCommerce prior to 3.2
* Fixed problem in updating shipping information from Packlink

= 2.0.5 =

* Added new registration links
* Fixed some CSS issues

= 2.0.4 =

* Added update message mechanism
* Minor bug fixes

= 2.0.3 =

* The Add-on configuration page is completely redesigned with advanced options
* Added possibility for admin to enable only specific shipping services for end customers
* Each shipping service can be additionally configured by admin - title, logo display, advanced pricing policy configuration
* Enhanced integration with Packlink API
* End customers can now select a specific drop-off point for such shipping services during the checkout process
* Order list now has information about Packlink shipments and option to print shipping labels directly from the order list
* Order details page now contains more information about each shipment

= 1.0.2 =
* Tested up to: 4.9.1

= 1.0 =
* Initial version.
