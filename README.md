# MageWorx MultiSource Inventory compatibility Plugin for the Order Editor module

**Base extension [MageWorx Order Management](https://www.mageworx.com/magento2-order-management-extension.html) is required*

## Upload the extension

### Upload using composer

1. Log into Magento server (or switch to) as a user who has permissions to write to the Magento file system.
2. Install package using composer: `composer require mageworx/module-ordereditor-inventory`

### Upload by copying code

1. Log into Magento server (or switch to) as a user who has permissions to write to the Magento file system.
2. Download the "Ready to paste" package from your customer's area, unzip it and upload the 'app' folder to your Magento install dir.


## Enable the extension

1. Log in to the Magento server as, or switch to, a user who has permissions to write to the Magento file system.
2. Go to your Magento install dir:
```
cd <your Magento install dir> 
```

3. And finally, update the database:
```
php bin/magento setup:upgrade
php bin/magento setup:static-content:deploy
```
