## WooCommerce OderoPay Gateway

This is the official WooCommerce extension to receive payments using the OderoPay payments provider.

### Description

The OderoPay extension for WooCommerce enables you to accept payments including Subscriptions, Deposits & Pre-Orders via one of Romania’s most popular payment gateways.

#### Important Notice:
Woocommerce has a Rounding Bug issue (check more on issues [1](https://github.com/woocommerce/woocommerce/issues/34529)
 and [2](https://github.com/woocommerce/woocommerce/issues/24184))

Odero plugin doesnt round up after 2nd decimals. Example;
Assume that cart total becomes `49.9000233`  after some division. 
Woocommerce displays this amount as `49.91` yet, it is `49.90` for odero.

### Why choose OderoPay?

OderoPay gives your customers more flexibility including putting down deposits, ordering ahead of time or paying on a weekly, monthly or annual basis.

### Frequently Asked Questions

#### Does this require a OderoPay merchant account?

Yes! An OderoPay merchant account, merchant token and merchant ID are required for this gateway to function.

#### Does this require an SSL certificate? 

An SSL certificate is recommended for additional safety and security for your customers.

#### Where can I find documentation? 

For help, setting up and configuring, please refer to our [user guide](https://developer.pay.odero.ro)

#### Where can I get support or talk to other users?

If you get stuck, you can ask for help in the Plugin Forum.

### Changelog

1.2.4 - 2024-05-31
Wordpress Plugin directory submission review

1.2.2 - 2024-05-31
Fixing calculation with inc/exl taxes
Fixing cart total with Shipping with Excluded Tax


1.0.9 - 2023-02-14
Fix for the products dont have images

1.0.8 - 2023-02-14
Improve logging

1.0.6 - 2023-02-14
Fix for empty Billing and Shipping address

1.0.1 - 2023-02-14
Fix for IPN webhook

1.0.0 - 2023-02-13
Initial version release


