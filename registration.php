<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */

use Magento\Framework\Component\ComponentRegistrar;

?>
<?php
ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'MageWorx_OrderEditorInventory',
    __DIR__
);
