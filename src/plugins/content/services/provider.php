<?php

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Sernicola\Plugin\Content\AiFriendly\Extension\AiFriendlyContent;

return new class implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(PluginInterface::class, static function (Container $container): PluginInterface {
            $plugin = new AiFriendlyContent((array) PluginHelper::getPlugin('content', 'aifriendly'));
            $plugin->setApplication(Factory::getApplication());
            return $plugin;
        });
    }
};
