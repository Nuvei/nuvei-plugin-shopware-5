# Nuvei payment plugin for ShopWare 5

## Description
Nuvei supports major international credit and debit cards enabling you to accept payments from your global customers. 

A wide selection of region-specific payment methods can help your business grow in new markets. Other popular payment methods, from mobile payments to e-wallets, can be easily implemented on your checkout page.

The correct payment methods at the checkout page can bring you global reach, help you increase conversions, and create a seamless experience for your customers.

## System Requirements
- ShopWare v5.3 to v5.7  
- Working PHP cURL module

## Nuvei Requirements
- Enabled DMNs into merchant settings.  
- Whitelisted plugin endpoint so the plugin can receive the DMNs.  
- On SiteID level "DMN  timeout" setting is recommendet to be not less than 20 seconds, 30 seconds is better.  

## Manual Installation
1. Download the last release of the plugin ("SwagNuvei.zip") or form master branch.
2. Select one of the following methods:
  - If you downloaded the plugin from some of the branches:
    1. Extract the plugin and rename the folder to "SwagNuvei".
	2. Add it to a ZIP archive.
	3. Install the archive from WordPress > Plugins > Add New. Go to Step 8.
  - If you downloaded the plugin from the Releases page continue.
3. Install the arhive into ShopWare.

## Notes
If you have problem to receive Nuvei DMNs, please consider disabling CSRF Protection for the frontend as described here - https://developers.shopware.com/developers-guide/csrf-protection/#disable-the-protection.

## Support
Please contact our Technical Support (tech-support@nuvei.com) for any questions and difficulties.