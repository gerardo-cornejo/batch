<?php

namespace Innite\Batch;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var Composer
     */
    protected $composer;

    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * Apply plugin modifications to Composer
     */
    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * Remove any hooks from Composer
     */
    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // No cleanup needed
    }

    /**
     * Prepare the plugin to be uninstalled
     */
    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // No cleanup needed
    }

    /**
     * Returns an array of event names this subscriber wants to listen to
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstall',
            ScriptEvents::POST_UPDATE_CMD => 'onPostUpdate',
        ];
    }

    /**
     * Handle post-install event
     */
    public function onPostInstall(Event $event): void
    {
        Installer::postInstall($event);
    }

    /**
     * Handle post-update event
     */
    public function onPostUpdate(Event $event): void
    {
        Installer::postUpdate($event);
    }
}
